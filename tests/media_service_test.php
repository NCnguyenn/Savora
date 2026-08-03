<?php
declare(strict_types=1);

if (getenv('SAVORA_ENV') !== 'test' || getenv('SAVORA_DB_NAME') !== 'savora_test') {
    fwrite(STDERR, "BLOCKED: media service tests require savora_test\n");
    exit(2);
}

require_once __DIR__ . '/../lib/services/media_service.php';
require_once __DIR__ . '/support/test_database.php';

function media_expect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$conn = savora_test_database();
$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'savora-media-' . bin2hex(random_bytes(6));
$ids = [];
try {
    mkdir($root, 0700, true);
    putenv('SAVORA_UPLOAD_ROOT=' . $root);
    $pngPath = $root . DIRECTORY_SEPARATOR . 'logo-source.png';
    file_put_contents($pngPath, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true));
    $png = ['name' => 'savora-logo.png', 'tmp_name' => $pngPath, 'error' => UPLOAD_ERR_OK, 'size' => filesize($pngPath)];
    $stored = media_store_restaurant_logo($conn, $png, 'restaurant_application', 701);
    $ids[] = (int) $stored['id'];
    media_expect($stored['mimeType'] === 'image/png', 'Detected MIME must be PNG.');
    media_expect($stored['visibility'] === 'private', 'Pending logo must be private.');
    media_expect(!isset($stored['storedPath']), 'Public result must not expose storage paths.');
    $row = $conn->query('SELECT * FROM media_assets WHERE id=' . (int) $stored['id'])->fetch_assoc();
    media_expect($row !== null && preg_match('/^[a-f0-9]{64}$/', (string) $row['sha256']) === 1, 'Media metadata and hash must persist.');
    $absolute = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, (string) $row['stored_path']);
    media_expect(is_file($absolute), 'Stored logo file must exist outside the webroot.');
    media_expect(media_find_public($conn, (string) $stored['publicId']) === [], 'Pending logo must not be public.');

    media_transfer($conn, (int) $stored['id'], 'restaurant', 801, 'public');
    $public = media_find_public($conn, (string) $stored['publicId']);
    media_expect(($public['owner_kind'] ?? '') === 'restaurant' && ($public['visibility'] ?? '') === 'public', 'Approved logo must become public Restaurant media.');

    $revokedPath = media_revoke($conn, (int) $stored['id']);
    media_expect($revokedPath === $row['stored_path'], 'Revocation must return the cleanup path.');
    media_expect(media_find_public($conn, (string) $stored['publicId']) === [], 'Revoked logo must not be public.');
    media_delete_file((string) $revokedPath);
    media_expect(!is_file($absolute), 'Revoked logo file must be removable.');

    $fakePath = $root . DIRECTORY_SEPARATOR . 'fake.png';
    file_put_contents($fakePath, 'not-an-image');
    $fake = ['name' => 'fake.png', 'tmp_name' => $fakePath, 'error' => UPLOAD_ERR_OK, 'size' => filesize($fakePath)];
    $rejected = false;
    try { media_store_restaurant_logo($conn, $fake, 'restaurant_application', 702); } catch (InvalidArgumentException) { $rejected = true; }
    media_expect($rejected, 'Fake image content must be rejected.');

    $svgPath = $root . DIRECTORY_SEPARATOR . 'logo.svg';
    file_put_contents($svgPath, '<svg xmlns="http://www.w3.org/2000/svg"></svg>');
    $svg = ['name' => 'logo.svg', 'tmp_name' => $svgPath, 'error' => UPLOAD_ERR_OK, 'size' => filesize($svgPath)];
    $svgRejected = false;
    try { media_store_restaurant_logo($conn, $svg, 'restaurant_application', 703); } catch (InvalidArgumentException) { $svgRejected = true; }
    media_expect($svgRejected, 'SVG logos must be rejected.');

    echo "PASS: Restaurant logo media validates bytes, protects pending assets, transfers, and revokes safely\n";
} finally {
    foreach ($ids as $id) $conn->query('DELETE FROM media_assets WHERE id=' . $id);
    $conn->close();
    putenv('SAVORA_UPLOAD_ROOT');
    if (is_dir($root)) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        rmdir($root);
    }
}
