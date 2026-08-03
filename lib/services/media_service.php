<?php
declare(strict_types=1);

const SAVORA_RESTAURANT_LOGO_MAX_BYTES = 5 * 1024 * 1024;
const SAVORA_RESTAURANT_LOGO_MIMES = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
];

function media_upload_root(): string
{
    $configured = trim((string) (getenv('SAVORA_UPLOAD_ROOT') ?: ''));
    if ($configured === '') throw new InvalidArgumentException('SAVORA_UPLOAD_ROOT must be configured before accepting media.');
    if (!is_dir($configured) && !mkdir($configured, 0700, true) && !is_dir($configured)) {
        throw new RuntimeException('Media upload root could not be created.');
    }
    $root = realpath($configured);
    if ($root === false) throw new RuntimeException('Media upload root could not be resolved.');
    $project = realpath(dirname(__DIR__, 2));
    $documentRoot = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? '')) ?: null;
    $inside = static function (string $candidate, ?string $parent): bool {
        if ($parent === null || $parent === '') return false;
        $candidate = strtolower(rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $candidate), DIRECTORY_SEPARATOR));
        $parent = strtolower(rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $parent), DIRECTORY_SEPARATOR));
        return $candidate === $parent || str_starts_with($candidate, $parent . DIRECTORY_SEPARATOR);
    };
    if ($inside($root, $project) || $inside($root, $documentRoot)) {
        throw new InvalidArgumentException('Media upload root must be outside the executable webroot.');
    }
    return $root;
}

function media_safe_absolute_path(string $relative): string
{
    if ($relative === '' || str_starts_with($relative, '/') || preg_match('/^[A-Za-z]:/', $relative) || preg_match('#(^|[\\/])\.\.([\\/]|$)#', $relative)) {
        throw new InvalidArgumentException('Media path is invalid.');
    }
    $root = media_upload_root();
    $absolute = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    $normalizedRoot = strtolower(rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $root), DIRECTORY_SEPARATOR));
    $normalizedAbsolute = strtolower(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $absolute));
    if (!str_starts_with($normalizedAbsolute, $normalizedRoot . DIRECTORY_SEPARATOR)) {
        throw new InvalidArgumentException('Media path escapes its configured root.');
    }
    return $absolute;
}

function media_store_restaurant_logo(mysqli $conn, array $file, string $ownerKind, int $ownerId): array
{
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) return [];
    if ($error !== UPLOAD_ERR_OK) throw new InvalidArgumentException('Restaurant logo upload failed.');
    if (!preg_match('/^[a-z_]{3,40}$/', $ownerKind) || $ownerId <= 0) throw new InvalidArgumentException('Media owner is invalid.');
    $source = (string) ($file['tmp_name'] ?? '');
    if ($source === '' || !is_file($source) || (PHP_SAPI !== 'cli' && !is_uploaded_file($source))) {
        throw new InvalidArgumentException('Restaurant logo upload is invalid.');
    }
    $size = (int) filesize($source);
    if ($size <= 0 || $size > SAVORA_RESTAURANT_LOGO_MAX_BYTES) {
        throw new InvalidArgumentException('Restaurant logo must be 5 MB or smaller.');
    }
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo ? (string) finfo_file($finfo, $source) : '';
    if ($finfo) finfo_close($finfo);
    if (!isset(SAVORA_RESTAURANT_LOGO_MIMES[$mime])) throw new InvalidArgumentException('Restaurant logo must be a JPEG, PNG, or WebP image.');
    $extension = strtolower((string) pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    $allowedExtension = SAVORA_RESTAURANT_LOGO_MIMES[$mime];
    if (($mime === 'image/jpeg' && !in_array($extension, ['jpg', 'jpeg'], true)) || ($mime !== 'image/jpeg' && $extension !== $allowedExtension)) {
        throw new InvalidArgumentException('Restaurant logo extension does not match its content.');
    }
    $digest = hash_file('sha256', $source);
    if (!is_string($digest) || !preg_match('/^[a-f0-9]{64}$/', $digest)) throw new RuntimeException('Restaurant logo could not be hashed.');
    $relative = 'restaurant-logos/' . date('Y/m') . '/' . bin2hex(random_bytes(18)) . '.' . $allowedExtension;
    $absolute = media_safe_absolute_path($relative);
    $directory = dirname($absolute);
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new RuntimeException('Restaurant logo directory could not be created.');
    $moved = PHP_SAPI === 'cli' ? rename($source, $absolute) : move_uploaded_file($source, $absolute);
    if (!$moved) throw new RuntimeException('Restaurant logo could not be stored.');
    @chmod($absolute, 0600);
    $publicId = 'MED-' . strtoupper(bin2hex(random_bytes(12)));
    $purpose = 'restaurant_logo';
    $visibility = 'private';
    $status = 'active';
    try {
        $statement = $conn->prepare(
            'INSERT INTO media_assets(public_id,owner_kind,owner_id,purpose,stored_path,mime_type,file_size,sha256,visibility,status) VALUES(?,?,?,?,?,?,?,?,?,?)'
        );
        $statement->bind_param('ssisssisss', $publicId, $ownerKind, $ownerId, $purpose, $relative, $mime, $size, $digest, $visibility, $status);
        $statement->execute();
        $id = (int) $statement->insert_id;
        $statement->close();
    } catch (Throwable $exception) {
        if (is_file($absolute)) @unlink($absolute);
        throw $exception;
    }
    return ['id' => $id, 'publicId' => $publicId, 'mimeType' => $mime, 'fileSize' => $size, 'sha256' => $digest, 'visibility' => $visibility, 'status' => $status];
}

