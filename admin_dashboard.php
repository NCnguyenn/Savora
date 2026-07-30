<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: index.php');
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Savora</title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <div class="dashboard-container">
        <!-- Sidebar -->
        <aside class="sidebar">
            <div class="sidebar-logo"><i class="fa-solid fa-utensils"></i> Savora <span style="font-size: 0.8rem; display:block; color:var(--text-muted);">Admin Control</span></div>
            <ul class="nav-menu">
                <li class="nav-item"><a href="#" class="nav-link active"><i class="fa-solid fa-chart-pie"></i> Overview</a></li>
                <li class="nav-item"><a href="#" class="nav-link"><i class="fa-solid fa-users"></i> Users</a></li>
                <li class="nav-item"><a href="#" class="nav-link"><i class="fa-solid fa-store"></i> Restaurants</a></li>
                <li class="nav-item"><a href="#" class="nav-link"><i class="fa-solid fa-motorcycle"></i> Drivers</a></li>
                <li class="nav-item"><a href="#" class="nav-link"><i class="fa-solid fa-money-bill-transfer"></i> Finance</a></li>
            </ul>
            <a href="logout.php" class="nav-link logout-btn"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <div class="top-header">
                <div>
                    <h2>System Overview</h2>
                    <p style="color: var(--text-muted);">Platform statistics and recent activities.</p>
                </div>
                <div class="user-info">
                    <span class="badge badge-info"><i class="fa-solid fa-shield-halved"></i> Admin Mode</span>
                    <div class="avatar" style="background-color: #2D3436;"><?php echo substr($_SESSION['username'], 0, 1); ?></div>
                </div>
            </div>

            <!-- Stats Grid -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon" style="background: rgba(45, 52, 54, 0.1); color: #2D3436;"><i class="fa-solid fa-users"></i></div>
                    <div class="stat-details">
                        <h3>1,250</h3>
                        <p>Total Customers</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background: rgba(252, 163, 17, 0.1); color: #fca311;"><i class="fa-solid fa-store"></i></div>
                    <div class="stat-details">
                        <h3>85</h3>
                        <p>Active Restaurants</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background: rgba(22, 101, 52, 0.1); color: #166534;"><i class="fa-solid fa-motorcycle"></i></div>
                    <div class="stat-details">
                        <h3>142</h3>
                        <p>Active Drivers</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fa-solid fa-sack-dollar"></i></div>
                    <div class="stat-details">
                        <h3>$12,450</h3>
                        <p>Platform Revenue</p>
                    </div>
                </div>
            </div>

            <!-- Recent System Activity -->
            <div class="content-card">
                <div class="card-header">
                    <h3>Recent User Registrations</h3>
                    <a href="#">Manage Users</a>
                </div>
                <table class="table">
                    <thead>
                        <tr>
                            <th>User ID</th>
                            <th>Name</th>
                            <th>Role</th>
                            <th>Joined Date</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>#U0892</td>
                            <td>Michael Scott</td>
                            <td><span class="badge badge-primary">Customer</span></td>
                            <td>Just now</td>
                            <td><span class="badge badge-success">Active</span></td>
                        </tr>
                        <tr>
                            <td>#R0045</td>
                            <td>Pizza Planet</td>
                            <td><span class="badge badge-warning">Restaurant</span></td>
                            <td>2 hours ago</td>
                            <td><span class="badge badge-warning">Pending Approval</span></td>
                        </tr>
                        <tr>
                            <td>#D0234</td>
                            <td>Tom Hardy</td>
                            <td><span class="badge badge-info">Driver</span></td>
                            <td>1 day ago</td>
                            <td><span class="badge badge-success">Active</span></td>
                        </tr>
                    </tbody>
                </table>
            </div>

        </main>
    </div>
</body>
</html>
