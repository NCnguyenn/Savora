<?php
declare(strict_types=1);

require_once __DIR__ . '/../repositories/pricing_repository.php';
require_once __DIR__ . '/commercial_service.php';

function pricing_error(int $status, string $message, array $errors = []): array
{
    $result = ['ok' => false, 'status' => $status, 'message' => $message];
    if ($errors !== []) $result['errors'] = $errors;
    return $result;
}

function pricing_success(array $data, string $message = 'Quote created.'): array
{
    return ['ok' => true, 'status' => 200, 'message' => $message, 'data' => $data];
}

function pricing_money(mixed $value): float
{
    return round(max(0, (float) $value), 2);
}

function pricing_identifier(mixed $value, string $field, int $maximum = 60): string
{
    $identifier = trim((string) $value);
    if (!preg_match('/^[A-Za-z0-9_-]{1,' . $maximum . '}$/', $identifier)) {
        throw new InvalidArgumentException("{$field} is invalid.");
    }
    return $identifier;
}

function pricing_option_rows_by_group(array $rows): array
{
    $groups = [];
    foreach ($rows as $row) {
        $groupId = (int) $row['group_id'];
        if (!isset($groups[$groupId])) {
            $groups[$groupId] = [
                'id' => $groupId,
                'name' => (string) $row['group_name'],
                'selectionType' => (string) $row['selection_type'],
                'minimum' => (int) $row['minimum_choices'],
                'maximum' => (int) $row['maximum_choices'],
                'choices' => [],
            ];
        }
        if ($row['choice_public_id'] !== null) {
            $groups[$groupId]['choices'][(string) $row['choice_public_id']] = [
                'publicId' => (string) $row['choice_public_id'],
                'name' => (string) $row['choice_name'],
                'priceDelta' => (float) $row['price_delta'],
                'available' => (int) $row['available'] === 1,
            ];
        }
    }
    return array_values($groups);
}

function pricing_selected_options(array $groups, mixed $rawIds): array
{
    if (!is_array($rawIds) || array_values($rawIds) !== $rawIds || count($rawIds) > 50) {
        throw new InvalidArgumentException('Selected menu options are invalid.');
    }
    $ids = [];
    foreach ($rawIds as $rawId) {
        $id = pricing_identifier($rawId, 'Option identifier');
        if (isset($ids[$id])) throw new InvalidArgumentException('An option may only be selected once.');
        $ids[$id] = true;
    }

    $selected = [];
    $priceDelta = 0.0;
    foreach ($groups as $group) {
        $count = 0;
        foreach ($ids as $id => $_) {
            if (!isset($group['choices'][$id])) continue;
            $choice = $group['choices'][$id];
            if (!$choice['available']) throw new RuntimeException('A selected menu option is unavailable.');
            $count++;
            $priceDelta += (float) $choice['priceDelta'];
            $selected[] = ['publicId' => $choice['publicId'], 'name' => $choice['name'], 'priceDelta' => pricing_money($choice['priceDelta'])];
        }
        if ($count < $group['minimum'] || $count > $group['maximum']) {
            throw new InvalidArgumentException("Option group {$group['name']} requires a valid number of choices.");
        }
    }
    if (count($selected) !== count($ids)) throw new InvalidArgumentException('A selected option does not belong to this menu item.');
    usort($selected, static fn (array $a, array $b): int => strcmp($a['publicId'], $b['publicId']));
    return ['items' => $selected, 'priceDelta' => $priceDelta];
}

