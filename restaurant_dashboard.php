<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'restaurant') {
    header('Location: index.php');
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Restaurant Dashboard - Savora</title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <div class="dashboard-container">
        <!-- Sidebar -->
        <aside class="sidebar">
            <div class="sidebar-logo"><i class="fa-solid fa-utensils"></i> Savora <span style="font-size: 0.8rem; display:block; color:var(--text-muted);">Restaurant Portal</span></div>
            <ul class="nav-menu">
                <li class="nav-item"><a href="#" class="nav-link active"><i class="fa-solid fa-chart-line"></i> Dashboard</a></li>
                <li class="nav-item"><a href="#" class="nav-link"><i class="fa-solid fa-list-check"></i> Orders <span class="badge badge-primary" style="margin-left: auto;">5</span></a></li>
                <li class="nav-item"><a href="#" class="nav-link"><i class="fa-solid fa-burger"></i> Menu Management</a></li>
                <li class="nav-item"><a href="#" class="nav-link"><i class="fa-solid fa-chart-pie"></i> Analytics</a></li>
                <li class="nav-item"><a href="#" class="nav-link"><i class="fa-solid fa-gear"></i> Settings</a></li>
            </ul>
            <a href="logout.php" class="nav-link logout-btn"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <div class="top-header">
                <div>
                    <h2>Welcome, <?php echo htmlspecialchars($_SESSION['full_name']); ?>!</h2>
                    <p style="color: var(--text-muted);">Here is what's happening at your restaurant today.</p>
                </div>
                <div class="user-info">
                    <span class="badge badge-success"><i class="fa-solid fa-store"></i> Accepting Orders</span>
                    <div class="avatar"><?php echo substr($_SESSION['username'], 0, 1); ?></div>
                </div>
            </div>

            <!-- Stats Grid -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon"><i class="fa-solid fa-dollar-sign"></i></div>
                    <div class="stat-details">
                        <h3>$1,245</h3>
                        <p>Today's Revenue</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fa-solid fa-bag-shopping"></i></div>
                    <div class="stat-details">
                        <h3>42</h3>
                        <p>Total Orders</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fa-solid fa-clock-rotate-left"></i></div>
                    <div class="stat-details">
                        <h3>15m</h3>
                        <p>Avg. Prep Time</p>
                    </div>
                </div>
            </div>

            <!-- Pending Orders -->
            <div class="content-card">
                <div class="card-header">
                    <h3>Recent Orders</h3>
                    <a href="#">View All</a>
                </div>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Order ID</th>
                            <th>Customer</th>
                            <th>Items</th>
                            <th>Total</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>#1043</td>
                            <td>Jane Smith</td>
                            <td>2x Cheeseburger, 1x Coke</td>
                            <td>$24.50</td>
                            <td><span class="badge badge-warning">Pending</span></td>
                            <td><button class="btn btn-primary" style="padding: 5px 10px; font-size:0.8rem;">Accept</button></td>
                        </tr>
                        <tr>
                            <td>#1042</td>
                            <td>John Doe</td>
                            <td>1x Pepperoni Pizza</td>
                            <td>$15.00</td>
                            <td><span class="badge badge-primary">Preparing</span></td>
                            <td><button class="btn" style="padding: 5px 10px; font-size:0.8rem; background:#e1e5eb;">Ready</button></td>
                        </tr>
                    </tbody>
                </table>
            </div>

        </main>
    </div>
</body>
</html>
