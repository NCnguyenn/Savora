<?php
declare(strict_types=1);

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
        "CREATE TABLE IF NOT EXISTS audit_logs (
            id BIGINT AUTO_INCREMENT PRIMARY KEY, actor_user_id INT NOT NULL, action VARCHAR(100) NOT NULL, entity_type VARCHAR(60) NOT NULL, entity_id BIGINT NULL, before_summary TEXT, after_summary TEXT, reason VARCHAR(500), ip_address VARCHAR(64), session_id VARCHAR(128), result VARCHAR(30) NOT NULL DEFAULT 'success', reference_id VARCHAR(60) NOT NULL UNIQUE, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS idempotency_keys (
            id BIGINT AUTO_INCREMENT PRIMARY KEY, actor_user_id INT NOT NULL, idempotency_key VARCHAR(100) NOT NULL, action VARCHAR(100) NOT NULL, response_json LONGTEXT NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_actor_key(actor_user_id, idempotency_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS user_sessions (
            id BIGINT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, session_hash VARCHAR(128) NOT NULL UNIQUE, ip_address VARCHAR(64), user_agent VARCHAR(500), revoked_at DATETIME NULL, last_seen_at DATETIME NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
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
}

function platform_seed(mysqli $conn): void
{
    $users = [
        ['customer', 'customer@savora.test', 'customer', 'John Doe (Customer)'],
        ['restaurant', 'restaurant@savora.test', 'restaurant', 'Savora Burger (Owner)'],
        ['driver', 'driver@savora.test', 'driver', 'Mike Smith (Driver)'],
        ['admin', 'admin@savora.test', 'admin', 'System Admin'],
        ['driver-nearby-2', 'alex@savora.test', 'driver', 'Alex Rivera (Driver)'],
        ['driver-nearby-3', 'jordan@savora.test', 'driver', 'Jordan Lee (Driver)'],
    ];
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
    ];
    $setting = $conn->prepare('INSERT IGNORE INTO platform_settings (setting_key, setting_value, value_type) VALUES (?, ?, ?)');
    foreach ($settings as [$key, $value, $type]) {
        $setting->bind_param('sss', $key, $value, $type);
        $setting->execute();
    }
    $setting->close();
}
