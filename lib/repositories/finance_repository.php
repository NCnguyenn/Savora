<?php
declare(strict_types=1);
function finance_repository_one(mysqli $conn, string $sql, string $types = '', array $params = []): array { $stmt=$conn->prepare($sql); if($types!=='')$stmt->bind_param($types,...$params); $stmt->execute(); $row=$stmt->get_result()->fetch_assoc()?:[]; $stmt->close(); return $row; }
function finance_repository_case(mysqli $conn, int $caseId, bool $forUpdate=false): array { $sql='SELECT * FROM support_cases WHERE id=? LIMIT 1'; if($forUpdate)$sql.=' FOR UPDATE'; return finance_repository_one($conn,$sql,'i',[$caseId]); }
function finance_repository_payment(mysqli $conn, int $orderId, bool $forUpdate=false): array { $sql='SELECT * FROM payments WHERE order_id=? LIMIT 1'; if($forUpdate)$sql.=' FOR UPDATE'; return finance_repository_one($conn,$sql,'i',[$orderId]); }
function finance_repository_refunded(mysqli $conn, int $orderId): float { $row=finance_repository_one($conn,'SELECT COALESCE(SUM(amount),0) AS total FROM refunds WHERE order_id=?','i',[$orderId]); return (float)($row['total']??0); }
function finance_repository_payout(mysqli $conn, int $payoutId, bool $forUpdate=false): array { $sql='SELECT * FROM payouts WHERE id=? LIMIT 1'; if($forUpdate)$sql.=' FOR UPDATE'; return finance_repository_one($conn,$sql,'i',[$payoutId]); }
function finance_repository_cod(mysqli $conn, int $reconciliationId, bool $forUpdate=false): array { $sql='SELECT * FROM cod_reconciliations WHERE id=? LIMIT 1'; if($forUpdate)$sql.=' FOR UPDATE'; return finance_repository_one($conn,$sql,'i',[$reconciliationId]); }

function finance_repository_rows(mysqli $conn, string $sql, string $types = '', array $params = []): array
{
    $statement = $conn->prepare($sql);
    if ($types !== '') $statement->bind_param($types, ...$params);
    $statement->execute();
    $rows = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();
    return $rows;
}

function finance_repository_normalize_filters(array $filters): array
{
    $today = new DateTimeImmutable('today');
    $fromInput = (string) ($filters['from'] ?? '');
    $toInput = (string) ($filters['to'] ?? '');
    $from = DateTimeImmutable::createFromFormat('!Y-m-d', $fromInput) ?: $today->modify('-30 days');
    $to = DateTimeImmutable::createFromFormat('!Y-m-d', $toInput) ?: $today;
    if ($from > $to) [$from, $to] = [$to, $from];
    $type = in_array((string) ($filters['type'] ?? ''), ['sale', 'refund'], true) ? (string) $filters['type'] : '';
    return ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d'), 'type' => $type];
}

function finance_repository_restaurant_id(mysqli $conn, int $ownerUserId): int
{
    return (int) (finance_repository_one($conn, 'SELECT id FROM restaurants WHERE owner_user_id=? LIMIT 1', 'i', [$ownerUserId])['id'] ?? 0);
}

