<?php
declare(strict_types=1);

if (getenv('SAVORA_ENV') !== 'test' || getenv('SAVORA_DB_NAME') !== 'savora_test') {
    fwrite(STDERR, "BLOCKED: partner upload integration tests require savora_test\n");
    exit(2);
}

require_once __DIR__ . '/../lib/services/partner_application_service.php';
require_once __DIR__ . '/support/test_database.php';

function partner_upload_expect(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException($message);
}

$conn = null;
$prefix = 'auth-logo-' . bin2hex(random_bytes(5));
$uploadRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $prefix;
$restaurantId = 0;
$mediaId = 0;

try {
    if (!mkdir($uploadRoot, 0700, true) && !is_dir($uploadRoot)) throw new RuntimeException('Unable to create test upload root.');
    putenv('SAVORA_UPLOAD_ROOT=' . $uploadRoot);
    $logoPath = $uploadRoot . DIRECTORY_SEPARATOR . 'restaurant-logo-source.png';
    file_put_contents($logoPath, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true));

    $conn = savora_test_database();
    $username = $prefix . '-restaurant';
    $email = $prefix . '@example.test';
    $result = partner_submit_application($conn, 'restaurant', [
        'ownerName' => 'Logo Owner',
        'username' => $username,
        'email' => $email,
        'phone' => '+1 555 010 6600',
        'password' => 'Strong-Restaurant-123!',
        'passwordConfirmation' => 'Strong-Restaurant-123!',
        'restaurantName' => 'Logo Kitchen',
        'description' => 'A restaurant with an optional logo.',
        'cuisine' => 'Asian',
        'address' => '66 Logo Avenue',
        'city' => 'Central City',
        'restaurantPhone' => '+1 555 010 6601',
        'opensAt' => '08:30',
        'closesAt' => '21:30',
        'acceptedTerms' => true,
    ], ['logo' => ['name' => 'restaurant-logo.png', 'tmp_name' => $logoPath, 'error' => UPLOAD_ERR_OK, 'size' => filesize($logoPath)]]);

    partner_upload_expect(($result['ok'] ?? false) === true, 'Restaurant application with an optional logo must be accepted.');
    $restaurantId = (int) ($result['data']['applicationId'] ?? 0);
    $mediaId = (int) ($result['data']['logo']['id'] ?? 0);
    partner_upload_expect($restaurantId > 0 && $mediaId > 0, 'Application and logo identifiers must be returned.');
    partner_upload_expect(!isset($result['data']['logo']['storedPath']), 'Logo response must not expose a storage path.');

    $media = partner_application_one($conn, 'SELECT owner_kind,owner_id,purpose,visibility,status,stored_path,mime_type FROM media_assets WHERE id=?', 'i', [$mediaId]);
    partner_upload_expect(($media['owner_kind'] ?? '') === 'restaurant_application' && (int) ($media['owner_id'] ?? 0) === $restaurantId, 'Logo must belong to the pending Restaurant application.');
    partner_upload_expect(($media['purpose'] ?? '') === 'restaurant_logo' && ($media['visibility'] ?? '') === 'private' && ($media['status'] ?? '') === 'active', 'Pending logo must be private and active.');
    partner_upload_expect(($media['mime_type'] ?? '') === 'image/png', 'Logo MIME must be detected from content.');
    partner_upload_expect(is_file($uploadRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, (string) $media['stored_path'])), 'Logo bytes must be stored below the configured private root.');

    $fakePath = $uploadRoot . DIRECTORY_SEPARATOR . 'fake.png';
    file_put_contents($fakePath, 'not an image');
    $rejected = partner_submit_application($conn, 'restaurant', [
        'ownerName' => 'Fake Logo Owner', 'username' => $prefix . '-fake', 'email' => $prefix . '-fake@example.test',
        'phone' => '+1 555 010 7700', 'password' => 'Strong-Restaurant-123!', 'passwordConfirmation' => 'Strong-Restaurant-123!',
        'restaurantName' => 'Fake Logo Kitchen', 'cuisine' => 'Asian', 'address' => '77 Test Avenue', 'city' => 'Central City',
        'restaurantPhone' => '+1 555 010 7701', 'opensAt' => '08:00', 'closesAt' => '20:00', 'acceptedTerms' => true,
    ], ['logo' => ['name' => 'fake.png', 'tmp_name' => $fakePath, 'error' => UPLOAD_ERR_OK, 'size' => filesize($fakePath)]]);
    partner_upload_expect(($rejected['ok'] ?? true) === false && ($rejected['status'] ?? 0) === 422, 'Fake logo content must be rejected.');
    partner_upload_expect(partner_application_one($conn, 'SELECT id FROM restaurant_applications WHERE username=?', 's', [$prefix . '-fake']) === [], 'Rejected logo must roll back its application.');

    echo "PASS: Restaurant application stores an optional private logo and rolls back unsafe uploads\n";
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
} finally {
    if ($conn instanceof mysqli) {
        $pattern = $prefix . '%';
        $statement = $conn->prepare("DELETE FROM identity_claims WHERE owner_kind='restaurant_application' AND owner_id IN (SELECT id FROM restaurant_applications WHERE username LIKE ?)");
        $statement->bind_param('s', $pattern); $statement->execute(); $statement->close();
        $statement = $conn->prepare("DELETE FROM media_assets WHERE owner_kind='restaurant_application' AND owner_id IN (SELECT id FROM restaurant_applications WHERE username LIKE ?)");
        $statement->bind_param('s', $pattern); $statement->execute(); $statement->close();
        $statement = $conn->prepare('DELETE FROM restaurant_applications WHERE username LIKE ?');
        $statement->bind_param('s', $pattern); $statement->execute(); $statement->close();
        $conn->close();
    }
    if (getenv('SAVORA_UPLOAD_ROOT') === $uploadRoot) putenv('SAVORA_UPLOAD_ROOT');
    if (is_dir($uploadRoot)) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($uploadRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        rmdir($uploadRoot);
    }
}
