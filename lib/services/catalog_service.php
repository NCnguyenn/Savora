<?php
declare(strict_types=1);

require_once __DIR__ . '/../idempotency.php';
require_once __DIR__ . '/../repositories/catalog_repository.php';

function catalog_error(int $status, string $message, array $errors = []): array
{
    $result = ['ok' => false, 'status' => $status, 'message' => $message];
    if ($errors !== []) {
        $result['errors'] = $errors;
    }
    return $result;
}

function catalog_success(array $data, string $message = 'Catalog operation completed.'): array
{
    return ['ok' => true, 'status' => 200, 'message' => $message, 'data' => $data];
}

function catalog_validate_public_id(mixed $value): string
{
    $publicId = trim((string) $value);
    if (!preg_match('/^[A-Za-z0-9_-]{1,60}$/', $publicId)) {
        throw new InvalidArgumentException('A valid public menu item identifier is required.');
    }
    return $publicId;
}

function catalog_validate_options(mixed $value): array
{
    if ($value === null) {
        return [];
    }
    if (!is_array($value) || count($value) > 20) {
        throw new InvalidArgumentException('Option groups must be an array of no more than 20 groups.');
    }
    $groups = [];
    foreach ($value as $group) {
        if (!is_array($group)) {
            throw new InvalidArgumentException('Each option group must be an object.');
        }
        $name = trim((string) ($group['name'] ?? ''));
        $selectionType = (string) ($group['selectionType'] ?? 'single');
        $minimum = (int) ($group['minimumChoices'] ?? 0);
        $maximum = (int) ($group['maximumChoices'] ?? 1);
        $choices = $group['choices'] ?? ($group['optionChoices'] ?? []);
        if ($name === '' || mb_strlen($name) > 120 || !in_array($selectionType, ['single', 'multiple'], true) || !is_array($choices)) {
            throw new InvalidArgumentException('Option group name, selection type, and choices are required.');
        }
        if ($minimum < 0 || $maximum < $minimum || ($selectionType === 'single' && $maximum > 1) || $maximum > count($choices) || count($choices) > 100) {
            throw new InvalidArgumentException('Option group selection limits are invalid.');
        }
        $normalizedChoices = [];
        foreach ($choices as $choice) {
            if (!is_array($choice)) {
                throw new InvalidArgumentException('Each option choice must be an object.');
            }
            $choiceId = catalog_validate_public_id($choice['publicId'] ?? '');
            $choiceName = trim((string) ($choice['name'] ?? ''));
            $delta = round((float) ($choice['priceDelta'] ?? 0), 2);
            if ($choiceName === '' || mb_strlen($choiceName) > 120 || $delta < -100000 || $delta > 100000) {
                throw new InvalidArgumentException('Option choice name or price delta is invalid.');
            }
            $normalizedChoices[] = [
                'publicId' => $choiceId,
                'name' => $choiceName,
                'priceDelta' => $delta,
                'available' => ($choice['available'] ?? true) !== false,
                'sortOrder' => max(0, (int) ($choice['sortOrder'] ?? count($normalizedChoices))),
            ];
        }
        if ($minimum > count($normalizedChoices)) {
            throw new InvalidArgumentException('Minimum option choices cannot exceed available choices.');
        }
        $groups[] = [
            'name' => $name,
            'selectionType' => $selectionType,
            'minimumChoices' => $minimum,
            'maximumChoices' => $maximum,
            'sortOrder' => max(0, (int) ($group['sortOrder'] ?? count($groups))),
            'choices' => $normalizedChoices,
        ];
    }
    return $groups;
}

