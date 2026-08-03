<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/http.php';
require_once __DIR__ . '/../lib/request_security.php';
require_once __DIR__ . '/../lib/idempotency.php';
require_once __DIR__ . '/../lib/services/delivery_service.php';

$actor = savora_request_actor($conn, ['driver']);
if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') savora_error(405, 'Method not allowed.');
try { savora_require_csrf(['X-CSRF-Token' => (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')]); }
catch (InvalidArgumentException) { savora_error(403, 'Secure session expired.'); }
try { $idempotencyKey = savora_require_idempotency_key(['Idempotency-Key' => (string) ($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '')]); }
catch (InvalidArgumentException) { savora_error(422, 'Idempotency key required.'); }

$deliveryId = (int) ($_POST['deliveryId'] ?? 0);
$type = trim((string) ($_POST['type'] ?? 'photo'));
$file = is_array($_FILES['evidence'] ?? null) ? $_FILES['evidence'] : [];
$driverId = (int) $actor['userId'];
$locked = false;
$data = null;
$httpError = null;
try {
    savora_idempotency_lock($conn, $driverId, $idempotencyKey);
    $locked = true;
    $data = delivery_store_evidence_upload($conn, $driverId, $deliveryId, $type, $file, $idempotencyKey);
} catch (SavoraIdempotencyConflict) {
    $httpError = [409, 'Idempotency key was already used for different evidence.'];
} catch (InvalidArgumentException $exception) {
    $httpError = [422, $exception->getMessage()];
} catch (RuntimeException $exception) {
    $httpError = [409, $exception->getMessage()];
} catch (Throwable) {
    $httpError = [500, 'Delivery evidence could not be uploaded.'];
} finally {
    if ($locked) savora_idempotency_unlock($conn, $driverId, $idempotencyKey);
}

if ($httpError !== null) savora_error($httpError[0], $httpError[1]);
savora_json(['ok' => true, 'message' => 'Delivery evidence uploaded.', 'data' => $data], 201);
