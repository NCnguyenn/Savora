<?php
declare(strict_types=1);

function http_expect(bool $value, string $message): void
{
    if (!$value) {
        throw new RuntimeException($message);
    }
}

function http_probe(string $mode, string $input = ''): array
{
    $cgi = dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . 'php-cgi.exe';
    if (!is_file($cgi)) {
        throw new RuntimeException('PHP CGI executable is required for HTTP contract tests.');
    }
    $probe = __DIR__ . '/support/http_contract_probe.php';
    $environment = array_merge(getenv(), [
        'REDIRECT_STATUS' => '1',
        'REQUEST_METHOD' => 'POST',
        'CONTENT_TYPE' => 'application/json',
        'CONTENT_LENGTH' => (string) strlen($input),
        'SAVORA_HTTP_PROBE' => $mode,
        'SCRIPT_FILENAME' => $probe,
        'SCRIPT_NAME' => '/http_contract_probe.php',
    ]);
    $process = proc_open([$cgi], [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, __DIR__, $environment);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start HTTP contract probe.');
    }
    fwrite($pipes[0], $input);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    if ($exitCode !== 0 || $stderr !== '') {
        throw new RuntimeException("HTTP contract probe failed: {$stderr}");
    }
    $parts = preg_split('/\r?\n\r?\n/', $stdout, 2);
    if (!is_array($parts) || count($parts) !== 2) {
        throw new RuntimeException('HTTP contract probe returned an invalid CGI response.');
    }
    preg_match('/^Status:\s+(\d+)/mi', $parts[0], $statusMatch);
    $status = isset($statusMatch[1]) ? (int) $statusMatch[1] : 200;
    $decoded = json_decode($parts[1], true, 512, JSON_THROW_ON_ERROR);
    return ['status' => $status, 'body' => $decoded];
}

$read = http_probe('read', '{"command":"check","payload":{"count":2}}');
http_expect($read === ['status' => 201, 'body' => ['ok' => true, 'data' => ['command' => 'check', 'payload' => ['count' => 2]]]], 'savora_read_json() must parse object input and savora_json() must emit it.');

$error = http_probe('error');
http_expect($error === ['status' => 422, 'body' => ['ok' => false, 'message' => 'Check the request.', 'errors' => ['field' => 'Required.'], 'referenceId' => 'REQ-1']], 'savora_error() must emit the canonical error envelope.');

foreach (['invalid_utf8', 'unsupported'] as $mode) {
    $failure = http_probe($mode);
    http_expect($failure === ['status' => 500, 'body' => ['ok' => false, 'message' => 'Response serialization failed.']], "{$mode} response serialization must produce valid JSON failure output.");
}

echo "http contract ok\n";
