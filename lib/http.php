<?php
declare(strict_types=1);

function savora_json(array $body, int $status = 200): never
{
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($status);
    try {
        $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        http_response_code(500);
        echo '{"ok":false,"message":"Response serialization failed."}';
        exit;
    }
    echo $json;
    exit;
}

function savora_read_json(): array
{
    $body = json_decode((string) file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($body)) {
        throw new JsonException('JSON request body must decode to an array.');
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
