<?php
require_once __DIR__ . '/db.php';
$tables=['users', 'restaurants', 'menu_items', 'customer_profiles', 'driver_profiles', 'admin_profiles', 'orders'];
foreach($tables as $t) {
    $r = $conn->query("SELECT COUNT(*) as c FROM $t");
    if($r) echo "$t: " . $r->fetch_assoc()['c'] . "\n";
}
