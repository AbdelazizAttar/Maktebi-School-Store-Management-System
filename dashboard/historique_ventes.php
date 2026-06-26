<?php
// Start output buffering to prevent stray output
ob_start();

session_start();

// Vérifier l'accès admin
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

// Define current page for sidebar
$current_page = 'ventes_historique';

require_once '../config.php';

// Initialiser les variables
$error = '';
$success = '';
$date_debut = $_GET['date_debut'] ?? '';
$date_fin = $_GET['date_fin'] ?? '';
$sort_by = $_GET['sort_by'] ?? 'date_vente';
$sort_order = $_GET['sort_order'] ?? 'DESC';
$search = $_GET['search'] ?? '';

// Valider les paramètres de tri
$valid_sort_columns = ['id', 'date_vente', 'total_vente'];
if (!in_array($sort_by, $valid_sort_columns)) {
    $sort_by = 'date_vente';
}
$sort_order = strtoupper($sort_order) === 'ASC' ? 'ASC' : 'DESC';

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $response = ['error' => '', 'html' => '', 'success' => false];

    // Get sale details
    if (isset($_POST['action']) && $_POST['action'] === 'get_sale_details') {
        $sale_id = intval($_POST['sale_id'] ?? 0);
        if ($sale_id) {
            try {
                // Verify sale exists
                $stmt_check = $conn->prepare("SELECT id FROM ventes WHERE id = ?");
                $stmt_check->bind_param('i', $sale_id);
                $stmt_check->execute();
                if ($stmt_check->get_result()->num_rows === 0) {
                    $response['error'] = 'Vente non trouvée.';
                    echo json_encode($response);
                    ob_end_flush();
                    exit;
                }
                $stmt_check->close();

                // Fetch sale details
                $stmt = $conn->prepare("
                    SELECT vd.produit_id, COALESCE(p.nom, 'Produit inconnu') AS produit_nom, 
                           vd.quantite, vd.prix_unitaire
                    FROM ventes_details vd
                    LEFT JOIN produits p ON vd.produit_id = p.id
                    WHERE vd.vente_id = ?
                ");
                $stmt->bind_param('i', $sale_id);
                if ($stmt->execute()) {
                    $result = $stmt->get_result();
                    $details = [];
                    $total = 0;
                    while ($row = $result->fetch_assoc()) {
                        $details[] = $row;
                        $total += $row['quantite'] * $row['prix_unitaire'];
                    }

                    if (empty($details)) {
                        $response['html'] = '<p class="alert alert-error"><i class="fas fa-exclamation-circle"></i> Aucun article trouvé pour cette vente.</p>';
                    } else {
                        $html = '<table class="sale-details-table">';
                        $html .= '<thead><tr><th>Produit</th><th>Quantité</th><th>Prix Unitaire (TND)</th><th>Total (TND)</th></tr></thead>';
                        $html .= '<tbody>';
                        foreach ($details as $row) {
                            $subtotal = $row['quantite'] * $row['prix_unitaire'];
                            $html .= '<tr>';
                            $html .= '<td>' . htmlspecialchars($row['produit_nom']) . '</td>';
                            $html .= '<td>' . htmlspecialchars($row['quantite']) . '</td>';
                            $html .= '<td>' . number_format($row['prix_unitaire'], 3, ',', ' ') . ' TND</td>';
                            $html .= '<td>' . number_format($subtotal, 3, ',', ' ') . ' TND</td>';
                            $html .= '</tr>';
                        }
                        $html .= '<tr class="total-row">';
                        $html .= '<td colspan="3"><strong>Total</strong></td>';
                        $html .= '<td><strong>' . number_format($total, 3, ',', ' ') . ' TND</strong></td>';
                        $html .= '</tr>';
                        $html .= '</tbody>';
                        $html .= '</table>';
                        $response['html'] = $html;
                        logAction($conn, $_SESSION['user_id'] ?? null, 'Consultation vente', "Détails de la vente ID $sale_id consultés");
                    }
                } else {
                    $response['error'] = 'Erreur lors de la récupération des détails : ' . $stmt->error;
                }
                $stmt->close();
            } catch (Exception $e) {
                $response['error'] = 'Erreur serveur : ' . $e->getMessage();
            }
        } else {
            $response['error'] = 'ID de vente non valide.';
        }
        echo json_encode($response);
        ob_end_flush();
        exit;
    }

    // Delete sale
    if (isset($_POST['action']) && $_POST['action'] === 'delete_sale') {
        $sale_id = intval($_POST['sale_id'] ?? 0);
        if ($sale_id) {
            $conn->begin_transaction();
            try {
                $stmt1 = $conn->prepare("DELETE FROM ventes_details WHERE vente_id = ?");
                $stmt1->bind_param('i', $sale_id);
                $stmt1->execute();

                $stmt2 = $conn->prepare("DELETE FROM ventes WHERE id = ?");
                $stmt2->bind_param('i', $sale_id);
                $stmt2->execute();

                if ($stmt2->affected_rows > 0) {
                    $conn->commit();
                    $response['success'] = true;
                    logAction($conn, $_SESSION['user_id'] ?? null, 'Suppression vente', "Vente ID $sale_id supprimée");
                } else {
                    $conn->rollback();
                    $response['error'] = 'Vente non trouvée.';
                }
                $stmt1->close();
                $stmt2->close();
            } catch (Exception $e) {
                $conn->rollback();
                $response['error'] = 'Erreur : ' . $e->getMessage();
            }
        } else {
            $response['error'] = 'ID de vente invalide.';
        }
        echo json_encode($response);
        ob_end_flush();
        exit;
    }

    // Get sale products
    if (isset($_POST['action']) && $_POST['action'] === 'get_sale_products') {
        $sale_id = intval($_POST['sale_id'] ?? 0);
        if ($sale_id) {
            try {
                $stmt = $conn->prepare("
                    SELECT p.nom AS product_name, vd.quantite, vd.prix_unitaire
                    FROM ventes_details vd
                    LEFT JOIN produits p ON vd.produit_id = p.id
                    WHERE vd.vente_id = ?
                ");
                $stmt->bind_param('i', $sale_id);
                if ($stmt->execute()) {
                    $result = $stmt->get_result();
                    $html = '<div class="product-list-container">';
                    if ($result->num_rows > 0) {
                        $html .= '<table style="width: 100%; border-collapse: collapse;">';
                        $html .= '<thead><tr><th>Produit</th><th>Quantité</th><th>Prix Unitaire</th></tr></thead>';
                        $html .= '<tbody>';
                        while ($row = $result->fetch_assoc()) {
                            $html .= '<tr class="product-item">';
                            $html .= '<td>' . htmlspecialchars($row['product_name'] ?? 'Produit inconnu') . '</td>';
                            $html .= '<td>' . htmlspecialchars($row['quantite']) . '</td>';
                            $html .= '<td>' . number_format($row['prix_unitaire'], 3, ',', ' ') . ' TND</td>';
                            $html .= '</tr>';
                        }
                        $html .= '</tbody></table>';
                    } else {
                        $html .= '<p class="text-muted">Aucun produit trouvé pour cette vente.</p>';
                    }
                    $html .= '</div>';
                    $response['html'] = $html;
                } else {
                    $response['error'] = 'Erreur lors de la récupération des produits : ' . $stmt->error;
                }
                $stmt->close();
            } catch (Exception $e) {
                $response['error'] = 'Erreur serveur : ' . $e->getMessage();
            }
        } else {
            $response['error'] = 'ID de vente non valide.';
        }
        echo json_encode($response);
        ob_end_flush();
        exit;
    }
}

// Construire la requête des ventes
$query = "
    SELECT v.id, v.date_vente, COALESCE(SUM(vd.quantite * vd.prix_unitaire), 0) AS total_vente, 
           u.nom AS caissier_nom, u.prenom AS caissier_prenom,
           COUNT(vd.produit_id) AS product_count
    FROM ventes v
    LEFT JOIN ventes_details vd ON v.id = vd.vente_id
    LEFT JOIN utilisateurs u ON v.utilisateur_id = u.id
    WHERE 1=1
";
$params = [];
$types = '';

if ($date_debut) {
    $query .= " AND v.date_vente >= ?";
    $params[] = $date_debut . ' 00:00:00';
    $types .= 's';
}
if ($date_fin) {
    $query .= " AND v.date_vente <= ?";
    $params[] = $date_fin . ' 23:59:59';
    $types .= 's';
}

if (!empty($search)) {
    $query .= " AND (u.nom LIKE ? OR u.prenom LIKE ? OR v.id LIKE ?)";
    $searchTerm = "%$search%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $types .= 'sss';
}

$query .= " GROUP BY v.id, v.date_vente, u.nom, u.prenom
            ORDER BY $sort_by $sort_order";
$stmt = $conn->prepare($query);
if ($params) {
    $stmt->bind_param($types, ...$params);
}

$ventes = [];
if ($stmt->execute()) {
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $ventes[] = $row;
    }
} else {
    $error = "Erreur lors de la récupération des ventes : " . $stmt->error;
}
$stmt->close();

