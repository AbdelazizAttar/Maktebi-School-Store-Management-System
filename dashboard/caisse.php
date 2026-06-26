
<?php


// Start secure session
session_start([
    'cookie_lifetime' => 86400,
    'cookie_secure'   => true,
    'cookie_httponly' => true,  
    'cookie_samesite' => 'Strict',  
    'use_strict_mode' => true
]);

// Regenerate session ID to prevent fixation
if (!isset($_SESSION['initiated'])) {
    session_regenerate_id(true);
    $_SESSION['initiated'] = true;
}

// Check authentication and authorization
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}
$utilisateur_id = $_SESSION['user_id'];
// Include configuration
require_once '../config.php';

// Database connection with error handling
try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    error_log("Database connection failed: " . $e->getMessage());
    die("System error. Please try again later.");
}

// Generate and validate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function validateCsrfToken() {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        error_log("CSRF token validation failed");
        die("Security error. Please refresh the page and try again.");
    }
}

// Initialize cart
if (!isset($_SESSION['panier'])) {
    $_SESSION['panier'] = [];
}

// Calculate cart totals with validation
function calculateCartTotals($cart, $pdo) {
    $total_ht = 0;
    $valid_cart = [];
    
    foreach ($cart as $product_id => $item) {
        // Validate product ID
        if (!is_numeric($product_id)) {
            continue;
        }
        
        // Get product info from database
        $stmt = $pdo->prepare("SELECT prix_vente, quantite, image FROM produits WHERE id = ?");
        $stmt->execute([$product_id]);
        $product = $stmt->fetch();
        
        if (!$product) {
            continue;
        }
        
        // Validate quantity
        $quantity = max(1, min($item['quantite'], $product['quantite']));
        
        $valid_cart[$product_id] = [
            'nom' => $item['nom'],
            'prix' => (float)$product['prix_vente'],
            'quantite' => $quantity,
            'image' => $product['image']
        ];
        
        $total_ht += $product['prix_vente'] * $quantity;
    }
    
    $_SESSION['panier'] = $valid_cart;
    
    $taux_tva = 0.00;
    $montant_tva = $total_ht * $taux_tva;
    $total_ttc = $total_ht + $montant_tva;
    
    return [
        'total_ht' => $total_ht,
        'montant_tva' => $montant_tva,
        'total_ttc' => $total_ttc
    ];
}

// Process POST actions with CSRF protection
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrfToken();
    
    if (isset($_POST['action'])) {
        $action = $_POST['action'];
        
        try {
            $pdo->beginTransaction();
            
            // Add product by barcode
            if ($action === 'add_by_barcode') {
                $barcode = substr($_POST['barcode'], 0, 50);
                $quantite = max(1, min((int)$_POST['quantite'], 100));
                
                $stmt = $pdo->prepare("SELECT id, nom, prix_vente, quantite, image FROM produits WHERE code_barres = ?");
                $stmt->execute([$barcode]);
                $produit = $stmt->fetch();
                
                if ($produit && $produit['quantite'] >= $quantite) {
                    if (isset($_SESSION['panier'][$produit['id']])) {
                        $_SESSION['panier'][$produit['id']]['quantite'] += $quantite;
                    } else {
                        $_SESSION['panier'][$produit['id']] = [
                            'nom' => $produit['nom'],
                            'prix' => $produit['prix_vente'],
                            'quantite' => $quantite,
                            'image' => $produit['image']
                        ];
                    }
                    echo json_encode(['success' => true]);
                    exit;
                } else {
                    echo json_encode(['success' => false, 'message' => 'Produit non trouvé ou stock insuffisant']);
                    exit;
                }
            }
            
            // Add to cart
            if ($action === 'add_to_cart') {
                $produit_id = (int)$_POST['produit_id'];
                $quantite = max(1, min((int)$_POST['quantite'], 100));
                
                $stmt = $pdo->prepare("SELECT id, nom, prix_vente, quantite, image FROM produits WHERE id = ?");
                $stmt->execute([$produit_id]);
                $produit = $stmt->fetch();
                
                if ($produit && $produit['quantite'] >= $quantite) {
                    if (isset($_SESSION['panier'][$produit_id])) {
                        $_SESSION['panier'][$produit_id]['quantite'] += $quantite;
                    } else {
                        $_SESSION['panier'][$produit_id] = [
                            'nom' => $produit['nom'],
                            'prix' => $produit['prix_vente'],
                            'quantite' => $quantite,
                            'image' => $produit['image']
                        ];
                    }
                    $_SESSION['success_message'] = "";
                } else {
                    $_SESSION['error_message'] = "Stock insuffisant";
                }
            }
            
            // Update quantity
            if ($action === 'update_quantity') {
                $produit_id = (int)$_POST['produit_id'];
                $quantite = max(0, min((int)$_POST['quantite'], 100));
                
                if ($quantite > 0) {
                    $stmt = $pdo->prepare("SELECT quantite FROM produits WHERE id = ?");
                    $stmt->execute([$produit_id]);
                    $produit = $stmt->fetch();
                    
                    if ($produit && $produit['quantite'] >= $quantite) {
                        $_SESSION['panier'][$produit_id]['quantite'] = $quantite;
                    }
                } else {
                    unset($_SESSION['panier'][$produit_id]);
                }
            }
            
            // Remove from cart
            if ($action === 'remove_from_cart') {
                $produit_id = (int)$_POST['produit_id'];
                unset($_SESSION['panier'][$produit_id]);
            }
            
            // Validate sale
          // Validate sale
if ($action === 'validate_sale') {
    $client_id = 1; // Or $_POST['id'] if dynamic
    $discount = min(max((float)$_POST['discount'], 0), 100);
    $cash_received = max(0, (float)$_POST['cash_received']);
    $notes = substr(strip_tags($_POST['notes']), 0, 500);
    
    $totals = calculateCartTotals($_SESSION['panier'], $pdo);
    $total_ht = $totals['total_ht'];
    
    // Apply discount
    $discount_amount = $total_ht * ($discount / 100);
    $total_ht_after_discount = $total_ht - $discount_amount;
    
    $montant_tva = $total_ht_after_discount * 0.00;
    $total_ttc = $total_ht_after_discount + $montant_tva;
    
    // Calculate change
    $change_given = $cash_received >= $total_ttc ? $cash_received - $total_ttc : 0;
    $utilisateur_id = $_SESSION['user_id'];
    
    // Insert sale
    $stmt = $pdo->prepare("
        INSERT INTO ventes 
        (utilisateur_id, client_id, total_ht, taux_tva, montant_tva, total_ttc, mode_paiement, statut, date_creation, date_vente, discount, cash_received, change_given, notes)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), ?, ?, ?, ?)
    ");

    $stmt->execute([
        $utilisateur_id,           // utilisateur_id
        $client_id,                     // client_id
        $total_ht_after_discount,       // total_ht
        0.00,                           // taux_tva
        $montant_tva,                   // montant_tva
        $total_ttc,                     // total_ttc
        $payment_method === 'cash',                        // mode_paiement (default, since payment_method removed)
        $statut=='validée',                      // statut
        $discount,                      // discount
        $cash_received,                 // cash_received
        $change_given,                  // change_given
        $notes                          // notes
    ]);
             

                $vente_id = $pdo->lastInsertId();
                
                // Insert sale details and update stock
                foreach ($_SESSION['panier'] as $produit_id => $item) {
                    $stmt = $pdo->prepare("INSERT INTO ventes_details (vente_id, produit_id, quantite, prix_unitaire) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$vente_id, $produit_id, $item['quantite'], $item['prix']]);
                    
                    $stmt = $pdo->prepare("UPDATE produits SET quantite = quantite - ? WHERE id = ?");
                    $stmt->execute([$item['quantite'], $produit_id]);
                    
                    $stmt = $pdo->prepare("INSERT INTO stock_mouvements (produit_id, type_mouvement, quantite, raison, utilisateur_id, date_mouvement) VALUES (?, ?, ?, ?, ?, NOW())");
                    $stmt->execute([$produit_id, 'sortie', $item['quantite'], 'Vente #' . $vente_id, $_SESSION['user_id']]);
                }
                
                // Log the sale
                $stmt = $pdo->prepare("INSERT INTO logs (utilisateur_id, action, details, ip_address) VALUES (?, ?, ?, ?)");
                $stmt->execute([$_SESSION['user_id'], 'Vente', 'Vente #' . $vente_id . ' complétée', $_SERVER['REMOTE_ADDR']]);
                
                // Reset cart and store last sale ID
                $_SESSION['panier'] = [];
                $_SESSION['last_sale_id'] = $vente_id;
                $_SESSION['success_message'] = "Vente validée avec succès!";
            }
            
            // Cancel sale
            if ($action === 'cancel_sale') {
                $_SESSION['panier'] = [];
                $_SESSION['success_message'] = "";
            }
            
            $pdo->commit();
            
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log("Transaction failed: " . $e->getMessage());
            $_SESSION['error_message'] = "Une erreur s'est produite. Veuillez réessayer.";
        }
        
        header("Location: ".$_SERVER['PHP_SELF']);
        exit;
    }
}