function pricing_promotion_discount(mysqli $conn, int $customerUserId, array $promotion, float $subtotal, int $restaurantId, ?DateTimeImmutable $at = null): array
{
    $status = (string) ($promotion['status'] ?? '');
    $now = ($at ?? new DateTimeImmutable('now'))->getTimestamp();
    $starts = strtotime((string) ($promotion['starts_at'] ?? ''));
    $ends = strtotime((string) ($promotion['ends_at'] ?? ''));
    if ($status !== 'active' || $starts === false || $ends === false || $now < $starts || $now > $ends) return ['error' => pricing_error(422, 'Promotion is not currently active.')];
    if ($subtotal < (float) $promotion['minimum_order']) return ['error' => pricing_error(422, 'Order does not meet the promotion minimum.')];
    $scope = (string) ($promotion['scope'] ?? 'all_restaurants');
    if ($scope !== 'all_restaurants' && $scope !== 'all' && $scope !== (string) $restaurantId && $scope !== 'restaurant:' . $restaurantId) return ['error' => pricing_error(422, 'Promotion is not valid for this Restaurant.')];
    if ((string) $promotion['audience'] === 'new_customers' && pricing_repository_customer_has_delivered_order($conn, $customerUserId)) return ['error' => pricing_error(422, 'Promotion is limited to new Customers.')];
    $usage = pricing_repository_promotion_usage($conn, (int) $promotion['id']);
    if ($promotion['usage_cap'] !== null && $usage >= (int) $promotion['usage_cap']) return ['error' => pricing_error(422, 'Promotion usage limit has been reached.')];
    $discount = (string) $promotion['discount_type'] === 'percentage'
        ? $subtotal * ((float) $promotion['discount_value'] / 100)
        : (float) $promotion['discount_value'];
    if ($promotion['maximum_discount'] !== null) $discount = min($discount, (float) $promotion['maximum_discount']);
    $discount = min($subtotal, pricing_money($discount));
    if ((float) $promotion['budget'] > 0 && (float) $promotion['used_amount'] + $discount > (float) $promotion['budget']) return ['error' => pricing_error(422, 'Promotion budget is exhausted.')];
    return ['discount' => $discount, 'id' => (int) $promotion['id'], 'code' => (string) $promotion['code']];
}

