<?php
declare(strict_types=1);

function savora_json(array $body, int $status = 200): never
{
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function savora_read_json(): array
{
    $body = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($body)) {
        savora_error(400, 'Invalid JSON request.');
    }
    return $body;
}

function savora_error(int $status, string $message, array $errors = [], ?string $referenceId = null): never
{
    $body = ['ok' => false, 'message' => $message];
    if ($errors !== []) {
        $body['errors'] = $errors;
    }
    if ($referenceId !== null) {
        $body['referenceId'] = $referenceId;
    }
    savora_json($body, $status);
}
