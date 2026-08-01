<?php
declare(strict_types=1);

require_once __DIR__ . '/../../lib/http.php';

$mode = (string) getenv('SAVORA_HTTP_PROBE');

if ($mode === 'read') {
    $body = savora_read_json();
    savora_json(['ok' => true, 'data' => $body], 201);
}

if ($mode === 'error') {
    savora_error(422, 'Check the request.', ['field' => 'Required.'], 'REQ-1');
}

if ($mode === 'invalid_utf8') {
    savora_json(['value' => "\xB1\x31"]);
}

if ($mode === 'unsupported') {
    $stream = fopen('php://memory', 'rb');
    savora_json(['value' => $stream]);
}

throw new RuntimeException('Unknown HTTP contract probe.');
