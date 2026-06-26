<?php
session_start();

// Verify admin access
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

require_once '../config.php';

// Initialize error and success variables
$error = '';
$success = '';

// Get categories for the form
$categories_query = "SELECT id, nom FROM categories";
$categories_result = $conn->query($categories_query);
$categories = [];
if ($categories_result) {
    while ($row = $categories_result->fetch_assoc()) {
        $categories[] = $row;
    }
} else {
    $error = "Erreur lors de la récupération des catégories : " . $conn->error;
}

// Get all products with pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// Get total products count
$count_query = "SELECT COUNT(*) as total FROM produits";
$count_result = $conn->query($count_query);
$total_products = $count_result->fetch_assoc()['total'];
$total_pages = ceil($total_products / $limit);

// Get products
$products_query = "SELECT p.id, p.nom, p.description, p.code_barres, p.prix_achat, p.prix_vente, p.quantite, p.seuil_alerte, p.fournisseur, c.nom AS categorie_nom, p.categorie_id
                   FROM produits p
                   LEFT JOIN categories c ON p.categorie_id = c.id
                   ORDER BY p.id DESC
                   LIMIT $limit OFFSET $offset";
$products_result = $conn->query($products_query);
$products = [];
if ($products_result) {
    while ($row = $products_result->fetch_assoc()) {
        $products[] = $row;
    }
} else {
    $error = "Erreur lors de la récupération des produits : " . $conn->error;
}

