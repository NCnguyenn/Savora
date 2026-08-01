<?php
declare(strict_types=1);

function admin_idempotency_response(mysqli $conn, int $actorId, string $idempotencyKey): ?array
{
    $lookup = $conn->prepare('SELECT response_json FROM idempotency_keys WHERE actor_user_id = ? AND idempotency_key = ? LIMIT 1');
    $lookup->bind_param('is', $actorId, $idempotencyKey);
    $lookup->execute();
    $existing = $lookup->get_result()->fetch_assoc();
    $lookup->close();
    if (!$existing) {
        return null;
    }
    $decoded = json_decode((string) $existing['response_json'], true);
    return is_array($decoded) ? $decoded : ['ok' => false, 'message' => 'Stored response is invalid.'];
}

function admin_store_idempotency(mysqli $conn, int $actorId, string $key, string $action, array $response): void
{
    $json = json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $stmt = $conn->prepare('INSERT INTO idempotency_keys (actor_user_id, idempotency_key, action, response_json) VALUES (?, ?, ?, ?)');
    $stmt->bind_param('isss', $actorId, $key, $action, $json);
    $stmt->execute();
    $stmt->close();
}

function admin_append_audit(mysqli $conn, int $actorId, string $action, string $entityType, ?int $entityId, mixed $before, mixed $after, string $reason, string $referenceId): void
{
    $beforeJson = $before === null ? null : json_encode($before, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $afterJson = $after === null ? null : json_encode($after, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $ipAddress = mb_substr((string) ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'), 0, 64);
    $sessionId = session_status() === PHP_SESSION_ACTIVE ? session_id() : '';
    $stmt = $conn->prepare("INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, before_summary, after_summary, reason, ip_address, session_id, result, reference_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'success', ?)");
    $stmt->bind_param('ississssss', $actorId, $action, $entityType, $entityId, $beforeJson, $afterJson, $reason, $ipAddress, $sessionId, $referenceId);
    $stmt->execute();
    $stmt->close();
}

function admin_setting_value(string $key, mixed $value, array $allowedSettingKeys): string
{
    $rule = $allowedSettingKeys[$key];
    if ($rule['type'] === 'boolean') {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
    }
    if (!is_numeric($value)) {
        throw new InvalidArgumentException('Enter a numeric value.');
    }
    $number = (int) $value;
    if ($number < $rule['min'] || $number > $rule['max']) {
        throw new InvalidArgumentException("Value must be between {$rule['min']} and {$rule['max']}.");
    }
    return (string) $number;
}

function admin_update_setting(mysqli $conn, array $payload, int $actorId, string $idempotencyKey): array
{
    $allowedSettingKeys = [
        'restaurant_acceptance_minutes' => ['type' => 'integer', 'min' => 1, 'max' => 30],
        'preparation_delay_minutes' => ['type' => 'integer', 'min' => 5, 'max' => 120],
        'dispatch_offer_seconds' => ['type' => 'integer', 'min' => 10, 'max' => 120],
        'dispatch_max_attempts' => ['type' => 'integer', 'min' => 1, 'max' => 12],
        'support_critical_minutes' => ['type' => 'integer', 'min' => 5, 'max' => 240],
        'support_standard_hours' => ['type' => 'integer', 'min' => 1, 'max' => 168],
        'maintenance_mode' => ['type' => 'boolean', 'min' => 0, 'max' => 1],
    ];
    $key = (string) ($payload['setting_key'] ?? '');
    if (!isset($allowedSettingKeys[$key])) {
        return ['ok' => false, 'message' => 'This setting cannot be changed.', 'errors' => ['setting_key' => 'Unsupported setting.'], 'referenceId' => admin_reference_id()];
    }
    try {
        $value = admin_setting_value($key, $payload['setting_value'] ?? null, $allowedSettingKeys);
    } catch (InvalidArgumentException $exception) {
        return ['ok' => false, 'message' => 'Check the setting value.', 'errors' => ['setting_value' => $exception->getMessage()], 'referenceId' => admin_reference_id()];
    }
    $expectedVersion = max(1, (int) ($payload['version'] ?? 0));
    $reason = trim((string) ($payload['reason'] ?? 'Platform configuration update'));
    $referenceId = admin_reference_id();

    $conn->begin_transaction();
    try {
        $lock = $conn->prepare('SELECT setting_value, value_type, version FROM platform_settings WHERE setting_key = ? FOR UPDATE');
        $lock->bind_param('s', $key);
        $lock->execute();
        $before = $lock->get_result()->fetch_assoc();
        $lock->close();
        if (!$before) {
            throw new RuntimeException('Setting not found.');
        }
        if ((int) $before['version'] !== $expectedVersion) {
            $conn->rollback();
            return ['ok' => false, 'message' => 'This setting changed in another session. Refresh and try again.', 'errors' => ['version' => 'Stale setting version.'], 'referenceId' => $referenceId];
        }
        $update = $conn->prepare('UPDATE platform_settings SET setting_value = ?, updated_by = ?, version = version + 1 WHERE setting_key = ? AND version = ?');
        $update->bind_param('sisi', $value, $actorId, $key, $expectedVersion);
        $update->execute();
        $update->close();
        $after = ['setting_value' => $value, 'version' => $expectedVersion + 1];
        admin_append_audit($conn, $actorId, 'update_setting', 'platform_setting', null, $before, $after, mb_substr($reason, 0, 500), $referenceId);
        $response = ['ok' => true, 'message' => 'Platform setting updated.', 'data' => ['setting_key' => $key] + $after, 'referenceId' => $referenceId];
        admin_store_idempotency($conn, $actorId, $idempotencyKey, 'update_setting', $response);
        $conn->commit();
        return $response;
    } catch (Throwable $exception) {
        $conn->rollback();
        return ['ok' => false, 'message' => 'The setting could not be updated.', 'errors' => ['setting_value' => $exception->getMessage()], 'referenceId' => $referenceId];
    }
}

function admin_update_notification_template(mysqli $conn, array $payload, int $actorId, string $idempotencyKey): array
{
    $key = mb_substr(trim((string) ($payload['template_key'] ?? '')), 0, 100);
    $subject = mb_substr(trim((string) ($payload['subject'] ?? '')), 0, 200);
    $message = mb_substr(trim((string) ($payload['message_template'] ?? '')), 0, 2000);
    $channel = (string) ($payload['channel'] ?? 'in_app');
    $enabled = filter_var($payload['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
    $expectedVersion = max(1, (int) ($payload['version'] ?? 0));
    if ($key === '' || $subject === '' || $message === '' || !in_array($channel, ['in_app', 'email', 'sms'], true)) {
        return ['ok' => false, 'message' => 'Complete all template fields.', 'errors' => ['template' => 'Subject, message and a supported channel are required.'], 'referenceId' => admin_reference_id()];
    }
    $referenceId = admin_reference_id();
    $conn->begin_transaction();
    try {
        $lock = $conn->prepare('SELECT template_key, subject, message_template, channel, enabled, version FROM notification_templates WHERE template_key = ? FOR UPDATE');
        $lock->bind_param('s', $key);
        $lock->execute();
        $before = $lock->get_result()->fetch_assoc();
        $lock->close();
        if (!$before || (int) $before['version'] !== $expectedVersion) {
            throw new RuntimeException('Template is missing or has a stale version.');
        }
        $update = $conn->prepare('UPDATE notification_templates SET subject = ?, message_template = ?, channel = ?, enabled = ?, updated_by = ?, version = version + 1 WHERE template_key = ? AND version = ?');
        $update->bind_param('sssiisi', $subject, $message, $channel, $enabled, $actorId, $key, $expectedVersion);
        $update->execute();
        $update->close();
        $after = ['subject' => $subject, 'message_template' => $message, 'channel' => $channel, 'enabled' => $enabled, 'version' => $expectedVersion + 1];
        admin_append_audit($conn, $actorId, 'update_notification_template', 'notification_template', null, $before, $after, 'Notification policy updated', $referenceId);
        $response = ['ok' => true, 'message' => 'Notification template updated.', 'data' => ['template_key' => $key] + $after, 'referenceId' => $referenceId];
        admin_store_idempotency($conn, $actorId, $idempotencyKey, 'update_notification_template', $response);
        $conn->commit();
        return $response;
    } catch (Throwable $exception) {
        $conn->rollback();
        return ['ok' => false, 'message' => 'The template could not be updated.', 'errors' => ['template' => $exception->getMessage()], 'referenceId' => $referenceId];
    }
}

function admin_account_action(mysqli $conn, string $action, array $payload, int $actorId, string $idempotencyKey): array
{
    $targetId = max(0, (int) ($payload['user_id'] ?? 0));
    $expectedVersion = max(1, (int) ($payload['version'] ?? 0));
    $reason = mb_substr(trim((string) ($payload['reason'] ?? '')), 0, 500);
    $statusActions = ['suspend_account' => 'suspended', 'reactivate_account' => 'active', 'block_account' => 'blocked'];
    if ($targetId === 0 || (($action !== 'reset_password') && $reason === '')) {
        return ['ok' => false, 'message' => 'A target account and audit reason are required.', 'errors' => ['reason' => 'Explain why this intervention is needed.'], 'referenceId' => admin_reference_id()];
    }
    $referenceId = admin_reference_id();
    $conn->begin_transaction();
    try {
        $lock = $conn->prepare('SELECT id, username, role, full_name, email, status, session_version, version FROM users WHERE id = ? FOR UPDATE');
        $lock->bind_param('i', $targetId);
        $lock->execute();
        $before = $lock->get_result()->fetch_assoc();
        $lock->close();
        if (!$before) {
            throw new RuntimeException('Account not found.');
        }
        if ($before['role'] === 'admin') {
            throw new RuntimeException('The only full-access Admin account is protected from account interventions.');
        }
        if ((int) $before['version'] !== $expectedVersion) {
            throw new RuntimeException('Account has a stale version. Refresh before retrying.');
        }

        $after = $before;
        $recoveryUrl = null;
        if (isset($statusActions[$action])) {
            $nextStatus = $statusActions[$action];
            if ($before['status'] === $nextStatus) {
                throw new RuntimeException('Account is already in that status.');
            }
            $update = $conn->prepare('UPDATE users SET status = ?, session_version = session_version + 1, version = version + 1 WHERE id = ? AND version = ?');
            $update->bind_param('sii', $nextStatus, $targetId, $expectedVersion);
            $update->execute();
            $update->close();
            $history = $conn->prepare('INSERT INTO account_status_history (user_id, previous_status, next_status, actor_user_id, reason) VALUES (?, ?, ?, ?, ?)');
            $history->bind_param('issis', $targetId, $before['status'], $nextStatus, $actorId, $reason);
            $history->execute();
            $history->close();
            $after['status'] = $nextStatus;
            $after['version'] = $expectedVersion + 1;
            $after['session_version'] = (int) $before['session_version'] + 1;
        } elseif ($action === 'revoke_sessions') {
            $revoke = $conn->prepare('UPDATE user_sessions SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL');
            $revoke->bind_param('i', $targetId);
            $revoke->execute();
            $revokedCount = $revoke->affected_rows;
            $revoke->close();
            $update = $conn->prepare('UPDATE users SET session_version = session_version + 1, version = version + 1 WHERE id = ? AND version = ?');
            $update->bind_param('ii', $targetId, $expectedVersion);
            $update->execute();
            $update->close();
            $after['revoked_sessions'] = $revokedCount;
            $after['version'] = $expectedVersion + 1;
        } elseif ($action === 'reset_password') {
            $temporarySecret = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $temporarySecret);
            $conn->query("UPDATE password_reset_tokens SET used_at = NOW() WHERE user_id = {$targetId} AND used_at IS NULL");
            $token = $conn->prepare('INSERT INTO password_reset_tokens (user_id, token_hash, expires_at, created_by) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 MINUTE), ?)');
            $token->bind_param('isi', $targetId, $tokenHash, $actorId);
            $token->execute();
            $token->close();
            $update = $conn->prepare('UPDATE users SET session_version = session_version + 1, version = version + 1 WHERE id = ? AND version = ?');
            $update->bind_param('ii', $targetId, $expectedVersion);
            $update->execute();
            $update->close();
            $revoke = $conn->prepare('UPDATE user_sessions SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL');
            $revoke->bind_param('i', $targetId);
            $revoke->execute();
            $revoke->close();
            $after['credential_reset'] = true;
            $recoveryUrl = 'reset_password.php?token=' . $temporarySecret;
            $after['version'] = $expectedVersion + 1;
            $reason = $reason ?: 'Administrator initiated secure credential recovery';
        } else {
            throw new RuntimeException('Unsupported account action.');
        }

        $notification = $conn->prepare('INSERT INTO notifications (user_id, event_type, title, message, entity_type, entity_id) VALUES (?, ?, ?, ?, ?, ?)');
        $title = 'Account security update';
        $message = 'An account security action was completed. Contact support if you did not expect this change.';
        $entityType = 'user';
        $notification->bind_param('issssi', $targetId, $action, $title, $message, $entityType, $targetId);
        $notification->execute();
        $notification->close();
        admin_append_audit($conn, $actorId, $action, 'user', $targetId, $before, $after, $reason, $referenceId);
        $responseData = ['user_id' => $targetId, 'status' => $after['status'], 'version' => $after['version']];
        if ($recoveryUrl !== null) $responseData['recovery_url'] = $recoveryUrl;
        $response = ['ok' => true, 'message' => 'Account security action completed.', 'data' => $responseData, 'referenceId' => $referenceId];
        admin_store_idempotency($conn, $actorId, $idempotencyKey, $action, $response);
        $conn->commit();
        return $response;
    } catch (Throwable $exception) {
        $conn->rollback();
        return ['ok' => false, 'message' => 'The account action could not be completed.', 'errors' => ['reason' => $exception->getMessage()], 'referenceId' => $referenceId];
    }
}

function admin_partner_application_action(mysqli $conn, string $action, array $payload, int $actorId, string $idempotencyKey): array
{
    $isRestaurant = str_contains($action, 'restaurant');
    $applicationTable = $isRestaurant ? 'restaurant_applications' : 'driver_applications';
    $documentTable = $isRestaurant ? 'restaurant_application_documents' : 'driver_application_documents';
    $role = $isRestaurant ? 'restaurant' : 'driver';
    $applicationId = max(0, (int) ($payload['application_id'] ?? 0));
    $expectedVersion = max(1, (int) ($payload['version'] ?? 0));
    $note = mb_substr(trim((string) ($payload['reviewer_note'] ?? $payload['reason'] ?? '')), 0, 1000);
    $decision = str_starts_with($action, 'approve_') ? 'approved' : (str_starts_with($action, 'request_') ? 'changes_requested' : 'rejected');
    if ($applicationId === 0 || ($decision !== 'approved' && $note === '')) {
        return ['ok' => false, 'message' => 'Application and reviewer note are required.', 'errors' => ['reviewer_note' => 'Add a clear decision note.'], 'referenceId' => admin_reference_id()];
    }
    $referenceId = admin_reference_id();
    $conn->begin_transaction();
    try {
        $lock = $conn->prepare("SELECT * FROM {$applicationTable} WHERE id = ? FOR UPDATE");
        $lock->bind_param('i', $applicationId);
        $lock->execute();
        $before = $lock->get_result()->fetch_assoc();
        $lock->close();
        if (!$before || !in_array($before['status'], ['pending','in_review','changes_requested'], true)) throw new RuntimeException('Application is no longer reviewable.');
        if ((int) $before['version'] !== $expectedVersion) throw new RuntimeException('Application has a stale version.');
        $newUserId = null;
        if ($decision === 'approved') {
            $documentQuery = $conn->prepare("SELECT document_type, verification_status, expires_at FROM {$documentTable} WHERE application_id = ?");
            $documentQuery->bind_param('i', $applicationId);
            $documentQuery->execute();
            $documents = $documentQuery->get_result()->fetch_all(MYSQLI_ASSOC);
            $documentQuery->close();
            $requiredDocuments = $isRestaurant
                ? ['business_registration', 'food_safety_certificate', 'owner_identity']
                : ['driver_license', 'vehicle_registration', 'background_check'];
            $documentsByType = [];
            foreach ($documents as $document) $documentsByType[(string) $document['document_type']] = $document;
            foreach ($requiredDocuments as $requiredType) {
                if (!isset($documentsByType[$requiredType])) throw new RuntimeException('A required document is missing: ' . str_replace('_', ' ', $requiredType) . '.');
                $document = $documentsByType[$requiredType];
                if ($document['verification_status'] !== 'verified' || ($document['expires_at'] && strtotime((string) $document['expires_at']) <= time())) throw new RuntimeException('Every required document must be verified and current.');
            }
            if (!$before['password_hash']) throw new RuntimeException('Application credentials were already consumed.');
            $duplicate = $conn->prepare('SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1');
            $email = $isRestaurant ? $before['owner_email'] : $before['email'];
            $duplicate->bind_param('ss', $before['username'], $email);
            $duplicate->execute();
            $exists = $duplicate->get_result()->fetch_assoc();
            $duplicate->close();
            if ($exists) throw new RuntimeException('Username or email already belongs to an account.');
            $fullName = $isRestaurant ? $before['owner_name'] : $before['full_name'];
            $phone = $isRestaurant ? $before['owner_phone'] : $before['phone'];
            $insert = $conn->prepare("INSERT INTO users (username, password, role, full_name, email, phone, status) VALUES (?, ?, ?, ?, ?, ?, 'active')");
            $insert->bind_param('ssssss', $before['username'], $before['password_hash'], $role, $fullName, $email, $phone);
            $insert->execute();
            $newUserId = $insert->insert_id;
            $insert->close();
            if ($isRestaurant) {
                $profile = $conn->prepare("INSERT INTO restaurants (owner_user_id, name, cuisine, address, city, phone, status, accepting_orders) VALUES (?, ?, ?, ?, ?, ?, 'active', 0)");
                $profile->bind_param('isssss', $newUserId, $before['restaurant_name'], $before['cuisine'], $before['address'], $before['city'], $before['owner_phone']);
            } else {
                $profile = $conn->prepare("INSERT INTO driver_profiles (user_id, city, vehicle_type, vehicle_model, license_plate, service_area, eligibility_status, availability_status) VALUES (?, ?, ?, ?, ?, ?, 'eligible', 'offline')");
                $profile->bind_param('isssss', $newUserId, $before['city'], $before['vehicle_type'], $before['vehicle_model'], $before['license_plate'], $before['service_area']);
            }
            $profile->execute();
            $profile->close();
        }
        $consumeCredentials = in_array($decision, ['approved', 'rejected'], true) ? 1 : 0;
        $update = $conn->prepare("UPDATE {$applicationTable} SET status = ?, reviewer_id = ?, reviewer_note = ?, decision_reason = ?, reviewed_at = NOW(), password_hash = IF(? = 1, NULL, password_hash), version = version + 1 WHERE id = ? AND version = ?");
        $update->bind_param('sissiii', $decision, $actorId, $note, $note, $consumeCredentials, $applicationId, $expectedVersion);
        $update->execute();
        $update->close();
        $after = ['status' => $decision, 'reviewer_id' => $actorId, 'user_id' => $newUserId, 'version' => $expectedVersion + 1];
        if ($newUserId) {
            $notification = $conn->prepare("INSERT INTO notifications (user_id, event_type, title, message, entity_type, entity_id) VALUES (?, ?, 'Application approved', 'Your Savora account is active. Sign in to complete your profile.', ?, ?)");
            $entityType = $role . '_application';
            $notification->bind_param('issi', $newUserId, $action, $entityType, $applicationId);
            $notification->execute();
            $notification->close();
        }
        admin_append_audit($conn, $actorId, $action, $role . '_application', $applicationId, $before, $after, $note ?: 'All required checks passed', $referenceId);
        $response = ['ok' => true, 'message' => ucfirst($role) . ' application ' . str_replace('_', ' ', $decision) . '.', 'data' => ['application_id' => $applicationId, 'status' => $decision, 'user_id' => $newUserId, 'version' => $expectedVersion + 1], 'referenceId' => $referenceId];
        admin_store_idempotency($conn, $actorId, $idempotencyKey, $action, $response);
        $conn->commit();
        return $response;
    } catch (Throwable $exception) {
        $conn->rollback();
        return ['ok' => false, 'message' => 'The application decision could not be completed.', 'errors' => ['reviewer_note' => $exception->getMessage()], 'referenceId' => $referenceId];
    }
}

function admin_operations_action(mysqli $conn,string $action,array $payload,int $actorId,string $idempotencyKey):array
{
    $reason=mb_substr(trim((string)($payload['reason']??'')),0,500);$referenceId=admin_reference_id();
    if($reason==='')return ['ok'=>false,'message'=>'An audit reason is required.','errors'=>['reason'=>'Explain the controlled intervention.'],'referenceId'=>$referenceId];
    $conn->begin_transaction();
    try{
        $entityType='operation';$entityId=null;$before=null;$after=[];
        if(in_array($action,['reassign_driver','cancel_order','open_incident'],true)){
            $orderId=max(0,(int)($payload['order_id']??0));$lock=$conn->prepare('SELECT * FROM orders WHERE id=? FOR UPDATE');$lock->bind_param('i',$orderId);$lock->execute();$before=$lock->get_result()->fetch_assoc();$lock->close();if(!$before)throw new RuntimeException('Order not found.');$entityType='order';$entityId=$orderId;
            if($action==='cancel_order'){if(in_array($before['status'],['delivered','cancelled','refunded'],true))throw new RuntimeException('Final orders cannot be cancelled.');$stmt=$conn->prepare("UPDATE orders SET status='cancelled',version=version+1 WHERE id=?");$stmt->bind_param('i',$orderId);$stmt->execute();$stmt->close();$history=$conn->prepare("INSERT INTO order_status_history(order_id,status,actor_role,actor_user_id,reason) VALUES(?,'cancelled','admin',?,?)");$history->bind_param('iis',$orderId,$actorId,$reason);$history->execute();$history->close();$after=['status'=>'cancelled'];}
            elseif($action==='reassign_driver'){$driverId=max(0,(int)($payload['driver_user_id']??0));$eligible=$conn->prepare("SELECT u.id FROM users u JOIN driver_profiles d ON d.user_id=u.id WHERE u.id=? AND u.status='active' AND d.eligibility_status='eligible' FOR UPDATE");$eligible->bind_param('i',$driverId);$eligible->execute();$ok=$eligible->get_result()->fetch_assoc();$eligible->close();if(!$ok)throw new RuntimeException('Selected Driver is not eligible.');$dispatch=$conn->prepare("INSERT INTO delivery_dispatches(order_id,status,assigned_driver_user_id,attempt_count) VALUES(?,'assigned',?,1) ON DUPLICATE KEY UPDATE status='assigned',assigned_driver_user_id=VALUES(assigned_driver_user_id),attempt_count=attempt_count+1,version=version+1");$dispatch->bind_param('ii',$orderId,$driverId);$dispatch->execute();$dispatch->close();$after=['driver_user_id'=>$driverId,'dispatch_status'=>'assigned'];}
            else{$caseReference='CASE-'.date('ymd').'-'.strtoupper(bin2hex(random_bytes(3)));$reportingRole='admin';$priority='high';$subject='Admin incident for '.$before['reference_code'];$case=$conn->prepare("INSERT INTO support_cases(reference_code,order_id,case_type,reporting_role,reporting_user_id,priority,status,subject,sla_due_at) VALUES(?,?,'operational_incident',?,? ,?,'open',?,DATE_ADD(NOW(),INTERVAL 30 MINUTE))");$case->bind_param('sisiss',$caseReference,$orderId,$reportingRole,$actorId,$priority,$subject);$case->execute();$entityId=$case->insert_id;$case->close();$entityType='support_case';$after=['reference_code'=>$caseReference,'status'=>'open'];}
        }elseif(in_array($action,['request_case_information','resolve_case','issue_refund'],true)){
            $caseId=max(0,(int)($payload['case_id']??0));$lock=$conn->prepare('SELECT * FROM support_cases WHERE id=? FOR UPDATE');$lock->bind_param('i',$caseId);$lock->execute();$before=$lock->get_result()->fetch_assoc();$lock->close();if(!$before)throw new RuntimeException('Case not found.');$entityType='support_case';$entityId=$caseId;
            if($action==='request_case_information'){$message=$conn->prepare("INSERT INTO case_messages(case_id,author_role,author_user_id,message,internal_only) VALUES(?,'admin',?,?,0)");$message->bind_param('iis',$caseId,$actorId,$reason);$message->execute();$message->close();$after=['status'=>$before['status'],'message_added'=>true];}
            elseif($action==='resolve_case'){$stmt=$conn->prepare("UPDATE support_cases SET status='resolved',resolution=?,version=version+1 WHERE id=?");$stmt->bind_param('si',$reason,$caseId);$stmt->execute();$stmt->close();$after=['status'=>'resolved'];}
            else{$orderId=(int)($before['order_id']??0);$amount=round((float)($payload['amount']??0),2);$payment=$conn->prepare('SELECT amount,status FROM payments WHERE order_id=? FOR UPDATE');$payment->bind_param('i',$orderId);$payment->execute();$paid=$payment->get_result()->fetch_assoc();$payment->close();$existing=(float)(admin_idempotency_scalar($conn,'SELECT COALESCE(SUM(amount),0) value FROM refunds WHERE order_id=?',$orderId));if(!$paid||$amount<=0||$amount>((float)$paid['amount']-$existing))throw new RuntimeException('Refund exceeds the remaining paid amount.');$destination=(string)($payload['destination']??'original_payment');$refund=$conn->prepare("INSERT INTO refunds(order_id,case_id,amount,destination,reason,status,actor_user_id) VALUES(?,?,?,?,?,'processed',?)");$refund->bind_param('iidssi',$orderId,$caseId,$amount,$destination,$reason,$actorId);$refund->execute();$refundId=$refund->insert_id;$refund->close();$ledgerRef='REF-'.$refundId;$net=-$amount;$ledger=$conn->prepare("INSERT INTO ledger_entries(reference_code,order_id,entry_type,party_type,gross_amount,net_amount,status) VALUES(?,?,'refund','customer',?,?,'completed')");$ledger->bind_param('sidd',$ledgerRef,$orderId,$net,$net);$ledger->execute();$ledger->close();if(abs(($existing+$amount)-(float)$paid['amount'])<0.01){$conn->query("UPDATE orders SET status='refunded',version=version+1 WHERE id={$orderId}");}$after=['refund_id'=>$refundId,'amount'=>$amount];}
        }elseif(in_array($action,['hold_payout','release_payout'],true)){$id=max(0,(int)($payload['payout_id']??0));$lock=$conn->prepare('SELECT * FROM payouts WHERE id=? FOR UPDATE');$lock->bind_param('i',$id);$lock->execute();$before=$lock->get_result()->fetch_assoc();$lock->close();if(!$before)throw new RuntimeException('Payout not found.');$status=$action==='hold_payout'?'held':'scheduled';$stmt=$conn->prepare('UPDATE payouts SET status=?,hold_reason=?,version=version+1 WHERE id=?');$stmt->bind_param('ssi',$status,$reason,$id);$stmt->execute();$stmt->close();$entityType='payout';$entityId=$id;$after=['status'=>$status];
        }elseif($action==='settle_cod'){$id=max(0,(int)($payload['reconciliation_id']??0));$amount=round((float)($payload['amount']??0),2);$lock=$conn->prepare('SELECT * FROM cod_reconciliations WHERE id=? FOR UPDATE');$lock->bind_param('i',$id);$lock->execute();$before=$lock->get_result()->fetch_assoc();$lock->close();if(!$before||$amount<=0)throw new RuntimeException('Valid COD reconciliation and amount required.');$stmt=$conn->prepare("UPDATE cod_reconciliations SET settled_amount=LEAST(due_amount,settled_amount+?),status=IF(settled_amount+?>=due_amount,'settled','open'),reconciled_at=NOW(),version=version+1 WHERE id=?");$stmt->bind_param('ddi',$amount,$amount,$id);$stmt->execute();$stmt->close();$entityType='cod_reconciliation';$entityId=$id;$after=['settled_amount'=>$amount];
        }elseif(in_array($action,['save_promotion','pause_promotion','schedule_fee_rule','set_service_area_status'],true)){$entityType='commercial_rule';
            if($action==='pause_promotion'){$id=max(0,(int)($payload['promotion_id']??0));$stmt=$conn->prepare("UPDATE promotions SET status='paused',version=version+1 WHERE id=?");$stmt->bind_param('i',$id);$stmt->execute();if($stmt->affected_rows!==1)throw new RuntimeException('Promotion not found.');$stmt->close();$entityId=$id;$after=['status'=>'paused'];}
            elseif($action==='save_promotion'){$code=strtoupper(mb_substr(trim((string)($payload['code']??'')),0,50));$value=(float)($payload['discount_value']??0);$starts=(string)($payload['starts_at']??'');$ends=(string)($payload['ends_at']??'');if(!preg_match('/^[A-Z0-9_-]{3,50}$/',$code)||$value<=0||strtotime($ends)<=strtotime($starts))throw new RuntimeException('Code, value and valid schedule are required.');$audience='all_customers';$type='percentage';$budget=max(0,(float)($payload['budget']??0));$stmt=$conn->prepare("INSERT INTO promotions(code,audience,discount_type,discount_value,budget,starts_at,ends_at,status,scope) VALUES(?,?,?,?,?,?,?,'scheduled','all_restaurants')");$stmt->bind_param('sssddss',$code,$audience,$type,$value,$budget,$starts,$ends);$stmt->execute();$entityId=$stmt->insert_id;$stmt->close();$after=['code'=>$code,'status'=>'scheduled'];}
            elseif($action==='schedule_fee_rule'){$name=mb_substr(trim((string)($payload['name']??'')),0,120);$amount=(float)($payload['amount']??0);$effective=(string)($payload['effective_at']??'');if($name===''||$amount<0||strtotime($effective)<=time())throw new RuntimeException('A future-effective fee rule is required.');$type='platform_commission';$unit='percent';$stmt=$conn->prepare("INSERT INTO fee_rules(rule_type,name,amount,unit,effective_at,status,created_by) VALUES(?,?,?, ?,?,'scheduled',?)");$stmt->bind_param('ssdssi',$type,$name,$amount,$unit,$effective,$actorId);$stmt->execute();$entityId=$stmt->insert_id;$stmt->close();$after=['name'=>$name,'effective_at'=>$effective];}
            else{$id=max(0,(int)($payload['service_area_id']??0));$status=in_array(($payload['status']??''),['active','paused'],true)?$payload['status']:'paused';$stmt=$conn->prepare('UPDATE service_areas SET status=?,version=version+1 WHERE id=?');$stmt->bind_param('si',$status,$id);$stmt->execute();if($stmt->affected_rows!==1)throw new RuntimeException('Service area not found.');$stmt->close();$entityId=$id;$after=['status'=>$status];}
        }else throw new RuntimeException('Unsupported operations action.');
        admin_append_audit($conn,$actorId,$action,$entityType,$entityId,$before,$after,$reason,$referenceId);$response=['ok'=>true,'message'=>'Controlled operation completed.','data'=>$after,'referenceId'=>$referenceId];admin_store_idempotency($conn,$actorId,$idempotencyKey,$action,$response);$conn->commit();return $response;
    }catch(Throwable $exception){$conn->rollback();return ['ok'=>false,'message'=>'The controlled operation could not be completed.','errors'=>['reason'=>$exception->getMessage()],'referenceId'=>$referenceId];}
}

function admin_idempotency_scalar(mysqli $conn,string $sql,int $id):float{$stmt=$conn->prepare($sql);$stmt->bind_param('i',$id);$stmt->execute();$row=$stmt->get_result()->fetch_assoc();$stmt->close();return (float)($row['value']??0);}

function admin_expected_version(array $payload): int
{
    $version = (int) ($payload['version'] ?? 0);
    if ($version < 1) throw new RuntimeException('A record version is required. Refresh before retrying.');
    return $version;
}

function admin_operations_action_v2(mysqli $conn, string $action, array $payload, int $actorId, string $idempotencyKey): array
{
    $reason = mb_substr(trim((string) ($payload['reason'] ?? '')), 0, 500);
    $referenceId = admin_reference_id();
    if ($reason === '') return ['ok' => false, 'message' => 'An audit reason is required.', 'errors' => ['reason' => 'Explain the controlled intervention.'], 'referenceId' => $referenceId];
    $conn->begin_transaction();
    try {
        $entityType = 'operation'; $entityId = null; $before = null; $after = [];
        if (in_array($action, ['reassign_driver', 'cancel_order', 'open_incident'], true)) {
            $orderId = max(0, (int) ($payload['order_id'] ?? 0));
            $expectedVersion = admin_expected_version($payload);
            $lock = $conn->prepare('SELECT * FROM orders WHERE id=? FOR UPDATE');
            $lock->bind_param('i', $orderId); $lock->execute(); $before = $lock->get_result()->fetch_assoc(); $lock->close();
            if (!$before) throw new RuntimeException('Order not found.');
            if ((int) $before['version'] !== $expectedVersion) throw new RuntimeException('Order has a stale version. Refresh before retrying.');
            $entityType = 'order'; $entityId = $orderId;
            if ($action === 'cancel_order') {
                if (in_array($before['status'], ['delivered', 'cancelled', 'refunded'], true)) throw new RuntimeException('Final orders cannot be cancelled.');
                $stmt = $conn->prepare("UPDATE orders SET status='cancelled',version=version+1 WHERE id=? AND version=?");
                $stmt->bind_param('ii', $orderId, $expectedVersion); $stmt->execute();
                if ($stmt->affected_rows !== 1) throw new RuntimeException('Order changed. Refresh before retrying.');
                $stmt->close();
                $history = $conn->prepare("INSERT INTO order_status_history(order_id,status,actor_role,actor_user_id,reason) VALUES(?,'cancelled','admin',?,?)");
                $history->bind_param('iis', $orderId, $actorId, $reason); $history->execute(); $history->close();
                $after = ['status' => 'cancelled', 'version' => $expectedVersion + 1];
            } elseif ($action === 'reassign_driver') {
                if (!in_array($before['status'], ['ready_for_pickup', 'assigned'], true)) throw new RuntimeException('Driver assignment is only allowed after the order is ready.');
                $driverId = max(0, (int) ($payload['driver_user_id'] ?? 0));
                $eligible = $conn->prepare("SELECT u.id FROM users u JOIN driver_profiles d ON d.user_id=u.id WHERE u.id=? AND u.status='active' AND d.eligibility_status='eligible' FOR UPDATE");
                $eligible->bind_param('i', $driverId); $eligible->execute(); $ok = $eligible->get_result()->fetch_assoc(); $eligible->close();
                if (!$ok) throw new RuntimeException('Selected Driver is not eligible.');
                $dispatch = $conn->prepare("INSERT INTO delivery_dispatches(order_id,status,assigned_driver_user_id,attempt_count) VALUES(?,'assigned',?,1) ON DUPLICATE KEY UPDATE status='assigned',assigned_driver_user_id=VALUES(assigned_driver_user_id),attempt_count=attempt_count+1,version=version+1");
                $dispatch->bind_param('ii', $orderId, $driverId); $dispatch->execute(); $dispatch->close();
                $delivery = $conn->prepare("INSERT INTO deliveries(order_id,driver_user_id,status,accepted_at) VALUES(?,?,'assigned',NOW()) ON DUPLICATE KEY UPDATE driver_user_id=VALUES(driver_user_id),status='assigned',accepted_at=NOW(),delivered_at=NULL,version=version+1");
                $delivery->bind_param('ii', $orderId, $driverId); $delivery->execute(); $delivery->close();
                $order = $conn->prepare("UPDATE orders SET status='assigned',version=version+1 WHERE id=? AND version=?");
                $order->bind_param('ii', $orderId, $expectedVersion); $order->execute();
                if ($order->affected_rows !== 1) throw new RuntimeException('Order changed. Refresh before retrying.');
                $order->close();
                $history = $conn->prepare("INSERT INTO order_status_history(order_id,status,actor_role,actor_user_id,reason) VALUES(?,'assigned','admin',?,?)");
                $history->bind_param('iis', $orderId, $actorId, $reason); $history->execute(); $history->close();
                $after = ['driver_user_id' => $driverId, 'dispatch_status' => 'assigned', 'version' => $expectedVersion + 1];
            } else {
                $caseReference = 'CASE-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
                $subject = 'Admin incident for ' . $before['reference_code'];
                $case = $conn->prepare("INSERT INTO support_cases(reference_code,order_id,case_type,reporting_role,reporting_user_id,priority,status,subject,sla_due_at) VALUES(?,?,'operational_incident','admin',?,'high','open',?,DATE_ADD(NOW(),INTERVAL 30 MINUTE))");
                $case->bind_param('siis', $caseReference, $orderId, $actorId, $subject); $case->execute(); $entityId = $case->insert_id; $case->close();
                $entityType = 'support_case'; $after = ['reference_code' => $caseReference, 'status' => 'open', 'version' => 1];
            }
        } elseif (in_array($action, ['request_case_information', 'resolve_case', 'issue_refund'], true)) {
            $caseId = max(0, (int) ($payload['case_id'] ?? 0));
            $expectedVersion = admin_expected_version($payload);
            $lock = $conn->prepare('SELECT * FROM support_cases WHERE id=? FOR UPDATE');
            $lock->bind_param('i', $caseId); $lock->execute(); $before = $lock->get_result()->fetch_assoc(); $lock->close();
            if (!$before) throw new RuntimeException('Case not found.');
            if ((int) $before['version'] !== $expectedVersion) throw new RuntimeException('Case has a stale version. Refresh before retrying.');
            if (in_array($before['status'], ['resolved', 'closed'], true) && $action !== 'request_case_information') throw new RuntimeException('This case is already final.');
            $entityType = 'support_case'; $entityId = $caseId;
            if ($action === 'request_case_information') {
                $message = $conn->prepare("INSERT INTO case_messages(case_id,author_role,author_user_id,message,internal_only) VALUES(?,'admin',?,?,0)");
                $message->bind_param('iis', $caseId, $actorId, $reason); $message->execute(); $message->close();
                $update = $conn->prepare('UPDATE support_cases SET version=version+1 WHERE id=? AND version=?');
                $update->bind_param('ii', $caseId, $expectedVersion); $update->execute();
                if ($update->affected_rows !== 1) throw new RuntimeException('Case changed. Refresh before retrying.');
                $update->close();
                $after = ['status' => $before['status'], 'message_added' => true, 'version' => $expectedVersion + 1];
            } elseif ($action === 'resolve_case') {
                $stmt = $conn->prepare("UPDATE support_cases SET status='resolved',resolution=?,version=version+1 WHERE id=? AND version=?");
                $stmt->bind_param('sii', $reason, $caseId, $expectedVersion); $stmt->execute();
                if ($stmt->affected_rows !== 1) throw new RuntimeException('Case changed. Refresh before retrying.');
                $stmt->close(); $after = ['status' => 'resolved', 'version' => $expectedVersion + 1];
            } else {
                $orderId = (int) ($before['order_id'] ?? 0); $amount = round((float) ($payload['amount'] ?? 0), 2);
                $payment = $conn->prepare("SELECT amount,status FROM payments WHERE order_id=? AND status='paid' FOR UPDATE");
                $payment->bind_param('i', $orderId); $payment->execute(); $paid = $payment->get_result()->fetch_assoc(); $payment->close();
                $existing = (float) admin_idempotency_scalar($conn, 'SELECT COALESCE(SUM(amount),0) value FROM refunds WHERE order_id=?', $orderId);
                if (!$paid || $amount <= 0 || $amount > ((float) $paid['amount'] - $existing)) throw new RuntimeException('Refund exceeds the remaining paid amount.');
                $destination = in_array(($payload['destination'] ?? ''), ['original_payment', 'wallet'], true) ? (string) $payload['destination'] : 'original_payment';
                $refund = $conn->prepare("INSERT INTO refunds(order_id,case_id,amount,destination,reason,status,actor_user_id) VALUES(?,?,?,?,?,'processed',?)");
                $refund->bind_param('iidssi', $orderId, $caseId, $amount, $destination, $reason, $actorId); $refund->execute(); $refundId = $refund->insert_id; $refund->close();
                $ledgerRef = 'REF-' . $refundId; $net = -$amount;
                $ledger = $conn->prepare("INSERT INTO ledger_entries(reference_code,order_id,entry_type,party_type,gross_amount,net_amount,status) VALUES(?,?,'refund','customer',?,?,'completed')");
                $ledger->bind_param('sidd', $ledgerRef, $orderId, $net, $net); $ledger->execute(); $ledger->close();
                if (abs(($existing + $amount) - (float) $paid['amount']) < 0.01) {
                    $orderUpdate = $conn->prepare("UPDATE orders SET status='refunded',version=version+1 WHERE id=?");
                    $orderUpdate->bind_param('i', $orderId); $orderUpdate->execute(); $orderUpdate->close();
                }
                $caseUpdate = $conn->prepare('UPDATE support_cases SET version=version+1 WHERE id=? AND version=?');
                $caseUpdate->bind_param('ii', $caseId, $expectedVersion); $caseUpdate->execute();
                if ($caseUpdate->affected_rows !== 1) throw new RuntimeException('Case changed. Refresh before retrying.');
                $caseUpdate->close(); $after = ['refund_id' => $refundId, 'amount' => $amount, 'version' => $expectedVersion + 1];
            }
        } elseif (in_array($action, ['hold_payout', 'release_payout'], true)) {
            $id = max(0, (int) ($payload['payout_id'] ?? 0)); $expectedVersion = admin_expected_version($payload);
            $lock = $conn->prepare('SELECT * FROM payouts WHERE id=? FOR UPDATE'); $lock->bind_param('i', $id); $lock->execute(); $before = $lock->get_result()->fetch_assoc(); $lock->close();
            if (!$before) throw new RuntimeException('Payout not found.');
            if ((int) $before['version'] !== $expectedVersion) throw new RuntimeException('Payout has a stale version.');
            $status = $action === 'hold_payout' ? 'held' : 'scheduled';
            $stmt = $conn->prepare('UPDATE payouts SET status=?,hold_reason=?,version=version+1 WHERE id=? AND version=?');
            $stmt->bind_param('ssii', $status, $reason, $id, $expectedVersion); $stmt->execute();
            if ($stmt->affected_rows !== 1) throw new RuntimeException('Payout changed. Refresh before retrying.');
            $stmt->close(); $entityType = 'payout'; $entityId = $id; $after = ['status' => $status, 'version' => $expectedVersion + 1];
        } elseif ($action === 'settle_cod') {
            $id = max(0, (int) ($payload['reconciliation_id'] ?? 0)); $amount = round((float) ($payload['amount'] ?? 0), 2); $expectedVersion = admin_expected_version($payload);
            $lock = $conn->prepare('SELECT * FROM cod_reconciliations WHERE id=? FOR UPDATE'); $lock->bind_param('i', $id); $lock->execute(); $before = $lock->get_result()->fetch_assoc(); $lock->close();
            if (!$before) throw new RuntimeException('Valid COD reconciliation required.');
            if ((int) $before['version'] !== $expectedVersion) throw new RuntimeException('COD reconciliation has a stale version.');
            if ($amount <= 0) $amount = round((float) $before['due_amount'] - (float) $before['settled_amount'], 2);
            if ($amount <= 0) throw new RuntimeException('This COD reconciliation is already settled.');
            if ($amount > (float) $before['due_amount'] - (float) $before['settled_amount']) throw new RuntimeException('Settlement exceeds the outstanding COD amount.');
            $stmt = $conn->prepare("UPDATE cod_reconciliations SET settled_amount=settled_amount+?,status=IF(settled_amount+?>=due_amount,'settled','open'),reconciled_at=NOW(),version=version+1 WHERE id=? AND version=?");
            $stmt->bind_param('ddii', $amount, $amount, $id, $expectedVersion); $stmt->execute();
            if ($stmt->affected_rows !== 1) throw new RuntimeException('COD reconciliation changed. Refresh before retrying.');
            $stmt->close(); $entityType = 'cod_reconciliation'; $entityId = $id; $after = ['settled_amount' => (float) $before['settled_amount'] + $amount, 'version' => $expectedVersion + 1];
        } elseif (in_array($action, ['save_promotion', 'pause_promotion', 'schedule_fee_rule', 'set_service_area_status'], true)) {
            $entityType = 'commercial_rule';
            if ($action === 'pause_promotion') {
                $id = max(0, (int) ($payload['promotion_id'] ?? 0)); $expectedVersion = admin_expected_version($payload);
                $lock = $conn->prepare('SELECT * FROM promotions WHERE id=? FOR UPDATE'); $lock->bind_param('i', $id); $lock->execute(); $before = $lock->get_result()->fetch_assoc(); $lock->close();
                if (!$before || (int) $before['version'] !== $expectedVersion) throw new RuntimeException('Promotion is missing or stale.');
                $stmt = $conn->prepare("UPDATE promotions SET status='paused',version=version+1 WHERE id=? AND version=?"); $stmt->bind_param('ii', $id, $expectedVersion); $stmt->execute();
                if ($stmt->affected_rows !== 1) throw new RuntimeException('Promotion changed. Refresh before retrying.');
                $stmt->close(); $entityId = $id; $after = ['status' => 'paused', 'version' => $expectedVersion + 1];
            } elseif ($action === 'save_promotion') {
                $code = strtoupper(mb_substr(trim((string) ($payload['code'] ?? '')), 0, 50)); $value = (float) ($payload['discount_value'] ?? 0); $starts = (string) ($payload['starts_at'] ?? ''); $ends = (string) ($payload['ends_at'] ?? '');
                if (!preg_match('/^[A-Z0-9_-]{3,50}$/', $code) || $value <= 0 || strtotime($ends) <= strtotime($starts)) throw new RuntimeException('Code, value and valid schedule are required.');
                $audience = 'all_customers'; $type = 'percentage'; $budget = max(0, (float) ($payload['budget'] ?? 0));
                $stmt = $conn->prepare("INSERT INTO promotions(code,audience,discount_type,discount_value,budget,starts_at,ends_at,status,scope) VALUES(?,?,?,?,?,?,?,'scheduled','all_restaurants')");
                $stmt->bind_param('sssddss', $code, $audience, $type, $value, $budget, $starts, $ends); $stmt->execute(); $entityId = $stmt->insert_id; $stmt->close(); $after = ['code' => $code, 'status' => 'scheduled', 'version' => 1];
            } elseif ($action === 'schedule_fee_rule') {
                $name = mb_substr(trim((string) ($payload['name'] ?? '')), 0, 120); $amount = (float) ($payload['amount'] ?? 0); $effective = (string) ($payload['effective_at'] ?? '');
                if ($name === '' || $amount < 0 || strtotime($effective) <= time()) throw new RuntimeException('A future-effective fee rule is required.');
                $type = 'platform_commission'; $unit = 'percent';
                $stmt = $conn->prepare("INSERT INTO fee_rules(rule_type,name,amount,unit,effective_at,status,created_by) VALUES(?,?,?,?,?,'scheduled',?)");
                $stmt->bind_param('ssdssi', $type, $name, $amount, $unit, $effective, $actorId); $stmt->execute(); $entityId = $stmt->insert_id; $stmt->close(); $after = ['name' => $name, 'effective_at' => $effective];
            } else {
                $id = max(0, (int) ($payload['service_area_id'] ?? 0)); $expectedVersion = admin_expected_version($payload); $status = in_array(($payload['status'] ?? ''), ['active', 'paused'], true) ? (string) $payload['status'] : 'paused';
                $lock = $conn->prepare('SELECT * FROM service_areas WHERE id=? FOR UPDATE'); $lock->bind_param('i', $id); $lock->execute(); $before = $lock->get_result()->fetch_assoc(); $lock->close();
                if (!$before || (int) $before['version'] !== $expectedVersion) throw new RuntimeException('Service area is missing or stale.');
                $stmt = $conn->prepare('UPDATE service_areas SET status=?,version=version+1 WHERE id=? AND version=?'); $stmt->bind_param('sii', $status, $id, $expectedVersion); $stmt->execute();
                if ($stmt->affected_rows !== 1) throw new RuntimeException('Service area changed. Refresh before retrying.');
                $stmt->close(); $entityId = $id; $after = ['status' => $status, 'version' => $expectedVersion + 1];
            }
        } else throw new RuntimeException('Unsupported operations action.');

        admin_append_audit($conn, $actorId, $action, $entityType, $entityId, $before, $after, $reason, $referenceId);
        $response = ['ok' => true, 'message' => 'Controlled operation completed.', 'data' => $after, 'referenceId' => $referenceId];
        admin_store_idempotency($conn, $actorId, $idempotencyKey, $action, $response);
        $conn->commit(); return $response;
    } catch (Throwable $exception) {
        $conn->rollback();
        return ['ok' => false, 'message' => 'The controlled operation could not be completed.', 'errors' => ['reason' => $exception->getMessage()], 'referenceId' => $referenceId];
    }
}

function admin_execute_action(mysqli $conn, string $action, array $payload, int $actorId, string $idempotencyKey): array
{
    $existing = admin_idempotency_response($conn, $actorId, $idempotencyKey);
    if ($existing !== null) {
        return $existing;
    }
    if ($action === 'update_setting') {
        return admin_update_setting($conn, $payload, $actorId, $idempotencyKey);
    }
    if ($action === 'update_notification_template') {
        return admin_update_notification_template($conn, $payload, $actorId, $idempotencyKey);
    }
    if (in_array($action, ['suspend_account', 'reactivate_account', 'block_account', 'revoke_sessions', 'reset_password'], true)) {
        return admin_account_action($conn, $action, $payload, $actorId, $idempotencyKey);
    }
    if (in_array($action, ['approve_restaurant', 'request_restaurant_changes', 'reject_restaurant', 'approve_driver', 'request_driver_changes', 'reject_driver'], true)) {
        return admin_partner_application_action($conn, $action, $payload, $actorId, $idempotencyKey);
    }
    if(in_array($action,['reassign_driver','cancel_order','open_incident','request_case_information','resolve_case','issue_refund','hold_payout','release_payout','settle_cod','save_promotion','pause_promotion','schedule_fee_rule','set_service_area_status'],true))return admin_operations_action_v2($conn,$action,$payload,$actorId,$idempotencyKey);
    return [
        'ok' => false,
        'message' => 'Unsupported Admin action.',
        'errors' => ['action' => "The action {$action} is not available."],
        'referenceId' => admin_reference_id(),
    ];
}
