<?php
require_once __DIR__ . '/db.php';

echo "Restaurants:\n";
$res = $conn->query("SELECT u.username, r.name FROM users u JOIN restaurants r ON u.id = r.user_id");
while($row = $res->fetch_assoc()) {
    echo "- Username: {$row['username']} | Name: {$row['name']}\n";
}

echo "\nDrivers:\n";
$res = $conn->query("SELECT u.username, d.first_name, d.last_name FROM users u JOIN driver_profiles d ON u.id = d.user_id");
while($row = $res->fetch_assoc()) {
    echo "- Username: {$row['username']} | Name: {$row['first_name']} {$row['last_name']}\n";
}

echo "\nCustomers:\n";
$res = $conn->query("SELECT u.username, c.first_name, c.last_name FROM users u JOIN customer_profiles c ON u.id = c.user_id");
while($row = $res->fetch_assoc()) {
    echo "- Username: {$row['username']} | Name: {$row['first_name']} {$row['last_name']}\n";
}