// Process actions (add, edit, delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'check_barcode') {
        $response = ['exists' => false, 'error' => ''];
        $code_barres = trim($_POST['code_barres'] ?? '');
        $product_id = intval($_POST['product_id'] ?? 0);

        if ($code_barres) {
            $query = "SELECT id FROM produits WHERE code_barres = ?";
            if ($product_id) {
                $query .= " AND id != ?";
            }
            $stmt = $conn->prepare($query);
            if ($product_id) {
                $stmt->bind_param("si", $code_barres, $product_id);
            } else {
                $stmt->bind_param("s", $code_barres);
            }
            if ($stmt->execute()) {
                $result = $stmt->get_result();
                if ($result->num_rows > 0) {
                    $response['exists'] = true;
                }
            } else {
                $response['error'] = "Erreur lors de la vérification : " . $stmt->error;
            }
            $stmt->close();
        } else {
            $response['error'] = "Code-barres invalide.";
        }
        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    } elseif ($action === 'add') {
        $nom = trim($_POST['nom'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $code_barres = trim($_POST['code_barres'] ?? '');
        $prix_achat = floatval($_POST['prix_achat'] ?? 0);
        $prix_vente = floatval($_POST['prix_vente'] ?? 0);
        $quantite = intval($_POST['quantite'] ?? 0);
        $seuil_alerte = intval($_POST['seuil_alerte'] ?? 5);
        $categorie_id = !empty($_POST['categorie_id']) ? intval($_POST['categorie_id']) : null;
        $fournisseur = trim($_POST['fournisseur'] ?? '');

        // Validation
        if ($nom && $code_barres && $prix_achat > 0 && $prix_vente > 0 && $quantite >= 0) {
            // Check barcode uniqueness
            $stmt = $conn->prepare("SELECT id FROM produits WHERE code_barres = ?");
            $stmt->bind_param("s", $code_barres);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result->num_rows > 0) {
                $error = "Ce code-barres existe déjà.";
            } else {
                $stmt = $conn->prepare("INSERT INTO produits (nom, description, code_barres, prix_achat, prix_vente, quantite, seuil_alerte, categorie_id, fournisseur) 
                                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $null = null;
                if ($categorie_id === null) {
                    $stmt->bind_param("sssddiiss", $nom, $description, $code_barres, $prix_achat, $prix_vente, $quantite, $seuil_alerte, $null, $fournisseur);
                } else {
                    $stmt->bind_param("sssddiisi", $nom, $description, $code_barres, $prix_achat, $prix_vente, $quantite, $seuil_alerte, $categorie_id, $fournisseur);
                }
                
                if ($stmt->execute()) {
                    // Record stock movement
                    $produit_id = $conn->insert_id;
                    $utilisateur_id = $_SESSION['user_id'] ?? null;
                    $stmt_stock = $conn->prepare("INSERT INTO stock_mouvements (produit_id, type_mouvement, quantite, raison, utilisateur_id) 
                                                  VALUES (?, 'entree', ?, 'Ajout de produit', ?)");
                    $stmt_stock->bind_param("iii", $produit_id, $quantite, $utilisateur_id);
                    $stmt_stock->execute();
                    $stmt_stock->close();
                    
                    $success = "Produit ajouté avec succès.";
                    header("Location: gestion_produits.php?success=" . urlencode($success));
                    exit;
                } else {
                    $error = "Erreur lors de l'ajout du produit : " . $stmt->error;
                }
            }
            $stmt->close();
        } else {
            $error = "Veuillez remplir tous les champs requis (nom, code-barres, prix d'achat, prix de vente).";
        }
    } elseif ($action === 'edit') {
        $id = intval($_POST['id'] ?? 0);
        $nom = trim($_POST['nom'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $code_barres = trim($_POST['code_barres'] ?? '');
        $prix_achat = floatval($_POST['prix_achat'] ?? 0);
        $prix_vente = floatval($_POST['prix_vente'] ?? 0);
        $quantite = intval($_POST['quantite'] ?? 0);
        $seuil_alerte = intval($_POST['seuil_alerte'] ?? 5);
        $categorie_id = !empty($_POST['categorie_id']) ? intval($_POST['categorie_id']) : null;
        $fournisseur = trim($_POST['fournisseur'] ?? '');

        // Get current quantity
        $stmt = $conn->prepare("SELECT quantite FROM produits WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $old_quantite = $result->fetch_assoc()['quantite'] ?? 0;
        $stmt->close();

        // Check barcode uniqueness
        $stmt = $conn->prepare("SELECT id FROM produits WHERE code_barres = ? AND id != ?");
        $stmt->bind_param("si", $code_barres, $id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $error = "Ce code-barres existe déjà pour un autre produit.";
        } else {
            // Validation
            if ($id && $nom && $code_barres && $prix_achat > 0 && $prix_vente > 0 && $quantite >= 0) {
                $stmt = $conn->prepare("UPDATE produits SET nom = ?, description = ?, code_barres = ?, prix_achat = ?, prix_vente = ?, quantite = ?, seuil_alerte = ?, categorie_id = ?, fournisseur = ? 
                                        WHERE id = ?");
                $null = null;
                if ($categorie_id === null) {
                    $stmt->bind_param("sssddiissi", $nom, $description, $code_barres, $prix_achat, $prix_vente, $quantite, $seuil_alerte, $null, $fournisseur, $id);
                } else {
                    $stmt->bind_param("sssddiisii", $nom, $description, $code_barres, $prix_achat, $prix_vente, $quantite, $seuil_alerte, $categorie_id, $fournisseur, $id);
                }
                
                if ($stmt->execute()) {
                    // Record stock movement if quantity changed
                    if ($quantite != $old_quantite) {
                        $type_mouvement = $quantite > $old_quantite ? 'entree' : 'sortie';
                        $quantite_diff = abs($quantite - $old_quantite);
                        $utilisateur_id = $_SESSION['user_id'] ?? null;
                        $stmt_stock = $conn->prepare("INSERT INTO stock_mouvements (produit_id, type_mouvement, quantite, raison, utilisateur_id) 
                                                      VALUES (?, ?, ?, 'Mise à jour de produit', ?)");
                        $stmt_stock->bind_param("isii", $id, $type_mouvement, $quantite_diff, $utilisateur_id);
                        $stmt_stock->execute();
                        $stmt_stock->close();
                    }
                    
                    $success = "Produit modifié avec succès.";
                    header("Location: gestion_produits.php?success=" . urlencode($success));
                    exit;
                } else {
                    $error = "Erreur lors de la modification du produit : " . $stmt->error;
                }
                $stmt->close();
            } else {
                $error = "Veuillez remplir tous les champs requis (nom, code-barres, prix d'achat, prix de vente).";
            }
        }
        $stmt->close();
    } elseif ($action === 'delete') {
        $id = intval($_POST['id'] ?? 0);
        if ($id) {
            // Get current quantity for logging
            $stmt = $conn->prepare("SELECT quantite FROM produits WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $result = $stmt->get_result();
            $quantite = $result->fetch_assoc()['quantite'] ?? 0;
            $stmt->close();

            $stmt = $conn->prepare("DELETE FROM produits WHERE id = ?");
            $stmt->bind_param("i", $id);
            
            if ($stmt->execute()) {
                // Record stock movement
                $utilisateur_id = $_SESSION['user_id'] ?? null;
                $stmt_stock = $conn->prepare("INSERT INTO stock_mouvements (produit_id, type_mouvement, quantite, raison, utilisateur_id) 
                                              VALUES (?, 'sortie', ?, 'Suppression de produit', ?)");
                $stmt_stock->bind_param("iii", $id, $quantite, $utilisateur_id);
                $stmt_stock->execute();
                $stmt_stock->close();
                
                $success = "Produit supprimé avec succès.";
                header("Location: gestion_produits.php?success=" . urlencode($success));
                exit;
            } else {
                $error = "Erreur lors de la suppression du produit : " . $stmt->error;
            }
            $stmt->close();
        } else {
            $error = "ID du produit non spécifié.";
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
    <title>Gestion des Produits - Gestionnaire Magasin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #4361ee;
            --secondary: #3f37c9;
            --success: #4cc9f0;
            --danger: #f72585;
            --warning: #f8961e;
            --info: #4895ef;
            --dark: #212529;
            --light:rgb(255, 255, 255);
            --gray: #6c757d;
            --light-gray: #e9ecef;
            --border-radius: 12px;
            --box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            --transition: all 0.3s ease;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        body {
            background-color: #f5f7fa;
            color: #333;
            min-height: 100vh;
        }

        .main-container {
            margin-left: 15px;
            margin-top:25px;
            position: relative;
			width: calc(98% - 300px);
			left: 300px;
			transition: .3s ease;
    }
    #sidebar.hide ~ .main-container {
			width: calc(99% - 80px);
			left: 60px;

		}
    
    .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            background: white;
            padding: 20px 30px;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            margin-bottom:15px;
            height:70px;
        }

        .header-left h1 {
            font-size: 20px;
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 4px;
        }

        .header-left p {
            color: #7f8c8d;
            font-size: 15px;
        }

        .header-right {
            display: flex;
            gap: 15px;
        }

        .action-btn {
            display: inline-flex;
            align-items: center;
            padding: 12px 20px;
            border-radius: 8px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border: none;
            color: white;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: var(--transition);
            box-shadow: 0 4px 10px rgba(67, 97, 238, 0.3);
        }

        .action-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 15px rgba(67, 97, 238, 0.4);
        }

        .action-btn i {
            margin-right: 8px;
        }

        .action-btn.add-btn {
            background: linear-gradient(135deg, #4cc9f0, #4895ef);
        }

        .action-btn.edit-btn {
            background: linear-gradient(135deg, #ffd166, #ffb703);
            padding: 8px 12px;
        }

        .action-btn.view-btn {
            background: linear-gradient(135deg, #8ac926, #5cb85c);
            padding: 8px 12px;
        }

        .action-btn.delete-btn {
            background: linear-gradient(135deg, #f8961e, #f3722c);
            padding: 8px 12px;
        }

        .action-btn:hover {
            opacity: 0.9;
        }

        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 25px;
            font-size: 15px;
            text-align: center;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.05);
        }

        .alert-error {
            background: rgba(231, 76, 60, 0.1);
            color: #e74c3c;
            border-left: 4px solid #e74c3c;
        }

        .alert-success {
            background: rgba(46, 204, 113, 0.1);
            color: #2ecc71;
            border-left: 4px solid #2ecc71;
        }

        .products-container {
            background: white;
            border-radius: var(--border-radius);
            padding: 20px;
            box-shadow: var(--box-shadow);
        }

        .products-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
        }

        .products-header h3 {
            font-size: 20px;
            font-weight: 600;
            color: #2c3e50;
        }

        .table-controls {
            display: flex;
            gap: 15px;
            align-items: center;
        }

        .search-box {
            position: relative;
            width: 300px;
        }

        .search-box input {
            width: 100%;
            padding: 12px 15px 12px 45px;
            border: 1px solid #dfe6e9;
            border-radius: 8px;
            font-size: 14px;
            color: #2c3e50;
            transition: var(--transition);
        }

        .search-box input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.2);
            outline: none;
        }

        .search-box i {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray);
        }

        .filter-btn {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px 15px;
            background: white;
            border: 1px solid #dfe6e9;
            border-radius: 8px;
            color: var(--dark);
            font-size: 14px;
            cursor: pointer;
            transition: var(--transition);
        }

        .filter-btn:hover {
            background: var(--light);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
            min-width: 1000px;
        }

        th, td {
            padding: 15px;
            text-align: left;
            border-bottom: 1px solid #dfe6e9;
        }

        th {
            background: #f8f9fa;
            font-weight: 600;
            color: #2c3e50;
            position: sticky;
            top: 0;
        }

        td {
            color: #2c3e50;
        }

        .low-stock {
            color: #e74c3c;
            font-weight: 600;
            background: rgba(231, 76, 60, 0.05);
            padding: 5px 10px;
            border-radius: 20px;
            display: inline-block;
        }

        .action-cell {
            display: flex;
            gap: 8px;
        }

        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            margin-top: 25px;
            gap: 10px;
        }

        .pagination-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            border-radius: 8px;
            background: white;
            border: 1px solid #dfe6e9;
            color: var(--dark);
            font-size: 14px;
            cursor: pointer;
            transition: var(--transition);
        }

        .pagination-btn.active, .pagination-btn:hover {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        .pagination-btn.disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(5px);
            animation: fadeIn 0.3s ease-out;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        @keyframes slideIn {
            from { transform: translateY(-50px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .modal-content {
            background: white;
            border-radius: 12px;
            padding: 30px;
            width: 90%;
            max-width: 700px;
            position: relative;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
            animation: slideIn 0.4s ease-out;
            border: 2px solid var(--primary);
            max-height: 90vh;
            overflow-y: auto;
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #eee;
        }
        
        .modal-title {
            font-size: 24px;
            font-weight: 700;
            color: var(--secondary);
            margin: 0;
        }
        
        .close-modal {
            background: none;
            border: none;
            font-size: 28px;
            color: #aaa;
            cursor: pointer;
            transition: color 0.2s;
        }
        
        .close-modal:hover {
            color: var(--danger);
        }
        
        .modal-body {
            padding: 15px 0;
            margin-bottom: 20px;
        }
        
        .modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            padding-top: 20px;
            border-top: 2px solid #eee;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            font-size: 14px;
            font-weight: 500;
            color: #2c3e50;
            margin-bottom: 8px;
        }

        .form-group label .required {
            color: #e74c3c;
        }

        .form-group input, .form-group textarea, .form-group select {
            width: 100%;
            padding: 12px;
            border: 1px solid #dfe6e9;
            border-radius: 8px;
            font-size: 14px;
            color: #2c3e50;
            transition: var(--transition);
        }

        .form-group input:focus, .form-group textarea:focus, .form-group select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.2);
            outline: none;
        }

        .form-group textarea {
            resize: vertical;
            min-height: 100px;
        }

        .form-row {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
        }

        .form-col {
            flex: 1;
        }

        .barcode-scan {
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .barcode-scan input[type="text"] {
            flex: 1;
        }

        .barcode-scan button {
            padding: 12px 15px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            transition: var(--transition);
        }

        .barcode-scan button:hover {
            opacity: 0.9;
        }
         
        .barcode-error {
            color: #e74c3c;
            font-size: 13px;
            margin-top: 5px;
            display: none;
        }
         
        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        } 
         
        .stat-card {
            background: white;
            border-radius: var(--border-radius);
            padding: 20px;
            box-shadow: var(--box-shadow);
            text-align: center;
            transition: var(--transition);
        }
          
        .stat-card:hover {
            transform: translateY(-5px);
        }

        .stat-value {
            font-size: 32px;
            font-weight: 700;
            color: var(--primary);
            margin: 10px 0;
        }

        .stat-label {
            color: var(--gray);
            font-size: 14px;
        }

        .stat-card.warning .stat-value {
            color: var(--warning);
        }

        .stat-card.danger .stat-value {
            color: var(--danger);
        }

        .stat-card.success .stat-value {
            color: var(--success);
        }

        .dashboard-section {
            margin-bottom: 10px;
        }

        .dashboard-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .dashboard-header h2 {
            font-size: 18px;
            color: #2c3e50;
        }

        .chart-container {
            background: white;
            border-radius: var(--border-radius);
            padding: 25px;
            box-shadow: var(--box-shadow);
            height: 350px;
        }

        @media (max-width: 992px) {
            .main-container {
                margin-left: 0;
                padding: 20px;
            }
            
            .header {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }

            .header-right {
                width: 100%;
                justify-content: flex-end;
            }

            .form-row {
                flex-direction: column;
                gap: 15px;
            }

            .search-box {
                width: 100%;
            }
        }

        @media (max-width: 768px) {
            .products-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }

            .table-controls {
                width: 100%;
                justify-content: space-between;
            }

            .stats-container {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 576px) {
            .modal-content {
                width: 95%;
                padding: 20px;
            }
        }
        
    </style>
</head>
<body>
    <main class="main-container">
        <header class="header">
            <div class="header-left">
                <h1>Gestion des Produits</h1>
                <p>Gérer les produits du magasin scolaire</p>
            </div>
            <div class="header-right">
                <button class="action-btn add-btn add-product-btn">
                    <i class="fas fa-plus-circle"></i>
                    Ajouter Produit
                </button>
            </div>
        </header>

        <!-- Stats Overview -->
        <div class="dashboard-section">
            <div class="stats-container">
                <div class="stat-card">
                    <div class="stat-value"><?= $total_products ?></div>
                    <div class="stat-label">Produits en Stock</div>
                </div>
                <div class="stat-card warning">
                    <?php
                    // Calculate low stock products
                    $low_stock_query = "SELECT COUNT(*) as count FROM produits WHERE quantite <= seuil_alerte";
                    $low_stock_result = $conn->query($low_stock_query);
                    $low_stock_count = $low_stock_result->fetch_assoc()['count'] ?? 0;
                    ?>
                    <div class="stat-value"><?= $low_stock_count ?></div>
                    <div class="stat-label">Produits en Rupture</div>
                </div>
                <div class="stat-card danger">
                    <?php
                    // Calculate out of stock products
                    $out_of_stock_query = "SELECT COUNT(*) as count FROM produits WHERE quantite = 0";
                    $out_of_stock_result = $conn->query($out_of_stock_query);
                    $out_of_stock_count = $out_of_stock_result->fetch_assoc()['count'] ?? 0;
                    ?>
                    <div class="stat-value"><?= $out_of_stock_count ?></div>
                    <div class="stat-label">Produits Épuisés</div>
                </div>
                <div class="stat-card success">
                    <?php
                    // Calculate total value of inventory
                    $inventory_value_query = "SELECT SUM(prix_achat * quantite) as total FROM produits";
                    $inventory_value_result = $conn->query($inventory_value_query);
                    $inventory_value = $inventory_value_result->fetch_assoc()['total'] ?? 0;
                    ?>
                    <div class="stat-value"><?= number_format($inventory_value, 3, ',', ' ') ?> TND</div>
                    <div class="stat-label">Valeur du Stock</div>
                </div>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error" role="alert">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php elseif ($success || isset($_GET['success'])): ?>
            <div class="alert alert-success" role="alert">
                <?= htmlspecialchars($success ?: $_GET['success']) ?>
            </div>
        <?php endif; ?>

        <section class="products-container">
    
            </div>
            <?php
// Connexion PDO
try {
    $pdo = new PDO("mysql:host=localhost;dbname=magasin;charset=utf8", "root", "");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Erreur de connexion : " . $e->getMessage());
}

// Pagination logic
$per_page = 15;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$start = ($page - 1) * $per_page;

// Total produits
$total_stmt = $pdo->query("SELECT COUNT(*) FROM produits");
$total_products = $total_stmt->fetchColumn();
$total_pages = ceil($total_products / $per_page);

// Fetch produits
$stmt = $pdo->prepare("SELECT p.*, c.nom AS categorie_nom 
                       FROM produits p 
                       LEFT JOIN categories c ON p.categorie_id = c.id
                       ORDER BY p.nom ASC 
                       LIMIT :start, :per_page");
$stmt->bindValue(':start', $start, PDO::PARAM_INT);
$stmt->bindValue(':per_page', $per_page, PDO::PARAM_INT);
$stmt->execute();
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Statistiques
$low_stock_stmt = $pdo->query("SELECT COUNT(*) FROM produits WHERE quantite <= seuil_alerte");
$low_stock_count = $low_stock_stmt->fetchColumn();

$out_of_stock_stmt = $pdo->query("SELECT COUNT(*) FROM produits WHERE quantite = 0");
$out_of_stock_count = $out_of_stock_stmt->fetchColumn();

$inventory_value_stmt = $pdo->query("SELECT SUM(prix_achat * quantite) FROM produits");
$inventory_value = $inventory_value_stmt->fetchColumn();

// Categories pour le modal
$categories_stmt = $pdo->query("SELECT id, nom FROM categories");
$categories = $categories_stmt->fetchAll(PDO::FETCH_ASSOC);
?>


<div class="table-responsive">
    <table id="productsTable" class="table table-striped table-bordered">
        <thead>
            <tr>
                <th>ID</th>
                <th>Nom</th>
                <th>Code-barres</th>
                <th>Catégorie</th>
                <th>Prix Achat</th>
                <th>Prix Vente</th>
                <th>Quantité</th>
                <th>Seuil Alerte</th>
                <th>Fournisseur</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!empty($products)): ?>
                <?php foreach ($products as $product): ?>
                    <tr>
                        <td><?= htmlspecialchars($product['id']) ?></td>
                        <td><?= htmlspecialchars($product['nom']) ?></td>
                        <td><?= htmlspecialchars($product['code_barres']) ?></td>
                        <td><?= htmlspecialchars($product['categorie_nom'] ?? 'Non spécifié') ?></td>
                        <td><?= number_format($product['prix_achat'], 3, ',', ' ') ?> TND</td>
                        <td><?= number_format($product['prix_vente'], 3, ',', ' ') ?> TND</td>
                        <td>
                            <span class="<?= $product['quantite'] <= $product['seuil_alerte'] ? 'low-stock' : '' ?>">
                                <?= htmlspecialchars($product['quantite']) ?>
                            </span>
                        </td>
                        <td><?= htmlspecialchars($product['seuil_alerte']) ?></td>
                        <td><?= htmlspecialchars($product['fournisseur'] ?? 'Non spécifié') ?></td>
                        <td class="action-cell">
                            <button class="action-btn edit-btn" 
                                    data-id="<?= $product['id'] ?>" 
                                    data-nom="<?= htmlspecialchars($product['nom'], ENT_QUOTES) ?>" 
                                    aria-label="Modifier le produit">
                                <i class="fas fa-edit"></i>
                            </button>
                            <form method="post" class="delete-form" style="display:inline;">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= $product['id'] ?>">
                                <button type="submit" class="action-btn delete-btn" 
                                        onclick="return confirm('Voulez-vous vraiment supprimer ce produit ?')" 
                                        aria-label="Supprimer le produit">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="10">Aucun produit trouvé.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Scrollable Pagination -->
<!-- Scrollable Pagination -->
<?php if ($total_pages > 1): ?>
    <div style="overflow-x:auto; margin-top: 15px;">
        <ul class="pagination flex-nowrap" style="white-space: nowrap;">
            <?php if ($page > 1): ?>
                <li class="page-item" style="display:inline-block;">
                    <a class="page-link" href="?page=<?= $page - 1 ?>"><i class="fas fa-chevron-left"></i></a>
                </li>
            <?php endif; ?>

            <?php
            // Always show 1 to total pages
            $max_links = 10; // number of page links to show
            $start_page = max(1, $page - floor($max_links / 2));
            $end_page = min($total_pages, $start_page + $max_links - 1);
            if ($end_page - $start_page + 1 < $max_links) {
                $start_page = max(1, $end_page - $max_links + 1);
            }
            for ($i = $start_page; $i <= $end_page; $i++): ?>
                <li class="page-item <?= $i == $page ? 'active' : '' ?>" style="display:inline-block;">
                    <a class="page-link" href="?page=<?= $i ?>"><?= $i ?></a>
                </li>
            <?php endfor; ?>
            

            <?php if ($page < $total_pages): ?>
                <li class="page-item" style="display:inline-block;">
                    <a class="page-link" href="?page=<?= $page + 1 ?>"><i class="fas fa-chevron-right"></i></a>
                </li>
            <?php endif; ?>
        </ul>
    </div>
<?php endif; ?>


<style>
.pagination .page-item.active .page-link {
    background-color: #007bff;
    color: white;
    border-color: #007bff;
}
.pagination .page-link {
    margin: 0 3px;
    padding: 5px 10px;
    cursor: pointer;
}
.low-stock {
    color: red;
    font-weight: bold;
}
</style>


        <!-- Modal for adding/editing a product -->
        <div class="modal" id="productModal">
            <div class="modal-content">
                <button class="close-modal">&times;</button>
                <h3 id="modalTitle">Ajouter un Produit</h3>
                <form id="productForm" method="post">
                    <input type="hidden" name="action" id="formAction" value="add">
                    <input type="hidden" name="id" id="productId">
                    
                    <div class="form-row">
                        <div class="form-col">
                            <div class="form-group">
                                <label for="nom">Nom <span class="required">*</span></label>
                                <input type="text" name="nom" id="nom" required>
                            </div>
                        </div>
                        <div class="form-col">
                            <div class="form-group">
                                <label for="categorie_id">Catégorie</label>
                                <select name="categorie_id" id="categorie_id">
                                    <option value="">Sélectionner une catégorie</option>
                                    <?php foreach ($categories as $category): ?>
                                        <option value="<?= $category['id'] ?>"><?= htmlspecialchars($category['nom']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="description">Description</label>
                        <textarea name="description" id="description"></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label for="barcode_scan">Scanner Code-barres <span class="required">*</span></label>
                        <div class="barcode-scan">
                            <input type="text" id="barcode_scan" placeholder="Scanner ici" aria-label="Scanner le code-barres">
                            <button type="button" id="scanButton" aria-label="Lancer le scan">
                                <i class="fas fa-barcode"></i> Scan
                            </button>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="code_barres">Code-barres <span class="required">*</span></label>
                        <input type="text" name="code_barres" id="code_barres" required>
                        <div id="barcodeError" class="barcode-error">Ce code-barres existe déjà.</div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-col">
                            <div class="form-group">
                                <label for="prix_achat">Prix d'achat (TND) <span class="required">*</span></label>
                                <input type="number" name="prix_achat" id="prix_achat" step="0.001" min="0" required>
                            </div>
                        </div>
                        <div class="form-col">
                            <div class="form-group">
                                <label for="prix_vente">Prix de vente (TND) <span class="required">*</span></label>
                                <input type="number" name="prix_vente" id="prix_vente" step="0.001" min="0" required>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-col">
                            <div class="form-group">
                                <label for="quantite">Quantité <span class="required">*</span></label>
                                <input type="number" name="quantite" id="quantite" min="0" required>
                            </div>
                        </div>
                        <div class="form-col">
                            <div class="form-group">
                                <label for="seuil_alerte">Seuil d'alerte</label>
                                <input type="number" name="seuil_alerte" id="seuil_alerte" min="0" value="5">
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="fournisseur">Fournisseur</label>
                        <input type="text" name="fournisseur" id="fournisseur">
                    </div>
                    
                    <button type="submit" class="action-btn" id="submitProduct">Enregistrer</button>
                </form>
            </div>
        </div>

        <!-- Modal for viewing product details -->
        <div class="modal" id="viewModal">
            <div class="modal-content">
                <button class="close-modal">&times;</button>
                <h3>Détails du Produit</h3>
                <div id="productDetails">
                    <!-- Product details will be loaded here via AJAX -->
                </div>
            </div>
        </div>
    </main>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Elements
            const modal = document.getElementById('productModal');
            const viewModal = document.getElementById('viewModal');
            const modalTitle = document.getElementById('modalTitle');
            const form = document.getElementById('productForm');
            const formAction = document.getElementById('formAction');
            const productId = document.getElementById('productId');
            const nomInput = document.getElementById('nom');
            const descriptionInput = document.getElementById('description');
            const barcodeScanInput = document.getElementById('barcode_scan');
            const codeBarresInput = document.getElementById('code_barres');
            const prixAchatInput = document.getElementById('prix_achat');
            const prixVenteInput = document.getElementById('prix_vente');
            const quantiteInput = document.getElementById('quantite');
            const seuilAlerteInput = document.getElementById('seuil_alerte');
            const categorieIdInput = document.getElementById('categorie_id');
            const fournisseurInput = document.getElementById('fournisseur');
            const closeModalButtons = document.querySelectorAll('.close-modal');
            const addProductBtn = document.querySelector('.add-product-btn');
            const editButtons = document.querySelectorAll('.edit-btn');
            const viewButtons = document.querySelectorAll('.view-btn');
            const scanButton = document.getElementById('scanButton');
            const barcodeError = document.getElementById('barcodeError');
            const submitProduct = document.getElementById('submitProduct');
            const searchInput = document.getElementById('searchInput');
            const productsTable = document.getElementById('productsTable');

            // Toggle sidebar
            const toggleButton = document.getElementById('toggleSidebar');
            const sidebar = document.getElementById('sidebar');
            if (toggleButton && sidebar) {
                toggleButton.addEventListener('click', function() {
                    sidebar.classList.toggle('active');
                });
            }

            // Open modal for adding product
            addProductBtn.addEventListener('click', function() {
                modalTitle.textContent = 'Ajouter un Produit';
                formAction.value = 'add';
                productId.value = '';
                form.reset();
                seuilAlerteInput.value = '5';
                barcodeError.style.display = 'none';
                submitProduct.disabled = false;
                modal.style.display = 'flex';
                barcodeScanInput.focus();
            });

            // Open modal for editing product
            editButtons.forEach(button => {
                button.addEventListener('click', function() {
                    modalTitle.textContent = 'Modifier le Produit';
                    formAction.value = 'edit';
                    productId.value = this.dataset.id;
                    nomInput.value = this.dataset.nom;
                    descriptionInput.value = this.dataset.description || '';
                    codeBarresInput.value = this.dataset.code_barres;
                    prixAchatInput.value = this.dataset.prix_achat;
                    prixVenteInput.value = this.dataset.prix_vente;
                    quantiteInput.value = this.dataset.quantite;
                    seuilAlerteInput.value = this.dataset.seuil_alerte;
                    if (this.dataset.categorie_id) {
                        categorieIdInput.value = this.dataset.categorie_id;
                    } else {
                        categorieIdInput.value = '';
                    }
                    fournisseurInput.value = this.dataset.fournisseur || '';
                    barcodeError.style.display = 'none';
                    submitProduct.disabled = false;
                    modal.style.display = 'flex';
                    barcodeScanInput.focus();
                });
            });

            // Open modal for viewing product details
            viewButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const productId = this.dataset.id;
                    fetchProductDetails(productId);
                    viewModal.style.display = 'flex';
                });
            });

            // Fetch product details for view modal
            function fetchProductDetails(productId) {
                const xhr = new XMLHttpRequest();
                xhr.open('GET', `get_product_details.php?id=${productId}`, true);
                xhr.onload = function() {
                    if (this.status === 200) {
                        document.getElementById('productDetails').innerHTML = this.responseText;
                    } else {
                        document.getElementById('productDetails').innerHTML = '<p>Erreur lors du chargement des détails du produit.</p>';
                    }
                };
                xhr.send();
            }

            // Close modals
            closeModalButtons.forEach(button => {
                button.addEventListener('click', function() {
                    modal.style.display = 'none';
                    viewModal.style.display = 'none';
                    barcodeError.style.display = 'none';
                });
            });

            window.addEventListener('click', function(event) {
                if (event.target === modal || event.target === viewModal) {
                    modal.style.display = 'none';
                    viewModal.style.display = 'none';
                    barcodeError.style.display = 'none';
                }
            });

            // Barcode scanning
            let isScanning = false;
            scanButton.addEventListener('click', function() {
                isScanning = !isScanning;
                if (isScanning) {
                    scanButton.innerHTML = '<i class="fas fa-stop"></i> Stop';
                    barcodeScanInput.focus();
                } else {
                    scanButton.innerHTML = '<i class="fas fa-barcode"></i> Scan';
                    codeBarresInput.focus();
                }
            });

            barcodeScanInput.addEventListener('change', function() {
                if (isScanning && this.value.trim()) {
                    codeBarresInput.value = this.value.trim();
                    checkBarcodeUniqueness(this.value.trim());
                    this.value = '';
                }
            });

            barcodeScanInput.addEventListener('keypress', function(e) {
                if (e.key === 'Enter' && isScanning && this.value.trim()) {
                    e.preventDefault();
                    codeBarresInput.value = this.value.trim();
                    checkBarcodeUniqueness(this.value.trim());
                    this.value = '';
                }
            });

            codeBarresInput.addEventListener('input', function() {
                if (this.value.trim()) {
                    checkBarcodeUniqueness(this.value.trim());
                } else {
                    barcodeError.style.display = 'none';
                    submitProduct.disabled = false;
                }
            });

            // Check barcode uniqueness via AJAX
            function checkBarcodeUniqueness(code) {
                const xhr = new XMLHttpRequest();
                xhr.open('POST', '', true);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.onreadystatechange = function() {
                    if (xhr.readyState === 4 && xhr.status === 200) {
                        try {
                            const response = JSON.parse(xhr.responseText);
                            if (response.error) {
                                barcodeError.style.display = 'block';
                                barcodeError.textContent = response.error;
                                submitProduct.disabled = true;
                            } else if (response.exists) {
                                barcodeError.style.display = 'block';
                                barcodeError.textContent = 'Ce code-barres existe déjà.';
                                submitProduct.disabled = true;
                            } else {
                                barcodeError.style.display = 'none';
                                submitProduct.disabled = false;
                            }
                        } catch (e) {
                            console.error('Error parsing JSON response', e);
                        }
                    }
                };
                const data = `action=check_barcode&code_barres=${encodeURIComponent(code)}&product_id=${encodeURIComponent(productId.value)}`;
                xhr.send(data);
            }

            // Form validation
            form.addEventListener('submit', function(e) {
                if (!codeBarresInput.value.trim()) {
                    e.preventDefault();
                    alert('Veuillez scanner ou entrer un code-barres.');
                    barcodeScanInput.focus();
                }
            });

            // Table search functionality
            if (searchInput && productsTable) {
                searchInput.addEventListener('input', function() {
                    const searchText = this.value.toLowerCase();
                    const rows = productsTable.querySelectorAll('tbody tr');
                    
                    rows.forEach(row => {
                        const cells = row.querySelectorAll('td');
                        let found = false;
                        
                        cells.forEach(cell => {
                            if (cell.textContent.toLowerCase().includes(searchText)) {
                                found = true;
                            }
                        });
                        
                        if (found) {
                            row.style.display = '';
                        } else {
                            row.style.display = 'none';
                        }
                    });
                });
            }
        });
    </script>
</body>
</html>