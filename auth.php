<?php
session_start();
require_once 'db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        $_SESSION['error'] = 'Please enter both username and password.';
        header('Location: index.php');
        exit();
    }
    
    $stmt = $conn->prepare("SELECT id, username, password, role, full_name FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();
        if (password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['full_name'] = $user['full_name'];
            
            switch ($user['role']) {
                case 'customer':
                    header('Location: customer_dashboard.php');
                    break;
                case 'restaurant':
                    header('Location: restaurant_dashboard.php');
                    break;
                case 'driver':
                    header('Location: driver_dashboard.php');
                    break;
                case 'admin':
                    header('Location: admin_dashboard.php');
                    break;
                default:
                    header('Location: index.php');
            }
            exit();
        } else {
            $_SESSION['error'] = 'Invalid password.';
            header('Location: index.php');
            exit();
        }
    } else {
        $_SESSION['error'] = 'User not found.';
        header('Location: index.php');
        exit();
    }
} else {
    header('Location: index.php');
    exit();
}
?>
