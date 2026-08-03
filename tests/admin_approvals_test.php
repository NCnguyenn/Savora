<?php
declare(strict_types=1);

if (getenv('SAVORA_ENV') !== 'test' || getenv('SAVORA_DB_NAME') !== 'savora_test') {
    fwrite(STDERR, "BLOCKED: Admin approval integration tests require savora_test\n");
    exit(2);
}
putenv('SAVORA_SEED_DEMO=1');
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/admin_actions.php';
require_once __DIR__ . '/../lib/services/partner_application_service.php';

function admin_approval_expect(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException($message);
}

$prefix = 'auth-approval-' . bin2hex(random_bytes(5));
$uploadRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $prefix;
$applicationIds = ['restaurant' => [], 'driver' => []];
$userIds = [];
$mediaIds = [];
$idempotencyKeys = [];

try {
    if (!mkdir($uploadRoot, 0700, true) && !is_dir($uploadRoot)) throw new RuntimeException('Could not create test upload root.');
    putenv('SAVORA_UPLOAD_ROOT=' . $uploadRoot);
    $actorRow = $conn->query("SELECT id FROM users WHERE role='admin' AND status='active' ORDER BY id LIMIT 1")->fetch_assoc();
    $actor = (int) ($actorRow['id'] ?? 0);
    admin_approval_expect($actor > 0, 'An active Admin actor is required.');

    $logoSource = $uploadRoot . DIRECTORY_SEPARATOR . 'source.png';
    file_put_contents($logoSource, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true));
    $restaurantUsername = $prefix . '-restaurant';
    $restaurant = partner_submit_application($conn, 'restaurant', [
        'ownerName' => 'Approval Owner', 'username' => $restaurantUsername, 'email' => $prefix . '-restaurant@example.test',
        'phone' => '+1 555 010 8100', 'password' => 'Strong-Restaurant-123!', 'passwordConfirmation' => 'Strong-Restaurant-123!',
        'restaurantName' => 'Approval Kitchen', 'description' => 'Approval description', 'cuisine' => 'Vietnamese',
        'address' => '81 Approval Street', 'city' => 'Central City', 'restaurantPhone' => '+1 555 010 8101',
        'opensAt' => '09:00', 'closesAt' => '22:00', 'acceptedTerms' => true,
    ], ['logo' => ['name' => 'logo.png', 'tmp_name' => $logoSource, 'error' => UPLOAD_ERR_OK, 'size' => filesize($logoSource)]]);
    admin_approval_expect(($restaurant['ok'] ?? false) === true, 'Restaurant application must be created without documents.');
    $restaurantAppId = (int) $restaurant['data']['applicationId'];
    $restaurantLogoId = (int) $restaurant['data']['logo']['id'];
    $mediaIds[] = $restaurantLogoId;
    $applicationIds['restaurant'][] = $restaurantAppId;
    $restaurantKey = 'approve-restaurant-' . bin2hex(random_bytes(6)); $idempotencyKeys[] = $restaurantKey;
    $restaurantPayload = ['application_id' => $restaurantAppId, 'version' => 1, 'reviewer_note' => 'Profile information reviewed.'];
    $approved = admin_execute_action($conn, 'approve_restaurant', $restaurantPayload, $actor, $restaurantKey);
    $retried = admin_execute_action($conn, 'approve_restaurant', $restaurantPayload, $actor, $restaurantKey);
    admin_approval_expect(($approved['ok'] ?? false) === true && $approved === $retried, 'Restaurant approval must succeed without documents and replay exactly.');
    $restaurantUserId = (int) ($approved['data']['user_id'] ?? 0); $userIds[] = $restaurantUserId;
    $restaurantProfile = partner_application_one($conn, 'SELECT id,name,description,phone,logo_media_id FROM restaurants WHERE owner_user_id=?', 'i', [$restaurantUserId]);
    admin_approval_expect(($restaurantProfile['description'] ?? '') === 'Approval description' && ($restaurantProfile['phone'] ?? '') === '+1 555 010 8101', 'Restaurant description and phone must transfer.');
    admin_approval_expect((int) ($restaurantProfile['logo_media_id'] ?? 0) === $restaurantLogoId, 'Restaurant logo must transfer to the active profile.');
    $restaurantId = (int) $restaurantProfile['id'];
    $hours = partner_application_one($conn, 'SELECT COUNT(*) AS total FROM restaurant_weekly_hours WHERE restaurant_id=?', 'i', [$restaurantId]);
    admin_approval_expect((int) $hours['total'] === 7, 'Restaurant approval must create seven weekly-hour rows.');
    $media = partner_application_one($conn, 'SELECT owner_kind,owner_id,visibility,status FROM media_assets WHERE id=?', 'i', [$restaurantLogoId]);
    admin_approval_expect(($media['owner_kind'] ?? '') === 'restaurant' && (int) $media['owner_id'] === $restaurantId && ($media['visibility'] ?? '') === 'public', 'Approved Restaurant logo must become public and profile-owned.');
    $claims = partner_application_one($conn, "SELECT COUNT(*) AS total FROM identity_claims WHERE owner_kind='user' AND owner_id=?", 'i', [$restaurantUserId]);
    admin_approval_expect((int) $claims['total'] === 2, 'Restaurant claims must transfer to the approved user.');
    $application = partner_application_one($conn, 'SELECT status,password_hash FROM restaurant_applications WHERE id=?', 'i', [$restaurantAppId]);
    admin_approval_expect(($application['status'] ?? '') === 'approved' && $application['password_hash'] === null, 'Restaurant credentials must be consumed.');
    $notification = partner_application_one($conn, 'SELECT COUNT(*) AS total FROM notifications WHERE user_id=?', 'i', [$restaurantUserId]);
    admin_approval_expect((int) $notification['total'] >= 1, 'Approval notification must be queued.');

    $driverUsername = $prefix . '-driver';
    $driver = partner_submit_application($conn, 'driver', [
        'fullName' => 'Approval Driver', 'username' => $driverUsername, 'email' => $prefix . '-driver@example.test',
        'phone' => '+1 555 010 8200', 'password' => 'Strong-Driver-123!', 'passwordConfirmation' => 'Strong-Driver-123!',
        'city' => 'Central City', 'serviceArea' => 'Central District', 'vehicleType' => 'Motorcycle',
        'vehicleModel' => 'Honda Wave', 'licensePlate' => 'APP-8200', 'vehicleColor' => 'Red', 'acceptedTerms' => true,
    ]);
    admin_approval_expect(($driver['ok'] ?? false) === true, 'Driver application must be created without documents.');
    $driverAppId = (int) $driver['data']['applicationId']; $applicationIds['driver'][] = $driverAppId;
    $driverKey = 'approve-driver-' . bin2hex(random_bytes(6)); $idempotencyKeys[] = $driverKey;
    $driverApproval = admin_execute_action($conn, 'approve_driver', ['application_id' => $driverAppId, 'version' => 1, 'reviewer_note' => 'Driver profile reviewed.'], $actor, $driverKey);
    admin_approval_expect(($driverApproval['ok'] ?? false) === true, 'Driver approval must succeed without documents.');
    $driverUserId = (int) ($driverApproval['data']['user_id'] ?? 0); $userIds[] = $driverUserId;
    $driverProfile = partner_application_one($conn, 'SELECT vehicle_model,license_plate,vehicle_color,service_area FROM driver_profiles WHERE user_id=?', 'i', [$driverUserId]);
    admin_approval_expect(($driverProfile['vehicle_model'] ?? '') === 'Honda Wave' && ($driverProfile['license_plate'] ?? '') === 'APP-8200' && ($driverProfile['vehicle_color'] ?? '') === 'Red' && ($driverProfile['service_area'] ?? '') === 'Central District', 'All Driver vehicle fields must transfer.');
    $claims = partner_application_one($conn, "SELECT COUNT(*) AS total FROM identity_claims WHERE owner_kind='user' AND owner_id=?", 'i', [$driverUserId]);
    admin_approval_expect((int) $claims['total'] === 2, 'Driver claims must transfer to the approved user.');

    $rejectedUsername = $prefix . '-rejected';
    $rejected = partner_submit_application($conn, 'driver', [
        'fullName' => 'Rejected Driver', 'username' => $rejectedUsername, 'email' => $prefix . '-rejected@example.test',
        'phone' => '+1 555 010 8300', 'password' => 'Strong-Driver-123!', 'passwordConfirmation' => 'Strong-Driver-123!',
        'city' => 'Central City', 'serviceArea' => 'West District', 'vehicleType' => 'Bicycle', 'vehicleModel' => 'City Bike',
        'licensePlate' => 'APP-8300', 'acceptedTerms' => true,
    ]);
    $rejectedAppId = (int) $rejected['data']['applicationId']; $applicationIds['driver'][] = $rejectedAppId;
    $rejectKey = 'reject-driver-' . bin2hex(random_bytes(6)); $idempotencyKeys[] = $rejectKey;
    $rejection = admin_execute_action($conn, 'reject_driver', ['application_id' => $rejectedAppId, 'version' => 1, 'reviewer_note' => 'Application information is not suitable.'], $actor, $rejectKey);
    admin_approval_expect(($rejection['ok'] ?? false) === true, 'Rejection must succeed.');
    $application = partner_application_one($conn, 'SELECT status,password_hash FROM driver_applications WHERE id=?', 'i', [$rejectedAppId]);
    $claimCount = partner_application_one($conn, "SELECT COUNT(*) AS total FROM identity_claims WHERE owner_kind='driver_application' AND owner_id=?", 'i', [$rejectedAppId]);
    $userCount = partner_application_one($conn, 'SELECT COUNT(*) AS total FROM users WHERE username=?', 's', [$rejectedUsername]);
    admin_approval_expect(($application['status'] ?? '') === 'rejected' && $application['password_hash'] === null, 'Rejection must finalize credentials.');
    admin_approval_expect((int) $claimCount['total'] === 0 && (int) $userCount['total'] === 0, 'Rejection must release claims and create no user.');

    echo "PASS: document-free partner approval transfers profiles, claims, hours, logo, notification, and rejects safely\n";
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
} finally {
    if ($conn instanceof mysqli) {
        if ($idempotencyKeys !== []) { $placeholders = implode(',', array_fill(0, count($idempotencyKeys), '?')); $types = str_repeat('s', count($idempotencyKeys)); $statement = $conn->prepare("DELETE FROM idempotency_keys WHERE idempotency_key IN ({$placeholders})"); $statement->bind_param($types, ...$idempotencyKeys); $statement->execute(); $statement->close(); }
        if ($userIds !== []) {
            $list = implode(',', array_map('intval', $userIds));
            $conn->query("DELETE FROM notification_outbox WHERE notification_id IN (SELECT id FROM notifications WHERE user_id IN ({$list}))");
            $conn->query("DELETE FROM notifications WHERE user_id IN ({$list})");
            $conn->query("DELETE FROM restaurant_weekly_hours WHERE restaurant_id IN (SELECT id FROM restaurants WHERE owner_user_id IN ({$list}))");
            $conn->query("DELETE FROM restaurants WHERE owner_user_id IN ({$list})");
            if ($mediaIds !== []) $conn->query('DELETE FROM media_assets WHERE id IN (' . implode(',', array_map('intval', $mediaIds)) . ')');
            $conn->query("DELETE FROM driver_profiles WHERE user_id IN ({$list})");
            $conn->query("DELETE FROM identity_claims WHERE owner_kind='user' AND owner_id IN ({$list})");
            $conn->query("DELETE FROM users WHERE id IN ({$list})");
        }
        foreach ($applicationIds as $type => $ids) if ($ids !== []) {
            $list = implode(',', array_map('intval', $ids));
            $conn->query("DELETE FROM identity_claims WHERE owner_kind='{$type}_application' AND owner_id IN ({$list})");
            if ($type === 'restaurant') $conn->query("DELETE FROM media_assets WHERE owner_kind='restaurant_application' AND owner_id IN ({$list})");
            $conn->query('DELETE FROM ' . $type . "_applications WHERE id IN ({$list})");
        }
        $conn->close();
    }
    if (getenv('SAVORA_UPLOAD_ROOT') === $uploadRoot) putenv('SAVORA_UPLOAD_ROOT');
    if (is_dir($uploadRoot)) { $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($uploadRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST); foreach ($iterator as $item) $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname()); rmdir($uploadRoot); }
}
