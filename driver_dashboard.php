<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'driver') {
    header('Location: index.php');
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Driver Dashboard - Savora</title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <div class="dashboard-container">
        <!-- Sidebar -->
        <aside class="sidebar">
            <div class="sidebar-logo"><i class="fa-solid fa-utensils"></i> Savora <span style="font-size: 0.8rem; display:block; color:var(--text-muted);">Driver App</span></div>
            <ul class="nav-menu">
                <li class="nav-item"><a href="#" class="nav-link active"><i class="fa-solid fa-map-location-dot"></i> Deliveries</a></li>
                <li class="nav-item"><a href="#" class="nav-link"><i class="fa-solid fa-wallet"></i> Earnings</a></li>
                <li class="nav-item"><a href="#" class="nav-link"><i class="fa-solid fa-clock-rotate-left"></i> History</a></li>
                <li class="nav-item"><a href="#" class="nav-link"><i class="fa-solid fa-gear"></i> Settings</a></li>
            </ul>
            <a href="logout.php" class="nav-link logout-btn"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <div class="top-header">
                <div>
                    <h2>Drive Safe, <?php echo htmlspecialchars($_SESSION['full_name']); ?>! 🏍️</h2>
                    <p style="color: var(--text-muted);">You are currently online and looking for orders.</p>
                </div>
                <div class="user-info">
                    <span class="badge badge-success"><i class="fa-solid fa-wifi"></i> Online</span>
                    <div class="avatar" style="background-color: var(--secondary-color);"><?php echo substr($_SESSION['username'], 0, 1); ?></div>
                </div>
            </div>

            <div style="display: flex; gap: 2rem;">
                <!-- Map Area -->
                <div style="flex: 2;">
                    <div class="map-placeholder" style="height: 400px; background-image: url('https://images.unsplash.com/photo-1524661135-423995f22d0b?auto=format&fit=crop&w=800&q=80'); background-size: cover; color: #222; text-shadow: 0 0 5px white; font-weight:bold;">
                        [ Live GPS Map Tracking Simulation ]
                    </div>
                </div>

                <!-- Active Task -->
                <div style="flex: 1;">
                    <div class="content-card">
                        <div class="card-header">
                            <h3>Current Delivery</h3>
                        </div>
                        <div style="margin-bottom: 20px;">
                            <h4 style="color: var(--primary-color);">Order #1042</h4>
                            <p style="font-size: 0.9rem; color: var(--text-muted);">Pickup: Savora Burger</p>
                            <p style="font-size: 0.9rem; color: var(--text-muted);">Dropoff: 123 Main St, Apt 4B</p>
                        </div>
                        
                        <div style="border-left: 2px dashed var(--primary-color); padding-left: 15px; margin-bottom: 20px;">
                            <div style="margin-bottom: 10px;">
                                <strong><i class="fa-regular fa-circle-check" style="color: var(--primary-color);"></i> Arrived at Restaurant</strong>
                            </div>
                            <div style="margin-bottom: 10px;">
                                <strong><i class="fa-regular fa-circle" style="color: var(--text-muted);"></i> Picked up order</strong>
                            </div>
                            <div style="margin-bottom: 10px;">
                                <strong><i class="fa-regular fa-circle" style="color: var(--text-muted);"></i> Delivered to Customer</strong>
                            </div>
                        </div>

                        <button class="btn btn-primary" style="width: 100%;">Confirm Pickup</button>
                    </div>

                    <div class="stats-grid" style="grid-template-columns: 1fr;">
                        <div class="stat-card" style="padding: 1rem;">
                            <div class="stat-icon" style="width:40px; height:40px; font-size:1.2rem;"><i class="fa-solid fa-wallet"></i></div>
                            <div class="stat-details">
                                <h3 style="font-size: 1.2rem;">$85.50</h3>
                                <p>Today's Earnings</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </main>
    </div>
</body>
</html>
