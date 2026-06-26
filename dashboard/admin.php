<?php
session_start();

if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

require_once '../config.php';

// Requête profit journalier
$query = "SELECT SUM(total_ttc) AS profit_journalier FROM ventes WHERE DATE(date_vente) = CURDATE()";
$result = $conn->query($query);
$profit = 0;
if ($result && $row = $result->fetch_assoc()) {
    $profit = $row['profit_journalier'] ?? 0;
}

// Nombre de ventes aujourd'hui
$queryVentes = "SELECT COUNT(*) AS total_ventes FROM ventes WHERE DATE(date_vente) = CURDATE()";
$resultVentes = $conn->query($queryVentes);
$totalVentes = 0;
if ($resultVentes && $rowV = $resultVentes->fetch_assoc()) {
    $totalVentes = $rowV['total_ventes'];
}

// Total clients
$queryClients = "SELECT COUNT(*) AS total_clients FROM clients";
$resultClients = $conn->query($queryClients);
$totalClients = 0;
if ($resultClients && $rowC = $resultClients->fetch_assoc()) {
    $totalClients = $rowC['total_clients'];
}

// Produits en stock faible
$queryStock = "SELECT COUNT(*) AS stock_faible FROM produits WHERE quantite_stock <= 5";
$resultStock = $conn->query($queryStock);
$stockFaible = 0;
if ($resultStock && $rowS = $resultStock->fetch_assoc()) {
    $stockFaible = $rowS['stock_faible'];
}

// Ventes récentes
$queryRecentSales = "SELECT v.id, v.date_vente, v.total_ttc, c.nom AS client_nom 
                     FROM ventes v
                     LEFT JOIN clients c ON v.client_id = c.id
                     ORDER BY v.date_vente DESC LIMIT 5";
$recentSales = [];
if ($resultRecent = $conn->query($queryRecentSales)) {
    while ($row = $resultRecent->fetch_assoc()) {
        $recentSales[] = $row;
    }
}

// Ventes des 7 derniers jours pour le graphique
$queryWeeklySales = "SELECT DATE(date_vente) AS sale_date, SUM(total_ttc) AS total_sales 
                     FROM ventes 
                     WHERE date_vente >= CURDATE() - INTERVAL 7 DAY 
                     GROUP BY DATE(date_vente) 
                     ORDER BY sale_date";
$weeklySales = [];
if ($resultWeekly = $conn->query($queryWeeklySales)) {
    while ($row = $resultWeekly->fetch_assoc()) {
        $weeklySales[] = $row;
    }
}

// Préparer les données pour le graphique
$chartLabels = [];
$chartData = [];
foreach ($weeklySales as $sale) {
    $chartLabels[] = date('d M', strtotime($sale['sale_date']));
    $chartData[] = $sale['total_sales'];
}

// Produits les plus vendus
$queryTopProducts = "SELECT p.nom, SUM(vd.quantite) AS total_vendu 
                     FROM ventes_details vd
                     JOIN produits p ON vd.produit_id = p.id
                     GROUP BY p.nom 
                     ORDER BY total_vendu DESC 
                     LIMIT 5";
$topProducts = [];
if ($resultTopProducts = $conn->query($queryTopProducts)) {
    while ($row = $resultTopProducts->fetch_assoc()) {
        $topProducts[] = $row;
    }
}