// Fonction pour enregistrer un log
function logAction($conn, $utilisateur_id, $action, $details) {
    $ip_address = filter_var($_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP) ?: '0.0.0.0';
    $stmt = $conn->prepare("INSERT INTO logs (utilisateur_id, action, details, ip_address, date_log) VALUES (?, ?, ?, ?, NOW())");
    $stmt->bind_param("isss", $utilisateur_id, $action, $details, $ip_address);
    $stmt->execute();
    $stmt->close();
}

// Export CSV
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=ventes_export_' . date('Ymd_His') . '.csv');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['ID', 'Date Vente', 'Caissier', 'Total Vente (TND)'], ';');
    
    foreach ($ventes as $vente) {
        $caissier = ($vente['caissier_prenom'] ?? '') . ' ' . ($vente['caissier_nom'] ?? '');
        fputcsv($output, [
            $vente['id'],
            date('d/m/Y H:i:s', strtotime($vente['date_vente'])),
            $caissier,
            number_format($vente['total_vente'], 3, ',', ' ')
        ], ';');
    }
    ob_end_flush();
    exit;
}

include '../sidebar.php';
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Historique des Ventes - Gestionnaire Magasin</title>
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
        }

        #sidebar.hide ~ .main-container {
            width: calc(99% - 80px);
            left: 60px;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: var(--shadow);
            border-left: 4px solid var(--primary);
            height: 200px;
        }

        .header-left h1 {
            font-size: 24px;
            font-weight: 600;
            color: var(--secondary);
            margin-bottom: 4px;
        }

        .header-left p {
            color: var(--gray);
            font-size: 14px;
        }

        .header-right {
            display: flex;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
        }

        .toolbar {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }

        .search-form {
            position: relative;
        }

        .search-form input {
            padding: 10px 15px 10px 40px;
            border: 1px solid var(--border);
            border-radius: 30px;
            font-size: 14px;
            width: 400px;
            transition: all 0.3s;
            background:rgba(203, 222, 241, 0.55);
        }

        .search-form input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.2);
        }

        .search-form i {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray);
        }

        .filter-form {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
        }

        .filter-form label {
            font-size: 13px;
            color: var(--secondary);
            font-weight: 500;
        }

        .filter-form input[type="date"] {
            padding: 10px;
            border: 1px solid var(--border);
            border-radius: 6px;
            font-size: 13px;
            color: var(--secondary);
            transition: all 0.3s;
            background: #f8f9fa;
        }

        .filter-form input[type="date"]:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.2);
        }

        .btn {
            padding: 10px 18px;
            border-radius: 6px;
            border: none;
            color: white;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
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

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
        }

        .btn-success {
            background: linear-gradient(135deg, var(--success), #27ae60);
        }

        .btn-danger {
            background: linear-gradient(135deg, var(--danger), #c0392b);
        }

        .btn-outline {
            background: transparent;
            border: 1px solid var(--primary);
            color: var(--primary);
        }

        .btn-outline:hover {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
        }

        .alert {
            padding: 12px 15px;
            border-radius: 6px;
            margin-bottom: 15px;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 10px;
            animation: slideIn 0.3s ease;
        }

        @keyframes slideIn {
            from { opacity: 0; transform: translateX(-20px); }
            to { opacity: 1; transform: translateX(0); }
        }

        .alert-error {
            background: rgba(231, 76, 60, 0.1);
            color: var(--danger);
            border-left: 4px solid var(--danger);
        }

        .alert-success {
            background: rgba(46, 204, 113, 0.1);
            color: var(--success);
            border-left: 4px solid var(--success);
        }

        .card {
            background: white;
            border-radius: 12px;
            box-shadow: var(--shadow);
            padding: 20px;
            margin-bottom: 25px;
            border-left: 4px solid var(--accent);
            transition: transform 0.3s ease;
        }

        .card:hover {
            transform: translateY(-5px);
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .card-title {
            font-size: 18px;
            font-weight: 600;
            color: var(--secondary);
        }

        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }

        .stat-card {
            background: white;
            border-radius: 12px;
            box-shadow: var(--shadow);
            padding: 20px;
            display: flex;
            flex-direction: column;
            transition: transform 0.3s ease;
            border-left: 4px solid var(--primary);
        }

        .stat-card:hover {
            transform: translateY(-5px);
        }

        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            transition: transform 0.3s ease;
        }

        .stat-icon:hover {
            transform: scale(1.1);
        }

        .bg-primary {
            background: rgba(52, 152, 219, 0.15);
            color: var(--primary);
        }

        .bg-success {
            background: rgba(46, 204, 113, 0.15);
            color: var(--success);
        }

        .bg-warning {
            background: rgba(243, 156, 18, 0.15);
            color: var(--warning);
        }

        .bg-info {
            background: rgba(142, 68, 173, 0.15);
            color: var(--info);
        }

        .stat-title {
            font-size: 14px;
            color: var(--gray);
            font-weight: 500;
        }

        .stat-value {
            font-size: 24px;
            font-weight: 600;
            color: var(--secondary);
            margin-top: 5px;
        }

        .stat-trend {
            font-size: 13px;
            margin-top: 8px;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .trend-up {
            color: var(--success);
        }

        .trend-down {
            color: var(--danger);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
            min-width: 600px;
        }

        th, td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid var(--border);
        }

        th {
            background: var(--light);
            font-weight: 600;
            color: var(--secondary);
            cursor: pointer;
            position: sticky;
            top: 0;
            transition: background 0.3s ease;
        }

        th:hover {
            background: #e9ecef;
        }

        th a {
            color: var(--secondary);
            text-decoration: none;
            display: block;
            padding: 8px 0;
        }

        td {
            color: var(--dark);
        }

        tr {
            transition: background 0.3s ease;
        }

        tr:hover {
            background-color: rgba(52, 152, 219, 0.05);
            transform: translateX(5px);
        }

        .action-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: var(--light);
            border: none;
            color: var(--primary);
            font-size: 14px;
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .action-btn:hover {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
        }

        .action-btn.delete-btn:hover {
            background: linear-gradient(135deg, var(--danger), #c0392b);
        }

        .pagination {
            display: flex;
            justify-content: center;
            margin-top: 25px;
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

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(3px);
        }

        .modal-content {
            background: white;
            border-radius: 12px;
            padding: 25px;
            width: 90%;
            max-width: 700px;
            max-height: 80vh;
            overflow-y: auto;
            position: relative;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            animation: modalOpen 0.3s ease;
        }

        @keyframes modalOpen {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .modal-close {
            position: absolute;
            top: 15px;
            right: 20px;
            font: 600 24px sans-serif;
            color: var(--gray);
            cursor: pointer;
            transition: color 0.3s;
        }

        .modal-close:hover {
            color: var(--dark);
        }

        .modal-content h3 {
            font-size: 18px;
            font-weight: 600;
            color: var(--secondary);
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 1px solid var(--border);
        }

        .sale-details-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
            margin-top: 10px;
        }

        .sale-details-table th, .sale-details-table td {
            padding: 12px 15px;
            border-bottom: 1px solid var(--border);
        }

        .sale-details-table th {
            background: var(--light);
            font-weight: 600;
        }

        .sale-details-table td {
            text-align: right;
        }

        .sale-details-table td:first-child {
            text-align: left;
        }

        .total-row td {
            font-weight: 600;
            background: var(--light);
        }

        .loading {
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 40px;
        }

        .spinner {
            width: 40px;
            height: 40px;
            border: 4px solid rgba(52, 152, 219, 0.2);
            border-top: 4px solid var(--primary);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .product-list-container table {
            border-collapse: collapse;
            width: 100%;
        }

        .product-list-container th, .product-list-container td {
            padding: 8px;
            border-bottom: 1px solid var(--border);
            text-align: left;
        }

        .text-muted {
            color: var(--gray);
        }

        @media (max-width: 1200px) {
            .main-container {
                margin-left: 0;
                padding: 15px;
            }
            
            .header {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }
            
            .header-right {
                width: 100%;
                justify-content: space-between;
            }
            
            .search-form input {
                width: 200px;
            }
        }

        @media (max-width: 768px) {
            .stats-container {
                grid-template-columns: 1fr;
            }
            
            .toolbar {
                flex-direction: column;
                width: 100%;
            }
            
            .filter-form {
                width: 100%;
                flex-wrap: wrap;
            }
            
            .search-form {
                width: 100%;
            }
            
            .search-form input {
                width: 100%;
            }
            
            table {
                font-size: 13px;
            }
            
            th, td {
                padding: 10px;
            }
        }

        @media (max-width: 576px) {
            .btn {
                padding: 8px 12px;
                font-size: 12px;
            }
            
            .stat-value {
                font-size: 20px;
            }
            
            .modal-content {
                width: 95%;
                padding: 15px;
            }
        }
    </style>
</head>
<body>
    <main class="main-container">
        <header class="header">
            <div class="header-left" style="display: flex; align-items: center; gap: 15px;">
                <i class="fas fa-history" style="font-size: 24px; color: var(--primary);"></i>
                <div>
                    <h1>Historique des Ventes</h1>
                    <p>Consulter les ventes du magasin scolaire</p>
                </div>
            </div>
            <div class="header-right">
                <div class="toolbar">
                    <form class="search-form" method="GET">
                        <i class="fas fa-search"></i>
                        <input type="text" name="search" placeholder="Rechercher..." value="<?= htmlspecialchars($search) ?>">
                        <input type="hidden" name="page" value="<?= htmlspecialchars($page ?? 1) ?>">
                    </form>
                    
                    <form class="filter-form" method="GET">
                        <label for="date_debut"><i class="fas fa-calendar"></i> Date début:</label>
                        <input type="date" id="date_debut" name="date_debut" value="<?= htmlspecialchars($date_debut) ?>">
                        <label for="date_fin">Date fin:</label>
                        <input type="date" id="date_fin" name="date_fin" value="<?= htmlspecialchars($date_fin) ?>">
                        <input type="hidden" name="page" value="<?= htmlspecialchars($page ?? 1) ?>">
                        <button type="submit" class="btn btn-primary" aria-label="Filtrer les ventes">
                            <i class="fas fa-filter"></i> Filtrer
                        </button>
                    </form>
                </div>
                
                <a href="?export=csv&date_debut=<?= htmlspecialchars(urlencode($date_debut)) ?>&date_fin=<?= htmlspecialchars(urlencode($date_fin)) ?>&search=<?= htmlspecialchars(urlencode($search)) ?>" 
                   class="btn btn-success">
                    <i class="fas fa-file-export"></i> Exporter CSV
                </a>
            </div>
        </header>

        <?php if ($error): ?>
            <div class="alert alert-error" role="alert">
                <i class="fas fa-exclamation-circle"></i>
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>
        
        <?php if (isset($_GET['exported']) && $_GET['exported'] === 'success'): ?>
            <div class="alert alert-success" role="alert">
                <i class="fas fa-check-circle"></i>
                Export CSV réalisé avec succès!
            </div>
        <?php endif; ?>

        <div class="stats-container">
            <div class="stat-card">
                <div class="stat-header">
                    <div>
                        <div class="stat-title">Total des Ventes</div>
                        <div class="stat-value"><?= number_format(array_sum(array_column($ventes, 'total_vente')), 3, ',', ' ') ?> TND</div>
                    </div>
                    <div class="stat-icon bg-primary">
                        <i class="fas fa-shopping-cart"></i>
                    </div>
                </div>
                <div class="stat-trend trend-up">
                    <i class="fas fa-arrow-up"></i>
                    <span>12.5% par rapport au mois dernier</span>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-header">
                    <div>
                        <div class="stat-title">Nombre de Transactions</div>
                        <div class="stat-value"><?= count($ventes) ?></div>
                    </div>
                    <div class="stat-icon bg-success">
                        <i class="fas fa-receipt"></i>
                    </div>
                </div>
                <div class="stat-trend trend-up">
                    <i class="fas fa-arrow-up"></i>
                    <span>8.3% par rapport au mois dernier</span>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-header">
                    <div>
                        <div class="stat-title">Panier Moyen</div>
                        <div class="stat-value">
                            <?= count($ventes) > 0 ? number_format(array_sum(array_column($ventes, 'total_vente')) / count($ventes), 3, ',', ' ') : '0' ?> TND
                        </div>
                    </div>
                    <div class="stat-icon bg-warning">
                        <i class="fas fa-chart-line"></i>
                    </div>
                </div>
                <div class="stat-trend trend-down">
                    <i class="fas fa-arrow-down"></i>
                    <span>2.1% par rapport au mois dernier</span>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-header">
                    <div>
                        <div class="stat-title">Produit le Plus Vendu</div>
                        <div class="stat-value">
                            <?php
                            $top_product = array_reduce($ventes, function($carry, $vente) {
                                return $carry ? ($vente['product_count'] > $carry['product_count'] ? $vente : $carry) : $vente;
                            }, null);
                            echo $top_product ? htmlspecialchars($top_product['top_product_name'] ?? 'N/A') : 'N/A';
                            ?>
                        </div>
                    </div>
                    <div class="stat-icon bg-info">
                        <i class="fas fa-star"></i>
                    </div>
                </div>
                <div class="stat-trend trend-up">
                    <i class="fas fa-arrow-up"></i>
                    <span>
                        <?php
                        echo $top_product ? number_format($top_product['product_count'], 0, ',', ' ') . ' unités' : '0 unités';
                        ?>
                    </span>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <div class="card-title">Liste des Ventes</div>
                <div class="card-actions">
                    <span class="text-muted"><?= count($ventes) ?> ventes trouvées</span>
                </div>
            </div>
            
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>
                                <a href="?sort_by=id&sort_order=<?= $sort_by === 'id' && $sort_order === 'ASC' ? 'DESC' : 'ASC' ?>&date_debut=<?= htmlspecialchars(urlencode($date_debut)) ?>&date_fin=<?= htmlspecialchars(urlencode($date_fin)) ?>&search=<?= htmlspecialchars(urlencode($search)) ?>&page=<?= htmlspecialchars($page ?? 1) ?>">
                                    ID <?= $sort_by === 'id' ? ($sort_order === 'ASC' ? '▲' : '▼') : '' ?>
                                </a>
                            </th>
                            <th>
                                <a href="?sort_by=date_vente&sort_order=<?= $sort_by === 'date_vente' && $sort_order === 'ASC' ? 'DESC' : 'ASC' ?>&date_debut=<?= htmlspecialchars(urlencode($date_debut)) ?>&date_fin=<?= htmlspecialchars(urlencode($date_fin)) ?>&search=<?= htmlspecialchars(urlencode($search)) ?>&page=<?= htmlspecialchars($page ?? 1) ?>">
                                    Date Vente <?= $sort_by === 'date_vente' ? ($sort_order === 'ASC' ? '▲' : '▼') : '' ?>
                                </a>
                            </th>
                            <th>Caissier</th>
                            <th>
                                <a href="?sort_by=total_vente&sort_order=<?= $sort_by === 'total_vente' && $sort_order === 'ASC' ? 'DESC' : 'ASC' ?>&date_debut=<?= htmlspecialchars(urlencode($date_debut)) ?>&date_fin=<?= htmlspecialchars(urlencode($date_fin)) ?>&search=<?= htmlspecialchars(urlencode($search)) ?>&page=<?= htmlspecialchars($page ?? 1) ?>">
                                    Total Vente (TND) <?= $sort_by === 'total_vente' ? ($sort_order === 'ASC' ? '▲' : '▼') : '' ?>
                                </a>
                            </th>
                            <th>Actions</th>
                            <th>Produits Vendus</th>
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
                        <?php if (!empty($paged_ventes)): ?>
                            <?php foreach ($paged_ventes as $vente): ?>
                                <tr>
                                    <td>#<?= htmlspecialchars($vente['id']) ?></td>
                                    <td><?= date('d/m/Y H:i', strtotime($vente['date_vente'])) ?></td>
                                    <td><?= htmlspecialchars(($vente['caissier_prenom'] ?? '') . ' ' . ($vente['caissier_nom'] ?? '')) ?></td>
                                    <td><strong><?= number_format($vente['total_vente'], 3, ',', ' ') ?> TND</strong></td>
                                    <td>
                                        <div class="action-group">
                                            <button class="action-btn delete-btn" 
                                                    data-id="<?= htmlspecialchars($vente['id']) ?>" 
                                                    aria-label="Supprimer la vente">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                    <td>
                                        <button class="btn btn-outline toggle-products" 
                                                data-id="<?= htmlspecialchars($vente['id']) ?>"
                                                aria-label="Afficher les produits">
                                            <i class="fas fa-boxes"></i>
                                            <?= htmlspecialchars($vente['product_count']) ?> produits
                                        </button>
                                        <div class="product-list" id="products-<?= htmlspecialchars($vente['id']) ?>" 
                                             style="display: none; margin-top: 10px; background: #f8f9fa; padding: 10px; border-radius: 5px;">
                                            <div class="loading">
                                                <div class="spinner"></div>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" style="text-align: center; padding: 30px;">
                                    <i class="fas fa-inbox" style="font-size: 48px; color: #bdc3c7; margin-bottom: 15px;"></i>
                                    <p>Aucune vente trouvée</p>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <a href="?page=<?= max(1, $page - 1) ?>&sort_by=<?= htmlspecialchars($sort_by) ?>&sort_order=<?= htmlspecialchars($sort_order) ?>&date_debut=<?= htmlspecialchars(urlencode($date_debut)) ?>&date_fin=<?= htmlspecialchars(urlencode($date_fin)) ?>&search=<?= htmlspecialchars(urlencode($search)) ?>" 
                       class="page-link <?= $page <= 1 ? 'disabled' : '' ?>">
                        <i class="fas fa-chevron-left"></i>
                    </a>
                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <a href="?page=<?= $i ?>&sort_by=<?= htmlspecialchars($sort_by) ?>&sort_order=<?= htmlspecialchars($sort_order) ?>&date_debut=<?= htmlspecialchars(urlencode($date_debut)) ?>&date_fin=<?= htmlspecialchars(urlencode($date_fin)) ?>&search=<?= htmlspecialchars(urlencode($search)) ?>" 
                           class="page-link <?= $i === $page ? 'active' : '' ?>">
                            <?= $i ?>
                        </a>
                    <?php endfor; ?>
                    <a href="?page=<?= min($total_pages, $page + 1) ?>&sort_by=<?= htmlspecialchars($sort_by) ?>&sort_order=<?= htmlspecialchars($sort_order) ?>&date_debut=<?= htmlspecialchars(urlencode($date_debut)) ?>&date_fin=<?= htmlspecialchars(urlencode($date_fin)) ?>&search=<?= htmlspecialchars(urlencode($search)) ?>" 
                       class="page-link <?= $page >= $total_pages ? 'disabled' : '' ?>">
                        <i class="fas fa-chevron-right"></i>
                    </a>
                </div>
            <?php endif; ?>
        </div>

        <!-- Modal pour les détails de la vente -->
        <div class="modal" id="saleModal">
            <div class="modal-content">
                <span class="modal-close">×</span>
                <h3>Détails de la Vente</h3>
                <div id="saleDetails">
                    <div class="loading">
                        <div class="spinner"></div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Modal de confirmation de suppression -->
        <div class="modal" id="confirmModal">
            <div class="modal-content">
                <span class="modal-close">×</span>
                <h3>Confirmer la suppression</h3>
                <p>Êtes-vous sûr de vouloir supprimer cette vente ? Cette action est irréversible.</p>
                <div style="display: flex; gap: 10px; margin-top: 20px;">
                    <button class="btn btn-outline" id="cancelDelete">Annuler</button>
                    <button class="btn btn-danger" id="confirmDelete">Supprimer</button>
                </div>
            </div>
        </div>
    </main>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Toggle sidebar
            const toggleButton = document.getElementById('toggleSidebar');
            const sidebar = document.getElementById('sidebar');
            if (toggleButton && sidebar) {
                toggleButton.addEventListener('click', function() {
                    sidebar.classList.toggle('hide');
                    localStorage.setItem('sidebarState', sidebar.classList.contains('hide') ? 'hidden' : 'visible');
                });

                if (localStorage.getItem('sidebarState') === 'hidden') {
                    sidebar.classList.add('hide');
                }
            }

            // Modal handling
            const modal = document.getElementById('saleModal');
            const confirmModal = document.getElementById('confirmModal');
            const saleDetails = document.getElementById('saleDetails');
            const closeButtons = document.querySelectorAll('.modal-close');
            const deleteButtons = document.querySelectorAll('.delete-btn');
            const toggleButtons = document.querySelectorAll('.toggle-products');
            let currentSaleId = null;

            // Open delete confirmation modal
            deleteButtons.forEach(button => {
                button.addEventListener('click', function() {
                    currentSaleId = this.dataset.id;
                    confirmModal.style.display = 'flex';
                    document.body.style.overflow = 'hidden';
                });
            });

            // Toggle product visibility
            toggleButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const saleId = this.dataset.id;
                    const productContainer = document.getElementById(`products-${saleId}`);
                    if (productContainer.style.display === 'none') {
                        productContainer.style.display = 'block';
                        if (productContainer.querySelector('.product-item') === null) {
                            fetchProductsForSale(saleId, productContainer);
                        }
                    } else {
                        productContainer.style.display = 'none';
                    }
                });
            });

            // Close modals
            closeButtons.forEach(button => {
                button.addEventListener('click', function() {
                    modal.style.display = 'none';
                    confirmModal.style.display = 'none';
                    document.body.style.overflow = 'auto';
                });
            });

            // Close modal on outside click
            window.addEventListener('click', function(event) {
                if (event.target === modal || event.target === confirmModal) {
                    modal.style.display = 'none';
                    confirmModal.style.display = 'none';
                    document.body.style.overflow = 'auto';
                }
            });

            // Cancel delete
            document.getElementById('cancelDelete').addEventListener('click', function() {
                confirmModal.style.display = 'none';
                document.body.style.overflow = 'auto';
            });

            // Confirm delete
            document.getElementById('confirmDelete').addEventListener('click', function() {
                if (currentSaleId) {
                    deleteSale(currentSaleId);
                }
            });

            // Fetch sale details via AJAX
            function fetchSaleDetails(saleId) {
                saleDetails.innerHTML = '<div class="loading"><div class="spinner"></div></div>';
                const formData = new FormData();
                formData.append('action', 'get_sale_details');
                formData.append('sale_id', saleId);
                
                fetch('', {
                    method: 'POST',
                    body: formData
                })
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Erreur réseau : ' + response.statusText);
                    }
                    return response.text();
                })
                .then(text => {
                    try {
                        const data = JSON.parse(text);
                        if (data.error) {
                            saleDetails.innerHTML = `<p style="color: #e74c3c;">${data.error}</p>`;
                        } else {
                            saleDetails.innerHTML = data.html;
                        }
                    } catch (e) {
                        console.error('Réponse brute:', text);
                        saleDetails.innerHTML = `<p style="color: #e74c3c;">Erreur: Réponse JSON invalide</p>`;
                    }
                })
                .catch(error => {
                    saleDetails.innerHTML = `<p style="color: #e74c3c;">Erreur: ${error.message}</p>`;
                });
            }

            // Fetch products for a specific sale
            function fetchProductsForSale(saleId, container) {
                const formData = new FormData();
                formData.append('action', 'get_sale_products');
                formData.append('sale_id', saleId);
                
                fetch('', {
                    method: 'POST',
                    body: formData
                })
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Erreur réseau : ' + response.statusText);
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.error) {
                        container.innerHTML = `<p style="color: #e74c3c;">${data.error}</p>`;
                    } else {
                        container.innerHTML = data.html;
                    }
                })
                .catch(error => {
                    container.innerHTML = `<p style="color: #e74c3c;">Erreur: ${error.message}</p>`;
                });
            }

            // Delete sale via AJAX
            function deleteSale(saleId) {
                const formData = new FormData();
                formData.append('action', 'delete_sale');
                formData.append('sale_id', saleId);
                
                fetch('', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const row = document.querySelector(`.delete-btn[data-id="${saleId}"]`).closest('tr');
                        row.remove();
                        showAlert('Vente supprimée avec succès!', 'success');
                        // Refresh stats after deletion
                        location.reload();
                    } else {
                        showAlert(data.error || 'Erreur lors de la suppression', 'error');
                    }
                    confirmModal.style.display = 'none';
                    document.body.style.overflow = 'auto';
                })
                .catch(error => {
                    showAlert('Erreur réseau: ' + error.message, 'error');
                    confirmModal.style.display = 'none';
                    document.body.style.overflow = 'auto';
                });
            }

            // Show alert message
            function showAlert(message, type) {
                const alertDiv = document.createElement('div');
                alertDiv.className = `alert alert-${type === 'error' ? 'error' : 'success'}`;
                alertDiv.innerHTML = `
                    <i class="fas ${type === 'error' ? 'fa-exclamation-circle' : 'fa-check-circle'}"></i>
                    ${message}
                `;
                document.querySelector('.main-container').insertBefore(alertDiv, document.querySelector('.header').nextSibling);
                setTimeout(() => {
                    alertDiv.remove();
                }, 5000);
            }

            // Filter form validation
            const filterForm = document.querySelector('.filter-form');
            if (filterForm) {
                filterForm.addEventListener('submit', function(event) {
                    const dateDebut = document.getElementById('date_debut').value;
                    const dateFin = document.getElementById('date_fin').value;
                    if (dateDebut && dateFin && dateDebut > dateFin) {
                        event.preventDefault();
                        showAlert('La date de début doit être antérieure ou égale à la date de fin.', 'error');
                    }
                });
            }
            
            // Search form submit on type
            const searchInput = document.querySelector('.search-form input');
            if (searchInput) {
                searchInput.addEventListener('keyup', function(event) {
                    if (event.key === 'Enter') {
                        this.form.submit();
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