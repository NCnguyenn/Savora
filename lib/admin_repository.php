<?php
declare(strict_types=1);

function admin_page_data(mysqli $conn, string $page, array $filters = []): array
{
    $role = $filters['role'] ?? null;
    if ($page === 'accounts' && is_string($role) && in_array($role, ['customer', 'restaurant', 'driver', 'admin'], true)) {
        $stmt = $conn->prepare('SELECT id, username, role, full_name, email, phone, status, last_login_at, created_at, version FROM users WHERE role = ? ORDER BY created_at DESC');
        $stmt->bind_param('s', $role);
    } else {
        $stmt = $conn->prepare('SELECT id, username, role, full_name, email, phone, status, last_login_at, created_at, version FROM users ORDER BY created_at DESC');
    }
    $stmt->execute();
    $accounts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return [
        'page' => $page,
        'filters' => $filters,
        'accounts' => $accounts,
    ];
}
