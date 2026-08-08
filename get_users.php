<?php
require_once __DIR__ . '/db.php';
$res = $conn->query("SELECT id, username, role FROM users");
while($row = $res->fetch_assoc()) {
    echo "ID: {$row['id']} | Username: {$row['username']} | Role: {$row['role']}\n";
}