function media_transfer(mysqli $conn, int $mediaId, string $ownerKind, int $ownerId, string $visibility): void
{
    if ($mediaId <= 0 || $ownerId <= 0 || !preg_match('/^[a-z_]{3,40}$/', $ownerKind) || !in_array($visibility, ['private', 'public'], true)) {
        throw new InvalidArgumentException('Media transfer is invalid.');
    }
    $statement = $conn->prepare("UPDATE media_assets SET owner_kind=?,owner_id=?,visibility=?,status='active' WHERE id=?");
    $statement->bind_param('sisi', $ownerKind, $ownerId, $visibility, $mediaId);
    $statement->execute();
    if ($statement->affected_rows !== 1) throw new RuntimeException('Media asset was not available for transfer.');
    $statement->close();
}

function media_revoke(mysqli $conn, int $mediaId): ?string
{
    $lookup = $conn->prepare('SELECT stored_path FROM media_assets WHERE id=? FOR UPDATE');
    $lookup->bind_param('i', $mediaId);
    $lookup->execute();
    $row = $lookup->get_result()->fetch_assoc();
    $lookup->close();
    if (!$row) return null;
    $statement = $conn->prepare("UPDATE media_assets SET visibility='private',status='revoked' WHERE id=?");
    $statement->bind_param('i', $mediaId);
    $statement->execute();
    $statement->close();
    return (string) $row['stored_path'];
}

function media_delete_file(string $relative): void
{
    if ($relative === '') return;
    $absolute = media_safe_absolute_path($relative);
    if (is_file($absolute) && !unlink($absolute)) throw new RuntimeException('Media file could not be removed.');
}

function media_find_asset(mysqli $conn, string $publicId): array
{
    if (!preg_match('/^MED-[A-F0-9]{24}$/', $publicId)) return [];
    $statement = $conn->prepare('SELECT id,public_id,owner_kind,owner_id,purpose,stored_path,mime_type,file_size,sha256,visibility,status FROM media_assets WHERE public_id=? LIMIT 1');
    $statement->bind_param('s', $publicId);
    $statement->execute();
    $row = $statement->get_result()->fetch_assoc();
    $statement->close();
    return $row ?: [];
}

function media_find_public(mysqli $conn, string $publicId): array
{
    $asset = media_find_asset($conn, $publicId);
    return $asset !== [] && $asset['status'] === 'active' && $asset['visibility'] === 'public' ? $asset : [];
}
