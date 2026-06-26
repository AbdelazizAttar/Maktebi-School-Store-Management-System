<?php
// Start output buffering
ob_start();

session_start();

// Vérification de l'authentification
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || !in_array($_SESSION['user_role'], ['admin', 'employe'])) {
    header("Location: ../login.php");
    exit;
}

// Define current page for sidebar
$current_page = 'rapports';

require_once '../config.php';
try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Erreur de connexion : " . htmlspecialchars($e->getMessage()));
}

// Taux de TVA (19% pour la Tunisie)
$taux_tva = 0.19;

// Récupérer et valider les filtres
$date_debut = filter_input(INPUT_GET, 'date_debut', FILTER_SANITIZE_SPECIAL_CHARS) ?: date('Y-m-d', strtotime('-30 days'));
$date_fin = filter_input(INPUT_GET, 'date_fin', FILTER_SANITIZE_SPECIAL_CHARS) ?: date('Y-m-d');
$client_id = filter_input(INPUT_GET, 'client_id', FILTER_VALIDATE_INT) ?: 0;
$produit_id = filter_input(INPUT_GET, 'produit_id', FILTER_VALIDATE_INT) ?: 0;

// Valider les dates
if ($date_debut > $date_fin) {
    $error = "La date de début doit être antérieure ou égale à la date de fin.";
    $date_debut = date('Y-m-d', strtotime('-30 days'));
    $date_fin = date('Y-m-d');
}

// Générer un token CSRF pour le formulaire
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Construire la requête SQL pour les ventes
$query = "
    SELECT v.id, v.date_vente, v.total_ht, v.montant_tva, v.total_ttc, COALESCE(c.nom, '') AS nom, COALESCE(c.prenom, '') AS prenom
    FROM ventes v
    LEFT JOIN clients c ON v.client_id = c.id
    WHERE v.date_vente BETWEEN ? AND ?
";
$params = [$date_debut . ' 00:00:00', $date_fin . ' 23:59:59'];

if ($client_id > 0) {
    $query .= " AND v.client_id = ?";
    $params[] = $client_id;
}

try {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $ventes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Erreur lors de la récupération des ventes : " . htmlspecialchars($e->getMessage());
    $ventes = [];
}

// Détails des ventes
$vente_details = [];
$total_items = 0;
$total_ht_global = 0;
$total_ttc_global = 0;

if (!empty($ventes)) {
    $vente_ids = array_column($ventes, 'id');
    $placeholders = str_repeat('?,', count($vente_ids) - 1) . '?';
    $details_query = "
        SELECT vd.vente_id, vd.quantite, vd.prix_unitaire, COALESCE(p.nom, 'Produit inconnu') AS nom
        FROM ventes_details vd
        LEFT JOIN produits p ON vd.produit_id = p.id
        WHERE vd.vente_id IN ($placeholders)
    ";
    if ($produit_id > 0) {
        $details_query .= " AND vd.produit_id = ?";
        $details_params = array_merge($vente_ids, [$produit_id]);
    } else {
        $details_params = $vente_ids;
    }

    try {
        $stmt = $pdo->prepare($details_query);
        $stmt->execute($details_params);
        $details = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($details as $detail) {
            $vente_details[$detail['vente_id']][] = $detail;
            $total_items += $detail['quantite'];
        }
    } catch (PDOException $e) {
        $error = "Erreur lors de la récupération des détails : " . htmlspecialchars($e->getMessage());
    }

    foreach ($ventes as &$vente) {
        $vente['details'] = $vente_details[$vente['id']] ?? [];
        $total_ht_global += $vente['total_ht'];
        $total_ttc_global += $vente['total_ttc'];
    }
    unset($vente);
}

