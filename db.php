<?php
// db.php
$host = "localhost";
$username = "root";
$password = "";
$dbname = "savora_db";

// Connect to MySQL
$conn = new mysqli($host, $username, $password);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Create database if not exists
$sql = "CREATE DATABASE IF NOT EXISTS $dbname CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
if ($conn->query($sql) !== TRUE) {
    die("Error creating database: " . $conn->error);
}

// Select the database
$conn->select_db($dbname);

// Create users table
$sql = "CREATE TABLE IF NOT EXISTS users (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('customer', 'restaurant', 'driver', 'admin') NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";
if ($conn->query($sql) !== TRUE) {
    die("Error creating table: " . $conn->error);
}

// Insert demo data if table is empty
$result = $conn->query("SELECT id FROM users LIMIT 1");
if ($result->num_rows == 0) {
    $demo_users = [
        ['username' => 'customer', 'password' => password_hash('123456', PASSWORD_DEFAULT), 'role' => 'customer', 'full_name' => 'John Doe (Customer)'],
        ['username' => 'restaurant', 'password' => password_hash('123456', PASSWORD_DEFAULT), 'role' => 'restaurant', 'full_name' => 'Savora Burger (Owner)'],
        ['username' => 'driver', 'password' => password_hash('123456', PASSWORD_DEFAULT), 'role' => 'driver', 'full_name' => 'Mike Smith (Driver)'],
        ['username' => 'admin', 'password' => password_hash('123456', PASSWORD_DEFAULT), 'role' => 'admin', 'full_name' => 'System Admin']
    ];

    $stmt = $conn->prepare("INSERT INTO users (username, password, role, full_name) VALUES (?, ?, ?, ?)");
    foreach ($demo_users as $user) {
        $stmt->bind_param("ssss", $user['username'], $user['password'], $user['role'], $user['full_name']);
        $stmt->execute();
    }
    $stmt->close();
}

// Keep the demo dispatch pool usable even after the initial database seed.
$driver_demo_users = [
    ['username' => 'driver-nearby-2', 'password' => password_hash('123456', PASSWORD_DEFAULT), 'role' => 'driver', 'full_name' => 'Alex Rivera (Driver)'],
    ['username' => 'driver-nearby-3', 'password' => password_hash('123456', PASSWORD_DEFAULT), 'role' => 'driver', 'full_name' => 'Jordan Lee (Driver)']
];
$stmt = $conn->prepare("INSERT IGNORE INTO users (username, password, role, full_name) VALUES (?, ?, ?, ?)");
foreach ($driver_demo_users as $user) {
    $stmt->bind_param("ssss", $user['username'], $user['password'], $user['role'], $user['full_name']);
    $stmt->execute();
}
$stmt->close();
?>