function finance_repository_report(mysqli $conn, int $restaurantId, array $rawFilters = []): array
{
    if ($restaurantId <= 0) throw new InvalidArgumentException('Restaurant scope is required.');
    $filters = finance_repository_normalize_filters($rawFilters);
    $where = "le.party_type='restaurant' AND le.party_id=? AND DATE(le.created_at) BETWEEN ? AND ? AND le.entry_type IN ('order_sale','refund','payout_adjustment')";
    $types = 'iss';
    $params = [$restaurantId, $filters['from'], $filters['to']];
    if ($filters['type'] === 'sale') $where .= " AND le.entry_type='order_sale'";
    if ($filters['type'] === 'refund') $where .= " AND le.entry_type='refund'";
    $rows = finance_repository_rows($conn, "SELECT le.reference_code,le.order_id,o.reference_code AS order_reference,le.entry_type,le.gross_amount,le.fee_amount,le.net_amount,le.payment_method,le.status,le.created_at FROM ledger_entries le LEFT JOIN orders o ON o.id=le.order_id WHERE {$where} ORDER BY le.created_at DESC,le.id DESC LIMIT 1000", $types, $params);
    $transactions = [];
    $grossSales = 0.0; $platformFees = 0.0; $refundTotal = 0.0; $netRevenue = 0.0; $completedOrders = 0; $refundedOrders = 0;
    foreach ($rows as $row) {
        $entryType = (string) $row['entry_type'];
        $gross = round((float) $row['gross_amount'], 2);
        $fee = round((float) $row['fee_amount'], 2);
        $net = round((float) $row['net_amount'], 2);
        if ($entryType === 'order_sale') { $grossSales += max(0, $gross); $platformFees += max(0, $fee); if ((string) $row['status'] === 'completed') $completedOrders++; }
        if ($entryType === 'refund') { $refundTotal += min(0, $gross); $refundedOrders++; }
        $netRevenue += $net;
        $transactions[] = ['reference' => (string) $row['reference_code'], 'orderId' => (int) ($row['order_id'] ?? 0), 'order' => (string) ($row['order_reference'] ?? ''), 'type' => $entryType === 'refund' ? 'refund' : 'sale', 'amount' => $gross, 'fee' => $fee, 'net' => $net, 'createdAt' => (string) $row['created_at'], 'status' => (string) $row['status'], 'paymentMethod' => (string) ($row['payment_method'] ?? '')];
    }
    $payouts = finance_repository_rows($conn, "SELECT id,reference_code,amount,status,scheduled_at,paid_at,version FROM payouts WHERE party_type='restaurant' AND party_id=? ORDER BY scheduled_at IS NULL,scheduled_at,id", 'i', [$restaurantId]);
    $documents = [];
    foreach ($transactions as $transaction) {
        $prefix = $transaction['type'] === 'refund' ? 'CRN-' : 'INV-';
        $documentId = $prefix . ($transaction['order'] !== '' ? $transaction['order'] : $transaction['reference']);
        $documents[] = ['id' => $documentId, 'kind' => $transaction['type'] === 'refund' ? 'Refund credit note' : 'Order invoice', 'order' => $transaction['order'] ?: $transaction['reference'], 'issued' => $transaction['createdAt'], 'amount' => $transaction['amount'], 'status' => 'available', 'printUrl' => 'restaurant_invoice_print.php?document=' . rawurlencode($documentId) . '&from=' . rawurlencode($filters['from']) . '&to=' . rawurlencode($filters['to'])];
    }
    $statementId = 'STMT-' . $filters['from'] . '-' . $filters['to'];
    $documents[] = ['id' => $statementId, 'kind' => 'Payout statement', 'order' => $filters['from'] . ' to ' . $filters['to'], 'issued' => $filters['to'], 'amount' => round($netRevenue, 2), 'status' => $payouts === [] ? 'available' : (string) $payouts[0]['status'], 'printUrl' => 'restaurant_invoice_print.php?document=' . rawurlencode($statementId) . '&from=' . rawurlencode($filters['from']) . '&to=' . rawurlencode($filters['to'])];
    return ['filters' => $filters, 'kpis' => ['grossSales' => round($grossSales, 2), 'platformFees' => round($platformFees, 2), 'refunds' => round($refundTotal, 2), 'netRevenue' => round($netRevenue, 2), 'completedOrders' => $completedOrders, 'refundedOrders' => $refundedOrders, 'averageOrderValue' => $completedOrders > 0 ? round($grossSales / $completedOrders, 2) : 0.0], 'transactions' => $transactions, 'payouts' => $payouts, 'documents' => $documents];
}

function finance_repository_document(mysqli $conn, int $restaurantId, string $documentId, array $filters = []): array
{
    $report = finance_repository_report($conn, $restaurantId, $filters);
    foreach ($report['documents'] as $document) if ((string) $document['id'] === $documentId) return $document;
    return [];
}
