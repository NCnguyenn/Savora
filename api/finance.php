<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/http.php';
require_once __DIR__ . '/../lib/request_security.php';
require_once __DIR__ . '/../lib/repositories/finance_repository.php';

$actor = savora_request_actor($conn, ['restaurant']);
$restaurantId = finance_repository_restaurant_id($conn, (int) $actor['userId']);
try {
    $filters = ['from' => $_GET['from'] ?? '', 'to' => $_GET['to'] ?? '', 'type' => $_GET['type'] ?? ''];
    if (isset($_GET['document'])) {
        $document = finance_repository_document($conn, $restaurantId, (string) $_GET['document'], $filters);
        if ($document === []) savora_error(404, 'Financial document not found.');
        savora_json(['ok' => true, 'data' => ['document' => $document]]);
    }
    savora_json(['ok' => true, 'data' => finance_repository_report($conn, $restaurantId, $filters)]);
} catch (InvalidArgumentException $exception) {
    savora_error(422, $exception->getMessage());
} catch (Throwable) {
    savora_error(500, 'Restaurant finance data is temporarily unavailable.');
}
