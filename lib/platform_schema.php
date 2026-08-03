<?php
declare(strict_types=1);

require_once __DIR__ . '/catalog_demo_seed.php';

function platform_column_exists(mysqli $conn, string $table, string $column): bool
{
    $database = (string) $conn->query('SELECT DATABASE() AS name')->fetch_assoc()['name'];
    $stmt = $conn->prepare('SELECT COUNT(*) AS total FROM information_schema.columns WHERE table_schema = ? AND table_name = ? AND column_name = ?');
    $stmt->bind_param('sss', $database, $table, $column);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int) ($row['total'] ?? 0) === 1;
}

function platform_add_column(mysqli $conn, string $table, string $column, string $definition): void
{
    if (!platform_column_exists($conn, $table, $column)) {
        if (!$conn->query("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}")) {
            throw new RuntimeException("Unable to add {$table}.{$column}");
        }
    }
}

function platform_migrate(mysqli $conn): void
{
    $statements = [
        "CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) NOT NULL UNIQUE,
            password VARCHAR(255) NOT NULL,
            role ENUM('customer','restaurant','driver','admin') NOT NULL,
            full_name VARCHAR(100) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS customer_profiles (
            id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL UNIQUE, email VARCHAR(190), phone VARCHAR(40), address VARCHAR(500), wallet_balance DECIMAL(12,2) NOT NULL DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS restaurant_applications (
            id INT AUTO_INCREMENT PRIMARY KEY, reference_code VARCHAR(40) NOT NULL UNIQUE, username VARCHAR(50) NOT NULL, password_hash VARCHAR(255), owner_name VARCHAR(120) NOT NULL, owner_email VARCHAR(190) NOT NULL, owner_phone VARCHAR(40), restaurant_name VARCHAR(160) NOT NULL, cuisine VARCHAR(100), city VARCHAR(100), address VARCHAR(500), status VARCHAR(30) NOT NULL DEFAULT 'pending', risk_level VARCHAR(20) NOT NULL DEFAULT 'low', reviewer_id INT NULL, reviewer_note TEXT, decision_reason TEXT, submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, reviewed_at TIMESTAMP NULL, version INT NOT NULL DEFAULT 1
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS restaurant_application_documents (
            id INT AUTO_INCREMENT PRIMARY KEY, application_id INT NOT NULL, document_type VARCHAR(80) NOT NULL, stored_path VARCHAR(500), mime_type VARCHAR(100), verification_status VARCHAR(30) NOT NULL DEFAULT 'pending', expires_at DATETIME NULL, reviewer_note TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_restaurant_document(application_id, document_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS driver_applications (
            id INT AUTO_INCREMENT PRIMARY KEY, reference_code VARCHAR(40) NOT NULL UNIQUE, username VARCHAR(50) NOT NULL, password_hash VARCHAR(255), full_name VARCHAR(120) NOT NULL, email VARCHAR(190) NOT NULL, phone VARCHAR(40), city VARCHAR(100), vehicle_type VARCHAR(80), vehicle_model VARCHAR(100), license_plate VARCHAR(40), service_area VARCHAR(160), status VARCHAR(30) NOT NULL DEFAULT 'pending', risk_level VARCHAR(20) NOT NULL DEFAULT 'low', reviewer_id INT NULL, reviewer_note TEXT, decision_reason TEXT, submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, reviewed_at TIMESTAMP NULL, version INT NOT NULL DEFAULT 1
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS driver_application_documents (
            id INT AUTO_INCREMENT PRIMARY KEY, application_id INT NOT NULL, document_type VARCHAR(80) NOT NULL, stored_path VARCHAR(500), mime_type VARCHAR(100), verification_status VARCHAR(30) NOT NULL DEFAULT 'pending', expires_at DATETIME NULL, reviewer_note TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_driver_document(application_id, document_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS restaurants (
            id INT AUTO_INCREMENT PRIMARY KEY, owner_user_id INT NOT NULL UNIQUE, name VARCHAR(160) NOT NULL, cuisine VARCHAR(100), address VARCHAR(500), city VARCHAR(100), phone VARCHAR(40), status VARCHAR(30) NOT NULL DEFAULT 'active', accepting_orders TINYINT(1) NOT NULL DEFAULT 1, rating DECIMAL(3,2) NOT NULL DEFAULT 0, cancellation_rate DECIMAL(5,2) NOT NULL DEFAULT 0, payout_status VARCHAR(30) NOT NULL DEFAULT 'scheduled', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, version INT NOT NULL DEFAULT 1
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS driver_profiles (
            id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL UNIQUE, city VARCHAR(100), vehicle_type VARCHAR(80), vehicle_model VARCHAR(100), license_plate VARCHAR(40), service_area VARCHAR(160), eligibility_status VARCHAR(30) NOT NULL DEFAULT 'eligible', availability_status VARCHAR(30) NOT NULL DEFAULT 'offline', rating DECIMAL(3,2) NOT NULL DEFAULT 0, acceptance_rate DECIMAL(5,2) NOT NULL DEFAULT 0, completion_rate DECIMAL(5,2) NOT NULL DEFAULT 0, cod_balance DECIMAL(12,2) NOT NULL DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, version INT NOT NULL DEFAULT 1
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS account_status_history (
            id BIGINT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, previous_status VARCHAR(30) NOT NULL, next_status VARCHAR(30) NOT NULL, actor_user_id INT NOT NULL, reason VARCHAR(500) NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS orders (
            id BIGINT AUTO_INCREMENT PRIMARY KEY, reference_code VARCHAR(40) NOT NULL UNIQUE, customer_user_id INT NOT NULL, restaurant_id INT NOT NULL, status VARCHAR(40) NOT NULL DEFAULT 'pending', payment_method VARCHAR(30) NOT NULL DEFAULT 'cash', subtotal DECIMAL(12,2) NOT NULL DEFAULT 0, delivery_fee DECIMAL(12,2) NOT NULL DEFAULT 0, total DECIMAL(12,2) NOT NULL DEFAULT 0, delivery_address VARCHAR(500), delivery_note VARCHAR(300), placed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, version INT NOT NULL DEFAULT 1
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS order_items (
            id BIGINT AUTO_INCREMENT PRIMARY KEY, order_id BIGINT NOT NULL, item_name VARCHAR(160) NOT NULL, quantity INT NOT NULL DEFAULT 1, unit_price DECIMAL(12,2) NOT NULL DEFAULT 0, options_text VARCHAR(500)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS order_status_history (
            id BIGINT AUTO_INCREMENT PRIMARY KEY, order_id BIGINT NOT NULL, status VARCHAR(40) NOT NULL, actor_role VARCHAR(30) NOT NULL, actor_user_id INT NULL, reason VARCHAR(500), created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS delivery_dispatches (
            id BIGINT AUTO_INCREMENT PRIMARY KEY, order_id BIGINT NOT NULL UNIQUE, status VARCHAR(40) NOT NULL DEFAULT 'searching_driver', assigned_driver_user_id INT NULL, attempt_count INT NOT NULL DEFAULT 0, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, version INT NOT NULL DEFAULT 1
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS delivery_offers (
            id BIGINT AUTO_INCREMENT PRIMARY KEY, dispatch_id BIGINT NOT NULL, driver_user_id INT NOT NULL, status VARCHAR(30) NOT NULL DEFAULT 'sent', offered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, expires_at DATETIME NOT NULL, responded_at DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS deliveries (
            id BIGINT AUTO_INCREMENT PRIMARY KEY, order_id BIGINT NOT NULL UNIQUE, driver_user_id INT NOT NULL, status VARCHAR(40) NOT NULL DEFAULT 'assigned', earning DECIMAL(12,2) NOT NULL DEFAULT 0, accepted_at DATETIME NULL, delivered_at DATETIME NULL, version INT NOT NULL DEFAULT 1
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS delivery_milestones (
            id BIGINT AUTO_INCREMENT PRIMARY KEY, delivery_id BIGINT NOT NULL, status VARCHAR(40) NOT NULL, actor_user_id INT NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS payments (
            id BIGINT AUTO_INCREMENT PRIMARY KEY, order_id BIGINT NOT NULL UNIQUE, method VARCHAR(30) NOT NULL, amount DECIMAL(12,2) NOT NULL, status VARCHAR(30) NOT NULL DEFAULT 'paid', provider_reference VARCHAR(100), paid_at DATETIME NULL, version INT NOT NULL DEFAULT 1
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS wallet_transactions (
            id BIGINT AUTO_INCREMENT PRIMARY KEY, customer_user_id INT NOT NULL, order_id BIGINT NULL, type VARCHAR(40) NOT NULL, amount DECIMAL(12,2) NOT NULL, description VARCHAR(255) NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS ledger_entries (
            id BIGINT AUTO_INCREMENT PRIMARY KEY, reference_code VARCHAR(50) NOT NULL UNIQUE, order_id BIGINT NULL, entry_type VARCHAR(50) NOT NULL, party_type VARCHAR(30) NOT NULL, party_id INT NULL, gross_amount DECIMAL(12,2) NOT NULL DEFAULT 0, fee_amount DECIMAL(12,2) NOT NULL DEFAULT 0, net_amount DECIMAL(12,2) NOT NULL DEFAULT 0, payment_method VARCHAR(30), status VARCHAR(30) NOT NULL DEFAULT 'completed', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS refunds (
            id BIGINT AUTO_INCREMENT PRIMARY KEY, order_id BIGINT NOT NULL, case_id BIGINT NULL, amount DECIMAL(12,2) NOT NULL, destination VARCHAR(40) NOT NULL, reason VARCHAR(500) NOT NULL, status VARCHAR(30) NOT NULL DEFAULT 'processed', actor_user_id INT NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS payouts (
            id BIGINT AUTO_INCREMENT PRIMARY KEY, reference_code VARCHAR(50) NOT NULL UNIQUE, party_type VARCHAR(30) NOT NULL, party_id INT NOT NULL, amount DECIMAL(12,2) NOT NULL, status VARCHAR(30) NOT NULL DEFAULT 'scheduled', scheduled_at DATETIME NULL, paid_at DATETIME NULL, hold_reason VARCHAR(500), version INT NOT NULL DEFAULT 1
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS payout_items (
            id BIGINT AUTO_INCREMENT PRIMARY KEY, payout_id BIGINT NOT NULL, ledger_entry_id BIGINT NOT NULL, amount DECIMAL(12,2) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS cod_reconciliations (
            id BIGINT AUTO_INCREMENT PRIMARY KEY, driver_user_id INT NOT NULL, collected_amount DECIMAL(12,2) NOT NULL DEFAULT 0, due_amount DECIMAL(12,2) NOT NULL DEFAULT 0, settled_amount DECIMAL(12,2) NOT NULL DEFAULT 0, status VARCHAR(30) NOT NULL DEFAULT 'open', reconciled_at DATETIME NULL, version INT NOT NULL DEFAULT 1
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS support_cases (
            id BIGINT AUTO_INCREMENT PRIMARY KEY, reference_code VARCHAR(50) NOT NULL UNIQUE, order_id BIGINT NULL, case_type VARCHAR(60) NOT NULL, reporting_role VARCHAR(30) NOT NULL, reporting_user_id INT NOT NULL, priority VARCHAR(20) NOT NULL DEFAULT 'medium', status VARCHAR(40) NOT NULL DEFAULT 'open', subject VARCHAR(200) NOT NULL, resolution VARCHAR(500), sla_due_at DATETIME NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, version INT NOT NULL DEFAULT 1
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS case_messages (
            id BIGINT AUTO_INCREMENT PRIMARY KEY, case_id BIGINT NOT NULL, author_role VARCHAR(30) NOT NULL, author_user_id INT NOT NULL, message TEXT NOT NULL, internal_only TINYINT(1) NOT NULL DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS case_attachments (
            id BIGINT AUTO_INCREMENT PRIMARY KEY, case_id BIGINT NOT NULL, message_id BIGINT NULL, file_name VARCHAR(255) NOT NULL, stored_path VARCHAR(500), mime_type VARCHAR(100), file_size INT NOT NULL DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS notifications (
            id BIGINT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, event_type VARCHAR(80) NOT NULL, title VARCHAR(160) NOT NULL, message VARCHAR(500) NOT NULL, entity_type VARCHAR(50), entity_id BIGINT NULL, read_at DATETIME NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS promotions (
            id INT AUTO_INCREMENT PRIMARY KEY, code VARCHAR(50) NOT NULL UNIQUE, audience VARCHAR(80) NOT NULL, discount_type VARCHAR(30) NOT NULL, discount_value DECIMAL(12,2) NOT NULL, maximum_discount DECIMAL(12,2) NULL, minimum_order DECIMAL(12,2) NOT NULL DEFAULT 0, usage_cap INT NULL, budget DECIMAL(12,2) NOT NULL DEFAULT 0, used_amount DECIMAL(12,2) NOT NULL DEFAULT 0, starts_at DATETIME NOT NULL, ends_at DATETIME NOT NULL, status VARCHAR(30) NOT NULL DEFAULT 'scheduled', scope VARCHAR(80) NOT NULL DEFAULT 'all_restaurants', version INT NOT NULL DEFAULT 1
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS promotion_redemptions (
            id BIGINT AUTO_INCREMENT PRIMARY KEY, promotion_id INT NOT NULL, customer_user_id INT NOT NULL, order_id BIGINT NOT NULL, amount DECIMAL(12,2) NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_promotion_order(promotion_id, order_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS service_areas (
            id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(120) NOT NULL UNIQUE, city VARCHAR(100), radius_km DECIMAL(8,2) NOT NULL DEFAULT 5, status VARCHAR(30) NOT NULL DEFAULT 'active', minimum_order DECIMAL(12,2) NOT NULL DEFAULT 0, driver_health VARCHAR(30) NOT NULL DEFAULT 'healthy', version INT NOT NULL DEFAULT 1
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS fee_rules (
            id INT AUTO_INCREMENT PRIMARY KEY, rule_type VARCHAR(50) NOT NULL, name VARCHAR(120) NOT NULL, amount DECIMAL(12,4) NOT NULL, unit VARCHAR(30) NOT NULL, effective_at DATETIME NOT NULL, status VARCHAR(30) NOT NULL DEFAULT 'scheduled', created_by INT NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS platform_settings (
            setting_key VARCHAR(100) PRIMARY KEY, setting_value TEXT NOT NULL, value_type VARCHAR(30) NOT NULL DEFAULT 'string', updated_by INT NULL, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, version INT NOT NULL DEFAULT 1
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS notification_templates (
            template_key VARCHAR(100) PRIMARY KEY, event_name VARCHAR(160) NOT NULL, audience VARCHAR(40) NOT NULL, channel VARCHAR(30) NOT NULL DEFAULT 'in_app', subject VARCHAR(200) NOT NULL, message_template TEXT NOT NULL, enabled TINYINT(1) NOT NULL DEFAULT 1, updated_by INT NULL, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, version INT NOT NULL DEFAULT 1
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS audit_logs (
            id BIGINT AUTO_INCREMENT PRIMARY KEY, actor_user_id INT NOT NULL, action VARCHAR(100) NOT NULL, entity_type VARCHAR(60) NOT NULL, entity_id BIGINT NULL, before_summary TEXT, after_summary TEXT, reason VARCHAR(500), ip_address VARCHAR(64), session_id VARCHAR(128), result VARCHAR(30) NOT NULL DEFAULT 'success', reference_id VARCHAR(60) NOT NULL UNIQUE, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS idempotency_keys (
            id BIGINT AUTO_INCREMENT PRIMARY KEY, actor_user_id INT NOT NULL, idempotency_key VARCHAR(100) NOT NULL, action VARCHAR(100) NOT NULL, response_json LONGTEXT NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_actor_key(actor_user_id, idempotency_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS user_sessions (
            id BIGINT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, session_hash VARCHAR(128) NOT NULL UNIQUE, ip_address VARCHAR(64), user_agent VARCHAR(500), revoked_at DATETIME NULL, last_seen_at DATETIME NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS password_reset_tokens (
            id BIGINT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, token_hash VARCHAR(128) NOT NULL UNIQUE, expires_at DATETIME NOT NULL, used_at DATETIME NULL, created_by INT NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS menu_items (
            id BIGINT AUTO_INCREMENT PRIMARY KEY, public_id VARCHAR(60) NOT NULL UNIQUE, restaurant_id INT NOT NULL, name VARCHAR(160) NOT NULL, price DECIMAL(12,2) NOT NULL, is_available TINYINT(1) NOT NULL DEFAULT 1, version INT NOT NULL DEFAULT 1, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    ];

    foreach ($statements as $sql) {
        if (!$conn->query($sql)) {
            throw new RuntimeException('Schema migration failed: ' . $conn->error);
        }
    }

    platform_add_column($conn, 'users', 'email', 'VARCHAR(190) NULL');
    platform_add_column($conn, 'users', 'phone', 'VARCHAR(40) NULL');
    platform_add_column($conn, 'users', 'status', "VARCHAR(30) NOT NULL DEFAULT 'active'");
    platform_add_column($conn, 'users', 'session_version', 'INT NOT NULL DEFAULT 1');
    platform_add_column($conn, 'users', 'last_login_at', 'DATETIME NULL');
    platform_add_column($conn, 'users', 'updated_at', 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
    platform_add_column($conn, 'users', 'version', 'INT NOT NULL DEFAULT 1');

    foreach (['customer_profiles', 'restaurants', 'driver_profiles'] as $table) {
        platform_add_column($conn, $table, 'latitude', 'DECIMAL(10,7) NULL');
        platform_add_column($conn, $table, 'longitude', 'DECIMAL(10,7) NULL');
        platform_add_column($conn, $table, 'location_method', "VARCHAR(10) NOT NULL DEFAULT 'manual'");
        platform_add_column($conn, $table, 'location_updated_at', 'DATETIME NULL');
    }
    platform_add_column($conn, 'driver_profiles', 'address', 'VARCHAR(500) NULL');
    platform_add_column($conn, 'restaurants', 'address_line1', 'VARCHAR(150) NULL');
    platform_add_column($conn, 'restaurants', 'address_line2', 'VARCHAR(150) NULL');
    platform_add_column($conn, 'restaurants', 'state', 'VARCHAR(100) NULL');
    platform_add_column($conn, 'restaurants', 'postal_code', 'VARCHAR(30) NULL');
    platform_add_column($conn, 'restaurants', 'country', 'VARCHAR(100) NULL');
}

function platform_seed(mysqli $conn): void
{
    $users = [
        ['customer', 'customer@savora.test', 'customer', 'John Doe (Customer)'],
        ['admin', 'admin@savora.test', 'admin', 'System Admin'],
    ];
    if (getenv('SAVORA_SEED_DEMO') === '1') {
        $users = array_merge($users, [
            ['restaurant', 'restaurant@savora.test', 'restaurant', 'Savora Burger (Owner)'],
            ['driver', 'driver@savora.test', 'driver', 'Mike Smith (Driver)'],
            ['driver-nearby-2', 'alex@savora.test', 'driver', 'Alex Rivera (Driver)'],
            ['driver-nearby-3', 'jordan@savora.test', 'driver', 'Jordan Lee (Driver)'],
        ]);
    }
    $stmt = $conn->prepare("INSERT IGNORE INTO users (username, password, role, full_name, email, status) VALUES (?, ?, ?, ?, ?, 'active')");
    foreach ($users as [$username, $email, $role, $fullName]) {
        $hash = password_hash('123456', PASSWORD_DEFAULT);
        $stmt->bind_param('sssss', $username, $hash, $role, $fullName, $email);
        $stmt->execute();
    }
    $stmt->close();

    $settings = [
        ['restaurant_acceptance_minutes', '5', 'integer'],
        ['preparation_delay_minutes', '15', 'integer'],
        ['dispatch_offer_seconds', '30', 'integer'],
        ['dispatch_max_attempts', '5', 'integer'],
        ['support_critical_minutes', '30', 'integer'],
        ['support_standard_hours', '24', 'integer'],
        ['maintenance_mode', '0', 'boolean'],
        ['delivery_fee_flat', '2.00', 'decimal'],
    ];
    $setting = $conn->prepare('INSERT IGNORE INTO platform_settings (setting_key, setting_value, value_type) VALUES (?, ?, ?)');
    foreach ($settings as [$key, $value, $type]) {
        $setting->bind_param('sss', $key, $value, $type);
        $setting->execute();
    }
    $setting->close();

    $templates = [
        ['order_status_customer', 'Order status update', 'customer', 'in_app', 'Your order has an update', 'Order {{order_reference}} is now {{status}}.'],
        ['restaurant_application_decision', 'Restaurant application decision', 'restaurant', 'email', 'Your Savora application', 'Application {{application_reference}} is now {{status}}.'],
        ['driver_application_decision', 'Driver application decision', 'driver', 'email', 'Your Savora driver application', 'Application {{application_reference}} is now {{status}}.'],
        ['support_case_update', 'Support case update', 'all', 'in_app', 'Case {{case_reference}} updated', 'A new update is available for case {{case_reference}}.'],
    ];
    $template = $conn->prepare('INSERT IGNORE INTO notification_templates (template_key, event_name, audience, channel, subject, message_template) VALUES (?, ?, ?, ?, ?, ?)');
    foreach ($templates as [$key, $event, $audience, $channel, $subject, $message]) {
        $template->bind_param('ssssss', $key, $event, $audience, $channel, $subject, $message);
        $template->execute();
    }
    $template->close();

    if (getenv('SAVORA_SEED_DEMO') === '1') {
        platform_seed_operations($conn);
    }
}

function platform_seed_operations(mysqli $conn): void
{
    catalog_demo_seed($conn);
    $ids = [];
    $userLookup = $conn->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
    foreach (['customer', 'restaurant', 'driver', 'admin', 'driver-nearby-2', 'driver-nearby-3'] as $username) {
        $userLookup->bind_param('s', $username);
        $userLookup->execute();
        $row = $userLookup->get_result()->fetch_assoc();
        if ($row) {
            $ids[$username] = (int) $row['id'];
        }
    }
    $userLookup->close();
    if (count($ids) < 6) {
        return;
    }

    $customerProfile = $conn->prepare("INSERT IGNORE INTO customer_profiles (user_id, email, phone, address, wallet_balance) VALUES (?, 'customer@savora.test', '+1 555 0142', '28 Market Street, Central District', 128.50)");
    $customerProfile->bind_param('i', $ids['customer']);
    $customerProfile->execute();
    $customerProfile->close();

    $restaurantLookup = $conn->prepare("SELECT id FROM restaurants WHERE demo_key = 'lotus-kitchen' LIMIT 1");
    $restaurantLookup->execute();
    $restaurantId = (int) ($restaurantLookup->get_result()->fetch_assoc()['id'] ?? 0);
    $restaurantLookup->close();
    if ($restaurantId === 0) {
        return;
    }

    $driverProfiles = [
        [$ids['driver'], 'Motorbike', 'Honda Vision', 'SVR-1042', 'Central District', 'online', 4.91, 94.50, 98.20],
        [$ids['driver-nearby-2'], 'Motorbike', 'Yamaha NMAX', 'SVR-2058', 'North District', 'online', 4.76, 91.20, 96.80],
        [$ids['driver-nearby-3'], 'Bicycle', 'Urban Cargo', 'SVR-3091', 'East District', 'offline', 4.68, 88.40, 95.10],
    ];
    $driver = $conn->prepare("INSERT IGNORE INTO driver_profiles (user_id, city, vehicle_type, vehicle_model, license_plate, service_area, eligibility_status, availability_status, rating, acceptance_rate, completion_rate) VALUES (?, 'Central City', ?, ?, ?, ?, 'eligible', ?, ?, ?, ?)");
    foreach ($driverProfiles as [$userId, $vehicleType, $model, $plate, $area, $availability, $rating, $acceptance, $completion]) {
        $driver->bind_param('isssssddd', $userId, $vehicleType, $model, $plate, $area, $availability, $rating, $acceptance, $completion);
        $driver->execute();
    }
    $driver->close();

    $restaurantApplications = [
        ['RA-2026-104', 'green-table', 'Ava Thompson', 'ava@greentable.test', 'Green Table Bistro', 'Mediterranean', 'pending', 'low'],
        ['RA-2026-105', 'little-orchid', 'Noah Wilson', 'noah@orchid.test', 'Little Orchid Kitchen', 'Vietnamese', 'pending', 'medium'],
    ];
    $restaurantApplication = $conn->prepare("INSERT IGNORE INTO restaurant_applications (reference_code, username, password_hash, owner_name, owner_email, restaurant_name, cuisine, city, address, status, risk_level) VALUES (?, ?, ?, ?, ?, ?, ?, 'Central City', 'Application address pending review', ?, ?)");
    foreach ($restaurantApplications as [$reference, $username, $owner, $email, $name, $cuisine, $status, $risk]) {
        $hash = password_hash('123456', PASSWORD_DEFAULT);
        $restaurantApplication->bind_param('sssssssss', $reference, $username, $hash, $owner, $email, $name, $cuisine, $status, $risk);
        $restaurantApplication->execute();
    }
    $restaurantApplication->close();
    $restaurantApplicationId = (int) ($conn->query("SELECT id FROM restaurant_applications WHERE reference_code = 'RA-2026-104'")->fetch_assoc()['id'] ?? 0);
    if ($restaurantApplicationId > 0) {
        $document = $conn->prepare("INSERT IGNORE INTO restaurant_application_documents (application_id, document_type, stored_path, mime_type, verification_status) VALUES (?, ?, ?, 'application/pdf', 'verified')");
        foreach ([['business_registration','uploads/demo/business-registration.pdf'],['food_safety_certificate','uploads/demo/food-safety.pdf'],['owner_identity','uploads/demo/owner-identity.pdf']] as [$type,$path]) {
            $document->bind_param('iss', $restaurantApplicationId, $type, $path);
            $document->execute();
        }
        $document->close();
    }

    $driverApplications = [
        ['DA-2026-207', 'jamie-carter', 'Jamie Carter', 'jamie@driver.test', 'Motorbike', 'pending', 'low'],
        ['DA-2026-208', 'morgan-lee', 'Morgan Lee', 'morgan@driver.test', 'Bicycle', 'pending', 'medium'],
    ];
    $driverApplication = $conn->prepare("INSERT IGNORE INTO driver_applications (reference_code, username, password_hash, full_name, email, city, vehicle_type, service_area, status, risk_level) VALUES (?, ?, ?, ?, ?, 'Central City', ?, 'Central District', ?, ?)");
    foreach ($driverApplications as [$reference, $username, $name, $email, $vehicle, $status, $risk]) {
        $hash = password_hash('123456', PASSWORD_DEFAULT);
        $driverApplication->bind_param('ssssssss', $reference, $username, $hash, $name, $email, $vehicle, $status, $risk);
        $driverApplication->execute();
    }
    $driverApplication->close();
    $driverApplicationId = (int) ($conn->query("SELECT id FROM driver_applications WHERE reference_code = 'DA-2026-207'")->fetch_assoc()['id'] ?? 0);
    if ($driverApplicationId > 0) {
        $document = $conn->prepare("INSERT IGNORE INTO driver_application_documents (application_id, document_type, stored_path, mime_type, verification_status, expires_at) VALUES (?, ?, ?, 'application/pdf', 'verified', DATE_ADD(NOW(), INTERVAL 1 YEAR))");
        foreach ([['driver_license','uploads/demo/driver-license.pdf'],['vehicle_registration','uploads/demo/vehicle-registration.pdf'],['background_check','uploads/demo/background-check.pdf']] as [$type,$path]) {
            $document->bind_param('iss', $driverApplicationId, $type, $path);
            $document->execute();
        }
        $document->close();
    }

    $orders = [
        ['SV-10482', 'delivered', 'wallet', 42.80, 3.90, 46.70, 0],
        ['SV-10483', 'delivered', 'card', 68.40, 4.20, 72.60, 1],
        ['SV-10484', 'preparing', 'cash', 31.20, 3.50, 34.70, 0],
        ['SV-10485', 'ready_for_pickup', 'card', 54.60, 4.00, 58.60, 0],
        ['SV-10486', 'assigned', 'wallet', 26.50, 3.20, 29.70, 0],
        ['SV-10487', 'cancelled', 'card', 39.90, 3.60, 43.50, 2],
        ['SV-10488', 'delivered', 'cash', 82.10, 4.80, 86.90, 3],
        ['SV-10489', 'pending', 'wallet', 24.00, 3.00, 27.00, 0],
    ];
    $orderInsert = $conn->prepare("INSERT IGNORE INTO orders (reference_code, customer_user_id, restaurant_id, status, payment_method, subtotal, delivery_fee, total, delivery_address, placed_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, '28 Market Street, Central District', DATE_SUB(NOW(), INTERVAL ? DAY))");
    foreach ($orders as [$reference, $status, $payment, $subtotal, $fee, $total, $daysAgo]) {
        $orderInsert->bind_param('siissdddi', $reference, $ids['customer'], $restaurantId, $status, $payment, $subtotal, $fee, $total, $daysAgo);
        $orderInsert->execute();
    }
    $orderInsert->close();

    $conn->query("INSERT IGNORE INTO payments (order_id,method,amount,status,provider_reference,paid_at) SELECT id,payment_method,total,'paid',CONCAT('DEMO-',reference_code),placed_at FROM orders WHERE reference_code IN ('SV-10482','SV-10483','SV-10484','SV-10485','SV-10486','SV-10487','SV-10488','SV-10489')");

    $ledger = $conn->prepare("INSERT IGNORE INTO ledger_entries (reference_code, order_id, entry_type, party_type, party_id, gross_amount, fee_amount, net_amount, payment_method, status, created_at) SELECT CONCAT('LED-', o.reference_code), o.id, 'order_sale', 'restaurant', ?, o.total, ROUND(o.total * 0.12, 2), ROUND(o.total * 0.88, 2), o.payment_method, 'completed', o.placed_at FROM orders o WHERE o.reference_code = ? AND o.status = 'delivered'");
    foreach (['SV-10482', 'SV-10483', 'SV-10488'] as $reference) {
        $ledger->bind_param('is', $restaurantId, $reference);
        $ledger->execute();
    }
    $ledger->close();

    $cases = [
        ['CASE-1042', 'delivery_delay', 'customer', $ids['customer'], 'urgent', 'open', 'Order has not arrived', 20],
        ['CASE-1043', 'payment_question', 'customer', $ids['customer'], 'medium', 'in_review', 'Duplicate payment concern', 360],
    ];
    $caseInsert = $conn->prepare('INSERT IGNORE INTO support_cases (reference_code, case_type, reporting_role, reporting_user_id, priority, status, subject, sla_due_at) VALUES (?, ?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE))');
    foreach ($cases as [$reference, $type, $role, $userId, $priority, $status, $subject, $minutes]) {
        $caseInsert->bind_param('sssisssi', $reference, $type, $role, $userId, $priority, $status, $subject, $minutes);
        $caseInsert->execute();
    }
    $caseInsert->close();
    $conn->query("UPDATE support_cases SET order_id=(SELECT id FROM orders WHERE reference_code='SV-10482' LIMIT 1) WHERE reference_code='CASE-1042' AND order_id IS NULL");

    $audit = $conn->prepare("INSERT IGNORE INTO audit_logs (actor_user_id, action, entity_type, entity_id, after_summary, reason, ip_address, result, reference_id) VALUES (?, ?, ?, ?, ?, ?, '127.0.0.1', 'success', ?)");
    $auditRows = [
        ['admin_login', 'session', null, 'Administrator session authenticated', 'Successful secure login', 'ADM-SEED-LOGIN'],
        ['review_application', 'restaurant_application', 1, 'Application entered review queue', 'Automated risk checks passed', 'ADM-SEED-APP'],
        ['monitor_order', 'order', 1, 'Operational status reviewed', 'Live operations health check', 'ADM-SEED-ORDER'],
    ];
    foreach ($auditRows as [$action, $entityType, $entityId, $after, $reason, $reference]) {
        $audit->bind_param('ississs', $ids['admin'], $action, $entityType, $entityId, $after, $reason, $reference);
        $audit->execute();
    }
    $audit->close();

    $conn->query("INSERT IGNORE INTO promotions (code,audience,discount_type,discount_value,maximum_discount,minimum_order,usage_cap,budget,used_amount,starts_at,ends_at,status,scope) VALUES ('WELCOME20','new_customers','percentage',20,12,25,500,3000,640,DATE_SUB(NOW(),INTERVAL 10 DAY),DATE_ADD(NOW(),INTERVAL 20 DAY),'active','all_restaurants'),('LUNCH5','all_customers','fixed',5,5,20,1000,5000,1120,DATE_SUB(NOW(),INTERVAL 5 DAY),DATE_ADD(NOW(),INTERVAL 30 DAY),'active','all_restaurants')");
    $conn->query("INSERT IGNORE INTO service_areas (name,city,radius_km,status,minimum_order,driver_health) VALUES ('Central District','Central City',6,'active',15,'healthy'),('North District','Central City',5,'active',18,'balanced'),('East District','Central City',4,'active',20,'limited')");
    $conn->query("INSERT INTO fee_rules (rule_type,name,amount,unit,effective_at,status,created_by) SELECT 'platform_commission','Standard Restaurant Commission',12,'percent',NOW(),'active',{$ids['admin']} WHERE NOT EXISTS (SELECT 1 FROM fee_rules WHERE name='Standard Restaurant Commission')");
    $conn->query("INSERT IGNORE INTO payouts (reference_code,party_type,party_id,amount,status,scheduled_at) VALUES ('PAY-REST-001','restaurant',{$restaurantId},218.40,'scheduled',DATE_ADD(NOW(),INTERVAL 2 DAY)),('PAY-DRV-001','driver',{$ids['driver']},48.20,'scheduled',DATE_ADD(NOW(),INTERVAL 2 DAY))");
}
