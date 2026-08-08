<?php
require_once __DIR__ . '/db.php';

$roles = ['customer', 'restaurant', 'driver', 'admin'];

foreach ($roles as $role) {
    $res = $conn->query("SELECT id, username FROM users WHERE username = '$role'");
    if ($row = $res->fetch_assoc()) {
        echo "Found user '$role' (ID: {$row['id']})\n";
        
        // Check profile
        if ($role === 'restaurant') {
            $r = $conn->query("SELECT id, name FROM restaurants WHERE user_id = {$row['id']}");
            if ($r && $p = $r->fetch_assoc()) {
                echo "  -> Has restaurant profile: {$p['name']}\n";
            } else {
                echo "  -> NO profile\n";
            }
        } elseif ($role === 'driver') {
            $r = $conn->query("SELECT id, name FROM drivers WHERE user_id = {$row['id']}");
            if ($r && $p = $r->fetch_assoc()) {
                echo "  -> Has driver profile: {$p['name']}\n";
            } else {
                echo "  -> NO profile\n";
            }
        } elseif ($role === 'customer') {
            $r = $conn->query("SELECT id, name FROM customers WHERE user_id = {$row['id']}");
            if ($r && $p = $r->fetch_assoc()) {
                echo "  -> Has customer profile: {$p['name']}\n";
            } else {
                echo "  -> NO profile\n";
            }
        }
    } else {
        echo "User '$role' NOT FOUND\n";
    }
}