function catalog_store_options(mysqli $conn, int $menuItemId, array $groups): void
{
    $delete = $conn->prepare('DELETE FROM menu_option_groups WHERE menu_item_id=?');
    $delete->bind_param('i', $menuItemId);
    $delete->execute();
    $delete->close();
    $groupInsert = $conn->prepare('INSERT INTO menu_option_groups(menu_item_id,name,selection_type,minimum_choices,maximum_choices,sort_order) VALUES(?,?,?,?,?,?)');
    $choiceInsert = $conn->prepare('INSERT INTO menu_option_choices(option_group_id,public_id,name,price_delta,available,sort_order) VALUES(?,?,?,?,?,?)');
    foreach ($groups as $group) {
        $groupInsert->bind_param('issiii', $menuItemId, $group['name'], $group['selectionType'], $group['minimumChoices'], $group['maximumChoices'], $group['sortOrder']);
        $groupInsert->execute();
        $groupId = $groupInsert->insert_id;
        foreach ($group['choices'] as $choice) {
            $available = $choice['available'] ? 1 : 0;
            $choiceInsert->bind_param('issdii', $groupId, $choice['publicId'], $choice['name'], $choice['priceDelta'], $available, $choice['sortOrder']);
            $choiceInsert->execute();
        }
    }
    $choiceInsert->close();
    $groupInsert->close();
}

function catalog_save_item_mutation(mysqli $conn, int $ownerUserId, array $input, int $expectedVersion): array
{
    try {
        $publicId = catalog_validate_public_id($input['publicId'] ?? '');
        $name = trim((string) ($input['name'] ?? ''));
        $price = round((float) ($input['price'] ?? 0), 2);
        if ($name === '' || mb_strlen($name) > 160 || $price <= 0 || $price > 1000000) {
            return catalog_error(422, 'Menu item name and price are invalid.');
        }
        $groups = catalog_validate_options($input['optionGroups'] ?? ($input['options'] ?? null));
        $restaurant = catalog_repository_restaurant($conn, $ownerUserId);
        if ($restaurant === []) {
            return catalog_error(403, 'Restaurant ownership could not be verified.');
        }
        $existing = catalog_repository_item_by_public_id($conn, $publicId, true);
        if ($existing !== [] && (int) $existing['restaurant_id'] !== (int) $restaurant['id']) {
            return catalog_error(403, 'This menu item belongs to another Restaurant.');
        }
        if ($existing === [] && $expectedVersion !== 0) {
            return catalog_error(409, 'Menu item version is stale.');
        }
        if ($existing !== [] && (int) $existing['item_version'] !== $expectedVersion) {
            return catalog_error(409, 'Menu item version is stale.');
        }
        $available = ($input['available'] ?? true) !== false ? 1 : 0;
        if ($existing === []) {
            $insert = $conn->prepare('INSERT INTO menu_items(public_id,restaurant_id,name,price,is_available,version) VALUES(?,?,?,?,?,1)');
            $restaurantId = (int) $restaurant['id'];
            $insert->bind_param('sisdi', $publicId, $restaurantId, $name, $price, $available);
            $insert->execute();
            $menuItemId = $insert->insert_id;
            $insert->close();
            $version = 1;
        } else {
            $update = $conn->prepare('UPDATE menu_items SET name=?,price=?,is_available=?,version=version+1 WHERE id=? AND version=?');
            $menuItemId = (int) $existing['menu_item_id'];
            $update->bind_param('sdiii', $name, $price, $available, $menuItemId, $expectedVersion);
            $update->execute();
            if ($update->affected_rows !== 1) {
                $update->close();
                return catalog_error(409, 'Menu item changed. Refresh before retrying.');
            }
            $update->close();
            $version = $expectedVersion + 1;
        }
        catalog_store_options($conn, $menuItemId, $groups);
        return catalog_success(['publicId' => $publicId, 'version' => $version, 'available' => (bool) $available]);
    } catch (InvalidArgumentException $exception) {
        return catalog_error(422, $exception->getMessage());
    }
}

function catalog_save_item(mysqli $conn, int $ownerUserId, array $input, int $expectedVersion): array
{
    $conn->begin_transaction();
    try {
        $result = catalog_save_item_mutation($conn, $ownerUserId, $input, $expectedVersion);
        $conn->commit();
        return $result;
    } catch (Throwable $exception) {
        $conn->rollback();
        return catalog_error(500, 'Menu item could not be saved.', ['reason' => $exception->getMessage()]);
    }
}

