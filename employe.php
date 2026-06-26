<?php
session_start();

// Redirect to login if not authenticated
if (!isset($_SESSION['user_role']) || !isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

require_once 'config.php';

// Get current user's details
$userId = $_SESSION['user_id'];
$userRole = $_SESSION['user_role'];
$queryUser = "SELECT nom, prenom, role, email FROM utilisateurs WHERE id = ?";
$stmtUser = $conn->prepare($queryUser);
$stmtUser->bind_param("i", $userId);
$stmtUser->execute();
$user = $stmtUser->get_result()->fetch_assoc();
$stmtUser->close();

// Employee's sales today (for caissier/gestionnaire)
$querySalesToday = "SELECT COUNT(*) AS total_sales, SUM(total_ttc) AS total_amount 
                    FROM ventes 
                    WHERE utilisateur_id = ? AND DATE(date_vente) = CURDATE()";
$stmtSales = $conn->prepare($querySalesToday);
$stmtSales->bind_param("i", $userId);
$stmtSales->execute();
$salesToday = $stmtSales->get_result()->fetch_assoc();
$totalSales = $salesToday['total_sales'] ?? 0;
$totalAmount = $salesToday['total_amount'] ?? 0;
$stmtSales->close();

// Recent activities (logs) for the current user
$queryRecentLogs = "SELECT action, date_action 
                    FROM logs 
                    WHERE utilisateur_id = ? 
                    ORDER BY date_action DESC 
                    LIMIT 5";
$stmtLogs = $conn->prepare($queryRecentLogs);
$stmtLogs->bind_param("i", $userId);
$stmtLogs->execute();
$recentLogs = [];
$resultLogs = $stmtLogs->get_result();
while ($row = $resultLogs->fetch_assoc()) {
    $recentLogs[] = $row;
}
$stmtLogs->close();

// Recent sales for the current user (only for today)
$queryRecentSales = "SELECT v.id, v.date_vente, v.total_ttc, c.nom AS client_nom 
                     FROM ventes v
                     LEFT JOIN clients c ON v.client_id = c.id
                     WHERE v.utilisateur_id = ? AND DATE(v.date_vente) = CURDATE()
                     ORDER BY v.date_vente DESC LIMIT 5";
$stmtSales = $conn->prepare($queryRecentSales);
$stmtSales->bind_param("i", $userId);
$stmtSales->execute();
$recentSales = [];
$resultSales = $stmtSales->get_result();
while ($row = $resultSales->fetch_assoc()) {
    $recentSales[] = $row;
}
$stmtSales->close();

// Admin-only: Total employees and active employees
$totalEmployees = 0;
$activeEmployees = 0;
if ($userRole === 'admin') {
    $queryEmployees = "SELECT COUNT(*) AS total_employees FROM utilisateurs";
    $resultEmployees = $conn->query($queryEmployees);
    if ($resultEmployees && $rowE = $resultEmployees->fetch_assoc()) {
        $totalEmployees = $rowE['total_employees'];
    }

    $queryActiveEmployees = "SELECT COUNT(*) AS active_employees FROM utilisateurs WHERE statut = 'actif'";
    $resultActiveEmployees = $conn->query($queryActiveEmployees);
    if ($resultActiveEmployees && $rowA = $resultActiveEmployees->fetch_assoc()) {
        $activeEmployees = $rowA['active_employees'];
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Magasin Scolaire - Tableau de Bord Employé</title>
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #1e3a8a;
            --primary-light: #60a5fa;
            --secondary: #8b5cf6;
            --secondary-light: #c4b5fd;
            --success: #10b981;
            --success-light: #6ee7b7;
            --warning: #f59e0b;
            --danger: #ef4444;
            --gray: #6b7280;
            --light-gray: #e5e7eb;
            --card-shadow: 0 8px 24px rgba(0,0,0,0.12);
            --transition: all 0.4s ease-in-out;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Inter', sans-serif;
        }

        body {
            background: url('https://images.unsplash.com/photo-1503676389279-5ec451718f92?auto=format&fit=crop&q=80') no-repeat center center fixed;
            background-size: cover;
            color: #1f2937;
            font-size: 16px;
            line-height: 1.5;
        }

        .main-container {
            max-width: 1500px;
            margin: 0 auto;
            padding: 32px;
        }

        .nav-bar {
            background: rgba(255,255,255,0.95);
            box-shadow: var(--card-shadow);
            padding: 20px 32px;
            border-radius: 16px;
            margin-bottom: 32px;
            position: sticky;
            top: 0;
            z-index: 1000;
            backdrop-filter: blur(10px);
        }

        .nav-bar ul {
            display: flex;
            justify-content: center;
            gap: 40px;
        }

        .nav-bar a {
            color: var(--gray);
            font-weight: 600;
            text-decoration: none;
            padding: 8px 16px;
            border-radius: 8px;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .nav-bar a:hover {
            color: var(--primary);
            background: var(--light-gray);
            transform: scale(1.05);
        }

        .header {
            background: linear-gradient(135deg, rgba(30,58,138,0.9), rgba(139,92,246,0.9)),
                        url('maktebti-logo.png') no-repeat center center;
            background-size: contain;
            background-blend-mode: overlay;
            padding: 40px;
            border-radius: 20px;
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 32px;
            position: relative;
            overflow: hidden;
            box-shadow: var(--card-shadow);
            min-height: 200px;
        }

        .header::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.3), transparent);
            animation: rotate 20s linear infinite;
            opacity: 0.5;
        }

        .header-left h1 {
            font-size: 32px;
            font-weight: 800;
            display: flex;
            align-items: center;
            gap: 16px;
            text-shadow: 0 2px 4px rgba(0,0,0,0.2);
            z-index: 1;
        }

        .header-left p {
            font-size: 18px;
            color: rgba(255,255,255,0.95);
            font-weight: 500;
            z-index: 1;
        }

        .header-right {
            display: flex;
            align-items: center;
            gap: 32px;
            z-index: 1;
        }

        .date-display {
            display: flex;
            align-items: center;
            gap: 12px;
            background: rgba(255,255,255,0.98);
            padding: 12px 24px;
            border-radius: 24px;
            box-shadow: var(--card-shadow);
            font-size: 16px;
            color: var(--primary);
            font-weight: 600;
        }

        .dashboard-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 24px;
            margin-bottom: 32px;
        }

        .stat-card {
            background: rgba(255,255,255,0.95);
            border-radius: 20px;
            box-shadow: var(--card-shadow);
            padding: 24px;
            display: flex;
            align-items: center;
            gap: 20px;
            transition: var(--transition);
            position: relative;
            overflow: hidden;
            cursor: pointer;
            border: 1px solid rgba(0,0,0,0.05);
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 8px;
            height: 100%;
            transition: var(--transition);
        }

        .stat-card:hover {
            transform: translateY(-6px);
            box-shadow: 0 12px 32px rgba(0,0,0,0.15);
            border-color: var(--primary-light);
        }

        .stat-card.sales-card {
            background-image: url('https://images.unsplash.com/photo-1503676389279-5ec451718f92?auto=format&fit=crop&q=80');
        }

        .stat-card.amount-card {
            background-image: url('https://images.unsplash.com/photo-1503676260728-954c8a314acd?auto=format&fit=crop&q=80');
        }

        .stat-card.employees-card {
            background-image: url('https://images.unsplash.com/photo-1503676431399-993e0b0d5a78?auto=format&fit=crop&q=80');
        }

        .stat-card.active-employees-card {
            background-image: url('https://images.unsplash.com/photo-1503676776686-dad6f9a39095?auto=format&fit=crop&q=80');
        }

        .stat-card.sales-card::before {
            background: linear-gradient(45deg, var(--success), var(--success-light));
        }

        .stat-card.amount-card::before {
            background: linear-gradient(45deg, var(--primary), var(--primary-light));
        }

        .stat-card.employees-card::before {
            background: linear-gradient(45deg, var(--warning), #facc15);
        }

        .stat-card.active-employees-card::before {
            background: linear-gradient(45deg, var(--success), var(--success-light));
        }

        .card-icon {
            width: 64px;
            height: 64px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            background: rgba(0,0,0,0.08);
            transition: var(--transition);
        }

        .sales-card .card-icon {
            background: linear-gradient(135deg, var(--success-light), var(--success));
            color: white;
        }

        .amount-card .card-icon {
            background: linear-gradient(135deg, var(--primary-light), var(--primary));
            color: white;
        }

        .employees-card .card-icon, .active-employees-card .card-icon {
            background: linear-gradient(135deg, #facc15, var(--warning));
            color: white;
        }

        .card-content h3 {
            font-size: 18px;
            font-weight: 600;
            color: var(--gray);
            margin-bottom: 8px;
        }

        .card-content h2 {
            font-size: 28px;
            font-weight: 700;
            color: #111827;
        }

        .recent-activity, .recent-sales {
            background: rgba(255,255,255,0.95);
            border-radius: 20px;
            box-shadow: var(--card-shadow);
            padding: 24px;
            margin-bottom: 32px;
            border: 1px solid rgba(0,0,0,0.05);
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .section-header h3 {
            font-size: 20px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 12px;
            color: var(--primary);
        }

        .section-header a {
            color: var(--secondary);
            font-weight: 600;
            text-decoration: none;
            transition: var(--transition);
        }

        .section-header a:hover {
            color: var(--secondary-light);
            transform: translateX(4px);
        }

        .activity-list, .sales-list {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .activity-item, .sales-item {
            display: flex;
            align-items: center;
            padding: 16px;
            border-radius: 12px;
            background: rgba(249,250,251,0.95);
            transition: var(--transition);
            font-size: 15px;
            border: 1px solid var(--light-gray);
        }

        .activity-item:hover, .sales-item:hover {
            background: linear-gradient(145deg, #f3f4f6, #ffffff);
            transform: translateX(6px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
        }

        .activity-icon, .sales-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            background: linear-gradient(135deg, var(--primary-light), var(--primary));
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            margin-right: 16px;
        }

        .sales-icon {
            background: linear-gradient(135deg, var(--success-light), var(--success));
        }

        .activity-details h4, .sales-details h4 {
            font-size: 16px;
            font-weight: 600;
            color: #111827;
            margin-bottom: 6px;
        }

        .activity-details p, .sales-details p {
            font-size: 14px;
            color: var(--gray);
        }

        .sales-meta .amount {
            font-weight: 700;
            color: var(--success);
            font-size: 15px;
        }

        .activity-meta .time, .sales-meta .time {
            font-size: 13px;
            color: var(--gray);
        }

        .quick-actions {
            background: rgba(255,255,255,0.95);
            border-radius: 20px;
            box-shadow: var(--card-shadow);
            padding: 24px;
            border: 1px solid rgba(0,0,0,0.05);
        }

        .action-buttons {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
        }

        .action-btn {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 24px;
            border-radius: 16px;
            background: linear-gradient(145deg, #ffffff, #f3f4f6);
            border: 2px solid var(--light-gray);
            color: var(--primary);
            font-size: 15px;
            font-weight: 600;
            transition: var(--transition);
            cursor: pointer;
            text-align: center;
            text-decoration: none;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            position: relative;
            overflow: hidden;
        }

        .action-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: url('https://images.unsplash.com/photo-1503676260728-954c8a314acd?auto=format&fit=crop&q=80') center center;
            background-size: cover;
            opacity: 0.1;
            z-index: -1;
        }

        .action-btn:hover {
            background: linear-gradient(145deg, var(--primary-light), var(--secondary-light));
            transform: translateY(-4px);
            border-color: var(--secondary);
            box-shadow: 0 8px 20px rgba(30, 64, 175, 0.2);
            color: white;
        }

        .action-btn i {
            font-size: 32px;
            margin-bottom: 12px;
            transition: var(--transition);
        }

        .action-btn:hover i {
            transform: scale(1.1);
        }

        .no-data {
            text-align: center;
            padding: 32px;
            color: var(--gray);
            font-size: 16px;
            background: rgba(249,250,251,0.95);
            border-radius: 12px;
        }

        @media (max-width: 1024px) {
            .main-container {
                padding: 20px;
            }

            .dashboard-stats {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .header {
                flex-direction: column;
                align-items: flex-start;
                gap: 20px;
            }

            .header-right {
                width: 100%;
                justify-content: space-between;
            }

            .nav-bar ul {
                flex-direction: column;
                gap: 20px;
            }

            .action-buttons {
                grid-template-columns: 1fr;
            }
        }

        @keyframes rotate {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .fade-in {
            animation: fadeIn 0.6s ease-out;
        }

        .tooltip {
            position: relative;
        }

        .tooltip:hover::after {
            content: attr(data-tooltip);
            position: absolute;
            bottom: 100%;
            left: 50%;
            transform: translateX(-50%);
            background: var(--primary);
            color: white;
            padding: 8px 16px;
            border-radius: 8px;
            font-size: 13px;
            white-space: nowrap;
            z-index: 1000;
            opacity: 0.95;
        }
    </style>
</head>
<body>
    <div class="nav-bar">
        <ul>
            <li><a href="dashboard/caisse.php" class="tooltip" data-tooltip="Gérer les ventes"><i class="fas fa-cash-register"></i> Caisse</a></li>
            <?php if ($userRole === 'admin'): ?>
                <li><a href="employees.php" class="tooltip" data-tooltip="Gérer les employés"><i class="fas fa-users-cog"></i> Gestion Employés</a></li>
                <li><a href="add_employee.php" class="tooltip" data-tooltip="Ajouter un nouvel employé"><i class="fas fa-user-plus"></i> Ajouter Employé</a></li>
            <?php endif; ?>
            <li><a href="logout.php" class="tooltip" data-tooltip="Se déconnecter"><i class="fas fa-sign-out-alt"></i> Déconnexion</a></li>
        </ul>
    </div>

    <div class="main-container fade-in">
        <div class="header">
            <div class="header-left">
                <h1><i class="fas fa-user"></i> Tableau de Bord Employé</h1>
                <p>Bonjour, <?php echo htmlspecialchars($user['prenom'] . ' ' . $user['nom']); ?> (<?php echo htmlspecialchars($user['role']); ?>)</p>
            </div>
            <div class="header-right">
                <div class="date-display">
                    <i class="fas fa-calendar-alt"></i>
                    <span><?php echo date('d F Y'); ?></span>
                </div>
            </div>
        </div>

        <div class="dashboard-stats">
            <div class="stat-card sales-card tooltip" data-tooltip="Nombre de ventes aujourd'hui">
                <div class="card-icon">
                    <i class="fas fa-shopping-cart"></i>
                </div>
                <div class="card-content">
                    <h3>Ventes Aujourd'hui</h3>
                    <h2><?php echo $totalSales; ?></h2>
                </div>
            </div>
            <div class="stat-card amount-card tooltip" data-tooltip="Montant total des ventes">
                <div class="card-icon">
                    <i class="fas fa-coins"></i>
                </div>
                <div class="card-content">
                    <h3>Montant Total</h3>
                    <h2><?php echo number_format($totalAmount, 3, ',', ' '); ?> TND</h2>
                </div>
            </div>
            <?php if ($userRole === 'admin'): ?>
                <div class="stat-card employees-card tooltip" data-tooltip="Nombre total d'employés">
                    <div class="card-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="card-content">
                        <h3>Total Employés</h3>
                        <h2><?php echo $totalEmployees; ?></h2>
                    </div>
                </div>
                <div class="stat-card active-employees-card tooltip" data-tooltip="Employés actuellement actifs">
                    <div class="card-icon">
                        <i class="fas fa-user-check"></i>
                    </div>
                    <div class="card-content">
                        <h3>Employés Actifs</h3>
                        <h2><?php echo $activeEmployees; ?></h2>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <div class="recent-sales">
            <div class="section-header">
                <h3><i class="fas fa-receipt"></i> Ventes Récentes (Aujourd'hui)</h3>
                <a href="sales_history.php">Voir tout</a>
            </div>
            <div class="sales-list">
                <?php if (!empty($recentSales)): ?>
                    <?php foreach ($recentSales as $sale): ?>
                        <div class="sales-item tooltip" data-tooltip="Détails de la vente #<?php echo htmlspecialchars($sale['id']); ?>">
                            <div class="sales-icon">
                                <i class="fas fa-receipt"></i>
                            </div>
                            <div class="sales-details">
                                <h4>Vente #<?php echo htmlspecialchars($sale['id']); ?></h4>
                                <p>Client: <?php echo htmlspecialchars($sale['client_nom'] ?? 'Non spécifié'); ?></p>
                            </div>
                            <div class="sales-meta">
                                <span class="amount"><?php echo number_format($sale['total_ttc'], 3, ',', ' '); ?> TND</span>
                                <span class="time"><?php echo date('d/m/Y H:i', strtotime($sale['date_vente'])); ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="no-data">
                        <p>Aucune vente aujourd'hui</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="recent-activity">
            <div class="section-header">
                <h3><i class="fas fa-history"></i> Vos Activités Récentes</h3>
            </div>
            <div class="activity-list">
                <?php if (!empty($recentLogs)): ?>
                    <?php foreach ($recentLogs as $log): ?>
                        <div class="activity-item tooltip" data-tooltip="<?php echo htmlspecialchars($log['action']); ?>">
                            <div class="activity-icon">
                                <i class="fas fa-user"></i>
                            </div>
                            <div class="activity-details">
                                <h4><?php echo htmlspecialchars($log['action']); ?></h4>
                                <p><?php echo date('d/m/Y H:i', strtotime($log['date_action'])); ?></p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="no-data">
                        <p>Aucune activité récente</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="quick-actions">
            <h3><i class="fas fa-bolt"></i> Actions Rapides</h3>
            <div class="action-buttons">
                <a href="dashboard/caisse.php" class="action-btn tooltip" data-tooltip="Démarrer une nouvelle vente">
                    <i class="fas fa-cash-register"></i>
                    <span>Nouvelle Vente</span>
                </a>
                <a href="logout.php" class="action-btn tooltip" data-tooltip="Se déconnecter du système">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Déconnexion</span>
                </a>
            </div>
        </div>
    </div>
</body>
</html>