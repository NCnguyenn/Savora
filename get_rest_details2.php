<?php
require_once __DIR__ . '/db.php';

echo "### Danh sách các nhà hàng:\n\n";
$res = $conn->query("SELECT u.username, r.name, r.cuisine, r.address FROM users u JOIN restaurants r ON u.id = r.owner_user_id");
while($row = $res->fetch_assoc()) {
    echo "- **{$row['name']}** ({$row['cuisine']})\n";
    echo "  + Tài khoản: `{$row['username']}`\n";
    echo "  + Mật khẩu: `123456`\n";
}
