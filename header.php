<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestionnaire Magasin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background-color: #f5f7fa;
            padding-top: 90px; /* Space for fixed header */
        }

        /* Modern Header Styles */
        .header-container {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            background: linear-gradient(135deg, #2c3e50 0%, #1a2530 100%);
            color: #ecf0f1;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.2);
            z-index: 1000;
        }

        .header-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 30px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .logo-container {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .logo-container i {
            font-size: 32px;
            color: #3498db;
        }

        .logo-container h1 {
            font-size: 24px;
            font-weight: 700;
            color: #fff;
        }

        .user-container {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .user-info {
            text-align: right;
        }

        .user-info p {
            font-size: 14px;
            margin-bottom: 5px;
            color: #fff;
            font-weight: 500;
        }

        .user-info .role {
            font-size: 12px;
            background: rgba(52, 152, 219, 0.2);
            color: #3498db;
            padding: 4px 10px;
            border-radius: 16px;
        }

        .avatar {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            background: linear-gradient(135deg, #3498db, #2c3e50);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 20px;
            color: white;
        }

        .logout-link {
            margin-left: 20px;
        }

        .logout-link a {
            color: #e74c3c;
            font-size: 16px;
            text-decoration: none;
            padding: 10px 15px;
            border-radius: 5px;
            transition: background 0.3s;
        }

        .logout-link a:hover {
            background: rgba(231, 76, 60, 0.2);
        }

        .logout-link a i {
            margin-right: 8px;
        }

        /* Navigation Menu */
        .nav-container {
            padding: 0 30px;
        }

        .nav-menu {
            display: flex;
            list-style: none;
        }

        .nav-menu li {
            margin-right: 5px;
        }

        .nav-menu li a {
            display: flex;
            align-items: center;
            color: #ecf0f1;
            text-decoration: none;
            padding: 15px 25px;
            font-size: 15px;
            font-weight: 500;
            transition: all 0.3s ease;
            border-radius: 5px 5px 0 0;
            position: relative;
        }

        .nav-menu li a:hover {
            background: rgba(255, 255, 255, 0.15);
        }

        .nav-menu li a.active {
            background: rgba(255, 255, 255, 0.2);
            color: #3498db;
        }

        .nav-menu li a.active::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: #3498db;
            border-radius: 2px 2px 0 0;
        }

        .nav-menu li a i {
            font-size: 16px;
            margin-right: 10px;
            width: 20px;
            text-align: center;
        }

        /* Mobile menu toggle */
        .mobile-toggle {
            display: none;
            background: none;
            border: none;
            color: white;
            font-size: 24px;
            cursor: pointer;
        }

        /* Main Content */
        .main-content {
            padding: 30px;
            min-height: calc(100vh - 90px);
        }

        .dashboard-header {
            background: white;
            padding: 30px 35px;
            border-radius: 18px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.08);
            margin-bottom: 40px;
        }

        .dashboard-header h2 {
            color: #2c3e50;
            font-size: 32px;
            margin-bottom: 15px;
            font-weight: 700;
        }

        .dashboard-header p {
            color: #7f8c8d;
            font-size: 18px;
        }

        .dashboard-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 25px;
            margin-bottom: 40px;
        }

        .stat-card {
            background: white;
            border-radius: 18px;
            padding: 30px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.08);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 12px 30px rgba(0, 0, 0, 0.15);
        }

        .stat-card h3 {
            color: #7f8c8d;
            font-size: 18px;
            margin-bottom: 15px;
        }

        .stat-card .value {
            font-size: 38px;
            font-weight: 700;
            color: #2c3e50;
            margin-bottom: 15px;
        }

        .stat-card .trend {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 16px;
            font-weight: 500;
        }

        .trend.positive {
            color: #27ae60;
        }

        .trend.negative {
            color: #c0392b;
        }

        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 25px;
            margin-bottom: 40px;
        }

        .action-card {
            background: white;
            border-radius: 18px;
            padding: 30px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.08);
            text-align: center;
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .action-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 30px rgba(0, 0, 0, 0.15);
            background: linear-gradient(135deg, #3498db, #2c6fd1);
        }

        .action-card:hover i, 
        .action-card:hover h3,
        .action-card:hover p {
            color: white;
        }

        .action-card i {
            font-size: 48px;
            color: #3498db;
            margin-bottom: 20px;
            transition: all 0.3s ease;
        }

        .action-card h3 {
            color: #2c3e50;
            font-size: 22px;
            margin-bottom: 10px;
            transition: all 0.3s ease;
        }

        .action-card p {
            color: #7f8c8d;
            font-size: 16px;
            transition: all 0.3s ease;
        }

        .recent-activity {
            background: white;
            border-radius: 18px;
            padding: 30px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.08);
        }

        .recent-activity h2 {
            color: #2c3e50;
            font-size: 26px;
            margin-bottom: 25px;
            font-weight: 700;
        }

        .activity-item {
            display: flex;
            align-items: center;
            padding: 20px;
            border-bottom: 1px solid #eee;
            transition: background 0.3s ease;
        }

        .activity-item:hover {
            background: #f8f9fa;
        }

        .activity-icon {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: rgba(52, 152, 219, 0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 20px;
        }

        .activity-icon i {
            color: #3498db;
            font-size: 22px;
        }

        .activity-content {
            flex: 1;
        }

        .activity-content h4 {
            color: #2c3e50;
            font-size: 18px;
            margin-bottom: 5px;
        }

        .activity-content p {
            color: #7f8c8d;
            font-size: 16px;
        }

        .activity-time {
            color: #95a5a6;
            font-size: 14px;
        }

        /* Responsive Design */
        @media (max-width: 992px) {
            .nav-menu li a span {
                display: none;
            }
            
            .nav-menu li a i {
                margin-right: 0;
                font-size: 20px;
            }
            
            .user-info {
                display: none;
            }
            
            .logo-container h1 {
                font-size: 20px;
            }
        }

        @media (max-width: 768px) {
            .header-top {
                padding: 15px 20px;
            }
            
            .nav-container {
                display: none;
                position: absolute;
                top: 100%;
                left: 0;
                width: 100%;
                background: #1a2530;
                padding: 0;
            }
            
            .nav-container.active {
                display: block;
            }
            
            .nav-menu {
                flex-direction: column;
            }
            
            .nav-menu li {
                margin-right: 0;
                border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            }
            
            .nav-menu li a {
                border-radius: 0;
                padding: 15px 20px;
            }
            
            .nav-menu li a span {
                display: inline-block;
            }
            
            .mobile-toggle {
                display: block;
            }
            
            .dashboard-stats, .quick-actions {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <!-- Header Navigation -->
    <div class="header-container">
        <div class="header-top">
            <div class="logo-container">
                <i class="fas fa-store"></i>
                <h1>Gestionnaire Magasin</h1>
            </div>
            
            <button class="mobile-toggle" id="mobileToggle">
                <i class="fas fa-bars"></i>
            </button>
            
            <div class="user-container">
                <div class="user-info">
                    <p><?= htmlspecialchars($_SESSION['user_name']) ?></p>
                    <span class="role">Administrateur</span>
                </div>
                <div class="avatar">
                    <?= strtoupper(substr($_SESSION['user_name'], 0, 1)) ?>
                </div>
                <div class="logout-link">
                    <a href="../logout.php"><i class="fas fa-sign-out-alt"></i> Déconnexion</a>
                </div>
            </div>
        </div>
        
        <div class="nav-container" id="navContainer">
            <ul class="nav-menu">
                <li><a href="admin.php" class="active"><i class="fas fa-tachometer-alt"></i> <span>Tableau de bord</span></a></li>
                <li><a href="gestion_produits.php"><i class="fas fa-box"></i> <span>Gestion des produits</span></a></li>
                <li><a href="gestion_utilisateurs.php"><i class="fas fa-users"></i> <span>Gestion des utilisateurs</span></a></li>
                <li><a href="historique_ventes.php"><i class="fas fa-history"></i> <span>Historique des ventes</span></a></li>
                <li><a href="caisse.php"><i class="fas fa-cash-register"></i> <span>Caisse</span></a></li>
                <li><a href="rapports.php"><i class="fas fa-file-alt"></i> <span>Rapports</span></a></li>
            </ul>
        </div>
    </div>


    <script>
        // Mobile menu toggle
        document.getElementById('mobileToggle').addEventListener('click', function() {
            document.getElementById('navContainer').classList.toggle('active');
        });
        
        // Set active link based on current page
        document.addEventListener('DOMContentLoaded', function() {
            const currentPage = window.location.pathname.split('/').pop();
            const links = document.querySelectorAll('.nav-menu li a');
            
            links.forEach(link => {
                if (link.getAttribute('href') === currentPage) {
                    link.classList.add('active');
                } else {
                    link.classList.remove('active');
                }
            });
        });
        
        // Close menu when clicking outside
        document.addEventListener('click', function(event) {
            const navContainer = document.getElementById('navContainer');
            const mobileToggle = document.getElementById('mobileToggle');
            
            if (navContainer.classList.contains('active') && 
                !navContainer.contains(event.target) && 
                event.target !== mobileToggle) {
                navContainer.classList.remove('active');
            }
        });
    </script>
</body>
</html>