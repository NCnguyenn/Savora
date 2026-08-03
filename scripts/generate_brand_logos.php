<?php
declare(strict_types=1);

$output = dirname(__DIR__) . '/assets/images/brands';
if (!is_dir($output) && !mkdir($output, 0775, true) && !is_dir($output)) {
    throw new RuntimeException('Unable to create brand asset directory.');
}

$brands = [
    'restaurant-placeholder' => ['Restaurant', '#0a4a38', '<circle cx="48" cy="48" r="22"/><path d="M35 50h26M39 41h18M42 59h12"/>'],
    'lotus-kitchen' => ['Lotus Kitchen', '#c9573f', '<path d="M48 65C28 54 27 35 48 22c21 13 20 32 0 43Z"/><path d="M48 65C36 48 39 34 48 22M48 65c12-17 9-31 0-43"/>'],
    'saigon-ember-grill' => ['Saigon Ember Grill', '#e05d3f', '<path d="M50 18c7 17-10 18-2 31 4-8 12-10 14-18 12 17 7 38-14 46-22-8-24-31-8-43 1 10 6 12 10 15-2-10 5-17 0-31Z"/>'],
    'hoi-an-garden' => ['Hoi An Garden', '#d79728', '<path d="M32 34h32l-4 35H36l-4-35Zm5-9h22l5 9H32l5-9Zm11-8v8M38 45h20M39 57h18"/>'],
    'mekong-bowl-tea' => ['Mekong Bowl and Tea', '#178a78', '<path d="M20 39c9-8 19-8 28 0s19 8 28 0M20 52c9-8 19-8 28 0s19 8 28 0M27 66h42"/>'],
    'tokyo-kumo' => ['Tokyo Kumo', '#416b91', '<path d="M27 62h42a13 13 0 0 0 0-26 20 20 0 0 0-37-5A16 16 0 0 0 27 62Z"/><circle cx="65" cy="25" r="7"/>'],
    'roma-verde' => ['Roma Verde', '#5e8f4d', '<path d="M25 68c29 1 43-17 47-45-29 4-47 18-47 45Zm5-5c12-13 23-22 37-33M44 50l-2-14M54 41l11 2"/>'],
];

foreach ($brands as $slug => [$title, $accent, $mark]) {
    $safeTitle = htmlspecialchars($title, ENT_QUOTES | ENT_XML1, 'UTF-8');
    $svg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 320 96" role="img" aria-labelledby="title">
  <title id="title">{$safeTitle} logo</title>
  <rect width="320" height="96" rx="24" fill="#fffdf7"/>
  <g fill="none" stroke="{$accent}" stroke-width="5" stroke-linecap="round" stroke-linejoin="round">{$mark}</g>
  <text x="92" y="56" fill="#17342b" font-family="Georgia, serif" font-size="25" font-weight="700">{$safeTitle}</text>
</svg>
SVG;
    if (file_put_contents($output . '/' . $slug . '.svg', $svg . PHP_EOL) === false) {
        throw new RuntimeException("Unable to write {$slug}.svg");
    }
}
