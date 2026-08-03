<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$path = $root . '/database/seeds/catalog_demo_data.json';
if (!is_file($path)) {
    fwrite(STDERR, "Missing catalog demo data file.\n");
    exit(1);
}

$raw = file_get_contents($path);
$data = json_decode((string) $raw, true);
if (!is_array($data) || count($data) !== 6) {
    fwrite(STDERR, "Catalog demo data must contain exactly six restaurants.\n");
    exit(1);
}
if (preg_match('/[^\x00-\x7F]/', (string) $raw)) {
    fwrite(STDERR, "Catalog demo data must stay English-only.\n");
    exit(1);
}

$vietnamese = 0;
$international = 0;
$publicIds = [];
foreach ($data as $restaurant) {
    $items = $restaurant['items'] ?? [];
    if (!is_array($items) || count($items) !== 8) {
        fwrite(STDERR, "Every demo restaurant must contain exactly eight menu items.\n");
        exit(1);
    }
    if (($restaurant['cuisine'] ?? '') === 'Vietnamese') $vietnamese++;
    else $international++;
    $types = array_count_values(array_column($items, 'type'));
    if (($types['food'] ?? 0) !== 6 || ($types['beverage'] ?? 0) !== 2) {
        fwrite(STDERR, "Every demo restaurant must contain six foods and two beverages.\n");
        exit(1);
    }
    foreach ($items as $item) {
        $publicId = 'demo-' . $restaurant['demo_key'] . '-' . $item['slug'];
        if (isset($publicIds[$publicId])) {
            fwrite(STDERR, "Demo public IDs must be unique.\n");
            exit(1);
        }
        $publicIds[$publicId] = true;
        foreach (['slug', 'name', 'description', 'category', 'ingredients'] as $field) {
            $value = $item[$field] ?? '';
            $missing = is_array($value) ? count($value) === 0 : trim((string) $value) === '';
            if ($missing) {
                fwrite(STDERR, "Rich menu field {$field} is required.\n");
                exit(1);
            }
        }
    }
}
if ($vietnamese !== 4 || $international !== 2) {
    fwrite(STDERR, "Catalog mix must be four Vietnamese and two international restaurants.\n");
    exit(1);
}

require_once $root . '/lib/catalog_demo_seed.php';
if (!function_exists('catalog_demo_seed')) {
    fwrite(STDERR, "catalog_demo_seed must be available.\n");
    exit(1);
}

echo "catalog_demo_seed contract passed\n";
