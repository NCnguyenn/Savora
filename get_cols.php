<?php
require_once __DIR__ . '/db.php';
$res = $conn->query("DESCRIBE restaurants");
while($row = $res->fetch_assoc()) {
    echo $row['Field'] . "\n";
}
