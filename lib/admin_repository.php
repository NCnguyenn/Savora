<?php
declare(strict_types=1);

function admin_rows(mysqli $conn, string $sql, string $types = '', array $params = []): array
{
    $stmt = $conn->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function admin_one(mysqli $conn, string $sql, string $types = '', array $params = []): array
{
    return admin_rows($conn, $sql, $types, $params)[0] ?? [];
}

function admin_valid_date(mixed $value, string $fallback): string
{
    if (!is_string($value)) {
        return $fallback;
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date && $date->format('Y-m-d') === $value ? $value : $fallback;
}

function admin_account_rows(mysqli $conn, array $filters): array
{
    $role = $filters['role'] ?? null;
    if (is_string($role) && in_array($role, ['customer', 'restaurant', 'driver', 'admin'], true)) {
        return admin_rows($conn, 'SELECT id, username, role, full_name, email, phone, status, last_login_at, created_at, version FROM users WHERE role = ? ORDER BY created_at DESC', 's', [$role]);
    }
    return admin_rows($conn, 'SELECT id, username, role, full_name, email, phone, status, last_login_at, created_at, version FROM users ORDER BY created_at DESC');
}

function admin_overview_data(mysqli $conn): array
{
    $orderMetrics = admin_one($conn, "SELECT COUNT(*) AS total_orders, COALESCE(SUM(total), 0) AS gross_order_value, SUM(status IN ('pending','accepted','preparing','ready_for_pickup','assigned','picked_up','in_transit')) AS active_orders, SUM(status = 'cancelled') AS cancelled_orders FROM orders WHERE placed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
    $revenue = admin_one($conn, "SELECT COALESCE(SUM(fee_amount), 0) AS platform_revenue FROM ledger_entries WHERE status = 'completed' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
    $restaurantPending = admin_one($conn, "SELECT COUNT(*) AS total FROM restaurant_applications WHERE status IN ('pending','in_review','changes_requested')");
    $driverPending = admin_one($conn, "SELECT COUNT(*) AS total FROM driver_applications WHERE status IN ('pending','in_review','changes_requested')");

    $liveOrders = admin_rows($conn, "SELECT o.id, o.reference_code, o.status, o.total, o.payment_method, o.placed_at, r.name AS restaurant_name, u.full_name AS customer_name, d.assigned_driver_user_id, du.full_name AS driver_name FROM orders o JOIN restaurants r ON r.id = o.restaurant_id JOIN users u ON u.id = o.customer_user_id LEFT JOIN delivery_dispatches d ON d.order_id = o.id LEFT JOIN users du ON du.id = d.assigned_driver_user_id WHERE o.status NOT IN ('delivered','completed','cancelled','refunded') ORDER BY o.placed_at DESC LIMIT 6");
    $restaurantQueue = admin_rows($conn, "SELECT id, reference_code, restaurant_name AS applicant_name, city, status, risk_level, submitted_at, 'restaurant' AS application_type FROM restaurant_applications WHERE status IN ('pending','in_review','changes_requested') ORDER BY submitted_at LIMIT 4");
    $driverQueue = admin_rows($conn, "SELECT id, reference_code, full_name AS applicant_name, city, status, risk_level, submitted_at, 'driver' AS application_type FROM driver_applications WHERE status IN ('pending','in_review','changes_requested') ORDER BY submitted_at LIMIT 4");
    $approvalQueue = array_slice(array_merge($restaurantQueue, $driverQueue), 0, 6);
    usort($approvalQueue, static fn(array $a, array $b): int => strcmp((string) $a['submitted_at'], (string) $b['submitted_at']));

    $trend = admin_rows($conn, "SELECT DATE_FORMAT(placed_at, '%a') AS label, DATE(placed_at) AS day, COUNT(*) AS orders, COALESCE(SUM(total), 0) AS revenue FROM orders WHERE placed_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) GROUP BY DATE(placed_at), DATE_FORMAT(placed_at, '%a') ORDER BY day");
    $statusDistribution = admin_rows($conn, "SELECT status, COUNT(*) AS total FROM orders WHERE placed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) GROUP BY status ORDER BY total DESC");
    $alerts = admin_rows($conn, "SELECT id, reference_code, subject, priority, status, sla_due_at FROM support_cases WHERE status NOT IN ('resolved','closed') ORDER BY FIELD(priority, 'urgent','high','medium','low'), sla_due_at LIMIT 5");
    $activity = admin_rows($conn, "SELECT a.reference_id, a.action, a.entity_type, a.result, a.reason, a.created_at, u.full_name AS actor_name FROM audit_logs a JOIN users u ON u.id = a.actor_user_id ORDER BY a.created_at DESC LIMIT 6");

    return [
        'metrics' => array_merge($orderMetrics, $revenue, ['pending_approvals' => (int) ($restaurantPending['total'] ?? 0) + (int) ($driverPending['total'] ?? 0)]),
        'live_orders' => $liveOrders,
        'approval_queue' => $approvalQueue,
        'trend' => $trend,
        'status_distribution' => $statusDistribution,
        'alerts' => $alerts,
        'activity' => $activity,
    ];
}

function admin_analytics_data(mysqli $conn, array $filters): array
{
    $fallbackTo = date('Y-m-d');
    $fallbackFrom = date('Y-m-d', strtotime('-30 days'));
    $from = admin_valid_date($filters['from'] ?? null, $fallbackFrom);
    $to = admin_valid_date($filters['to'] ?? null, $fallbackTo);
    if ($from > $to) {
        [$from, $to] = [$to, $from];
    }

    $kpis = admin_one($conn, "SELECT COUNT(*) AS orders, COALESCE(SUM(total), 0) AS gross_order_value, ROUND(100 * SUM(status IN ('delivered','completed')) / NULLIF(COUNT(*), 0), 1) AS completion_rate, ROUND(AVG(CASE WHEN status IN ('delivered','completed') THEN TIMESTAMPDIFF(MINUTE, placed_at, updated_at) END), 0) AS average_delivery_minutes FROM orders WHERE DATE(placed_at) BETWEEN ? AND ?", 'ss', [$from, $to]);
    $trend = admin_rows($conn, "SELECT DATE(placed_at) AS day, DATE_FORMAT(placed_at, '%b %e') AS label, COUNT(*) AS orders, COALESCE(SUM(total), 0) AS revenue FROM orders WHERE DATE(placed_at) BETWEEN ? AND ? GROUP BY DATE(placed_at), DATE_FORMAT(placed_at, '%b %e') ORDER BY day", 'ss', [$from, $to]);
    $funnel = admin_rows($conn, "SELECT status, COUNT(*) AS total FROM orders WHERE DATE(placed_at) BETWEEN ? AND ? GROUP BY status ORDER BY total DESC", 'ss', [$from, $to]);
    $cancellations = admin_rows($conn, "SELECT COALESCE(NULLIF(h.reason, ''), 'Customer changed plans') AS reason, COUNT(*) AS total FROM orders o LEFT JOIN order_status_history h ON h.order_id = o.id AND h.status = 'cancelled' WHERE o.status = 'cancelled' AND DATE(o.placed_at) BETWEEN ? AND ? GROUP BY COALESCE(NULLIF(h.reason, ''), 'Customer changed plans') ORDER BY total DESC", 'ss', [$from, $to]);
    $hourly = admin_rows($conn, "SELECT HOUR(placed_at) AS hour, COUNT(*) AS total FROM orders WHERE DATE(placed_at) BETWEEN ? AND ? GROUP BY HOUR(placed_at) ORDER BY hour", 'ss', [$from, $to]);
    $restaurants = admin_rows($conn, "SELECT r.id, r.name, r.rating, r.cancellation_rate, COUNT(o.id) AS orders, COALESCE(SUM(CASE WHEN o.status IN ('delivered','completed') THEN o.total ELSE 0 END), 0) AS revenue FROM restaurants r LEFT JOIN orders o ON o.restaurant_id = r.id AND DATE(o.placed_at) BETWEEN ? AND ? GROUP BY r.id, r.name, r.rating, r.cancellation_rate ORDER BY revenue DESC LIMIT 6", 'ss', [$from, $to]);
    $drivers = admin_rows($conn, "SELECT u.id, u.full_name, d.rating, d.acceptance_rate, d.completion_rate, COUNT(v.id) AS deliveries FROM driver_profiles d JOIN users u ON u.id = d.user_id LEFT JOIN deliveries v ON v.driver_user_id = d.user_id GROUP BY u.id, u.full_name, d.rating, d.acceptance_rate, d.completion_rate ORDER BY d.completion_rate DESC LIMIT 6");
    $retention = admin_one($conn, "SELECT COUNT(*) AS active_customers, SUM(order_count > 1) AS repeat_customers, ROUND(100 * SUM(order_count > 1) / NULLIF(COUNT(*), 0), 1) AS repeat_rate FROM (SELECT customer_user_id, COUNT(*) AS order_count FROM orders WHERE placed_at >= DATE_SUB(NOW(), INTERVAL 90 DAY) GROUP BY customer_user_id) customer_orders");

    return compact('from', 'to', 'kpis', 'trend', 'funnel', 'cancellations', 'hourly', 'restaurants', 'drivers', 'retention');
}

function admin_settings_data(mysqli $conn, array $filters): array
{
    $auditQuery = trim((string) ($filters['q'] ?? ''));
    if ($auditQuery !== '') {
        $like = '%' . mb_substr($auditQuery, 0, 80) . '%';
        $audit = admin_rows($conn, "SELECT a.id, a.reference_id, a.action, a.entity_type, a.entity_id, a.before_summary, a.after_summary, a.reason, a.ip_address, a.result, a.created_at, u.full_name AS actor_name FROM audit_logs a JOIN users u ON u.id = a.actor_user_id WHERE a.reference_id LIKE ? OR a.action LIKE ? OR u.full_name LIKE ? ORDER BY a.created_at DESC LIMIT 50", 'sss', [$like, $like, $like]);
    } else {
        $audit = admin_rows($conn, "SELECT a.id, a.reference_id, a.action, a.entity_type, a.entity_id, a.before_summary, a.after_summary, a.reason, a.ip_address, a.result, a.created_at, u.full_name AS actor_name FROM audit_logs a JOIN users u ON u.id = a.actor_user_id ORDER BY a.created_at DESC LIMIT 50");
    }
    return [
        'settings' => admin_rows($conn, 'SELECT setting_key, setting_value, value_type, updated_at, version FROM platform_settings ORDER BY setting_key'),
        'templates' => admin_rows($conn, 'SELECT template_key, event_name, audience, channel, subject, message_template, enabled, updated_at, version FROM notification_templates ORDER BY event_name'),
        'audit' => $audit,
        'security' => admin_one($conn, "SELECT COUNT(*) AS active_sessions, COUNT(DISTINCT user_id) AS active_users FROM user_sessions WHERE revoked_at IS NULL AND (last_seen_at IS NULL OR last_seen_at >= DATE_SUB(NOW(), INTERVAL 30 DAY))"),
    ];
}

function admin_accounts_data(mysqli $conn, array $filters): array
{
    $role = in_array(($filters['role'] ?? ''), ['customer', 'restaurant', 'driver', 'admin'], true) ? (string) $filters['role'] : '';
    $status = in_array(($filters['status'] ?? ''), ['active', 'suspended', 'blocked', 'pending'], true) ? (string) $filters['status'] : '';
    $query = mb_substr(trim((string) ($filters['q'] ?? '')), 0, 80);
    $like = '%' . $query . '%';
    $accounts = admin_rows($conn, "SELECT id, username, role, full_name, email, phone, status, last_login_at, created_at, version FROM users WHERE (? = '' OR role = ?) AND (? = '' OR status = ?) AND (? = '' OR full_name LIKE ? OR username LIKE ? OR email LIKE ?) ORDER BY created_at DESC", 'ssssssss', [$role, $role, $status, $status, $query, $like, $like, $like]);
    $summaryRows = admin_rows($conn, 'SELECT status, COUNT(*) AS total FROM users GROUP BY status');
    $summary = ['all' => 0, 'active' => 0, 'suspended' => 0, 'blocked' => 0, 'pending' => 0];
    foreach ($summaryRows as $row) {
        $summary['all'] += (int) $row['total'];
        $summary[$row['status']] = (int) $row['total'];
    }
    $selectedId = max(0, (int) ($filters['id'] ?? 0));
    if ($selectedId === 0 && $accounts) {
        $selectedId = (int) $accounts[0]['id'];
    }
    $selected = $selectedId ? admin_one($conn, 'SELECT id, username, role, full_name, email, phone, status, session_version, last_login_at, created_at, updated_at, version FROM users WHERE id = ?', 'i', [$selectedId]) : [];
    return [
        'summary' => $summary,
        'accounts' => $accounts,
        'selected' => $selected,
        'status_history' => $selectedId ? admin_rows($conn, 'SELECT h.previous_status, h.next_status, h.reason, h.created_at, u.full_name AS actor_name FROM account_status_history h JOIN users u ON u.id = h.actor_user_id WHERE h.user_id = ? ORDER BY h.created_at DESC LIMIT 10', 'i', [$selectedId]) : [],
        'sessions' => $selectedId ? admin_rows($conn, 'SELECT id, ip_address, user_agent, revoked_at, last_seen_at, created_at FROM user_sessions WHERE user_id = ? ORDER BY created_at DESC LIMIT 10', 'i', [$selectedId]) : [],
        'current_admin' => admin_one($conn, "SELECT ap.privilege_level FROM admin_profiles ap JOIN users u ON u.id=ap.user_id WHERE ap.user_id=? AND u.role='admin' AND u.status='active'", 'i', [(int) ($_SESSION['user_id'] ?? 0)]),
    ];
}

function admin_customers_data(mysqli $conn, array $filters): array
{
    $query = mb_substr(trim((string) ($filters['q'] ?? '')), 0, 80);
    $like = '%' . $query . '%';
    $customers = admin_rows($conn, "SELECT u.id, u.full_name, u.username, u.email, u.phone, u.status, u.last_login_at, u.created_at, u.version, COALESCE(p.wallet_balance, 0) AS wallet_balance, p.address AS location_address, p.latitude, p.longitude, p.location_method, p.location_updated_at, COUNT(DISTINCT o.id) AS order_count, COALESCE(SUM(CASE WHEN o.status IN ('delivered','completed') THEN o.total ELSE 0 END), 0) AS lifetime_value, MAX(o.placed_at) AS last_order_at, COUNT(DISTINCT c.id) AS open_cases FROM users u LEFT JOIN customer_profiles p ON p.user_id = u.id LEFT JOIN orders o ON o.customer_user_id = u.id LEFT JOIN support_cases c ON c.reporting_user_id = u.id AND c.status NOT IN ('resolved','closed') WHERE u.role = 'customer' AND (? = '' OR u.full_name LIKE ? OR u.email LIKE ?) GROUP BY u.id, u.full_name, u.username, u.email, u.phone, u.status, u.last_login_at, u.created_at, u.version, p.wallet_balance, p.address, p.latitude, p.longitude, p.location_method, p.location_updated_at ORDER BY u.created_at DESC", 'sss', [$query, $like, $like]);
    $summary = admin_one($conn, "SELECT COUNT(DISTINCT u.id) AS total_customers, COALESCE(AVG(order_totals.average_order), 0) AS average_order_value, COALESCE(SUM(c.status NOT IN ('resolved','closed')), 0) AS open_cases, COALESCE(SUM(p.wallet_balance), 0) AS wallet_balance FROM users u LEFT JOIN customer_profiles p ON p.user_id = u.id LEFT JOIN (SELECT customer_user_id, AVG(total) AS average_order FROM orders GROUP BY customer_user_id) order_totals ON order_totals.customer_user_id = u.id LEFT JOIN support_cases c ON c.reporting_user_id = u.id WHERE u.role = 'customer'");
    $selectedId = max(0, (int) ($filters['id'] ?? 0));
    if ($selectedId === 0 && $customers) {
        $selectedId = (int) $customers[0]['id'];
    }
    $selected = [];
    foreach ($customers as $customer) {
        if ((int) $customer['id'] === $selectedId) {
            $selected = $customer;
            break;
        }
    }
    return [
        'summary' => $summary,
        'customers' => $customers,
        'selected' => $selected,
        'orders' => $selectedId ? admin_rows($conn, 'SELECT id, reference_code, status, total, payment_method, placed_at FROM orders WHERE customer_user_id = ? ORDER BY placed_at DESC LIMIT 12', 'i', [$selectedId]) : [],
        'wallet' => $selectedId ? admin_rows($conn, 'SELECT id, type, amount, description, created_at FROM wallet_transactions WHERE customer_user_id = ? ORDER BY created_at DESC LIMIT 12', 'i', [$selectedId]) : [],
        'cases' => $selectedId ? admin_rows($conn, 'SELECT id, reference_code, priority, status, subject, created_at FROM support_cases WHERE reporting_user_id = ? ORDER BY created_at DESC LIMIT 10', 'i', [$selectedId]) : [],
        'sessions' => $selectedId ? admin_rows($conn, 'SELECT id, ip_address, user_agent, revoked_at, last_seen_at, created_at FROM user_sessions WHERE user_id = ? ORDER BY created_at DESC LIMIT 10', 'i', [$selectedId]) : [],
    ];
}

function admin_restaurant_partner_data(mysqli $conn, array $filters): array
{
    $applications = admin_rows($conn, "SELECT id, reference_code, username, owner_name, owner_email, owner_phone, restaurant_name, description, cuisine, city, address, restaurant_phone, opens_at, closes_at, status, risk_level, reviewer_note, submitted_at, version FROM restaurant_applications WHERE status IN ('pending','in_review') ORDER BY submitted_at");
    $selectedId = max(0, (int) ($filters['id'] ?? 0));
    if ($selectedId === 0 && $applications) $selectedId = (int) $applications[0]['id'];
    $selected = $selectedId ? admin_one($conn, 'SELECT * FROM restaurant_applications WHERE id = ?', 'i', [$selectedId]) : [];
    return [
        'applications' => $applications,
        'selected' => $selected,
        'logo' => $selectedId ? admin_one($conn, "SELECT public_id,mime_type,file_size,status,visibility FROM media_assets WHERE owner_kind='restaurant_application' AND owner_id=? AND purpose='restaurant_logo' ORDER BY id DESC LIMIT 1", 'i', [$selectedId]) : [],
        'restaurants' => admin_rows($conn, 'SELECT r.id, r.name, r.description, r.cuisine, r.city, r.address AS location_address, r.latitude, r.longitude, r.location_method, r.location_updated_at, r.status, r.accepting_orders, r.rating, r.cancellation_rate, r.payout_status, u.full_name AS owner_name, u.email FROM restaurants r JOIN users u ON u.id = r.owner_user_id ORDER BY r.created_at DESC'),
        'summary' => admin_one($conn, "SELECT COUNT(*) AS pending, SUM(risk_level IN ('medium','high')) AS needs_review, COALESCE(AVG(TIMESTAMPDIFF(HOUR, submitted_at, NOW())), 0) AS average_age_hours FROM restaurant_applications WHERE status IN ('pending','in_review')"),
    ];
}

function admin_driver_partner_data(mysqli $conn, array $filters): array
{
    $applications = admin_rows($conn, "SELECT id, reference_code, username, full_name, email, phone, city, vehicle_type, vehicle_model, license_plate, service_area, vehicle_color, status, risk_level, reviewer_note, submitted_at, version FROM driver_applications WHERE status IN ('pending','in_review') ORDER BY submitted_at");
    $selectedId = max(0, (int) ($filters['id'] ?? 0));
    if ($selectedId === 0 && $applications) $selectedId = (int) $applications[0]['id'];
    $selected = $selectedId ? admin_one($conn, 'SELECT * FROM driver_applications WHERE id = ?', 'i', [$selectedId]) : [];
    return [
        'applications' => $applications,
        'selected' => $selected,
        'drivers' => admin_rows($conn, 'SELECT d.id, u.id AS user_id, u.full_name, u.email, u.status, d.vehicle_type, d.vehicle_model, d.license_plate, d.vehicle_color, d.service_area, d.address AS location_address, d.latitude, d.longitude, d.location_method, d.location_updated_at, d.eligibility_status, d.availability_status, d.rating, d.acceptance_rate, d.completion_rate FROM driver_profiles d JOIN users u ON u.id = d.user_id ORDER BY d.created_at DESC'),
        'summary' => admin_one($conn, "SELECT COUNT(*) AS pending, SUM(risk_level IN ('medium','high')) AS needs_review, COALESCE(AVG(TIMESTAMPDIFF(HOUR, submitted_at, NOW())), 0) AS average_age_hours FROM driver_applications WHERE status IN ('pending','in_review')"),
    ];
}

function admin_orders_data(mysqli $conn, array $filters): array
{
    $orders = admin_rows($conn, "SELECT o.*,u.full_name AS customer_name,r.name AS restaurant_name,du.full_name AS driver_name,d.status AS dispatch_status,d.attempt_count FROM orders o JOIN users u ON u.id=o.customer_user_id JOIN restaurants r ON r.id=o.restaurant_id LEFT JOIN delivery_dispatches d ON d.order_id=o.id LEFT JOIN users du ON du.id=d.assigned_driver_user_id ORDER BY o.placed_at DESC LIMIT 100");
    $selectedId=max(0,(int)($filters['id']??0)); if($selectedId===0&&$orders)$selectedId=(int)$orders[0]['id']; $selected=[];foreach($orders as $order)if((int)$order['id']===$selectedId){$selected=$order;break;}
    return ['orders'=>$orders,'selected'=>$selected,'summary'=>admin_one($conn,"SELECT COUNT(*) total_orders,SUM(status NOT IN ('delivered','completed','cancelled','refunded')) live_orders,SUM(status IN ('pending','ready_for_pickup')) needs_attention,COALESCE(SUM(total),0) gross_value FROM orders"),'items'=>$selectedId?admin_rows($conn,'SELECT * FROM order_items WHERE order_id=?','i',[$selectedId]):[],'timeline'=>$selectedId?admin_rows($conn,'SELECT * FROM order_status_history WHERE order_id=? ORDER BY created_at','i',[$selectedId]):[],'dispatch_attempts'=>$selectedId?admin_rows($conn,'SELECT f.status,f.offered_at,f.expires_at,f.responded_at,u.full_name AS driver_name FROM delivery_offers f JOIN delivery_dispatches d ON d.id=f.dispatch_id JOIN users u ON u.id=f.driver_user_id WHERE d.order_id=? ORDER BY f.offered_at DESC','i',[$selectedId]):[],'payment'=>$selectedId?admin_one($conn,'SELECT * FROM payments WHERE order_id=?','i',[$selectedId]):[],'available_drivers'=>admin_rows($conn,"SELECT u.id,u.full_name,d.service_area,d.rating FROM driver_profiles d JOIN users u ON u.id=d.user_id WHERE u.status='active' AND d.eligibility_status='eligible' ORDER BY d.rating DESC")];
}

function admin_cases_data(mysqli $conn,array $filters):array
{
    $cases=admin_rows($conn,"SELECT c.*,u.full_name AS reporter_name,o.reference_code AS order_reference FROM support_cases c JOIN users u ON u.id=c.reporting_user_id LEFT JOIN orders o ON o.id=c.order_id ORDER BY FIELD(c.priority,'urgent','high','medium','low'),c.sla_due_at");$selectedId=max(0,(int)($filters['id']??0));if($selectedId===0&&$cases)$selectedId=(int)$cases[0]['id'];$selected=[];foreach($cases as $case)if((int)$case['id']===$selectedId){$selected=$case;break;}
    return ['cases'=>$cases,'selected'=>$selected,'summary'=>admin_one($conn,"SELECT COUNT(*) total_cases,SUM(status NOT IN ('resolved','closed')) open_cases,SUM(priority='urgent' AND status NOT IN ('resolved','closed')) urgent_cases,SUM(sla_due_at<NOW() AND status NOT IN ('resolved','closed')) breached FROM support_cases"),'messages'=>$selectedId?admin_rows($conn,'SELECT m.*,u.full_name AS author_name FROM case_messages m JOIN users u ON u.id=m.author_user_id WHERE m.case_id=? ORDER BY m.created_at','i',[$selectedId]):[],'attachments'=>$selectedId?admin_rows($conn,'SELECT * FROM case_attachments WHERE case_id=? ORDER BY created_at','i',[$selectedId]):[],'refunds'=>$selectedId?admin_rows($conn,'SELECT * FROM refunds WHERE case_id=? ORDER BY created_at DESC','i',[$selectedId]):[]];
}

function admin_finance_data(mysqli $conn):array
{
    return ['summary'=>array_merge(admin_one($conn,"SELECT COALESCE(SUM(gross_amount),0) gross_order_value,COALESCE(SUM(fee_amount),0) platform_revenue FROM ledger_entries WHERE status='completed'"),admin_one($conn,"SELECT COALESCE(SUM(amount),0) refunds FROM refunds WHERE status='processed'"),admin_one($conn,"SELECT COALESCE(SUM(amount),0) pending_payouts FROM payouts WHERE status IN ('scheduled','processing','held')")),'ledger'=>admin_rows($conn,'SELECT * FROM ledger_entries ORDER BY created_at DESC LIMIT 100'),'restaurant_payouts'=>admin_rows($conn,"SELECT p.*,r.name AS party_name FROM payouts p JOIN restaurants r ON p.party_type='restaurant' AND p.party_id=r.id ORDER BY p.scheduled_at DESC"),'driver_payouts'=>admin_rows($conn,"SELECT p.*,u.full_name AS party_name FROM payouts p JOIN users u ON p.party_type='driver' AND p.party_id=u.id ORDER BY p.scheduled_at DESC"),'cod'=>admin_rows($conn,'SELECT c.*,u.full_name AS driver_name FROM cod_reconciliations c JOIN users u ON u.id=c.driver_user_id ORDER BY c.id DESC'),'refund_rows'=>admin_rows($conn,'SELECT f.*,o.reference_code AS order_reference FROM refunds f JOIN orders o ON o.id=f.order_id ORDER BY f.created_at DESC')];
}

function admin_promotions_data(mysqli $conn):array
{
    return ['promotions'=>admin_rows($conn,'SELECT * FROM promotions ORDER BY starts_at DESC'),'fees'=>admin_rows($conn,'SELECT * FROM fee_rules ORDER BY effective_at DESC'),'areas'=>admin_rows($conn,'SELECT * FROM service_areas ORDER BY name'),'summary'=>admin_one($conn,"SELECT SUM(status='active') active_promotions,COALESCE(SUM(budget),0) total_budget,COALESCE(SUM(used_amount),0) used_budget,(SELECT COUNT(*) FROM promotion_redemptions) redemptions FROM promotions")];
}

function admin_page_data(mysqli $conn, string $page, array $filters = []): array
{
    $data = ['page' => $page, 'filters' => $filters];
    if ($page === 'overview') {
        return array_merge($data, admin_overview_data($conn));
    }
    if ($page === 'analytics') {
        return array_merge($data, admin_analytics_data($conn, $filters));
    }
    if ($page === 'settings') {
        return array_merge($data, admin_settings_data($conn, $filters));
    }
    if ($page === 'accounts') {
        return array_merge($data, admin_accounts_data($conn, $filters));
    }
    if ($page === 'customers') {
        return array_merge($data, admin_customers_data($conn, $filters));
    }
    if ($page === 'restaurants') {
        return array_merge($data, admin_restaurant_partner_data($conn, $filters));
    }
    if ($page === 'drivers') {
        return array_merge($data, admin_driver_partner_data($conn, $filters));
    }
    if($page==='orders')return array_merge($data,admin_orders_data($conn,$filters));
    if($page==='cases')return array_merge($data,admin_cases_data($conn,$filters));
    if($page==='finance')return array_merge($data,admin_finance_data($conn));
    if($page==='promotions')return array_merge($data,admin_promotions_data($conn));
    $data['accounts'] = admin_account_rows($conn, $filters);
    return $data;
}
