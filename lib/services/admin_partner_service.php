<?php
declare(strict_types=1);

require_once __DIR__ . '/../idempotency.php';
require_once __DIR__ . '/audit_service.php';
require_once __DIR__ . '/notification_service.php';
require_once __DIR__ . '/../admin_security.php';
require_once __DIR__ . '/../repositories/registration_repository.php';
require_once __DIR__ . '/media_service.php';

function admin_partner_application_action(mysqli $conn, string $action, array $payload, int $actorId, string $idempotencyKey): array
{
    $allowed = ['approve_restaurant', 'reject_restaurant', 'approve_driver', 'reject_driver'];
    $referenceId = admin_reference_id();
    if (!in_array($action, $allowed, true)) return ['ok' => false, 'message' => 'Unsupported partner decision.', 'referenceId' => $referenceId];
    $isRestaurant = str_contains($action, 'restaurant');
    $applicationTable = $isRestaurant ? 'restaurant_applications' : 'driver_applications';
    $role = $isRestaurant ? 'restaurant' : 'driver';
    $ownerKind = $role . '_application';
    $applicationId = max(0, (int) ($payload['application_id'] ?? 0));
    $expectedVersion = max(1, (int) ($payload['version'] ?? 0));
    $note = mb_substr(trim((string) ($payload['reviewer_note'] ?? $payload['reason'] ?? '')), 0, 1000);
    $decision = str_starts_with($action, 'approve_') ? 'approved' : 'rejected';
    if ($applicationId === 0 || ($decision === 'rejected' && $note === '')) {
        return ['ok' => false, 'message' => 'Application and a rejection note are required.', 'errors' => ['reviewer_note' => 'Explain why this application is being rejected.'], 'referenceId' => $referenceId];
    }

    $hash = savora_idempotency_hash($action, $payload);
    $conn->begin_transaction();
    try {
        $stored = savora_idempotency_find($conn, $actorId, $idempotencyKey, $action, $hash);
        if ($stored !== null) { $conn->commit(); return $stored; }

        $lock = $conn->prepare("SELECT * FROM {$applicationTable} WHERE id=? FOR UPDATE");
        $lock->bind_param('i', $applicationId);
        $lock->execute();
        $before = $lock->get_result()->fetch_assoc();
        $lock->close();
        if (!$before || !in_array((string) $before['status'], ['pending', 'in_review'], true)) throw new RuntimeException('Application is no longer reviewable.');
        if ((int) $before['version'] !== $expectedVersion) throw new RuntimeException('Application has a stale version.');
        if (!$before['password_hash']) throw new RuntimeException('Application credentials were already consumed.');

        $newUserId = null;
        $profileId = null;
        if ($decision === 'approved') {
            $email = (string) ($isRestaurant ? $before['owner_email'] : $before['email']);
            $fullName = (string) ($isRestaurant ? $before['owner_name'] : $before['full_name']);
            $phone = (string) ($isRestaurant ? $before['owner_phone'] : $before['phone']);
            $insert = $conn->prepare("INSERT INTO users(username,password,role,full_name,email,phone,status) VALUES(?,?,?,?,?,?,'active')");
            $insert->bind_param('ssssss', $before['username'], $before['password_hash'], $role, $fullName, $email, $phone);
            $insert->execute();
            $newUserId = (int) $insert->insert_id;
            $insert->close();

            if ($isRestaurant) {
                $profile = $conn->prepare("INSERT INTO restaurants(owner_user_id,name,description,cuisine,address,city,phone,status,accepting_orders) VALUES(?,?,?,?,?,?,?,'active',0)");
                $restaurantPhone = (string) ($before['restaurant_phone'] ?: $before['owner_phone']);
                $profile->bind_param('issssss', $newUserId, $before['restaurant_name'], $before['description'], $before['cuisine'], $before['address'], $before['city'], $restaurantPhone);
                $profile->execute();
                $profileId = (int) $profile->insert_id;
                $profile->close();

                $hours = $conn->prepare('INSERT INTO restaurant_weekly_hours(restaurant_id,weekday,opens_at,closes_at,is_closed) VALUES(?,?,?,?,0)');
                for ($weekday = 0; $weekday < 7; $weekday++) {
                    $hours->bind_param('iiss', $profileId, $weekday, $before['opens_at'], $before['closes_at']);
                    $hours->execute();
                }
                $hours->close();

                $logoQuery = $conn->prepare("SELECT id FROM media_assets WHERE owner_kind='restaurant_application' AND owner_id=? AND purpose='restaurant_logo' AND status='active' ORDER BY id DESC LIMIT 1 FOR UPDATE");
                $logoQuery->bind_param('i', $applicationId);
                $logoQuery->execute();
                $logo = $logoQuery->get_result()->fetch_assoc();
                $logoQuery->close();
                if ($logo) {
                    $logoId = (int) $logo['id'];
                    media_transfer($conn, $logoId, 'restaurant', $profileId, 'public');
                    $setLogo = $conn->prepare('UPDATE restaurants SET logo_media_id=? WHERE id=?');
                    $setLogo->bind_param('ii', $logoId, $profileId);
                    $setLogo->execute();
                    $setLogo->close();
                }
            } else {
                $profile = $conn->prepare("INSERT INTO driver_profiles(user_id,city,vehicle_type,vehicle_model,license_plate,service_area,vehicle_color,eligibility_status,availability_status) VALUES(?,?,?,?,?,?,?,'eligible','offline')");
                $profile->bind_param('issssss', $newUserId, $before['city'], $before['vehicle_type'], $before['vehicle_model'], $before['license_plate'], $before['service_area'], $before['vehicle_color']);
                $profile->execute();
                $profileId = (int) $profile->insert_id;
                $profile->close();
            }
            registration_repository_transfer_claims($conn, $ownerKind, $applicationId, 'user', $newUserId);
        } else {
            registration_repository_release_claims($conn, $ownerKind, $applicationId);
            if ($isRestaurant) {
                $media = $conn->prepare("SELECT id FROM media_assets WHERE owner_kind='restaurant_application' AND owner_id=? AND purpose='restaurant_logo' AND status='active' FOR UPDATE");
                $media->bind_param('i', $applicationId);
                $media->execute();
                $assets = $media->get_result()->fetch_all(MYSQLI_ASSOC);
                $media->close();
                foreach ($assets as $asset) media_revoke($conn, (int) $asset['id']);
            }
        }

        $update = $conn->prepare("UPDATE {$applicationTable} SET status=?,reviewer_id=?,reviewer_note=?,decision_reason=?,reviewed_at=NOW(),password_hash=NULL,version=version+1 WHERE id=? AND version=?");
        $update->bind_param('sissii', $decision, $actorId, $note, $note, $applicationId, $expectedVersion);
        $update->execute();
        if ($update->affected_rows !== 1) throw new RuntimeException('Application changed during review.');
        $update->close();

        $after = ['status' => $decision, 'reviewer_id' => $actorId, 'user_id' => $newUserId, 'profile_id' => $profileId, 'version' => $expectedVersion + 1];
        if ($newUserId !== null) notification_queue($conn, $newUserId, $action, 'Application approved', 'Your Savora account is active. Sign in to continue.', $ownerKind, $applicationId);
        $auditBefore = $before; unset($auditBefore['password_hash']);
        audit_append($conn, $actorId, $action, $ownerKind, $applicationId, $auditBefore, $after, $note ?: 'Partner profile approved', $referenceId);
        $response = ['ok' => true, 'message' => ucfirst($role) . ' application ' . $decision . '.', 'data' => ['application_id' => $applicationId, 'status' => $decision, 'user_id' => $newUserId, 'profile_id' => $profileId, 'version' => $expectedVersion + 1], 'referenceId' => $referenceId];
        savora_idempotency_store($conn, $actorId, $idempotencyKey, $action, $hash, $response);
        $conn->commit();
        return $response;
    } catch (Throwable $exception) {
        $conn->rollback();
        return ['ok' => false, 'message' => 'The application decision could not be completed.', 'errors' => ['reviewer_note' => $exception->getMessage()], 'referenceId' => $referenceId];
    }
}
