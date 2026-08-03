<?php
declare(strict_types=1);

function catalog_demo_seed(mysqli $conn): void
{
    $path = __DIR__ . '/../database/seeds/catalog_demo_data.json';
    $raw = file_get_contents($path);
    $restaurants = json_decode((string) $raw, true);
    if (!is_array($restaurants) || count($restaurants) !== 6) {
        throw new RuntimeException('Rich catalog demo data must contain exactly six restaurants.');
    }

    $conn->begin_transaction();
    try {
        $userInsert = $conn->prepare("INSERT IGNORE INTO users (username,password,role,full_name,email,status) VALUES (?,?,'restaurant',?,?, 'active')");
        $userLookup = $conn->prepare('SELECT id FROM users WHERE username=? AND role=\'restaurant\' LIMIT 1');
        $restaurantLookup = $conn->prepare('SELECT id FROM restaurants WHERE demo_key=? LIMIT 1');
        $ownerLookup = $conn->prepare('SELECT id FROM restaurants WHERE owner_user_id=? LIMIT 1');
        $restaurantInsert = $conn->prepare("INSERT INTO restaurants (owner_user_id,demo_key,public_id,name,cuisine,description,slogan,hero_image,logo_path,address,city,phone,rating,cancellation_rate,latitude,longitude,status,accepting_orders) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'active',1)");
        $restaurantUpdate = $conn->prepare("UPDATE restaurants SET demo_key=?,public_id=?,name=?,cuisine=?,description=?,slogan=?,hero_image=?,logo_path=?,address=?,city=?,phone=?,rating=?,cancellation_rate=?,latitude=?,longitude=?,status='active',accepting_orders=1,version=version+1 WHERE id=?");
        $menuExisting = $conn->prepare('SELECT public_id FROM menu_items WHERE restaurant_id=?');
        $menuDelete = $conn->prepare('DELETE FROM menu_items WHERE restaurant_id=? AND public_id=?');
        $menu = $conn->prepare('INSERT INTO menu_items (public_id,restaurant_id,name,price,description,image_path,category,item_type,prep_time_minutes,calories,dietary_tags,allergens,ingredients,sort_order,is_available) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,1) ON DUPLICATE KEY UPDATE restaurant_id=VALUES(restaurant_id),name=VALUES(name),price=VALUES(price),description=VALUES(description),image_path=VALUES(image_path),category=VALUES(category),item_type=VALUES(item_type),prep_time_minutes=VALUES(prep_time_minutes),calories=VALUES(calories),dietary_tags=VALUES(dietary_tags),allergens=VALUES(allergens),ingredients=VALUES(ingredients),sort_order=VALUES(sort_order),is_available=1,version=version+1');
        $hours = $conn->prepare('INSERT INTO restaurant_weekly_hours(restaurant_id,weekday,opens_at,closes_at,is_closed) VALUES(?,?,?,?,0) ON DUPLICATE KEY UPDATE opens_at=VALUES(opens_at),closes_at=VALUES(closes_at),is_closed=0,version=version+1');

        foreach ($restaurants as $restaurant) {
            $demoKey = trim((string) ($restaurant['demo_key'] ?? ''));
            $ownerUsername = trim((string) ($restaurant['owner_username'] ?? ''));
            if ($demoKey === '' || $ownerUsername === '') {
                throw new RuntimeException('Every rich catalog restaurant requires a demo key and owner username.');
            }
            $ownerEmail = $ownerUsername . '@savora.test';
            $ownerName = (string) $restaurant['name'] . ' Owner';
            $hash = password_hash('123456', PASSWORD_DEFAULT);
            $userInsert->bind_param('ssss', $ownerUsername, $hash, $ownerName, $ownerEmail);
            $userInsert->execute();
            $userLookup->bind_param('s', $ownerUsername);
            $userLookup->execute();
            $owner = $userLookup->get_result()->fetch_assoc();
            $ownerId = (int) ($owner['id'] ?? 0);
            if ($ownerId === 0) {
                throw new RuntimeException("Unable to resolve catalog demo owner {$ownerUsername}.");
            }

            $restaurantLookup->bind_param('s', $demoKey);
            $restaurantLookup->execute();
            $restaurantId = (int) ($restaurantLookup->get_result()->fetch_assoc()['id'] ?? 0);
            if ($restaurantId === 0) {
                $ownerLookup->bind_param('i', $ownerId);
                $ownerLookup->execute();
                $restaurantId = (int) ($ownerLookup->get_result()->fetch_assoc()['id'] ?? 0);
            }

            $name = (string) $restaurant['name'];
            $cuisine = (string) $restaurant['cuisine'];
            $description = (string) $restaurant['description'];
            $publicId = (string) $restaurant['public_id'];
            $slogan = (string) $restaurant['slogan'];
            $heroImage = (string) $restaurant['hero_image'];
            $logoPath = (string) $restaurant['logo_path'];
            $address = (string) $restaurant['address'];
            $city = (string) $restaurant['city'];
            $phone = (string) $restaurant['phone'];
            $rating = (float) $restaurant['rating'];
            $cancellationRate = (float) $restaurant['cancellation_rate'];
            $latitude = (float) $restaurant['latitude'];
            $longitude = (float) $restaurant['longitude'];
            $opensAt = (string) $restaurant['opens_at'];
            $closesAt = (string) $restaurant['closes_at'];
            if ($restaurantId === 0) {
                $restaurantInsert->bind_param('isssssssssssdddd', $ownerId, $demoKey, $publicId, $name, $cuisine, $description, $slogan, $heroImage, $logoPath, $address, $city, $phone, $rating, $cancellationRate, $latitude, $longitude);
                $restaurantInsert->execute();
                $restaurantId = (int) $conn->insert_id;
            } else {
                $restaurantUpdate->bind_param('sssssssssssddddi', $demoKey, $publicId, $name, $cuisine, $description, $slogan, $heroImage, $logoPath, $address, $city, $phone, $rating, $cancellationRate, $latitude, $longitude, $restaurantId);
                $restaurantUpdate->execute();
            }
            if ($restaurantId === 0) {
                throw new RuntimeException("Unable to seed catalog demo restaurant {$demoKey}.");
            }
            for ($weekday = 0; $weekday < 7; $weekday++) {
                $hours->bind_param('iiss', $restaurantId, $weekday, $opensAt, $closesAt);
                $hours->execute();
            }

            $items = is_array($restaurant['items'] ?? null) ? $restaurant['items'] : [];
            $keep = [];
            foreach ($items as $item) {
                $keep['demo-' . $demoKey . '-' . (string) $item['slug']] = true;
            }
            $menuExisting->bind_param('i', $restaurantId);
            $menuExisting->execute();
            foreach ($menuExisting->get_result()->fetch_all(MYSQLI_ASSOC) as $existing) {
                $existingPublicId = (string) $existing['public_id'];
                if (!isset($keep[$existingPublicId]) && (ctype_digit($existingPublicId) || str_starts_with($existingPublicId, 'demo-'))) {
                    $menuDelete->bind_param('is', $restaurantId, $existingPublicId);
                    $menuDelete->execute();
                }
            }

            foreach ($items as $item) {
                $menuPublicId = 'demo-' . $demoKey . '-' . (string) $item['slug'];
                $itemName = (string) $item['name'];
                $itemDescription = (string) $item['description'];
                $imagePath = (string) ($item['image_path'] ?? ('assets/images/catalog/' . $menuPublicId . '.jpg'));
                $category = (string) $item['category'];
                $itemType = (string) ($item['type'] ?? 'food');
                if (!in_array($itemType, ['food', 'drink'], true)) {
                    throw new RuntimeException("Invalid item type for {$menuPublicId}.");
                }
                $price = (float) $item['price'];
                $prepTime = (int) $item['prep_time_minutes'];
                $calories = (int) $item['calories'];
                $dietaryTags = json_encode(array_values($item['dietary_tags'] ?? []), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                $allergens = json_encode(array_values($item['allergens'] ?? []), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                $ingredients = json_encode(array_values($item['ingredients'] ?? []), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                $sortOrder = (int) $item['sort_order'];
                $menu->bind_param('sisdssssiisssi', $menuPublicId, $restaurantId, $itemName, $price, $itemDescription, $imagePath, $category, $itemType, $prepTime, $calories, $dietaryTags, $allergens, $ingredients, $sortOrder);
                $menu->execute();
            }
        }
        $userInsert->close();
        $userLookup->close();
        $restaurantLookup->close();
        $ownerLookup->close();
        $restaurantInsert->close();
        $restaurantUpdate->close();
        $menuExisting->close();
        $menuDelete->close();
        $menu->close();
        $hours->close();
        $conn->commit();
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }
}
