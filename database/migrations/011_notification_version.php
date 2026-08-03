<?php
declare(strict_types=1);
return static function (mysqli $conn): void {
    $database=(string)($conn->query('SELECT DATABASE() AS name')->fetch_assoc()['name']??'');$check=$conn->prepare("SELECT COUNT(*) AS total FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME='notifications' AND COLUMN_NAME='version'");$check->bind_param('s',$database);$check->execute();$exists=(int)$check->get_result()->fetch_assoc()['total']===1;$check->close();if(!$exists&&!$conn->query('ALTER TABLE notifications ADD COLUMN version INT NOT NULL DEFAULT 1'))throw new RuntimeException('Notification version migration failed: '.$conn->error);
};
