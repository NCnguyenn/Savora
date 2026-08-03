<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/request_security.php';
require_once __DIR__ . '/lib/repositories/finance_repository.php';

$actor = savora_request_actor($conn, ['restaurant']);
$restaurantId = finance_repository_restaurant_id($conn, (int) $actor['userId']);
$document = finance_repository_document($conn, $restaurantId, (string) ($_GET['document'] ?? ''), ['from' => $_GET['from'] ?? '', 'to' => $_GET['to'] ?? '']);
if ($document === []) { http_response_code(404); echo 'Financial document not found.'; exit; }
$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?php echo $escape($document['id']); ?> | Savora</title><style>body{font-family:system-ui,sans-serif;max-width:760px;margin:40px auto;padding:0 24px;color:#17352e}header{display:flex;justify-content:space-between;border-bottom:2px solid #073b2b;padding-bottom:16px}dl{display:grid;grid-template-columns:180px 1fr;gap:12px;margin-top:32px}dt{font-weight:700}dd{margin:0}.print-action{margin-top:32px;padding:10px 16px}@media print{.print-action{display:none}body{margin:0;max-width:none}}</style></head>
<body data-server-financial-document>
<header><div><strong>Savora</strong><h1><?php echo $escape($document['kind']); ?></h1></div><strong><?php echo $escape($document['id']); ?></strong></header>
<dl><dt>Order or period</dt><dd><?php echo $escape($document['order']); ?></dd><dt>Issued</dt><dd><?php echo $escape($document['issued']); ?></dd><dt>Amount</dt><dd><?php echo number_format((float) $document['amount'], 2); ?></dd><dt>Status</dt><dd><?php echo $escape($document['status']); ?></dd></dl>
<p>This printable document is generated from the authenticated restaurant ledger on the Savora server.</p><button class="print-action" type="button" data-print-document>Print</button><script>document.querySelector('[data-print-document]').addEventListener('click',()=>window.print());</script>
</body></html>
