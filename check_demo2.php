<?php
require_once __DIR__ . '/db.php';

$roles = ['customer', 'restaurant', 'driver', 'admin'];
echo "Checking Demo Accounts:\n";

foreach ($roles as $role) {
    $res = $conn->query("SELECT id, username FROM users WHERE username = '$role'");
    if ($row = $res->fetch_assoc()) {
        echo "Found user '$role' (ID: {$row['id']})\n";
        
        if ($role === 'restaurant') {
            $r = $conn->query("SELECT id, name FROM restaurants WHERE user_id = {$row['id']}");
            if ($r && $p = $r->fetch_assoc()) {
                echo "  -> Has restaurant profile: {$p['name']}\n";
                $menu_res = $conn->query("SELECT COUNT(*) as c FROM menu_items WHERE restaurant_id = {$p['id']}");
                $menu_c = $menu_res->fetch_assoc()['c'];
                echo "  -> Has $menu_c menu items\n";
            } else {
                echo "  -> NO profile\n";
            }
        } elseif ($role === 'driver') {
            $r = $conn->query("SELECT id, first_name, last_name FROM driver_profiles WHERE user_id = {$row['id']}");
            if ($r && $p = $r->fetch_assoc()) {
                echo "  -> Has driver profile: {$p['first_name']} {$p['last_name']}\n";
            } else {
                echo "  -> NO profile\n";
            }
        } elseif ($role === 'customer') {
            $r = $conn->query("SELECT id, first_name, last_name FROM customer_profiles WHERE user_id = {$row['id']}");
            if ($r && $p = $r->fetch_assoc()) {
                echo "  -> Has customer profile: {$p['first_name']} {$p['last_name']}\n";
            } else {
                echo "  -> NO profile\n";
            }
        } elseif ($role === 'admin') {
            $r = $conn->query("SELECT id, name FROM admin_profiles WHERE user_id = {$row['id']}");
            if ($r && $p = $r->fetch_assoc()) {
                echo "  -> Has admin profile: {$p['name']}\n";
            } else {
                echo "  -> NO profile\n";
            }
        }
    } else {
        echo "User '$role' NOT FOUND\n";
    }
}