function catalog_set_item_availability_mutation(mysqli $conn, int $ownerUserId, array $input, int $expectedVersion): array
{
    try {
        $publicId = catalog_validate_public_id($input['publicId'] ?? '');
        $restaurant = catalog_repository_restaurant($conn, $ownerUserId);
        $existing = catalog_repository_item_by_public_id($conn, $publicId, true);
        if ($restaurant === [] || $existing === [] || (int) $existing['restaurant_id'] !== (int) $restaurant['id']) {
            return catalog_error(403, 'This menu item belongs to another Restaurant.');
        }
        if ((int) $existing['item_version'] !== $expectedVersion) {
            return catalog_error(409, 'Menu item version is stale.');
        }
        $available = ($input['available'] ?? false) !== false ? 1 : 0;
        $update = $conn->prepare('UPDATE menu_items SET is_available=?,version=version+1 WHERE id=? AND version=?');
        $itemId = (int) $existing['menu_item_id'];
        $update->bind_param('iii', $available, $itemId, $expectedVersion);
        $update->execute();
        $affected = $update->affected_rows;
        $update->close();
        if ($affected !== 1) {
            return catalog_error(409, 'Menu item changed. Refresh before retrying.');
        }
        return catalog_success(['publicId' => $publicId, 'version' => $expectedVersion + 1, 'available' => (bool) $available]);
    } catch (InvalidArgumentException $exception) {
        return catalog_error(422, $exception->getMessage());
    }
}

function catalog_save_profile_mutation(mysqli $conn, int $ownerUserId, array $input, int $expectedVersion): array
{
    $restaurant = catalog_repository_restaurant($conn, $ownerUserId);
    if ($restaurant === []) {
        return catalog_error(403, 'Restaurant ownership could not be verified.');
    }
    if ((int) $restaurant['version'] !== $expectedVersion) {
        return catalog_error(409, 'Restaurant profile version is stale.');
    }
    $name = trim((string) ($input['name'] ?? $restaurant['name']));
    if ($name === '' || mb_strlen($name) > 160) {
        return catalog_error(422, 'Restaurant name is invalid.');
    }
    $cuisine = mb_substr(trim((string) ($input['cuisine'] ?? $restaurant['cuisine'])), 0, 100);
    $address = mb_substr(trim((string) ($input['address'] ?? $restaurant['address'])), 0, 500);
    $city = mb_substr(trim((string) ($input['city'] ?? $restaurant['city'])), 0, 100);
    $phone = mb_substr(trim((string) ($input['phone'] ?? $restaurant['phone'])), 0, 40);
    $latitude = array_key_exists('latitude', $input) ? (is_numeric($input['latitude']) ? (float) $input['latitude'] : null) : ($restaurant['latitude'] === null ? null : (float) $restaurant['latitude']);
    $longitude = array_key_exists('longitude', $input) ? (is_numeric($input['longitude']) ? (float) $input['longitude'] : null) : ($restaurant['longitude'] === null ? null : (float) $restaurant['longitude']);
    if ($latitude !== null && ($latitude < -90 || $latitude > 90) || $longitude !== null && ($longitude < -180 || $longitude > 180)) {
        return catalog_error(422, 'Restaurant coordinates are invalid.');
    }
    $update = $conn->prepare('UPDATE restaurants SET name=?,cuisine=?,address=?,city=?,phone=?,latitude=?,longitude=?,version=version+1 WHERE id=? AND version=?');
    $restaurantId = (int) $restaurant['id'];
    $latitudeValue = $latitude === null ? null : (string) $latitude;
    $longitudeValue = $longitude === null ? null : (string) $longitude;
    $update->bind_param('sssssssii', $name, $cuisine, $address, $city, $phone, $latitudeValue, $longitudeValue, $restaurantId, $expectedVersion);
    $update->execute();
    $affected = $update->affected_rows;
    $update->close();
    if ($affected !== 1) {
        return catalog_error(409, 'Restaurant profile changed. Refresh before retrying.');
    }
    return catalog_success(['restaurantId' => $restaurantId, 'version' => $expectedVersion + 1]);
}