function pricing_create_quote_mutation(mysqli $conn, int $customerUserId, array $cart, string $addressPublicId, ?string $promotionCode): array
{
    try {
        if ($customerUserId <= 0 || !is_array($cart) || $cart === [] || array_values($cart) !== $cart || count($cart) > 50) return pricing_error(422, 'Cart is invalid.');
        $addressPublicId = pricing_identifier($addressPublicId, 'Address identifier');
        $address = pricing_repository_address_for_customer($conn, $customerUserId, $addressPublicId, true);
        if ($address === []) return pricing_error(403, 'Delivery address is not owned by this Customer.');
        $normalizedItems = [];
        $canonicalItems = [];
        $restaurantId = 0;
        $restaurant = [];
        $subtotal = 0.0;
        foreach ($cart as $line) {
            if (!is_array($line)) return pricing_error(422, 'Cart line is invalid.');
            $itemPublicId = pricing_identifier($line['itemPublicId'] ?? '', 'Menu item identifier');
            $quantity = filter_var($line['quantity'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 20]]);
            if ($quantity === false) return pricing_error(422, 'Menu item quantity is invalid.');
            $item = pricing_repository_menu_item($conn, $itemPublicId, true);
            if ($item === [] || (int) $item['is_available'] !== 1 || (string) $item['restaurant_status'] !== 'active' || (int) $item['accepting_orders'] !== 1) return pricing_error(409, 'A selected menu item is unavailable.');
            if ($restaurantId !== 0 && $restaurantId !== (int) $item['restaurant_id']) return pricing_error(422, 'One quote can contain items from only one Restaurant.');
            $restaurantId = (int) $item['restaurant_id'];
            $restaurant = ['id' => $restaurantId, 'name' => (string) $item['restaurant_name'], 'city' => (string) $item['restaurant_city']];
            try {
                $optionResult = pricing_selected_options(pricing_option_rows_by_group(pricing_repository_option_rows($conn, (int) $item['id'], true)), $line['optionPublicIds'] ?? []);
            } catch (RuntimeException $exception) {
                return pricing_error(409, $exception->getMessage());
            } catch (InvalidArgumentException $exception) {
                return pricing_error(422, $exception->getMessage());
            }
            $unitPrice = pricing_money((float) $item['price'] + (float) $optionResult['priceDelta']);
            $lineTotal = pricing_money($unitPrice * (int) $quantity);
            $subtotal += $lineTotal;
            $optionIds = array_map(static fn (array $option): string => (string) $option['publicId'], $optionResult['items']);
            $normalizedItems[] = [
                'itemPublicId' => $itemPublicId, 'name' => (string) $item['name'], 'quantity' => (int) $quantity,
                'basePrice' => pricing_money($item['price']), 'unitPrice' => $unitPrice, 'options' => $optionResult['items'], 'lineTotal' => $lineTotal,
            ];
            sort($optionIds, SORT_STRING);
            $canonicalItems[] = ['itemPublicId' => $itemPublicId, 'quantity' => (int) $quantity, 'optionPublicIds' => $optionIds];
        }
        $subtotal = pricing_money($subtotal);
        $serverClock = new DateTimeImmutable('now');
        $commercial = commercial_active_rules($conn, $restaurantId, $customerUserId, $serverClock);
        if ($commercial['maintenanceMode']) return pricing_error(503, 'Checkout is temporarily unavailable.');
        $serviceArea = commercial_service_area_for_city($conn, (string) $address['city']);
        if ($serviceArea === [] || (string) ($serviceArea['status'] ?? '') !== 'active') return pricing_error(409, 'Delivery is not available in this area.');
        if ($subtotal < (float) $serviceArea['minimum_order']) return pricing_error(422, 'Order does not meet the service area minimum.');

        $feeRule = $commercial['feeRule'] ?? [];
        if ($feeRule !== [] && !in_array((string) ($feeRule['rule_type'] ?? ''), ['delivery_fee', 'delivery'], true)) $feeRule = [];
        $deliveryFee = 0.0; $feeRuleId = null;
        if ($feeRule !== []) {
            $feeRuleId = (int) $feeRule['id'];
            $deliveryFee = (string) $feeRule['unit'] === 'percent' ? pricing_money($subtotal * ((float) $feeRule['amount'] / 100)) : pricing_money($feeRule['amount']);
        } else {
            $flatFee = pricing_repository_setting($conn, 'delivery_fee_flat');
            if ($flatFee === null || !is_numeric($flatFee)) return pricing_error(503, 'Delivery fee configuration is unavailable.');
            $deliveryFee = pricing_money($flatFee);
        }

        $promotion = null; $discount = 0.0; $promotionId = null; $normalizedPromotionCode = null;
        $promotionCode = $promotionCode === null ? null : trim($promotionCode);
        if ($promotionCode !== null && $promotionCode !== '') {
            if (mb_strlen($promotionCode) > 50) return pricing_error(422, 'Promotion code is invalid.');
            $promotion = commercial_promotion_rules($conn, $promotionCode, $serverClock);
            if ($promotion === []) return pricing_error(422, 'Promotion code is invalid.');
            $promotionResult = pricing_promotion_discount($conn, $customerUserId, $promotion, $subtotal, $restaurantId, $serverClock);
            if (isset($promotionResult['error'])) return $promotionResult['error'];
            $discount = (float) $promotionResult['discount']; $promotionId = (int) $promotionResult['id']; $normalizedPromotionCode = (string) $promotionResult['code'];
        }
        $total = pricing_money($subtotal - $discount + $deliveryFee);
        $canonicalJson = json_encode($canonicalItems, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $itemsJson = json_encode($normalizedItems, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $quoteId = 'quote-' . bin2hex(random_bytes(12));
        $expiresAt = date('Y-m-d H:i:s', time() + 900);
        pricing_repository_insert_quote($conn, $quoteId, $customerUserId, $restaurantId, (int) $address['id'], hash('sha256', $canonicalJson), $itemsJson, $subtotal, $discount, $deliveryFee, $total, 'USD', $normalizedPromotionCode, $promotionId, $feeRuleId, $expiresAt);
        return pricing_success([
            'quoteId' => $quoteId, 'currency' => 'USD', 'restaurant' => $restaurant, 'items' => $normalizedItems,
            'subtotal' => $subtotal, 'discount' => $discount, 'deliveryFee' => $deliveryFee, 'total' => $total,
            'expiresAt' => $expiresAt, 'version' => 1,
        ]);
    } catch (InvalidArgumentException $exception) {
        return pricing_error(422, $exception->getMessage());
    }
}

function pricing_create_quote(mysqli $conn, int $customerUserId, array $cart, string $addressPublicId, ?string $promotionCode): array
{
    $conn->begin_transaction();
    try {
        $result = pricing_create_quote_mutation($conn, $customerUserId, $cart, $addressPublicId, $promotionCode);
        $conn->commit();
        return $result;
    } catch (Throwable) {
        $conn->rollback();
        return pricing_error(500, 'Quote could not be created.');
    }
}