// Charger les clients et produits pour les filtres
try {
    $clients = $pdo->query("SELECT id, CONCAT(COALESCE(nom, ''), ' ', COALESCE(prenom, '')) AS nom_complet FROM clients ORDER BY nom_complet")->fetchAll(PDO::FETCH_ASSOC);
    $produits = $pdo->query("SELECT id, COALESCE(nom, 'Produit inconnu') AS nom FROM produits ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Erreur lors du chargement des filtres : " . htmlspecialchars($e->getMessage());
    $clients = [];
    $produits = [];
}

// Générer le PDF si demandé
if (isset($_GET['export_pdf']) && $_GET['export_pdf'] === '1' && isset($_GET['csrf_token']) && $_GET['csrf_token'] === $csrf_token) {
    $fpdf_path = '../lib/fpdf/fpdf.php';
    if (!file_exists($fpdf_path)) {
        $error = "Erreur: Bibliothèque FPDF non trouvée à $fpdf_path. Veuillez installer FPDF dans C:\\xamp1\\htdocs\\magasin\\lib\\fpdf\\fpdf.php";
    } else {
        require_once $fpdf_path;

        // Vérifier le dossier factures
        $factures_dir = '../factures';
        if (!is_dir($factures_dir)) {
            if (!mkdir($factures_dir, 0755, true)) {
                $error = "Erreur: Impossible de créer le dossier $factures_dir";
            }
        }

        if (!$error) {
            class PDF extends FPDF {
                function Header() {
                    $this->SetFont('Arial', 'B', 16);
                    $this->Cell(0, 10, 'Rapport des Ventes', 0, 1, 'C');
                    $this->Ln(5);
                    $this->SetFont('Arial', '', 12);
                    $this->Cell(0, 10, 'Magasin Scolaire', 0, 1, 'C');
                    $this->Ln(5);
                }

                function Footer() {
                    $this->SetY(-15);
                    $this->SetFont('Arial', 'I', 8);
                    $this->Cell(0, 10, 'Page ' . $this->PageNo(), 0, 0, 'C');
                }
            }

            $pdf = new PDF();
            $pdf->AddPage();
            $pdf->SetFont('Arial', '', 12);
            $pdf->Cell(0, 10, 'Periode: ' . date('d/m/Y', strtotime($date_debut)) . ' au ' . date('d/m/Y', strtotime($date_fin)), 0, 1);
            if ($client_id > 0) {
                $stmt = $pdo->prepare("SELECT CONCAT(COALESCE(nom, ''), ' ', COALESCE(prenom, '')) AS nom_complet FROM clients WHERE id = ?");
                $stmt->execute([$client_id]);
                $client = $stmt->fetch(PDO::FETCH_ASSOC);
                $pdf->Cell(0, 10, 'Client: ' . ($client['nom_complet'] ?? 'N/A'), 0, 1);
            }
          
            $pdf->Ln(10);

            // En-tête du tableau
            $pdf->SetFillColor(200, 220, 255);
            $pdf->SetFont('Arial', 'B', 10);
            $pdf->Cell(20, 10, 'ID', 1, 0, 'C', 1);
            $pdf->Cell(30, 10, 'Date', 1, 0, 'C', 1);
            $pdf->Cell(50, 10, 'Client', 1, 0, 'C', 1);
            $pdf->Cell(30, 10, 'Total HT', 1, 0, 'C', 1);
            $pdf->Cell(30, 10, 'TVA', 1, 0, 'C', 1);
            $pdf->Cell(30, 10, 'Total TTC', 1, 1, 'C', 1);

            // Données des ventes
            $pdf->SetFont('Arial', '', 10);
            foreach ($ventes as $vente) {
                $pdf->Cell(20, 8, $vente['id'], 1);
                $pdf->Cell(30, 8, date('d/m/Y', strtotime($vente['date_vente'])), 1);
                $pdf->Cell(50, 8, ($vente['nom'] . ' ' . $vente['prenom']) ?: 'N/A', 1);
                $pdf->Cell(30, 8, number_format($vente['total_ht'], 3), 1, 0, 'R');
                $pdf->Cell(30, 8, number_format($vente['montant_tva'], 3), 1, 0, 'R');
                $pdf->Cell(30, 8, number_format($vente['total_ttc'], 3), 1, 1, 'R');
               
            }

            // Résumé
            $pdf->Ln(10);
            $pdf->SetFont('Arial', 'B', 12);
            $pdf->Cell(0, 10, 'Total HT Global: ' . number_format($total_ht_global, 3) . ' TND', 0, 1);
            $pdf->Cell(0, 10, 'Total TVA Global: ' . number_format($total_ttc_global - $total_ht_global, 3) . ' TND', 0, 1);
            $pdf->Cell(0, 10, 'Total TTC Global: ' . number_format($total_ttc_global, 3) . ' TND', 0, 1);
            $pdf->Cell(0, 10, 'Total Articles Vendus: ' . $total_items, 0, 1);

            // Journalisation
            try {
                $stmt = $pdo->prepare("INSERT INTO logs (utilisateur_id, action, details, ip_address, date_log) VALUES (?, ?, ?, ?, NOW())");
                $ip_address = filter_var($_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP) ?: '0.0.0.0';
                $stmt->execute([$_SESSION['user_id'], 'Rapport PDF', "Génération du rapport pour la période $date_debut à $date_fin", $ip_address]);
            } catch (PDOException $e) {
                $error = "Erreur lors de la journalisation : " . htmlspecialchars($e->getMessage());
            }

            $pdf_file = "$factures_dir/rapport_" . time() . ".pdf";
            $pdf->Output('F', $pdf_file);
            if (file_exists($pdf_file)) {
                header('Content-Type: application/pdf');
                header('Content-Disposition: inline; filename="' . basename($pdf_file) . '"');
                readfile($pdf_file);
                ob_end_flush();
                exit;
            } else {
                $error = "Erreur: Impossible de générer le fichier PDF.";
            }
        }
    }
}

include '../sidebar.php';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rapports - Magasin Scolaire</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #3498db;
            --primary-dark: #2980b9;
            --secondary: #2c3e50;
            --success: #2ecc71;
            --danger: #e74c3c;
            --warning: #f39c12;
            --info: #8e44ad;
            --light: #ffffff;
            --dark: #343a40;
            --gray: #6c757d;
            --border: #dfe6e9;
            --shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            --accent: #00cec9;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        body {
            background: linear-gradient(to bottom right, #f5f7fa, #e4e9ee);
            color: #333;
        }

        .main-container {
            margin-left: 15px;
            margin-top: 25px;
            position: relative;
            width: calc(98% - 300px);
            left: 300px;
            transition: .3s ease;
            padding: 20px;
            display: flex;
            flex-direction: column;
            gap: 2rem; /* Space between major sections */
        }

        #sidebar.hide ~ .main-container {
            width: calc(99% - 80px);
            left: 60px;
        }

        .page-title {
            font-size: 24px;
            font-weight: 600;
            color: var(--secondary);
            display: flex;
            align-items: center;
            gap: 0.75rem;
            position: relative;
        }

        .page-title::after {
            content: '';
            width: 70px;
            height: 4px;
            background: linear-gradient(90deg, var(--primary), var(--accent));
            position: absolute;
            bottom: -8px;
            left: 0;
            border-radius: 2px;
            animation: slideIn 0.5s ease-out;
        }

        @keyframes slideIn {
            from { width: 0; }
            to { width: 70px; }
        }

        .alert-error {
            background: rgba(231, 76, 60, 0.1);
            color: var(--danger);
            border-left: 4px solid var(--danger);
            padding: 12px 15px;
            border-radius: 6px;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 10px;
            animation: fadeIn 0.3s ease-out;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem; /* Increased gap for better spacing */
            
        }

        .card {
            background: white;
            border-radius: 12px;
            box-shadow: var(--shadow);
            overflow: hidden;
            transition: all 0.3s ease;
            border-left: 4px solid var(--accent);
            
        }

        .card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        }

        .card-header {
            padding: 1rem 1.5rem;
            font-weight: 600;
            font-size: 18px;
            color: var(--secondary);
            display: flex;
            align-items: center;
            gap: 0.5rem;
            background: linear-gradient(135deg, rgba(52, 152, 219, 0.05), rgba(0, 206, 201, 0.05));
        }

        .card-body {
            padding: 1.5rem;
        }

        .stat-card {
            text-align: center;
            padding: 1.1rem;
            position: relative;
            overflow: hidden;
            height: 160px;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(52, 152, 219, 0.1) 0%, transparent 70%);
            transition: all 0.5s ease;
        }

        .stat-card:hover::before {
            transform: scale(1.2);
        }

        .stat-icon {
            font-size: 28px;
            color: var(--primary);
            margin-bottom: 0.75rem;
            transition: transform 0.3s ease;
        }

        .stat-card:hover .stat-icon {
            transform: scale(1.1);
        }

        .stat-value {
            font-size: 26px;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 0.25rem;
        }

        .stat-label {
            font-size: 14px;
            color: var(--gray);
            text-transform: uppercase;
        }

        .filter-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-label {
            font-size: 14px;
            font-weight: 500;
            color: var(--secondary);
            margin-bottom: 0.5rem;
        }

        .form-control, .form-select {
            padding: 0.75rem;
            border: 1px solid var(--border);
            border-radius: 6px;
            font-size: 14px;
            background: #f8f9fa;
            transition: all 0.3s ease;
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.2);
            outline: none;
        }

        .form-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            margin-top: 1.5rem;
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 6px;
            font-weight: 500;
            font-size: 14px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(
                90deg,
                transparent,
                rgba(255, 255, 255, 0.2),
                transparent
            );
            transition: 0.5s;
        }

        .btn:hover::before {
            left: 100%;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, var(--primary-dark), var(--primary));
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(52, 152, 219, 0.3);
        }

        .btn-success {
            background: linear-gradient(135deg, var(--success), #27ae60);
            color: white;
        }

        .btn-success:hover {
            background: linear-gradient(135deg, #27ae60, var(--success));
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(46, 204, 113, 0.3);
        }

        .table-container {
            overflow-x: auto;
            border-radius: 12px;
            box-shadow: var(--shadow);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background: white;
        }

        thead {
            background: linear-gradient(135deg, var(--primary), var(--accent));
            color: white;
        }

        th {
            padding: 1rem;
            font-weight: 600;
            text-align: left;
            font-size: 14px;
            position: sticky;
            top: 0;
        }

        td {
            padding: 1rem;
            border-bottom: 1px solid var(--border);
            font-size: 14px;
            transition: background 0.3s ease;
        }

        tbody tr:hover {
            background: rgba(52, 152, 219, 0.05);
            transform: translateX(5px);
        }

        .badge {
            padding: 0.4rem 0.8rem;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 500;
        }

        .badge-primary {
            background: var(--primary);
            color: white;
        }

        .badge-detail {
            display: inline-block;
            background: rgba(52, 152, 219, 0.1);
            color: var(--primary);
            padding: 0.5rem 1rem;
            border-radius: 6px;
            font-size: 12px;
            margin: 0.25rem;
            transition: transform 0.3s ease;
        }

        .badge-detail:hover {
            transform: scale(1.05);
        }

        .summary-card {
            border-left: 4px solid var(--primary);
        }

        .summary-item {
            display: flex;
            justify-content: space-between;
            padding: 0.75rem 0;
            border-bottom: 1px solid var(--border);
            font-size: 14px;
            transition: background 0.3s ease;
        }

        .summary-item:hover {
            background: rgba(52, 152, 219, 0.05);
        }

        .summary-item:last-child {
            border-bottom: none;
        }

        .summary-label {
            font-weight: 500;
            color: var(--gray);
        }

        .summary-value {
            font-weight: 600;
            color: var(--dark);
        }

        .total-summary {
            color: var(--primary);
            font-weight: 700;
        }

        .pagination {
            display: flex;
            justify-content: center;
            margin-top: 2rem;
            gap: 8px;
            align-items: center;
        }

        .page-item {
            display: inline-block;
        }

        .page-link {
            padding: 8px 15px;
            border-radius: 6px;
            background: white;
            border: 1px solid var(--border);
            color: var(--primary);
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s;
        }

        .page-link:hover {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
            transform: translateY(-2px);
        }

        .page-link.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
            cursor: default;
        }

        .page-link.disabled {
            color: var(--gray);
            cursor: not-allowed;
            opacity: 0.6;
        }

        .fade-in {
            animation: fadeIn 0.5s ease-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @media (max-width: 1200px) {
            .main-container {
                margin-left: 0;
                left: 0;
                width: 100%;
                padding: 15px;
                gap: 1.5rem;
            }
        }

        @media (max-width: 768px) {
            .filter-form {
                grid-template-columns: 1fr;
                gap: 1rem;
            }
            .form-actions {
                flex-direction: column;
                align-items: stretch;
            }
            .btn {
                width: 100%;
                justify-content: center;
            }
            table {
                font-size: 12px;
            }
            th, td {
                padding: 0.75rem;
            }
            .grid {
                gap: 1.5rem;
            }
        }

        @media (max-width: 576px) {
            .stat-value {
                font-size: 20px;
            }
            .card-header {
                font-size: 16px;
            }
            .main-container {
                gap: 1rem;
            }
        }
        .btn btn-primary{
            margin-left:-200px;
        }
    </style>
</head>
<body>
    <main class="main-container fade-in">
        <h1 class="page-title">
            <i class="fas fa-chart-line"></i> Rapports des Ventes
        </h1>

        <?php if (isset($error)): ?>
            <div class="alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <!-- Stats Summary -->
        <div class="grid">
            <div class="card stat-card fade-in">
                <div class="stat-icon"><i class="fas fa-shopping-cart"></i></div>
                <div class="stat-value"><?= count($ventes) ?></div>
                <div class="stat-label">Ventes</div>
            </div>
            <div class="card stat-card fade-in">
                <div class="stat-icon"><i class="fas fa-boxes"></i></div>
                <div class="stat-value"><?= $total_items ?></div>
                <div class="stat-label">Articles Vendus</div>
            </div>
            <div class="card stat-card fade-in">
                <div class="stat-icon"><i class="fas fa-coins"></i></div>
                <div class="stat-value"><?= number_format($total_ht_global, 3, ',', ' ') ?> TND</div>
                <div class="stat-label">Total HT</div>
            </div>
            <div class="card stat-card fade-in">
                <div class="stat-icon"><i class="fas fa-file-invoice-dollar"></i></div>
                <div class="stat-value"><?= number_format($total_ttc_global, 3, ',', ' ') ?> TND</div>
                <div class="stat-label">Total TTC</div>
            </div>
            <div class="card stat-card fade-in">
                <div class="stat-icon"><i class="fas fa-chart-line"></i></div>
                <div class="stat-value">
                    <?= count($ventes) > 0 ? number_format($total_ttc_global / count($ventes), 3, ',', ' ') : '0,000' ?> TND
                </div>
                <div class="stat-label">Panier Moyen</div>
            </div>
        </div>

        <!-- Filter Section -->
        <div class="card fade-in">
            <div class="card-header">
                <i class="fas fa-filter"></i> Filtres de Rapport
            </div>
            <div class="card-body">
                <form method="GET" class="filter-form">
                    <div class="form-group">
                        <label for="date_debut" class="form-label">Date Début</label>
                        <input type="date" name="date_debut" id="date_debut" class="form-control" value="<?= htmlspecialchars($date_debut) ?>">
                    </div>
                    <div class="form-group">
                        <label for="date_fin" class="form-label">Date Fin</label>
                        <input type="date" name="date_fin" id="date_fin" class="form-control" value="<?= htmlspecialchars($date_fin) ?>">
                    </div>
                    <div class="form-group">
                        <label for="client_id" class="form-label">Client</label>
                        <select name="client_id" id="client_id" class="form-select">
                            <option value="0">Tous les clients</option>
                            <?php foreach ($clients as $client): ?>
                                <option value="<?= $client['id'] ?>" <?= $client_id == $client['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars(trim($client['nom_complet'])) ?: 'Client inconnu' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="produit_id" class="form-label">Produit</label>
                        <select name="produit_id" id="produit_id" class="form-select">
                            <option value="0">Tous les produits</option>
                            <?php foreach ($produits as $produit): ?>
                                <option value="<?= $produit['id'] ?>" <?= $produit_id == $produit['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($produit['nom']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                    <input type="hidden" name="page" value="<?= htmlspecialchars($page ?? 1) ?>">
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary"></i> Appliquer</button>
                        <button type="submit" name="export_pdf" value="1" class="btn btn-success"><i class="fas fa-file-pdf"></i> Exporter PDF</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Sales Table -->
        <div class="card fade-in">
            <div class="card-header">
                <i class="fas fa-receipt"></i> Détail des Ventes
            </div>
            <div class="card-body">
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>ID Vente</th>
                                <th>Date</th>
                                <th>Client</th>
                                <th>Total HT (TND)</th>
                                <th>TVA (TND)</th>
                                <th>Total TTC (TND)</th>
                                
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $items_per_page = 10;
                            $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
                            $total_pages = ceil(count($ventes) / $items_per_page);
                            $offset = ($page - 1) * $items_per_page;
                            $paged_ventes = array_slice($ventes, $offset, $items_per_page);
                            ?>
                            <?php if (empty($paged_ventes)): ?>
                                <tr>
                                    <td colspan="7" style="text-align: center; padding: 30px;">
                                        <i class="fas fa-inbox" style="font-size: 48px; color: var(--gray);"></i>
                                        <p>Aucune vente trouvée</p>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($paged_ventes as $vente): ?>
                                    <tr>
                                        <td><span class="badge badge-primary">#<?= $vente['id'] ?></span></td>
                                        <td><?= date('d/m/Y H:i', strtotime($vente['date_vente'])) ?></td>
                                        <td><?= htmlspecialchars(trim($vente['nom'] . ' ' . $vente['prenom'])) ?: 'N/A' ?></td>
                                        <td class="fw-bold"><?= number_format($vente['total_ht'], 3, ',', ' ') ?></td>
                                        <td><?= number_format($vente['montant_tva'], 3, ',', ' ') ?></td>
                                        <td class="fw-bold" style="color: var(--primary);"><?= number_format($vente['total_ttc'], 3, ',', ' ') ?></td>
                                        <td>
                                            <?php if (empty($vente['details'])): ?>
                                                <span class="badge-detail">Aucun article</span>
                                            <?php else: ?>
                                               
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($total_pages > 1): ?>
                    <div class="pagination">
                        <a href="?page=<?= max(1, $page - 1) ?>&date_debut=<?= htmlspecialchars(urlencode($date_debut)) ?>&date_fin=<?= htmlspecialchars(urlencode($date_fin)) ?>&client_id=<?= htmlspecialchars($client_id) ?>&produit_id=<?= htmlspecialchars($produit_id) ?>&csrf_token=<?= htmlspecialchars(urlencode($csrf_token)) ?>" 
                           class="page-link <?= $page <= 1 ? 'disabled' : '' ?>">
                            <i class="fas fa-chevron-left"></i>
                        </a>
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <a href="?page=<?= $i ?>&date_debut=<?= htmlspecialchars(urlencode($date_debut)) ?>&date_fin=<?= htmlspecialchars(urlencode($date_fin)) ?>&client_id=<?= htmlspecialchars($client_id) ?>&produit_id=<?= htmlspecialchars($produit_id) ?>&csrf_token=<?= htmlspecialchars(urlencode($csrf_token)) ?>" 
                               class="page-link <?= $i === $page ? 'active' : '' ?>">
                                <?= $i ?>
                            </a>
                        <?php endfor; ?>
                        <a href="?page=<?= min($total_pages, $page + 1) ?>&date_debut=<?= htmlspecialchars(urlencode($date_debut)) ?>&date_fin=<?= htmlspecialchars(urlencode($date_fin)) ?>&client_id=<?= htmlspecialchars($client_id) ?>&produit_id=<?= htmlspecialchars($produit_id) ?>&csrf_token=<?= htmlspecialchars(urlencode($csrf_token)) ?>" 
                           class="page-link <?= $page >= $total_pages ? 'disabled' : '' ?>">
                            <i class="fas fa-chevron-right"></i>
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Summary Section -->
        <div class="grid">
            <div class="card summary-card fade-in">
                <div class="card-header">
                    <i class="fas fa-chart-pie"></i> Résumé Financier
                </div>
                <div class="card-body">
                    <div class="summary-item">
                        <span class="summary-label">Total HT Global:</span>
                        <span class="summary-value"><?= number_format($total_ht_global, 3, ',', ' ') ?> TND</span>
                    </div>
                    <div class="summary-item">
                        <span class="summary-label">Total TVA Global:</span>
                        <span class="summary-value"><?= number_format($total_ttc_global - $total_ht_global, 3, ',', ' ') ?> TND</span>
                    </div>
                    <div class="summary-item">
                        <span class="summary-label">Total TTC Global:</span>
                        <span class="summary-value total-summary"><?= number_format($total_ttc_global, 3, ',', ' ') ?> TND</span>
                    </div>
                    <div class="summary-item">
                        <span class="summary-label">Total Articles Vendus:</span>
                        <span class="summary-value"><?= $total_items ?></span>
                    </div>
                    <div class="summary-item">
                        <span class="summary-label">Nombre de Ventes:</span>
                        <span class="summary-value"><?= count($ventes) ?></span>
                    </div>
                </div>
            </div>
            <div class="card fade-in">
                <div class="card-header">
                    <i class="fas fa-info-circle"></i> Informations du Rapport
                </div>
                <div class="card-body">
                    <p><strong>Période:</strong> <?= date('d/m/Y', strtotime($date_debut)) . ' - ' . date('d/m/Y', strtotime($date_fin)) ?></p>
                    <p><strong>Client:</strong> 
                        <?php 
                        if ($client_id > 0) {
                            foreach ($clients as $client) {
                                if ($client['id'] == $client_id) {
                                    echo htmlspecialchars(trim($client['nom_complet'])) ?: 'Client inconnu';
                                    break;
                                }
                            }
                        } else {
                            echo 'Tous les clients';
                        }
                        ?>
                    </p>
                    <p><strong>Produit:</strong> 
                        <?php 
                        if ($produit_id > 0) {
                            foreach ($produits as $produit) {
                                if ($produit['id'] == $produit_id) {
                                    echo htmlspecialchars($produit['nom']);
                                    break;
                                }
                            }
                        } else {
                            echo 'Tous les produits';
                        }
                        ?>
                    </p>
                    <p><strong>Généré le:</strong> <?= date('d/m/Y H:i') ?></p>
                    <p><strong>Généré par:</strong> <?= $_SESSION['user_role'] == 'admin' ? 'Administrateur' : 'Employé' ?></p>
                </div>
            </div>
        </div>
    </main>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            // Sidebar toggle
            const toggleButton = document.getElementById('toggleSidebar');
            const sidebar = document.getElementById('sidebar');
            if (toggleButton && sidebar) {
                toggleButton.addEventListener('click', () => {
                    sidebar.classList.toggle('hide');
                    localStorage.setItem('sidebarState', sidebar.classList.contains('hide') ? 'hidden' : 'visible');
                });

                if (localStorage.getItem('sidebarState') === 'hidden') {
                    sidebar.classList.add('hide');
                }
            }

            // Filter form validation
            const filterForm = document.querySelector('.filter-form');
            if (filterForm) {
                filterForm.addEventListener('submit', (event) => {
                    const dateDebut = document.getElementById('date_debut').value;
                    const dateFin = document.getElementById('date_fin').value;
                    if (dateDebut && dateFin && dateDebut > dateFin) {
                        event.preventDefault();
                        const alertDiv = document.createElement('div');
                        alertDiv.className = 'alert-error';
                        alertDiv.innerHTML = '<i class="fas fa-exclamation-circle"></i> La date de début doit être antérieure ou égale à la date de fin.';
                        document.querySelector('.main-container').insertBefore(alertDiv, document.querySelector('.page-title').nextSibling);
                        setTimeout(() => alertDiv.remove(), 5000);
                    }
                });
            }
        });
    </script>
</body>
</html>
<?php
ob_end_flush();
?>