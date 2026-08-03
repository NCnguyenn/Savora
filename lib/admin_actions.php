<?php
declare(strict_types=1);

require_once __DIR__ . '/idempotency.php';
require_once __DIR__ . '/admin_security.php';
require_once __DIR__ . '/services/admin_settings_service.php';
require_once __DIR__ . '/services/admin_account_service.php';
require_once __DIR__ . '/services/admin_partner_service.php';
require_once __DIR__ . '/services/admin_provisioning_service.php';
require_once __DIR__ . '/services/admin_operations_service.php';
require_once __DIR__ . '/services/admin_order_service.php';
require_once __DIR__ . '/services/finance_service.php';
require_once __DIR__ . '/services/support_service.php';

/**
 * Stable Admin command map. SQL, transactions, authorization policy, and
 * domain side effects live in the focused services loaded above.
 */
function admin_execute_action(mysqli $conn, string $action, array $payload, int $actorId, string $idempotencyKey): array
{
    savora_idempotency_lock($conn, $actorId, $idempotencyKey);
    try {
        return match (true) {
            $action === 'update_setting' => admin_update_setting($conn, $payload, $actorId, $idempotencyKey),
            $action === 'update_notification_template' => admin_update_notification_template($conn, $payload, $actorId, $idempotencyKey),
            in_array($action, ['suspend_account', 'reactivate_account', 'block_account', 'revoke_sessions', 'reset_password'], true)
                => admin_account_action($conn, $action, $payload, $actorId, $idempotencyKey),
            in_array($action, ['approve_restaurant', 'reject_restaurant', 'approve_driver', 'reject_driver'], true)
                => admin_partner_application_action($conn, $action, $payload, $actorId, $idempotencyKey),
            $action === 'create_admin_account'
                => admin_provision_account($conn, $payload, $actorId, $idempotencyKey),
            $action === 'cancel_order'
                => admin_cancel_order($conn, $actorId, (int) ($payload['order_id'] ?? 0), (string) ($payload['reason'] ?? ''), (int) ($payload['version'] ?? 0), $idempotencyKey),
            $action === 'reassign_driver'
                => admin_reassign_driver($conn, $actorId, (int) ($payload['order_id'] ?? 0), (int) ($payload['driver_user_id'] ?? 0), (string) ($payload['reason'] ?? ''), (int) ($payload['version'] ?? 0), $idempotencyKey),
            $action === 'issue_refund'
                => finance_issue_refund($conn, $actorId, (int) ($payload['case_id'] ?? 0), (float) ($payload['amount'] ?? 0), (string) ($payload['destination'] ?? 'original_payment'), (string) ($payload['reason'] ?? ''), (int) ($payload['version'] ?? 0), $idempotencyKey),
            $action === 'request_case_information'
                => support_add_message($conn, $actorId, 'admin', (int) ($payload['case_id'] ?? 0), (string) ($payload['reason'] ?? ''), (int) ($payload['version'] ?? 0), $idempotencyKey),
            $action === 'resolve_case'
                => support_resolve_case($conn, $actorId, (int) ($payload['case_id'] ?? 0), (string) ($payload['reason'] ?? ''), (int) ($payload['version'] ?? 0), $idempotencyKey),
            $action === 'hold_payout'
                => finance_hold_payout($conn, $actorId, (int) ($payload['payout_id'] ?? 0), (string) ($payload['reason'] ?? ''), (int) ($payload['version'] ?? 0), $idempotencyKey),
            $action === 'release_payout'
                => finance_release_payout($conn, $actorId, (int) ($payload['payout_id'] ?? 0), (string) ($payload['reason'] ?? ''), (int) ($payload['version'] ?? 0), $idempotencyKey),
            $action === 'settle_cod'
                => finance_settle_cod($conn, $actorId, (int) ($payload['reconciliation_id'] ?? 0), (float) ($payload['amount'] ?? 0), (string) ($payload['reason'] ?? ''), (int) ($payload['version'] ?? 0), $idempotencyKey),
            $action === 'open_incident'
                => admin_open_incident($conn, $actorId, (int) ($payload['order_id'] ?? 0), (string) ($payload['reason'] ?? ''), (int) ($payload['version'] ?? 0), $idempotencyKey),
            in_array($action, ['save_promotion', 'pause_promotion', 'schedule_fee_rule', 'set_service_area_status'], true)
                => admin_commercial_action($conn, $action, $payload, $actorId, $idempotencyKey),
            default => ['ok' => false, 'message' => 'Unsupported Admin action.', 'errors' => ['action' => "The action {$action} is not available."], 'referenceId' => admin_reference_id()],
        };
    } finally {
        savora_idempotency_unlock($conn, $actorId, $idempotencyKey);
    }
}
