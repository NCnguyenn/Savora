<?php
declare(strict_types=1);
putenv('SAVORA_SEED_DEMO=1');
putenv('SAVORA_DB_NAME=' . (getenv('SAVORA_DB_NAME') ?: 'savora_test'));
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/admin_actions.php';
require_once __DIR__ . '/../lib/services/partner_application_service.php';

function security_assert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
$actorId = (int) $conn->query("SELECT id FROM users WHERE role='admin' AND status='active' LIMIT 1")->fetch_assoc()['id'];
$prefix = 'security-partner-' . bin2hex(random_bytes(5));
$applicationId = 0;
try {
    $application = partner_submit_application($conn, 'driver', [
        'fullName'=>'Security Driver','username'=>$prefix,'email'=>$prefix.'@example.test','phone'=>'+1 555 019 1000',
        'password'=>'Strong-Driver-123!','passwordConfirmation'=>'Strong-Driver-123!','city'=>'Central City',
        'serviceArea'=>'Central District','vehicleType'=>'Motorcycle','vehicleModel'=>'Security Bike','licensePlate'=>'SEC-1000','acceptedTerms'=>true,
    ]);
    security_assert(($application['ok'] ?? false) === true, 'Document-free Driver application must be accepted.');
    $applicationId = (int) $application['data']['applicationId'];
    $rejected = admin_execute_action($conn, 'reject_driver', ['application_id'=>$applicationId,'version'=>1,'reviewer_note'=>'Security regression rejection'], $actorId, 'security-reject-'.bin2hex(random_bytes(4)));
    security_assert(($rejected['ok'] ?? false) === true, 'Final rejection must succeed.');
    $final = $conn->query("SELECT status,password_hash FROM driver_applications WHERE id={$applicationId}")->fetch_assoc();
    security_assert($final['status'] === 'rejected' && $final['password_hash'] === null, 'Final rejection must consume credentials.');
    $claims = (int) $conn->query("SELECT COUNT(*) AS total FROM identity_claims WHERE owner_kind='driver_application' AND owner_id={$applicationId}")->fetch_assoc()['total'];
    security_assert($claims === 0, 'Final rejection must release reserved identities.');

    $target = $conn->query("SELECT id,version,password FROM users WHERE username='driver-nearby-2' LIMIT 1")->fetch_assoc();
    $reset = admin_execute_action($conn, 'reset_password', ['user_id'=>(int)$target['id'],'version'=>(int)$target['version'],'reason'=>'Security regression test'], $actorId, 'security-reset-'.bin2hex(random_bytes(4)));
    security_assert(($reset['ok'] ?? false) === true, 'Password recovery must succeed.');
    security_assert(empty($reset['data']['recovery_url']), 'Password recovery token must not be returned in the Admin response.');
    $delivery = $conn->query("SELECT message FROM notifications WHERE user_id=".(int)$target['id']." AND event_type='reset_password' ORDER BY id DESC LIMIT 1")->fetch_assoc();
    security_assert($delivery && str_contains((string)$delivery['message'], 'reset_password.php?token='), 'Password recovery must queue the one-time link in the server notification channel.');
    $afterPassword = $conn->query('SELECT password FROM users WHERE id='.(int)$target['id'])->fetch_assoc()['password'];
    security_assert(hash_equals((string)$target['password'], (string)$afterPassword), 'Issuing recovery must not destroy the existing credential.');
    echo "PASS: partner rejection releases identity and password reset issues a recovery link\n";
} finally {
    if ($applicationId > 0) { $conn->query("DELETE FROM identity_claims WHERE owner_kind='driver_application' AND owner_id={$applicationId}"); $conn->query("DELETE FROM driver_applications WHERE id={$applicationId}"); }
    $conn->close();
}