// Handle barcode AJAX request
if (isset($_GET['barcode'])) {
    $barcode = substr($_GET['barcode'], 0, 50);
    $stmt = $pdo->prepare("SELECT * FROM produits WHERE code_barres = ?");
    $stmt->execute([$barcode]);
    $produit = $stmt->fetch();
    
    if ($produit) {
        echo json_encode(['success' => true, 'produit' => $produit]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Produit non trouvé']);
    }
    exit;
}

// Load categories with prepared statement
$stmt = $pdo->prepare("SELECT * FROM categories");
$stmt->execute();
$categories = $stmt->fetchAll();

// Load clients with prepared statement
$stmt = $pdo->prepare("SELECT id, CONCAT(nom, ' ', prenom) AS nom_complet FROM clients ORDER BY nom, prenom");
$stmt->execute();
$clients = $stmt->fetchAll();

// Calculate totals
$totals = calculateCartTotals($_SESSION['panier'], $pdo);
$total_ht = $totals['total_ht'];
$montant_tva = $totals['montant_tva'];
$total_ttc = $totals['total_ttc'];

// Get last sale for receipt
$last_sale = null;
if (isset($_SESSION['last_sale_id'])) {
    $sale_id = (int)$_SESSION['last_sale_id'];
    unset($_SESSION['last_sale_id']);
    
    $stmt = $pdo->prepare("SELECT v.*, CONCAT(c.nom, ' ', c.prenom) AS client_name 
                          FROM ventes v 
                          LEFT JOIN clients c ON v.client_id = c.id 
                          WHERE v.id = ?");
    $stmt->execute([$sale_id]);
    $last_sale = $stmt->fetch();
    
    if ($last_sale) {
        $stmt = $pdo->prepare("SELECT vd.*, p.nom AS produit_nom 
                              FROM ventes_details vd 
                              JOIN produits p ON vd.produit_id = p.id 
                              WHERE vd.vente_id = ?");
        $stmt->execute([$sale_id]);
        $last_sale_details = $stmt->fetchAll();
    }
}

// Get new client ID if added
$new_client_id = isset($_SESSION['new_client_id']) ? (int)$_SESSION['new_client_id'] : null;
unset($_SESSION['new_client_id']);

// Get quick stats
$stats = [
    'produits' => $pdo->query("SELECT COUNT(*) FROM produits")->fetchColumn(),
    'clients' => $pdo->query("SELECT COUNT(*) FROM clients")->fetchColumn(),
    'ventes_jour' => $pdo->query("SELECT COUNT(*) FROM ventes WHERE DATE(date_vente) = CURDATE()")->fetchColumn(),
    'ca_jour' => $pdo->query("SELECT COALESCE(SUM(total_ttc), 0) FROM ventes WHERE DATE(date_vente) = CURDATE()")->fetchColumn()
];

// Display messages
$success_message = $_SESSION['success_message'] ?? null;
$error_message = $_SESSION['error_message'] ?? null;
unset($_SESSION['success_message'], $_SESSION['error_message']);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <link rel="icon" href="favicon-32x32.png" sizes="32x32" type="images/logo.png">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Point de Vente - Magasin Scolaire</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Libre+Barcode+39&family=Poppins:wght@300;400;500&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #1e3a8a;
            --secondary: #3b82f6;
            --accent: #ef4444;
            --light: #f1f5f9;
            --dark: #1e293b;
            --success: #22c55e;
            --warning: #f59e0b;
            --info: #3b82f6;
            --gradient: linear-gradient(135deg, var(--primary), var(--secondary));
        }

        body {
            background: linear-gradient(to bottom, #e2e8f0, #f8fafc);
            font-family: 'Poppins', sans-serif;
            font-size: 0.85rem;
            animation: fadeIn 0.5s ease-in;
            overflow-x: hidden;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .header {
            background: var(--gradient);
            color: white;
            padding: 15px 0;
            box-shadow: 0 3px 15px rgba(0,0,0,0.15);
            animation: slideDown 0.5s ease-out;
            position: relative;
            z-index: 1000;
        }

        @keyframes slideDown {
            from { transform: translateY(-20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        .card {
    border-radius: 10px;
    box-shadow: 0 5px 15px rgba(0,0,0,0.1);
    border: none;
    transition: all 0.3s ease;
    background: white;
    overflow: hidden;
    position: relative;
}

.card:hover {
    transform: translateY(-5px);
    box-shadow: 0 8px 20px rgba(0,0,0,0.15);
}

.cart-container {
    background: linear-gradient(to bottom, #ffffff, #f8fafc);
    border-radius: 10px;
    box-shadow: 0 5px 15px rgba(0,0,0,0.1);
    display: flex;
    flex-direction: column;
    max-height: 85vh;
    animation: slideUp 0.5s ease-out;
}

@keyframes slideUp {
    from { transform: translateY(20px); opacity: 0; }
    to { transform: translateY(0); opacity: 1; }
}

.cart-header {
    background: var(--gradient);
    color: white;
    border-top-left-radius: 10px;
    border-top-right-radius: 10px;
    padding: 12px 15px;
    font-weight: 600;
    font-size: 0.95rem;
    position: relative;
    overflow: hidden;
}

.cart-header::before {
    content: '';
    position: absolute;
    top: -50%;
    left: -50%;
    width: 200%;
    height: 200%;
    background: radial-gradient(circle, rgba(255,255,255,0.2) 0%, transparent 70%);
    animation: pulse 3s infinite;
}

@keyframes pulse {
    0% { transform: scale(0.8); opacity: 0.2; }
    50% { transform: scale(1); opacity: 0.4; }
    100% { transform: scale(0.8); opacity: 0.2; }
}

.cart-body {
    max-height: 400px;
    overflow-y: auto;
    flex-grow: 1;
    padding: 10px 15px;
}

.cart-footer {
    background: var(--light);
    border-bottom-left-radius: 10px;
    border-bottom-right-radius: 10px;
    padding: 12px 15px;
    height: auto;
}

.barcode-input-container {
    position: relative;
    margin-bottom: 12px;
}

.barcode-input {
    width: 100%;
    padding: 6px 35px 6px 10px;
    border-radius: 6px;
    transition: all 0.3s ease;
    border: 1px solid #e5e7eb;
    font-size: 0.8rem;
}

.barcode-input:focus {
    border-color: var(--secondary);
    box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.15);
}

.barcode-icon {
    position: absolute;
    right: 10px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--secondary);
    font-size: 0.85rem;
    transition: transform 0.3s ease;
}

.barcode-input:focus + .barcode-icon {
    transform: translateY(-50%) rotate(360deg);
}

.cart-item {
    border-bottom: 1px solid #e5e7eb;
    padding: 5px 0;
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.85rem;
    transition: all 0.2s;
}

.cart-item:hover {
    background-color: rgba(59, 130, 246, 0.05);
    border-radius: 6px;
}

.cart-item-img {
    width: 20px;
    height: 20px;
    object-fit: cover;
    border-radius: 4px;
    border: 1px solid #e5e7eb;
}


        .badge-custom {
            background: var(--gradient);
            font-weight: 500;
            color: white;
            padding: 5px 10px;
            border-radius: 10px;
            font-size: 0.75rem;
        }

        .btn-primary {
            background: var(--gradient);
            border: none;
            transition: all 0.3s ease;
            font-size: 0.85rem;
            padding: 8px 12px;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
        }

        .btn-success {
            background: linear-gradient(135deg, var(--success), #16a34a);
            border: none;
            transition: all 0.3s ease;
            font-size: 0.85rem;
            padding: 8px 12px;
        }

        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(34, 197, 94, 0.3);
        }

        .btn-danger {
            background: linear-gradient(135deg, var(--accent), #dc2626);
            border: none;
            transition: all 0.3s ease;
            font-size: 0.85rem;
            padding: 8px 12px;
        }

        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3);
        }

        .btn-outline-primary {
            color: var(--secondary);
            border-color: var(--secondary);
            transition: all 0.3s;
            font-size: 0.85rem;
            padding: 8px 12px;
        }

        .btn-outline-primary:hover {
            background: var(--secondary);
            color: white;
            transform: translateY(-2px);
        }

        .search-container input {
            font-size: 0.85rem;
            padding: 8px 12px;
        }

        .search-container input:focus {
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
            border-color: var(--secondary);
        }

        .tabs {
            display: flex;
            border-bottom: 2px solid #e5e7eb;
            margin-bottom: 20px;
            background: #ffffff;
            border-radius: 8px;
            padding: 4px;
        }

        .tab {
            padding: 10px 20px;
            cursor: pointer;
            border-radius: 6px;
            transition: all 0.3s;
            font-weight: 400;
            font-size: 0.85rem;
        }

        .tab:hover {
            background: rgba(59, 130, 246, 0.05);
        }

        .tab.active {
            background: var(--secondary);
            color: white;
            font-weight: 500;
        }

        .stats-box {
            background: white;
            border-radius: 10px;
            padding: 15px;
            text-align: center;
            margin-bottom: 15px;
            transition: all 0.3s;
            position: relative;
            overflow: hidden;
        }

        .stats-box::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(59, 130, 246, 0.1) 0%, transparent 70%);
            animation: pulse 3s infinite;
        }

        .stats-box:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.15);
        }

        .stats-value {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--secondary);
            text-shadow: 0 1px 2px rgba(0,0,0,0.1);
        }

        .stats-label {
            color: #6b7280;
            font-size: 0.8rem;
            text-transform: uppercase;
        }

        .total-box {
            background: #f8fafc;
            border-radius: 8px;
            padding: 15px;
            border-left: 4px solid var(--secondary);
            animation: fadeIn 0.5s ease-in;
        }

        .discount-input {
            max-width: 100px;
            border-radius: 6px;
            border: 1px solid #e5e7eb;
            transition: all 0.3s ease;
            font-size: 0.85rem;
        }

        .discount-input:focus {
            border-color: var(--secondary);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .payment-methods {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(100px, 1fr));
            gap: 10px;
            margin-bottom: 15px;
        }

        .payment-method {
            text-align: center;
            padding: 12px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s;
            background: white;
            font-size: 0.8rem;
        }

        .payment-method:hover {
            transform: translateY(-3px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }

        .payment-method.active {
            border-color: var(--success);
            background: linear-gradient(to bottom, rgba(34, 197, 94, 0.1), rgba(34, 197, 94, 0.05));
        }

        .payment-method i {
            font-size: 1.2rem;
            margin-bottom: 6px;
            display: block;
            transition: transform 0.3s ease;
            color: var(--secondary);
        }

        .payment-method:hover i {
            transform: scale(1.2);
        }

        .receipt {
            font-family: 'Courier New', monospace;
            background: white;
            padding: 20px;
            max-width: 350px;
            margin: 0 auto;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            border: 1px solid #e5e7eb;
            font-size: 0.8rem;
        }

        .receipt-header {
            text-align: center;
            margin-bottom: 15px;
            border-bottom: 2px dashed #1e293b;
            padding-bottom: 10px;
        }

        .receipt-title {
            font-weight: bold;
            font-size: 1rem;
            color: var(--primary);
            text-transform: uppercase;
        }

        .receipt-item {
            display: flex;
            justify-content: space-between;
            padding: 5px 0;
            transition: background-color 0.2s;
        }

        .receipt-item:hover {
            background-color: rgba(59, 130, 246, 0.05);
        }

        .receipt-divider {
            border-top: 2px dashed #1e293b;
            margin: 10px 0;
        }

        .receipt-total {
            font-weight: bold;
            font-size: 0.9rem;
            color: var(--secondary);
        }

        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(100px, 1fr));
            gap: 10px;
            margin-bottom: 15px;
        }

        .quick-action-btn {
            text-align: center;
            padding: 10px;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s;
            background: var(--gradient);
            color: white;
            font-weight: 400;
            font-size: 0.8rem;
        }

        .quick-action-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }

        .product-barcode {
            font-family: 'Libre Barcode 39', cursive;
            font-size: 1.2rem;
            text-align: center;
            margin-top: 5px;
            color: var(--primary);
        }

        .client-info-card {
            background: #f8fafc;
            border-radius: 8px;
            padding: 12px;
            margin-bottom: 15px;
            border-left: 3px solid var(--info);
            animation: fadeIn 0.5s ease-in;
        }

        .client-info-label {
            font-size: 0.8rem;
            color: #6b7280;
            font-weight: 400;
        }

        .client-info-value {
            font-weight: 500;
            color: var(--dark);
            font-size: 0.85rem;
        }

        #product-list {
            max-height: 350px;
            overflow-y: auto;
            padding-right: 10px;
        }

        #product-list::-webkit-scrollbar {
            width: 8px;
        }

        #product-list::-webkit-scrollbar-track {
            background: #f1f5f9;
            border-radius: 8px;
        }

        #product-list::-webkit-scrollbar-thumb {
            background: var(--secondary);
            border-radius: 8px;
        }

        #product-list::-webkit-scrollbar-thumb:hover {
            background: #2563eb;
        }

        .modal-content {
            border-radius: 10px;
            animation: zoomIn 0.3s ease-out;
            overflow: hidden;
        }

        @keyframes zoomIn {
            from { transform: scale(0.8); opacity: 0; }
            to { transform: scale(1); opacity: 1; }
        }

        .modal-header {
            background: var(--gradient);
            color: white;
            border-top-left-radius: 10px;
            border-top-right-radius: 10px;
            padding: 15px;
        }

        .modal-footer {
            border-bottom-left-radius: 10px;
            border-bottom-right-radius: 10px;
            padding: 15px;
        }

        .image-upload-container {
            border: 1px dashed #e5e7eb;
            border-radius: 8px;
            padding: 10px;
            text-align: center;
            margin-top: 10px;
            transition: all 0.3s ease;
            font-size: 0.75rem;
        }

        .image-upload-container:hover {
            border-color: var(--secondary);
            background: rgba(59, 130, 246, 0.05);
        }

        .image-upload-container.dragover {
            border-color: var(--success);
            background: rgba(34, 197, 94, 0.1);
        }

        .preview-img {
            max-width: 100%;
            max-height: 80px;
            border-radius: 6px;
            margin-top: 10px;
            border: 1px solid #e5e7eb;
        }

        .product-img {
            height: 80px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        @media (max-width: 900px) {
            .product-card .btn {
                width: 100%;
                margin-top: 10px;
            }

            .payment-method {
                min-width: calc(50% - 10px);
            }

            .quick-action-btn {
                min-width: calc(50% - 10px);
            }

            .cart-body {
                max-height: 250px;
            }
        }

        @media print {
            body * {
                visibility: hidden;
            }
            .receipt, .receipt * {
                visibility: visible;
            }
            .receipt {
                position: absolute;
                left: 0;
                top: 0;
                width: 100%;
                max-width: 100%;
                box-shadow: none;
                border: none;
            }
        }
  
.top-bar {
      background: linear-gradient(135deg,rgb(37, 82, 126), #3498db); /* Gradient background for depth */
      padding: 10px 16px;
      box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1); /* Subtle shadow for elevation */
      display: flex;
      align-items: center;
      justify-content: flex-start;
      width: 100%;
      position: fixed; /* Fixed positioning for a sticky top bar */
      top: 0;
      z-index: 1000;
    }

    /* Dashboard link styling */
    .dashboard-link {
      display: flex;
      align-items: center;
      color: #ffffff; /* White text for contrast */
      text-decoration: none;
      font-family: 'Segoe UI', Arial, sans-serif; /* Clean, modern font */
      font-size: 16px;
      font-weight: 500;
      padding: 10px 20px;
      border-radius: 8px; /* Rounded corners */
      transition: background-color 0.3s ease, transform 0.2s ease; /* Smooth transitions */
      background-color: rgba(255, 255, 255, 0.1); /* Slight background for link */
    }

    /* Icon styling */
    .dashboard-link i {
      margin-right: 8px; /* Space between icon and text */
      font-size: 18px;
    }

    /* Hover effect */
    .dashboard-link:hover {
      background-color: rgba(255, 255, 255, 0.2); /* Lighter background on hover */
      transform: translateY(-2px); /* Subtle lift effect */
    }

    /* Responsive design */
    @media (max-width: 600px) {
      .top-bar {
        padding: 10px 15px;
      }

      .dashboard-link {
        font-size: 14px;
        padding: 8px 15px;
      }

      .dashboard-link i {
        font-size: 16px;
      }
    }
  


    </style>
</head>
<body>

<div class="top-bar">
  <a href="admin.php" class="dashboard-link">
    <i class="fas fa-home"></i> Retour au Tableau de Bord
  </a>
</div>
<br><br><br><br>

        
        
    <div class="container-fluid py-3">
        <?php if ($success_message): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($success_message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if ($error_message): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($error_message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <div class="row">
            <!-- Products Column -->
            <div class="col-lg-8">
                <div class="card mb-3">
                    <div class="card-body p-3">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h2 class="h5 mb-0 text-primary">Produits</h2>
                            <div class="d-flex">
                                <div class="search-container me-2">
                                    <input type="text" id="search" class="form-control" placeholder="Rechercher un produit...">
                                </div>
                            </div>
                        </div>
                        
                        <!-- Barcode Scanner Input -->
                        <div class="barcode-input-container">
                            <input type="text" id="barcode-input" class="form-control barcode-input" placeholder="Scanner un code-barres..." autofocus>
                            <i class="fas fa-barcode barcode-icon"></i>
                        </div>
                        <?php
$categorie_id = isset($_GET['categorie']) ? (int)$_GET['categorie'] : 0;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// Count total products
if ($categorie_id) {
    $countQuery = "SELECT COUNT(*) FROM produits WHERE categorie_id = ?";
    $countStmt = $pdo->prepare($countQuery);
    $countStmt->execute([$categorie_id]);
} else {
    $countQuery = "SELECT COUNT(*) FROM produits";
    $countStmt = $pdo->query($countQuery);
}
$total_products = $countStmt->fetchColumn();
$total_pages = ceil($total_products / $limit);

// Fetch products for current page
if ($categorie_id) {
    $query = "SELECT * FROM produits WHERE categorie_id = ? ORDER BY nom LIMIT $limit OFFSET $offset";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$categorie_id]);
} else {
    $query = "SELECT * FROM produits ORDER BY nom LIMIT $limit OFFSET $offset";
    $stmt = $pdo->query($query);
}
$produits = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>


<!-- Products Grid -->
<div class="row" id="product-list">
    <?php
    // 1. Get current page
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $limit = 8;
    $offset = ($page - 1) * $limit;

    // 2. Get category filter
    $categorie_id = isset($_GET['categorie']) ? (int)$_GET['categorie'] : 0;

    // 3. Count total produits
    $countQuery = "SELECT COUNT(*) FROM produits" . ($categorie_id ? " WHERE categorie_id = ?" : "");
    $stmtCount = $pdo->prepare($countQuery);
    if ($categorie_id) $stmtCount->execute([$categorie_id]);
    else $stmtCount->execute();
    $totalProduits = $stmtCount->fetchColumn();
    $totalPages = ceil($totalProduits / $limit);

    // 4. Fetch produits with LIMIT + OFFSET
    $query = "SELECT * FROM produits" . ($categorie_id ? " WHERE categorie_id = ?" : "") . " ORDER BY nom LIMIT ? OFFSET ?";
    $stmt = $pdo->prepare($query);
    if ($categorie_id) {
        $stmt->bindValue(1, $categorie_id, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->bindValue(3, $offset, PDO::PARAM_INT);
    } else {
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->bindValue(2, $offset, PDO::PARAM_INT);
    }
    $stmt->execute();
    $produits = $stmt->fetchAll();

    foreach ($produits as $produit):
    ?>
        <div class="col-md-3 mb-3">
            <div class="card product-card h-100">
                <div class="product-img p-2 text-center">
                    <?php if (!empty($produit['image'])): ?>
                        <img src="../Uploads/<?= htmlspecialchars($produit['image']) ?>" 
                             alt="<?= htmlspecialchars($produit['nom']) ?>" 
                             style="max-height: 80px; max-width: 100%;">
                    <?php else: ?>
                        <i class="fas fa-<?= $produit['categorie_id'] == 1 ? 'book' : ($produit['categorie_id'] == 2 ? 'pencil-alt' : 'box') ?> fa-2x text-secondary"></i>
                    <?php endif; ?>
                </div>
                <div class="card-body d-flex flex-column p-3">
                    <h5 class="card-title mb-2 fs-6"><?= htmlspecialchars($produit['nom']) ?></h5>
                    <?php if (!empty($produit['code_barres'])): ?>
                        <div class="product-barcode">*<?= htmlspecialchars($produit['code_barres']) ?>*</div>
                    <?php endif; ?>

                    <div class="mt-auto">
                        <p class="card-text mb-1">
                            <span class="fw-bold text-primary"><?= number_format($produit['prix_vente'], 3) ?> TND</span>
                        </p>
                        <p class="card-text mb-2">
                            <span class="badge bg-<?= $produit['quantite'] > 10 ? 'success' : ($produit['quantite'] > 0 ? 'warning' : 'danger') ?> badge-custom">
                                Stock: <?= $produit['quantite'] ?>
                            </span>
                        </p>
                        <form method="POST" class="d-flex align-items-center gap-1">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                            <input type="hidden" name="action" value="add_to_cart">
                            <input type="hidden" name="produit_id" value="<?= $produit['id'] ?>">
                            <input type="number" name="quantite" value="1" min="1" max="<?= $produit['quantite'] ?>" class="form-control" style="width: 60px;">
                            <button type="submit" class="btn btn-primary btn-sm">
                                <i class="fas fa-cart-plus"></i>
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<!-- Scrollable Pagination -->
<div style="overflow-x:auto; margin-top: 15px;">
    <ul class="pagination flex-nowrap" style="white-space: nowrap;">
        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
            <li class="page-item <?= $i == $page ? 'active' : '' ?>" style="display: inline-block;">
                <a class="page-link" href="?categorie=<?= $categorie_id ?>&page=<?= $i ?>"><?= $i ?></a>
            </li>
        <?php endfor; ?>
    </ul>
</div>
</div>
</div>
</div>


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
</style>


            
            <!-- Cart Column -->
            <div class="col-lg-4">
                <div class="cart-container">
                    <div class="cart-header">
                        <h3 class="h6 mb-0"><i class="fas fa-shopping-cart me-2"></i>Panier d'achat</h3>
                    </div>
                    
                    <div class="cart-body p-3">
                        <?php if (empty($_SESSION['panier'])): ?>
                            <div class="text-center py-4">
                                <i class="fas fa-shopping-cart fa-2x text-muted mb-2"></i>
                                <p class="text-muted">Votre panier est vide</p>
                                <p class="text-secondary">Ajoutez des produits pour commencer une vente</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover table-sm">
                              
                                    <tbody>
                                        <?php foreach ($_SESSION['panier'] as $produit_id => $item): ?>
                                            <tr class="cart-item">
                                                <td>
                                                    <?php if (!empty($item['image'])): ?>
                                                        <img src="../Uploads/<?= htmlspecialchars($item['image']) ?>" alt="<?= htmlspecialchars($item['nom']) ?>" class="cart-item-img">
                                                    <?php else: ?>
                                                        <i class="fas fa-box cart-item-img p-1"></i>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="align-middle"><?= htmlspecialchars($item['nom']) ?></td>
                                                <td class="align-middle">
                                                    <form method="POST">
                                                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                                        <input type="hidden" name="action" value="update_quantity">
                                                        <input type="hidden" name="produit_id" value="<?= $produit_id ?>">
                                                        <input type="number" name="quantite" value="<?= $item['quantite'] ?>" min="1" class="form-control form-control-sm" style="width: 60px;" onchange="this.form.submit()">
                                                    </form>
                                                </td>
                                                <td class="align-middle"><?= number_format($item['prix'], 3) ?> TND</td>
                                                <td class="align-middle"><?= number_format($item['prix'] * $item['quantite'], 3) ?> TND</td>
                                                <td class="align-middle text-end">
                                                    <form method="POST">
                                                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                                        <input type="hidden" name="action" value="remove_from_cart">
                                                        <input type="hidden" name="produit_id" value="<?= $produit_id ?>">
                                                        <button type="submit" class="btn btn-danger btn-sm">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="cart-footer">
                        <form id="cartForm">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                            
                            <div class="total-box mb-3">
                                <div class="d-flex justify-content-between mb-1">
                                    <span>Sous-total:</span>
                                    <span class="fw-bold"><?= number_format($total_ht, 3) ?> TND</span>
                                </div>
                                <div class="d-flex justify-content-between mb-1">
                                    <span>Réduction:</span>
                                    <span>
                                        <input type="number" name="discount" id="discount" class="form-control form-control-sm discount-input" min="0" max="100" value="0" step="0.5"> %
                                    </span>
                                </div>
                                <div class="d-flex justify-content-between mb-1">
                                    <span>TVA </span>
                                    <span class="fw-bold"><?= number_format($montant_tva, 3) ?> TND</span>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <span class="fw-bold">Total:</span>
                                    <span class="fw-bold text-primary"><?= number_format($total_ttc, 3) ?> TND</span>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label fw-bold">Méthode de paiement</label>
                                <div class="payment-methods">
                                    <div class="payment-method active" data-method="cash" onclick="selectPaymentMethod('cash')">
                                        <i class="fas fa-money-bill-wave"></i>
                                        <div>Espèces</div>
                                    </div>
                                    <div class="payment-method" data-method="card" onclick="selectPaymentMethod('card')">
                                        <i class="fas fa-credit-card"></i>
                                        <div>Carte</div>
                                    </div>
                                    <div class="payment-method" data-method="check" onclick="selectPaymentMethod('check')">
                                        <i class="fas fa-file-invoice-dollar"></i>
                                        <div>Chèque</div>
                                    </div>
                                    <div class="payment-method" data-method="transfer" onclick="selectPaymentMethod('transfer')">
                                        <i class="fas fa-exchange-alt"></i>
                                        <div>Virement</div>
                                    </div>
                                </div>
                                <input type="hidden" name="payment_method" id="payment_method" value="cash">
                            </div>
                            
                            <div class="d-flex gap-2">
                                <button type="button" class="btn btn-danger flex-grow-1 btn-sm" onclick="cancelSale()" <?= empty($_SESSION['panier']) ? 'disabled' : '' ?>>
                                    <i class="fas fa-times-circle me-1"></i>Annuler
                                </button>
                                <button type="button" class="btn btn-success flex-grow-1 btn-sm" onclick="openPaymentModal()" <?= empty($_SESSION['panier']) ? 'disabled' : '' ?>>
                                    <i class="fas fa-check-circle me-1"></i>Finaliser
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Payment Modal -->
    <div class="modal fade" id="paymentModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fs-6">Finaliser le paiement</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="paymentForm">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <div class="modal-body p-3">
                        <input type="hidden" name="action" value="validate_sale">
                        <input type="hidden" name="client_id" id="payment_client_id">
                        <input type="hidden" name="payment_method" id="payment_payment_method">
                        <input type="hidden" name="discount" id="payment_discount">
                        <input type="hidden" name="notes" id="payment_notes">
                        <div class="mb-3">
                            <label class="form-label fw-bold">Montant total</label>
                            <input type="text" class="form-control" id="total_amount" value="<?= number_format($total_ttc, 3) ?> TND" readonly>
                        </div>
                        <div class="mb-3" id="cashInputSection">
                            <label class="form-label fw-bold">Montant reçu</label>
                            <input type="number" name="cash_received" id="cash_received" class="form-control" step="0.001" min="0" placeholder="Entrez le montant reçu">
                        </div>
                        <div class="mb-3" id="changeSection" style="display: none;">
                            <label class="form-label fw-bold">Monnaie à rendre</label>
                            <input type="text" id="change_given" class="form-control" readonly>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" class="btn btn-success btn-sm" id="validatePayment">Valider le paiement</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Receipt Modal -->
    <?php if ($last_sale): ?>
    <div class="modal fade" id="receiptModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fs-6">Reçu de vente</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-3">
                    <div class="receipt" id="receiptContent">
                        <div class="receipt-header">
                            <div class="receipt-title">MAGASIN SCOLAIRE</div>
                            <div>123 Rue de l'Éducation, Tunis</div>
                            <div>Tél: (216) 12 345 678</div>
                        </div>
                        
                        <div class="receipt-item">
                            <span>Reçu #:</span>
                            <span><?php echo $last_sale['id']; ?></span>
                        </div>
                        <div class="receipt-item">
                            <span>Date:</span>
                            <span><?php echo date('d/m/Y H:i', strtotime($last_sale['date_vente'])); ?></span>
                        </div>
                
                        <div class="receipt-item">
                            <span>Client:</span>
                            <span><?php echo $last_sale['client_name'] ?: 'Anonyme'; ?></span>
                        </div>
                        
                        <div class="receipt-divider"></div>
                        
                        <?php foreach ($last_sale_details as $item): ?>
                            <div class="receipt-item">
                                <div>
                                    <div><?php echo htmlspecialchars($item['produit_nom']); ?></div>
                                    <div style="font-size: 0.75em;">
                                        <?php echo $item['quantite']; ?> x <?php echo number_format($item['prix_unitaire'], 3); ?> TND
                                    </div>
                                </div>
                                <div><?php echo number_format($item['quantite'] * $item['prix_unitaire'], 3); ?> TND</div>
                            </div>
                        <?php endforeach; ?>
                        
                        <div class="receipt-divider"></div>
                        
                        <div class="receipt-item">
                            <span>Sous-total:</span>
                            <span><?php echo number_format($last_sale['total_ht'], 3); ?> TND</span>
                        </div>
                        <?php if ($last_sale['discount'] > 0): ?>
                        <div class="receipt-item">
                            <span>Réduction (<?php echo $last_sale['discount']; ?>%):</span>
                            <span>-<?php echo number_format($last_sale['total_ht'] * ($last_sale['discount']/100), 3); ?> TND</span>
                        </div>
                        <?php endif; ?>
                        <div class="receipt-item">
                            <span>TVA:</span>
                            <span><?php echo number_format($last_sale['montant_tva'], 3); ?> TND</span>
                        </div>
                        <div class="receipt-item receipt-total">
                            <span>Total:</span>
                            <span><?php echo number_format($last_sale['total_ttc'], 3); ?> TND</span>
                        </div>
                        
                        <div class="receipt-divider"></div>
                        
                        <?php if ($last_sale['cash_received'] !== null): ?>
<div class="receipt-item">
    <span>Montant reçu:</span>
    <span><?php echo number_format($last_sale['cash_received'], 3); ?> TND</span>
</div>
<div class="receipt-item">
    <span>Monnaie rendue:</span>
    <span><?php echo number_format($last_sale['change_given'], 3); ?> TND</span>
</div>
<?php endif; ?>

                        
                        
                        <?php if (!empty($last_sale['notes'])): ?>
                        <div class="receipt-divider"></div>
                        <div class="receipt-item">
                            <div><strong>Notes:</strong> <?php echo htmlspecialchars($last_sale['notes']); ?></div>
                        </div>
                        <?php endif; ?>
                        
                        <div class="receipt-divider"></div>
                        
                        <div class="text-center mt-3">
                            <p class="fw-bold">Merci pour votre achat !</p>
                            <p>Fournitures scolaires & matériel éducatif</p>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Fermer</button>
                    <button type="button" class="btn btn-primary btn-sm" onclick="printReceipt()">
                        <i class="fas fa-print me-1"></i>Imprimer
                    </button>
                    <button type="button" class="btn btn-success btn-sm" onclick="downloadReceipt()">
                        <i class="fas fa-download me-1"></i>Télécharger PDF
                    </button>
                    <button type="button" class="btn btn-info btn-sm" onclick="sendReceiptByEmail()">
                        <i class="fas fa-envelope me-1"></i>Envoyer par email
                    </button>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

 

    

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Focus on barcode input when page loads
            const barcodeInput = document.getElementById('barcode-input');
            if (barcodeInput) {
                barcodeInput.focus();
                
                // Handle barcode scanning
                barcodeInput.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') {
                        const barcode = this.value.trim();
                        if (barcode) {
                            fetch(`?barcode=${encodeURIComponent(barcode)}`)
                                .then(response => response.json())
                                .then(data => {
                                    if (data.success) {
                                        const formData = new FormData();
                                        formData.append('csrf_token', '<?= $_SESSION['csrf_token'] ?>');
                                        formData.append('action', 'add_by_barcode');
                                        formData.append('barcode', barcode);
                                        formData.append('quantite', 1);
                                        
                                        fetch('', {
                                            method: 'POST',
                                            body: formData
                                        })
                                        .then(response => response.json())
                                        .then(result => {
                                            if (result.success) {
                                                window.location.reload();
                                            } else {
                                                alert('Erreur: ' + result.message);
                                                this.value = '';
                                                this.focus();
                                            }
                                        });
                                    } else {
                                        alert('Produit non trouvé avec ce code-barres');
                                        this.value = '';
                                        this.focus();
                                    }
                                });
                        }
                    }
                });
            }

            // Show receipt modal if available
            <?php if ($last_sale): ?>
                const receiptModal = new bootstrap.Modal(document.getElementById('receiptModal'));
                receiptModal.show();
            <?php endif; ?>
        });

        // Payment method selection
        function selectPaymentMethod(method) {
            document.querySelectorAll('.payment-method').forEach(el => {
                el.classList.remove('active');
            });
            document.querySelector(`.payment-method[data-method="${method}"]`).classList.add('active');
            document.getElementById('payment_method').value = method;
            
            // Show/hide cash input based on method
            const cashInput = document.getElementById('cashInputSection');
            const changeSection = document.getElementById('changeSection');
            if (method === 'cash') {
                cashInput.style.display = 'block';
                changeSection.style.display = 'block';
            } else {
                cashInput.style.display = 'none';
                changeSection.style.display = 'none';
            }
        }

        // Open payment modal
        function openPaymentModal() {
            const clientId = 33;
            const paymentMethod = document.getElementById('payment_method').value;
            const discount = document.getElementById('discount').value;
            const notes = "  ";

            document.getElementById('payment_client_id').value = clientId;
            document.getElementById('payment_payment_method').value = paymentMethod;
            document.getElementById('payment_discount').value = discount;
            document.getElementById('payment_notes').value = notes;

            const paymentModal = new bootstrap.Modal(document.getElementById('paymentModal'));
            paymentModal.show();
            
            // Focus on cash input if payment is cash
            if (paymentMethod === 'cash') {
                document.getElementById('cash_received').focus();
            }
        }

        // Calculate change dynamically
        document.getElementById('cash_received')?.addEventListener('input', function() {
            const totalAmount = <?= $total_ttc ?>;
            const cashReceived = parseFloat(this.value) || 0;
            const change = cashReceived >= totalAmount ? cashReceived - totalAmount : 0;
            document.getElementById('change_given').value = change.toFixed(3) + ' TND';
        });

        // Print receipt
        function printReceipt() {
            window.print();
        }

        // Cancel sale
        function cancelSale() {
            
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = '';
                
                const csrfInput = document.createElement('input');
                csrfInput.type = 'hidden';
                csrfInput.name = 'csrf_token';
                csrfInput.value = '<?= $_SESSION['csrf_token'] ?>';
                form.appendChild(csrfInput);
                
                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = 'cancel_sale';
                form.appendChild(actionInput);
                
                document.body.appendChild(form);
                form.submit();
            }
        
        document.querySelectorAll('[id^="productImageInput_"]').forEach(input => {
        input.addEventListener('change', async (e) => {
            const file = e.target.files[0];
            const productId = e.target.dataset.productId;
            if (file) {
                const reader = new FileReader();
                reader.onload = (e) => {
                    const preview = document.getElementById(`previewImage_${productId}`);
                    preview.src = e.target.result;
                    preview.classList.remove('d-none');
                };
                reader.readAsDataURL(file);

                const formData = new FormData();
                formData.append('image', file);
                formData.append('product_id', productId);
                formData.append('csrf_token', '<?= $_SESSION['csrf_token'] ?>');

                try {
                    const response = await fetch('upload_product_image.php', {
                        method: 'POST',
                        body: formData
                    });

                    const contentType = response.headers.get('content-type');
                    if (!contentType || !contentType.includes('application/json')) {
                        throw new Error('Non-JSON response');
                    }

                    const data = await response.json();
                    if (data.success) {
                        alert('Image uploaded successfully!');
                        const productImg = document.querySelector(`#product-list [data-product-id="${productId}"] .product-img img`);
                        if (productImg) {
                            productImg.src = `../Uploads/${data.image}`;
                        }
                    } else {
                        alert('Error uploading image: ' + data.message);
                    }
                } catch (error) {
                    console.error('Upload error:', error);
                    alert('Error uploading image: ' + error.message);
                }
            }
        });
    });

    // Drag and Drop Handling
    document.querySelectorAll('[id^="imageUploadContainer_"]').forEach(container => {
        const productId = container.id.split('_')[1];
        ['dragover', 'dragenter'].forEach(event => {
            container.addEventListener(event, (e) => {
                e.preventDefault();
                container.classList.add('dragover');
            });
        });

        ['dragleave', 'dragend'].forEach(event => {
            container.addEventListener(event, () => {
                container.classList.remove('dragover');
            });
        });

        container.addEventListener('drop', (e) => {
            e.preventDefault();
            container.classList.remove('dragover');
            const file = e.dataTransfer.files[0];
            if (file) {
                const input = document.getElementById(`productImageInput_${productId}`);
                input.files = e.dataTransfer.files;
                input.dispatchEvent(new Event('change'));
            }
        });
    });
    
    // Print Receipt
    window.printReceipt = function() {
        window.print();
    };

    // Download Receipt as PDF with delayed reload
    window.downloadReceipt = function() {
        const element = document.getElementById('receiptContent');
        const opt = {
            margin: 0.3,
            filename: `Receipt_${new Date().toISOString().slice(0,10)}.pdf`,
            image: { type: 'jpeg', quality: 0.98 },
            html2canvas: { scale: 2 },
            jsPDF: { unit: 'in', format: 'letter', orientation: 'portrait' }
        };
        html2pdf().from(element).set(opt).save().then(() => {
            // Delay reload to allow modal visibility
            setTimeout(() => {
                const receiptModal = bootstrap.Modal.getInstance(document.getElementById('receiptModal'));
                if (receiptModal) {
                    receiptModal.hide();
                }
                window.location.reload();
            }, 6000); // 2-second delay to ensure modal is visible
        });
    };

    // Send Receipt by Email (Placeholder)
    window.sendReceiptByEmail = function() {
        alert('Fonctionnalité d\'envoi par email en cours de développement.');
    };

    // Auto-show receipt modal if last sale exists
    <?php if ($last_sale): ?>
        try {
            const receiptModal = new bootstrap.Modal(document.getElementById('receiptModal'), {
                backdrop: 'static', // Prevent closing by clicking outside
                keyboard: false // Prevent closing with Esc key
            });
            receiptModal.show();
            // Trigger PDF download after modal is fully shown
            setTimeout(() => {
                downloadReceipt();
            }, 500); // Small delay to ensure modal is visible
        } catch (error) {
            console.error('Error showing receipt modal:', error);
            alert('Erreur lors de l\'affichage du reçu. Le PDF va être téléchargé.');
            downloadReceipt();
        }
    <?php endif; ?>
    </script>
</body>
</html>