function catalog_save_operations_mutation(mysqli $conn, int $ownerUserId, array $input, int $expectedVersion): array
{
    $restaurant = catalog_repository_restaurant($conn, $ownerUserId);
    if ($restaurant === []) {
        return catalog_error(403, 'Restaurant ownership could not be verified.');
    }
    if ((int) $restaurant['version'] !== $expectedVersion) {
        return catalog_error(409, 'Restaurant operations version is stale.');
    }
    $accepting = ($input['acceptingOrders'] ?? true) !== false ? 1 : 0;
    $weekly = $input['weeklyHours'] ?? [];
    $special = $input['specialHours'] ?? [];
    if (!is_array($weekly) || !is_array($special)) {
        return catalog_error(422, 'Restaurant hours must be arrays.');
    }
    $normalizedWeekly = [];
    foreach ($weekly as $row) {
        $weekday = (int) ($row['weekday'] ?? -1);
        $opens = isset($row['opensAt']) ? (string) $row['opensAt'] : null;
        $closes = isset($row['closesAt']) ? (string) $row['closesAt'] : null;
        if (!is_array($row) || $weekday < 0 || $weekday > 6 || ($opens !== null && !preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $opens)) || ($closes !== null && !preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $closes))) {
            return catalog_error(422, 'Weekly hours are invalid.');
        }
        $normalizedWeekly[] = [$weekday, $opens, $closes, ($row['isClosed'] ?? false) ? 1 : 0];
    }
    $normalizedSpecial = [];
    foreach ($special as $row) {
        $date = (string) ($row['date'] ?? '');
        $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $opens = isset($row['opensAt']) ? (string) $row['opensAt'] : null;
        $closes = isset($row['closesAt']) ? (string) $row['closesAt'] : null;
        if (!is_array($row) || !$parsedDate || $parsedDate->format('Y-m-d') !== $date || ($opens !== null && !preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $opens)) || ($closes !== null && !preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $closes))) {
            return catalog_error(422, 'Special hours are invalid.');
        }
        $normalizedSpecial[] = [$date, $opens, $closes, ($row['isClosed'] ?? false) ? 1 : 0, mb_substr((string) ($row['note'] ?? ''), 0, 255)];
    }
    $restaurantId = (int) $restaurant['id'];
    $update = $conn->prepare('UPDATE restaurants SET accepting_orders=?,version=version+1 WHERE id=? AND version=?');
    $update->bind_param('iii', $accepting, $restaurantId, $expectedVersion);
    $update->execute();
    $affected = $update->affected_rows;
    $update->close();
    if ($affected !== 1) {
        return catalog_error(409, 'Restaurant operations changed. Refresh before retrying.');
    }
    $deleteWeekly = $conn->prepare('DELETE FROM restaurant_weekly_hours WHERE restaurant_id=?');
    $deleteWeekly->bind_param('i', $restaurantId);
    $deleteWeekly->execute();
    $deleteWeekly->close();
    $weeklyInsert = $conn->prepare('INSERT INTO restaurant_weekly_hours(restaurant_id,weekday,opens_at,closes_at,is_closed) VALUES(?,?,?,?,?)');
    foreach ($normalizedWeekly as [$weekday, $opens, $closes, $closed]) {
        $weeklyInsert->bind_param('iissi', $restaurantId, $weekday, $opens, $closes, $closed);
        $weeklyInsert->execute();
    }
    $weeklyInsert->close();
    $deleteSpecial = $conn->prepare('DELETE FROM restaurant_special_hours WHERE restaurant_id=?');
    $deleteSpecial->bind_param('i', $restaurantId);
    $deleteSpecial->execute();
    $deleteSpecial->close();
    $specialInsert = $conn->prepare('INSERT INTO restaurant_special_hours(restaurant_id,special_date,opens_at,closes_at,is_closed,note) VALUES(?,?,?,?,?,?)');
    foreach ($normalizedSpecial as [$date, $opens, $closes, $closed, $note]) {
        $specialInsert->bind_param('isssis', $restaurantId, $date, $opens, $closes, $closed, $note);
        $specialInsert->execute();
    }
    $specialInsert->close();
    return catalog_success(['restaurantId' => $restaurantId, 'version' => $expectedVersion + 1, 'acceptingOrders' => (bool) $accepting]);
}

