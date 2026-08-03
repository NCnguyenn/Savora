<?php
declare(strict_types=1);

require_once __DIR__ . '/../idempotency.php';
require_once __DIR__ . '/../repositories/review_repository.php';

function review_error(int $status, string $message, array $errors = []): array
{
    $result = ['ok' => false, 'status' => $status, 'message' => $message];
    if ($errors !== []) $result['errors'] = $errors;
    return $result;
}

function review_success(array $data, string $message = 'Review operation completed.'): array
{ return ['ok' => true, 'status' => 200, 'message' => $message, 'data' => $data]; }

function reviews_for_customer(mysqli $conn, int $customerUserId): array
{ return review_success(review_repository_for_customer($conn, $customerUserId)); }

function reviews_for_restaurant(mysqli $conn, int $ownerUserId): array
{ return review_success(review_repository_for_restaurant($conn, $ownerUserId)); }

function review_create_for_order_mutation(mysqli $conn, int $customerUserId, string $orderReference, int $rating, string $comment): array
{
    $orderReference = trim($orderReference);
    $comment = trim($comment);
    if ($orderReference === '' || mb_strlen($orderReference) > 40 || $rating < 1 || $rating > 5 || mb_strlen($comment) > 1000) {
        return review_error(422, 'Order, rating, or review text is invalid.');
    }
    $order = review_repository_customer_order($conn, $customerUserId, $orderReference, true);
    if ($order === []) return review_error(403, 'This order does not belong to the Customer.');
    if ((string) $order['status'] !== 'delivered') return review_error(409, 'Only a delivered order can be reviewed.');
    if (review_repository_by_order($conn, (int) $order['id'], true) !== []) return review_error(409, 'This order already has a review.');
    $publicId = 'review-' . bin2hex(random_bytes(12));
    $insert = $conn->prepare("INSERT INTO restaurant_reviews(public_id,order_id,customer_user_id,restaurant_id,rating,comment,reply_status,version) VALUES(?,?,?,?,?,?,'none',1)");
    $orderId = (int) $order['id']; $restaurantId = (int) $order['restaurant_id'];
    $insert->bind_param('siiiis', $publicId, $orderId, $customerUserId, $restaurantId, $rating, $comment);
    $insert->execute(); $insert->close();
    return review_success(['publicId' => $publicId, 'orderReference' => $orderReference, 'rating' => $rating, 'comment' => $comment, 'version' => 1], 'Review submitted.');
}

function review_reply_as_restaurant_mutation(mysqli $conn, int $ownerUserId, string $reviewPublicId, string $reply, string $status, int $expectedVersion): array
{
    $reviewPublicId = trim($reviewPublicId); $reply = trim($reply);
    if (!preg_match('/^[A-Za-z0-9_-]{1,60}$/', $reviewPublicId) || $reply === '' || mb_strlen($reply) > 1000 || !in_array($status, ['draft', 'published'], true)) {
        return review_error(422, 'Review reply is invalid.');
    }
    $review = review_repository_for_owner($conn, $ownerUserId, $reviewPublicId, true);
    if ($review === []) return review_error(403, 'Only the owning Restaurant may reply to this review.');
    if ((int) $review['version'] !== $expectedVersion) return review_error(409, 'Review version is stale.');
    $update = $conn->prepare("UPDATE restaurant_reviews SET reply_text=?,reply_status=?,replied_at=IF(?='published',NOW(),replied_at),version=version+1 WHERE id=? AND version=?");
    $reviewId = (int) $review['id'];
    $update->bind_param('sssii', $reply, $status, $status, $reviewId, $expectedVersion);
    $update->execute(); $affected = $update->affected_rows; $update->close();
    if ($affected !== 1) return review_error(409, 'Review changed. Refresh before retrying.');
    return review_success(['publicId' => $reviewPublicId, 'replyText' => $reply, 'replyStatus' => $status, 'version' => $expectedVersion + 1], 'Restaurant reply saved.');
}

function review_transaction(mysqli $conn, callable $operation): array
{
    $conn->begin_transaction();
    try { $result = $operation(); $conn->commit(); return $result; }
    catch (Throwable $exception) { $conn->rollback(); return review_error(500, 'Review operation could not be completed.'); }
}

function review_create_for_order(mysqli $conn, int $customerUserId, string $orderReference, int $rating, string $comment): array
{ return review_transaction($conn, fn (): array => review_create_for_order_mutation($conn, $customerUserId, $orderReference, $rating, $comment)); }

function review_reply_as_restaurant(mysqli $conn, int $ownerUserId, string $reviewPublicId, string $reply, int $expectedVersion, string $status = 'published'): array
{ return review_transaction($conn, fn (): array => review_reply_as_restaurant_mutation($conn, $ownerUserId, $reviewPublicId, $reply, $status, $expectedVersion)); }

function review_execute_action(mysqli $conn, int $actorUserId, string $role, string $action, array $payload, int $expectedVersion, string $idempotencyKey): array
{
    $conn->begin_transaction();
    try {
        $result = match ($action) {
            'create_review' => $role === 'customer'
                ? review_create_for_order_mutation($conn, $actorUserId, (string) ($payload['orderReference'] ?? ''), (int) ($payload['rating'] ?? 0), (string) ($payload['comment'] ?? ''))
                : review_error(403, 'Only Customers may create reviews.'),
            'reply_review' => $role === 'restaurant'
                ? review_reply_as_restaurant_mutation($conn, $actorUserId, (string) ($payload['publicId'] ?? ''), (string) ($payload['reply'] ?? ''), (string) ($payload['status'] ?? 'published'), $expectedVersion)
                : review_error(403, 'Only Restaurants may reply to reviews.'),
            default => review_error(422, 'Unsupported review action.'),
        };
        savora_idempotency_store($conn, $actorUserId, $idempotencyKey, $action, savora_idempotency_hash($action, $payload), $result);
        $conn->commit(); return $result;
    } catch (Throwable $exception) {
        $conn->rollback(); return review_error(500, 'Review operation could not be completed.');
    }
}
