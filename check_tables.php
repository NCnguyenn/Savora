<?php
require_once __DIR__ . '/db.php';
$res = $conn->query('SHOW TABLES');
while($r = $res->fetch_array()) {
    echo $r[0] . "\n";
}