function catalog_save_profile(mysqli $conn, int $ownerUserId, array $input, int $expectedVersion): array
{
    $conn->begin_transaction();
    try {
        $result = catalog_save_profile_mutation($conn, $ownerUserId, $input, $expectedVersion);
        $conn->commit();
        return $result;
    } catch (Throwable $exception) {
        $conn->rollback();
        return catalog_error(500, 'Restaurant profile could not be saved.', ['reason' => $exception->getMessage()]);
    }
}

function catalog_save_operations(mysqli $conn, int $ownerUserId, array $input, int $expectedVersion): array
{
    $conn->begin_transaction();
    try {
        $result = catalog_save_operations_mutation($conn, $ownerUserId, $input, $expectedVersion);
        $conn->commit();
        return $result;
    } catch (Throwable $exception) {
        $conn->rollback();
        return catalog_error(500, 'Restaurant operations could not be saved.', ['reason' => $exception->getMessage()]);
    }
}

function catalog_for_customer(mysqli $conn, array $filters): array
{
    $filters['q'] = mb_substr(trim((string) ($filters['q'] ?? '')), 0, 80);
    $filters['restaurant'] = mb_substr(trim((string) ($filters['restaurant'] ?? '')), 0, 120);
    return catalog_repository_customer_items($conn, $filters);
}

function catalog_for_restaurant(mysqli $conn, int $ownerUserId): array
{
    $restaurant = catalog_repository_restaurant($conn, $ownerUserId);
    if ($restaurant === []) {
        return ['ok' => false, 'status' => 403, 'message' => 'Restaurant ownership could not be verified.'];
    }
    $restaurantId = (int) $restaurant['id'];
    return [
        'ok' => true,
        'status' => 200,
        'restaurant' => [
            'id' => $restaurantId, 'name' => (string) $restaurant['name'], 'cuisine' => (string) $restaurant['cuisine'],
            'address' => (string) $restaurant['address'], 'city' => (string) $restaurant['city'], 'phone' => (string) $restaurant['phone'],
            'status' => (string) $restaurant['status'], 'acceptingOrders' => (bool) $restaurant['accepting_orders'],
            'latitude' => $restaurant['latitude'] === null ? null : (float) $restaurant['latitude'],
            'longitude' => $restaurant['longitude'] === null ? null : (float) $restaurant['longitude'],
            'version' => (int) $restaurant['version'],
        ],
        'items' => catalog_repository_restaurant_items($conn, $ownerUserId),
        'weeklyHours' => catalog_repository_weekly_hours($conn, $restaurantId),
        'specialHours' => catalog_repository_special_hours($conn, $restaurantId),
    ];
}

function catalog_execute_action(mysqli $conn, int $ownerUserId, string $action, array $payload, int $expectedVersion, string $idempotencyKey): array
{
    $conn->begin_transaction();
    try {
        $result = match ($action) {
            'save_item' => catalog_save_item_mutation($conn, $ownerUserId, $payload, $expectedVersion),
            'set_item_availability' => catalog_set_item_availability_mutation($conn, $ownerUserId, $payload, $expectedVersion),
            'save_profile' => catalog_save_profile_mutation($conn, $ownerUserId, $payload, $expectedVersion),
            'save_operations' => catalog_save_operations_mutation($conn, $ownerUserId, $payload, $expectedVersion),
            default => catalog_error(422, 'Unsupported catalog action.'),
        };
        savora_idempotency_store($conn, $ownerUserId, $idempotencyKey, $action, savora_idempotency_hash($action, $payload), $result);
        $conn->commit();
        return $result;
    } catch (Throwable $exception) {
        $conn->rollback();
        return catalog_error(500, 'Catalog operation could not be completed.', ['reason' => $exception->getMessage()]);
    }
}
