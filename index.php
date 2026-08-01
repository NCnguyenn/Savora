<?php
require_once __DIR__ . '/lib/session_security.php';
savora_start_session();
// Automatically initialize DB and create demo users if they don't exist
require_once 'db.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Savora Food Delivery</title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <div class="login-container">
        <!-- Left Side: Branding -->
        <div class="login-left">
            <h1><i class="fa-solid fa-utensils"></i> Savora</h1>
            <p>Your favorite meals delivered fresh and fast. Seamlessly connecting customers, restaurants, and drivers.</p>
        </div>
        
        <!-- Right Side: Login Form -->
        <div class="login-right">
            <div class="login-card">
                <h2>Welcome Back</h2>
                <p>Please enter your details to sign in.</p>
                
                <?php if (isset($_SESSION['error'])): ?>
                    <div class="alert alert-error">
                        <?php 
                            echo $_SESSION['error']; 
                            unset($_SESSION['error']);
                        ?>
                    </div>
                <?php endif; ?>

                <form action="auth.php" method="POST">
                    <div class="form-group">
                        <label for="username">Username</label>
                        <input type="text" id="username" name="username" class="form-control" placeholder="Enter your username" required>
                    </div>
                    <div class="form-group">
                        <label for="password">Password</label>
                        <input type="password" id="password" name="password" class="form-control" placeholder="Enter your password" required>
                    </div>
                    <button type="submit" class="btn btn-primary login-btn">Sign In</button>
                </form>

                <!-- Demo Credentials for Testing -->
                <div class="demo-credentials">
                    <h4>Demo Accounts (Password: 123456)</h4>
                    <ul>
                        <li>Customer: <span>customer</span></li>
                        <li>Restaurant: <span>restaurant</span></li>
                        <li>Driver: <span>driver</span></li>
                        <li>Admin: <span>admin</span></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