// Inclure sidebar.php
include '../sidebar.php';
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Tableau de bord Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" />
    <link rel="stylesheet" href="../assets/css/style.css" />
    <style>
    :root {
        --primary: #4361ee;
        --primary-light: #4895ef;
        --secondary: #3f37c9;
        --success: #4cc9f0;
        --warning: #f72585;
        --danger: #e63946;
        --light:rgb(255, 255, 255);
        --dark: #212529;
        --gray: #6c757d;
        --light-gray: #e9ecef;
        --card-shadow: 0 4px 12px rgba(0,0,0,0.08);
        --transition: all 0.3s ease;
        --compact-padding: 1.25rem;
    }

    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
        font-family: 'Poppins', sans-serif;
    }

    body {
        background-color:rgb(249, 249, 251);;
        color: #333;
        overflow-x: hidden;
        font-size: 14px;
    }

    .main-container {
            margin-left:5px;
            position: relative;
			width: calc(100% - 280px);
			left: 280px;
			transition: .3s ease;
    }
    #sidebar.hide ~ .main-container {
			width: calc(100% - 60px);
			left: 60px;
		}
    

    .header-left h1 {
        font-size: 22px;
        font-weight: 600;
        color: white;
        margin-bottom: 4px;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .header-left p {
        color: var(--gray);
        font-size: 13px;
    }

    .header-right {
        display: flex;
        align-items: center;
        gap: 20px;
    }

    .date-display {
        display: flex;
        align-items: center;
        gap: 8px;
        background: #fff;
        padding: 6px 12px;
        border-radius: 18px;
        box-shadow: var(--card-shadow);
        font-size: 13px;
        color: var(--gray);
    }

    
    .dashboard-stats {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
        gap: 16px;
        margin-bottom: 20px;
    }

    .stat-card {
        background: white;
        border-radius: 12px;
        box-shadow: var(--card-shadow);
        padding: 18px;
        display: flex;
        align-items: center;
        gap: 15px;
        transition: var(--transition);
        position: relative;
        overflow: hidden;
        cursor: pointer;
    }

    .stat-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 4px;
        height: 100%;
    }

    .stat-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 6px 15px rgba(0,0,0,0.1);
    }

    .stat-card.profit-card::before {
        background: linear-gradient(to bottom, var(--primary), var(--primary-light));
    }

    .stat-card.sales-card::before {
        background: linear-gradient(to bottom, var(--success), #3a86ff);
    }

    .stat-card.stock-card::before {
        background: linear-gradient(to bottom, var(--warning), #ff006e);
    }

    .stat-card.customers-card::before {
        background: linear-gradient(to bottom, var(--secondary), #560bad);
    }

    .card-icon {
        width: 48px;
        height: 48px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 20px;
        background: rgba(67, 97, 238, 0.1);
    }

    .profit-card .card-icon {
        background: rgba(67, 97, 238, 0.1);
        color: var(--primary);
    }

    .sales-card .card-icon {
        background: rgba(76, 201, 240, 0.1);
        color: var(--success);
    }

    .stock-card .card-icon {
        background: rgba(247, 37, 133, 0.1);
        color: var(--warning);
    }

    .customers-card .card-icon {
        background: rgba(63, 55, 201, 0.1);
        color: var(--secondary);
    }

    .card-content {
        flex: 1;
    }

    .card-content h3 {
        font-size: 14px;
        font-weight: 500;
        color: var(--gray);
        margin-bottom: 4px;
    }

    .card-content h2 {
        font-size: 22px;
        font-weight: 600;
        color: var(--dark);
        margin-bottom: 6px;
    }

    .trend {
        display: flex;
        align-items: center;
        gap: 4px;
        font-size: 12px;
        font-weight: 500;
        padding: 3px 8px;
        border-radius: 20px;
        width: fit-content;
    }

    .trend.positive {
        background: rgba(46, 204, 113, 0.1);
        color: #27ae60;
    }

    .trend.negative {
        background: rgba(231, 76, 60, 0.1);
        color: #c0392b;
    }

    .trend.neutral {
        background: rgba(241, 196, 15, 0.1);
        color: #d35400;
    }

    .dashboard-content {
        display: grid;
        grid-template-columns: 2fr 1fr;
        gap: 20px;
        margin-bottom: 20px;
    }

    .chart-container, .recent-activity, .top-products, .low-stock, .recent-customers {
        background: white;
        border-radius: 12px;
        box-shadow: var(--card-shadow);
        padding: 18px;
    }

    
    .chart-filter select {
        background: var(--light);
        border: 1px solid var(--light-gray);
        border-radius: 8px;
        padding: 6px 12px;
        font-size: 13px;
        color: var(--gray);
        outline: none;
        cursor: pointer;
    }

    .chart {
        height: 250px;
        position: relative;
    }

    .recent-activity, .top-products, .low-stock, .recent-customers {
        height: 100%;
    }

    .activity-list, .product-list, .low-stock-list, .customer-list {
        display: flex;
        flex-direction: column;
        gap: 12px;
    }

    .activity-item, .product-item, .low-stock-item, .customer-item {
        display: flex;
        align-items: center;
        padding: 12px;
        border-radius: 8px;
        background: var(--light);
        transition: var(--transition);
        font-size: 13px;
    }

    .activity-item:hover, .product-item:hover, .low-stock-item:hover, .customer-item:hover {
        background: #edf2f7;
        transform: translateX(3px);
    }

    .activity-icon, .product-icon, .low-stock-icon, .customer-icon {
        width: 38px;
        height: 38px;
        border-radius: 8px;
        background: rgba(52, 152, 219, 0.1);
        color: var(--primary);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 16px;
        margin-right: 12px;
        flex-shrink: 0;
    }

    .product-icon {
        background: rgba(76, 201, 240, 0.1);
        color: var(--success);
    }
    
    .low-stock-icon {
        background: rgba(247, 37, 133, 0.1);
        color: var(--warning);
    }
    
    .customer-icon {
        background: rgba(63, 55, 201, 0.1);
        color: var(--secondary);
    }

    .activity-details, .product-details, .low-stock-details, .customer-details {
        flex: 1;
        min-width: 0;
    }

    .activity-details h4, .product-details h4, .low-stock-details h4, .customer-details h4 {
        font-size: 14px;
        font-weight: 500;
        color: var(--dark);
        margin-bottom: 2px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .activity-details p, .product-details p, .low-stock-details p, .customer-details p {
        font-size: 12px;
        color: var(--gray);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .activity-meta, .product-meta, .low-stock-meta, .customer-meta {
        display: flex;
        flex-direction: column;
        align-items: flex-end;
        flex-shrink: 0;
    }

    .amount {
        font-weight: 600;
        color: var(--primary);
        font-size: 13px;
    }

    .time, .quantity, .stock-level {
        font-size: 11px;
        color: var(--gray);
    }

    .no-activity {
        text-align: center;
        padding: 25px;
        color: var(--gray);
        font-size: 14px;
    }

    .quick-actions {
        background: white;
        border-radius: 12px;
        box-shadow: var(--card-shadow);
        padding: 18px;
        margin-bottom: 20px;
    }

    .quick-actions h3 {
        font-size: 16px;
        font-weight: 600;
        color: var(--dark);
        margin-bottom: 15px;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .action-buttons {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
        gap: 12px;
    }

    .action-btn {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        padding: 18px 12px;
        border-radius: 8px;
        background: var(--light);
        border: 1px dashed #dce1e6;
        color: var(--primary);
        font-size: 13px;
        font-weight: 500;
        transition: var(--transition);
        cursor: pointer;
        text-align: center;
        text-decoration: none;
    }

    .action-btn:hover {
        background: #e6f7ff;
        transform: translateY(-2px);
        border-color: var(--primary);
        box-shadow: 0 4px 12px rgba(67, 97, 238, 0.1);
    }

    .action-btn i {
        font-size: 24px;
        margin-bottom: 8px;
        color: var(--primary);
    }

    .dashboard-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 20px;
        margin-bottom: 20px;
    }
    
    .dashboard-column {
        display: flex;
        flex-direction: column;
        gap: 20px;
    }
    
    .full-width-section {
        grid-column: 1 / -1;
    }

    .dark-mode-toggle {
        position: fixed;
        bottom: 20px;
        right: 20px;
        width: 46px;
        height: 46px;
        border-radius: 50%;
        background: var(--primary);
        color: white;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        z-index: 1000;
        transition: var(--transition);
        font-size: 18px;
    }

    .dark-mode-toggle:hover {
        transform: scale(1.08);
    }
    
    /* Search bar */
    .search-container {
        position: relative;
        width: 200px;
    }
    
    .search-container input {
        width: 100%;
        padding: 8px 12px 8px 36px;
        border-radius: 20px;
        border: 1px solid #e0e0e0;
        font-size: 13px;
        transition: var(--transition);
    }
    
    .search-container input:focus {
        outline: none;
        border-color: var(--primary);
        box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.2);
    }
    
    .search-icon {
        position: absolute;
        left: 12px;
        top: 50%;
        transform: translateY(-50%);
        color: var(--gray);
    }
    
    /* Dropdown menus */
    .dropdown {
        position: relative;
    }
    
    .dropdown-content {
        position: absolute;
        right: 0;
        top: 120%;
        background: white;
        border-radius: 10px;
        box-shadow: 0 6px 20px rgba(0,0,0,0.1);
        min-width: 220px;
        z-index: 100;
        opacity: 0;
        visibility: hidden;
        transform: translateY(10px);
        transition: var(--transition);
        padding: 10px 0;
    }
    
    .dropdown.active .dropdown-content {
        opacity: 1;
        visibility: visible;
        transform: translateY(0);
    }
    
    .dropdown-item {
        padding: 10px 16px;
        display: flex;
        align-items: center;
        gap: 10px;
        cursor: pointer;
        transition: var(--transition);
        font-size: 13px;
    }
    
    .dropdown-item:hover {
        background: #f0f5ff;
        color: var(--primary);
    }
    
    .dropdown-divider {
        height: 1px;
        background: #eee;
        margin: 6px 0;
    }
    
    /* Responsive design */
    @media (max-width: 1200px) {
        .dashboard-content {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 992px) {
        .main-container {
            margin-left: 0;
            padding: 15px;
        }
        
        .collapsed-sidebar .main-container {
            margin-left: 0;
        }
    }

    @media (max-width: 768px) {
        .header {
            flex-direction: column;
            align-items: flex-start;
            gap: 12px;
        }
        
        .header-right {
            width: 100%;
            justify-content: space-between;
        }
        
        .dashboard-stats {
            grid-template-columns: 1fr 1fr;
        }
        
        .action-buttons {
            grid-template-columns: 1fr 1fr;
        }
        
        .dashboard-grid {
            grid-template-columns: 1fr;
        }
        
        .search-container {
            width: 100%;
            margin-top: 10px;
        }
    }

    @media (max-width: 576px) {
        .dashboard-stats {
            grid-template-columns: 1fr;
        }
        
        .action-buttons {
            grid-template-columns: 1fr;
        }
        
        .user-info {
            display: none;
        }
    }
    .header {
  position: relative;
  z-index: 1;
  overflow: hidden;
  background: linear-gradient(to bottom,rgb(49, 88, 160),rgb(82, 130, 211));
}

  .snow,
.snow::before,
.snow::after {
  position: absolute;
  top: -600px;
  left: 0;
  right: 0;
 
}


   
    </style>
 
</head>
<body>
   

    <div class="main-container" id="contant">
        <div class="header">
        <div class="snow"></div>

            <div class="header-left">
                <h1><i class="fas fa-chart-line"></i> Tableau de bord Admin</h1>
            </div>
            <div class="header-right">
                <div class="date-display">
                    <i class="fas fa-calendar-alt"></i>
                    <span><?php echo date('d F Y'); ?></span>
                </div>
              
             
            </div>
        </div>

        <div class="dashboard-stats">
            <div class="stat-card profit-card">
                <div class="card-icon">
                    <i class="fas fa-chart-line"></i>
                </div>
                <div class="card-content">
                    <h3>Profit journalier</h3>
                    <h2><?= number_format($profit, 3, ',', ' ') ?> TND</h2>
                    <div class="trend positive">
                        <i class="fas fa-arrow-up"></i>
                        <span>12.5% depuis hier</span>
                    </div>
                </div>
            </div>

            <div class="stat-card sales-card">
                <div class="card-icon">
                    <i class="fas fa-shopping-cart"></i>
                </div>
                <div class="card-content">
                    <h3>Ventes aujourd'hui</h3>
                    <h2><?= $totalVentes ?></h2>
                    <div class="trend negative">
                        <i class="fas fa-arrow-down"></i>
                        <span>3.2% depuis hier</span>
                    </div>
                </div>
            </div>

            <div class="stat-card stock-card">
                <div class="card-icon">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <div class="card-content">
                    <h3>Stock faible</h3>
                    <h2><?= $stockFaible ?></h2>
                    <div class="trend neutral">
                        <span>À surveiller</span>
                    </div>
                </div>
            </div>

            <div class="stat-card customers-card">
                <div class="card-icon">
                    <i class="fas fa-users"></i>
                </div>
                <div class="card-content">
                    <h3>Clients</h3>
                    <h2> <?=$totalClients?></h2>
                    <div class="trend positive">
                        <i class="fas fa-arrow-up"></i>
                        <span>8.7% ce mois</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="dashboard-content">
            <div class="chart-container">
                <div class="section-header">
                    <h3><i class="fas fa-chart-bar"></i> Ventes des 7 derniers jours</h3>
                    <div class="chart-filter">
                        <select id="chartPeriod">
                            <option value="7">7 derniers jours</option>
                            <option value="30">30 derniers jours</option>
                            <option value="90">90 derniers jours</option>
                        </select>
                    </div>
                </div>
                <div class="chart">
                    <canvas id="salesChart"></canvas>
                </div>
            </div>

            <div class="recent-activity">
                <div class="section-header">
                    <h3><i class="fas fa-history"></i> Ventes récentes</h3>
                    <a href="historique_ventes.php" style="color: var(--primary); font-size: 14px;">Voir tout</a>
                </div>
                <div class="activity-list">
                    <?php if (!empty($recentSales)): ?>
                        <?php foreach ($recentSales as $sale): ?>
                            <div class="activity-item">
                                <div class="activity-icon">
                                    <i class="fas fa-receipt"></i>
                                </div>
                                <div class="activity-details">
                                    <h4>Vente #<?= $sale['id'] ?></h4>
                                    <p>Client: <?= $sale['client_nom'] ?? 'Non spécifié' ?></p>
                                </div>
                                <div class="activity-meta">
                                    <span class="amount"><?= number_format($sale['total_ttc'], 3, ',', ' ') ?> TND</span>
                                    <span class="time"><?= date('H:i', strtotime($sale['date_vente'])) ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="no-activity">
                            <p>Aucune vente récente</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="top-products-container">
            <div class="top-products">
                <div class="section-header">
                    <h3><i class="fas fa-star"></i> Produits les plus vendus</h3>
                    <a href="gestion_produits.php" style="color: var(--primary); font-size: 14px;">Voir tout</a>
                </div>
                <div class="product-list">
                    <?php if (!empty($topProducts)): ?>
                        <?php foreach ($topProducts as $product): ?>
                            <div class="product-item">
                                <div class="product-icon">
                                    <i class="fas fa-box"></i>
                                </div>
                                <div class="product-details">
                                    <h4><?= $product['nom'] ?></h4>
                                    <p>Produit</p>
                                </div>
                                <div class="product-meta">
                                    <span class="quantity"><?= $product['total_vendu'] ?> unités</span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="no-activity">
                            <p>Aucun produit vendu récemment</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
<br><br>
            <div class="quick-actions">
                <h3><i class="fas fa-bolt"></i> Actions rapides</h3>
                <div class="action-buttons">
                    <a href="caisse.php" class="action-btn">
                        <i class="fas fa-plus-circle"></i>
                        <span>Nouvelle vente</span>
                    </a>
                    <a href="gestion_produits.php" class="action-btn">
                        <i class="fas fa-box"></i>
                        <span>Gérer le stock</span>
                    </a>
                    <a href="gestion_utilisateurs.php" class="action-btn">
                        <i class="fas fa-user-plus"></i>
                        <span>Ajouter Utilisateur</span>
                    </a>
                    <a href="rapports.php" class="action-btn">
                        <i class="fas fa-file-invoice-dollar"></i>
                        <span>Générer rapport</span>
                    </a>
                </div>
            </div>
        </div>
    </div>

    

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
   

        // Chart data
        const chartLabels = <?php echo json_encode($chartLabels); ?>;
        const chartData = <?php echo json_encode($chartData); ?>;

        // Chart configuration
        const ctx = document.getElementById('salesChart').getContext('2d');
        const salesChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: chartLabels,
                datasets: [{
                    label: 'Ventes (TND)',
                    data: chartData,
                    backgroundColor: 'rgba(67, 97, 238, 0.1)',
                    borderColor: '#4361ee',
                    borderWidth: 3,
                    pointBackgroundColor: '#4361ee',
                    pointBorderColor: '#fff',
                    pointHoverBackgroundColor: '#fff',
                    pointHoverBorderColor: '#4361ee',
                    tension: 0.3,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        backgroundColor: 'rgba(0, 0, 0, 0.7)',
                        titleFont: {
                            size: 14
                        },
                        bodyFont: {
                            size: 13
                        },
                        padding: 10,
                        displayColors: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: 'rgba(0, 0, 0, 0.05)'
                        },
                        ticks: {
                            callback: function(value) {
                                return value.toLocaleString('fr-FR') + ' TND';
                            }
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        }
                    }
                }
            }
        });

        // Chart period selector
        const chartPeriod = document.getElementById('chartPeriod');
        chartPeriod.addEventListener('change', function() {
            // In a real application, this would fetch new data
            alert('Fonctionnalité avancée: Chargement des données pour ' + this.value + ' jours');
        });

        // Toggle sidebar (if implemented)
        const sidebarToggle = document.querySelector('.sidebar-toggle');
        if (sidebarToggle) {
            sidebarToggle.addEventListener('click', function() {
                document.body.classList.toggle('collapsed-sidebar');
            });
        }
    });
    </script>
</body>
</html>