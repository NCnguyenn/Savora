<?php
declare(strict_types=1);

require_once __DIR__ . '/../idempotency.php';
require_once __DIR__ . '/../services/audit_service.php';
require_once __DIR__ . '/../services/notification_service.php';
require_once __DIR__ . '/../repositories/order_repository.php';
require_once __DIR__ . '/../repositories/payment_repository.php';
require_once __DIR__ . '/../services/payment_service.php';

function order_error(int $status, string $message, array $errors = []): array
{
    $result = ['ok' => false, 'status' => $status, 'message' => $message];
    if ($errors !== []) $result['errors'] = $errors;
    return $result;
}

function order_success(array $data, string $message = 'Order placed.'): array
{
    return ['ok' => true, 'status' => 200, 'message' => $message, 'data' => $data];
}

function order_place_from_quote(mysqli $conn, int $customerUserId, string $quotePublicId, string $paymentMethod, string $idempotencyKey, string $deliveryNote = ''): array
{
    $quotePublicId = trim($quotePublicId);
    $paymentMethod = trim($paymentMethod);
    $deliveryNote = trim($deliveryNote);
    if (strlen($deliveryNote) > 300) $deliveryNote = substr($deliveryNote, 0, 300);
    $requestPayload = ['quoteId' => $quotePublicId, 'paymentMethod' => $paymentMethod, 'deliveryNote' => $deliveryNote];
    $requestHash = savora_idempotency_hash('place_order', $requestPayload);
    $conn->begin_transaction();
    try {
        $stored = savora_idempotency_find($conn, $customerUserId, $idempotencyKey, 'place_order', $requestHash);
        if ($stored !== null) {
            $conn->commit();
            return $stored;
        }
        $paymentError = payment_method_error($paymentMethod);
        if ($paymentError !== null) {
            $result = order_error($paymentError['status'], $paymentError['message']);
            savora_idempotency_store($conn, $customerUserId, $idempotencyKey, 'place_order', $requestHash, $result);
            $conn->commit();
            return $result;
        }

        $quote = order_repository_quote_for_customer($conn, $customerUserId, $quotePublicId, true);
        if ($quote === []) {
            $result = order_error(404, 'Checkout quote was not found.');
            savora_idempotency_store($conn, $customerUserId, $idempotencyKey, 'place_order', $requestHash, $result);
            $conn->commit();
            return $result;
        }
        if ($quote['consumed_at'] !== null) {
            $result = order_error(409, 'Checkout quote has already been used.');
            savora_idempotency_store($conn, $customerUserId, $idempotencyKey, 'place_order', $requestHash, $result);
            $conn->commit();
            return $result;
        }
        if (strtotime((string) $quote['expires_at']) <= time()) {
            $result = order_error(409, 'Checkout quote has expired.');
            savora_idempotency_store($conn, $customerUserId, $idempotencyKey, 'place_order', $requestHash, $result);
            $conn->commit();
            return $result;
        }
        if ((string) $quote['restaurant_status'] !== 'active' || (int) $quote['accepting_orders'] !== 1) {
            $result = order_error(409, 'Restaurant is no longer accepting this order.');
            savora_idempotency_store($conn, $customerUserId, $idempotencyKey, 'place_order', $requestHash, $result);
            $conn->commit();
            return $result;
        }

        $items = json_decode((string) $quote['items_json'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($items) || $items === []) throw new RuntimeException('Quote items are invalid.');
        foreach ($items as $item) {
            $publicId = trim((string) ($item['itemPublicId'] ?? ''));
            $available = order_repository_menu_available($conn, $publicId, true);
            if ($available === [] || (int) $available['is_available'] !== 1 || (string) $available['restaurant_status'] !== 'active' || (int) $available['accepting_orders'] !== 1) {
                $result = order_error(409, 'A quoted menu item is no longer available.');
                savora_idempotency_store($conn, $customerUserId, $idempotencyKey, 'place_order', $requestHash, $result);
                $conn->commit();
                return $result;
            }
        }

        $subtotal = (float) $quote['subtotal'];
        $discount = (float) $quote['discount_amount'];
        $deliveryFee = (float) $quote['delivery_fee'];
        $total = (float) $quote['total'];
        if ($paymentMethod === 'wallet' && !payment_repository_debit_wallet($conn, $customerUserId, $total)) {
            $result = order_error(409, 'Insufficient wallet balance.');
            savora_idempotency_store($conn, $customerUserId, $idempotencyKey, 'place_order', $requestHash, $result);
            $conn->commit();
            return $result;
        }
        $referenceCode = 'SVR-' . strtoupper(bin2hex(random_bytes(10)));
        $addressParts = array_filter([
            trim((string) $quote['address_line1']),
            trim((string) ($quote['address_line2'] ?? '')),
            trim((string) $quote['city']),
            trim((string) ($quote['region'] ?? '')),
            trim((string) ($quote['postal_code'] ?? '')),
        ], static fn (string $part): bool => $part !== '');
        $orderId = order_repository_insert_order($conn, $referenceCode, $customerUserId, (int) $quote['restaurant_id'], $paymentMethod, $subtotal, $discount, $deliveryFee, $total, implode(', ', $addressParts), $deliveryNote, (int) $quote['id'], $quote['promotion_id'] === null ? null : (int) $quote['promotion_id'], $quote['fee_rule_id'] === null ? null : (int) $quote['fee_rule_id']);
        order_repository_insert_items($conn, $orderId, $items);
        order_repository_insert_history($conn, $orderId, $customerUserId);
        $paymentStatus = payment_repository_status($paymentMethod);
        payment_repository_insert($conn, $orderId, $paymentMethod, $total, $paymentStatus);
        if ($paymentMethod === 'wallet') payment_repository_insert_wallet_debit($conn, $customerUserId, $orderId, $total);
        if ($quote['promotion_id'] !== null) {
            $promotionId = (int) $quote['promotion_id'];
            $redemption = $conn->prepare('INSERT INTO promotion_redemptions(promotion_id,customer_user_id,order_id,amount) VALUES(?,?,?,?)');
            $redemption->bind_param('iiid', $promotionId, $customerUserId, $orderId, $discount); $redemption->execute(); $redemption->close();
            $promote = $conn->prepare('UPDATE promotions SET used_amount=used_amount+? WHERE id=?');
            $promote->bind_param('di', $discount, $promotionId); $promote->execute(); $promote->close();
        }
        if (!order_repository_mark_quote_consumed($conn, (int) $quote['id'], (int) $quote['version'])) throw new RuntimeException('Checkout quote changed.');
        notification_queue($conn, $customerUserId, 'order_placed', 'Order placed', 'Your order ' . $referenceCode . ' was placed.', 'order', $orderId);
        audit_append($conn, $customerUserId, 'place_order', 'order', $orderId, null, ['referenceCode' => $referenceCode, 'total' => $total], 'Customer placed an order from a server quote.', $referenceCode);
        $order = order_repository_one_order($conn, $orderId);
        $result = order_success([
            'referenceCode' => (string) $order['reference_code'], 'status' => (string) $order['status'], 'paymentMethod' => (string) $order['payment_method'],
            'paymentStatus' => $paymentStatus, 'subtotal' => (float) $order['subtotal'], 'discount' => (float) $order['discount_amount'],
            'deliveryFee' => (float) $order['delivery_fee'], 'total' => (float) $order['total'], 'version' => (int) $order['version'],
        ]);
        savora_idempotency_store($conn, $customerUserId, $idempotencyKey, 'place_order', $requestHash, $result);
        $conn->commit();
        return $result;
    } catch (SavoraIdempotencyConflict) {
        $conn->rollback();
        throw new SavoraIdempotencyConflict('Idempotency key was already used for a different checkout request.');
    } catch (Throwable) {
        $conn->rollback();
        return order_error(500, 'Order could not be placed.');
    }
}
