<?php
require_once __DIR__ . '/db.php';
$res = $conn->query("SELECT u.username, r.name, r.description FROM users u JOIN restaurants r ON u.id = r.user_id");
while($row = $res->fetch_assoc()) {
    echo "- Tên hiển thị trên Home: **{$row['name']}**\n";
    echo "  + Tài khoản: `{$row['username']}`\n";
    echo "  + Mật khẩu: `123456`\n";
}
