<?php
declare(strict_types=1);

function rate_limit_bucket_key(string $actor, string $action): string
{
    return hash('sha256', mb_substr($actor, 0, 200) . '|' . mb_substr($action, 0, 80));
}

function rate_limit_consume(mysqli $conn, string $actor, string $action, int $maxAttempts, int $windowSeconds): bool
{
    $maxAttempts = max(1, min(1000, $maxAttempts));
    $windowSeconds = max(1, min(86400, $windowSeconds));
    $key = rate_limit_bucket_key($actor, $action);
    $conn->begin_transaction();
    try {
        $select = $conn->prepare('SELECT window_started_at, attempts FROM rate_limit_buckets WHERE bucket_key=? FOR UPDATE');
        $select->bind_param('s', $key); $select->execute(); $row = $select->get_result()->fetch_assoc() ?: []; $select->close();
        $allowed = true;
        if ($row === []) {
            $insert = $conn->prepare('INSERT INTO rate_limit_buckets(bucket_key,window_started_at,attempts) VALUES(?,NOW(),1)');
            $insert->bind_param('s', $key); $insert->execute(); $insert->close();
        } elseif (strtotime((string) $row['window_started_at']) <= time() - $windowSeconds) {
            $reset = $conn->prepare('UPDATE rate_limit_buckets SET window_started_at=NOW(),attempts=1,version=version+1 WHERE bucket_key=?');
            $reset->bind_param('s', $key); $reset->execute(); $reset->close();
        } elseif ((int) $row['attempts'] < $maxAttempts) {
            $increment = $conn->prepare('UPDATE rate_limit_buckets SET attempts=attempts+1,version=version+1 WHERE bucket_key=?');
            $increment->bind_param('s', $key); $increment->execute(); $increment->close();
        } else $allowed = false;
        $conn->commit();
        return $allowed;
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }
}
