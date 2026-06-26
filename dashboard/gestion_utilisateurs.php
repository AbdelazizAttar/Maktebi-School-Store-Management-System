<?php
session_start();

// Admin access check
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

require_once '../config.php';

// Initialize error/success messages
$error = '';
$success = '';

// Pagination
$perPage = 10;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $perPage;

// Search functionality
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$where = '';
$params = [];
$types = '';

if (!empty($search)) {
    $where = "WHERE CONCAT(nom, ' ', prenom) LIKE ? OR email LIKE ?";
    $searchTerm = "%$search%";
    $params = [$searchTerm, $searchTerm];
    $types = 'ss';
}
// Get total users
$countQuery = "SELECT COUNT(*) AS total FROM utilisateurs";
$countStmt = $conn->prepare($countQuery);
if (!$countStmt) {
    $error = "Erreur de préparation de la requête de comptage : " . $conn->error;
} else {
    $countStmt->execute();
    $totalUsers = $countStmt->get_result()->fetch_assoc()['total'];
    $totalPages = ceil($totalUsers / $perPage);
}

// Get users with pagination
$usersQuery = "SELECT id, nom, prenom, email, role, statut, derniere_connexion
               FROM utilisateurs 
               ORDER BY nom ASC 
               LIMIT ? OFFSET ?";
$usersStmt = $conn->prepare($usersQuery);
if (!$usersStmt) {
    $error = "Erreur de préparation de la requête des utilisateurs : " . $conn->error;
} else {
    $usersStmt->bind_param('ii', $perPage, $offset);
    $usersStmt->execute();
    $usersResult = $usersStmt->get_result();
    $users = $usersResult->fetch_all(MYSQLI_ASSOC);
}

// Log action function
function logAction($conn, $utilisateur_id, $action, $details) {
    $ip_address = $_SERVER['REMOTE_ADDR'];
    $user_agent = $_SERVER['HTTP_USER_AGENT'];
    $stmt = $conn->prepare("INSERT INTO logs (utilisateur_id, action, details, ip_address, user_agent) 
                           VALUES (?, ?, ?, ?, ?)");
    if ($stmt) {
        $stmt->bind_param("issss", $utilisateur_id, $action, $details, $ip_address, $user_agent);
        $stmt->execute();
        $stmt->close();
    }
}

// Check email uniqueness
function isEmailUnique($conn, $email, $excludeId = null) {
    $query = "SELECT COUNT(*) AS count FROM utilisateurs WHERE email = ?";
    if ($excludeId) {
        $query .= " AND id != ?";
    }
    $stmt = $conn->prepare($query);
    if ($excludeId) {
        $stmt->bind_param("si", $email, $excludeId);
    } else {
        $stmt->bind_param("s", $email);
    }
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    return $result['count'] == 0;
}

// Handle user actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $utilisateur_id = $_SESSION['user_id'] ?? null;

    // CSRF protection
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = "Token de sécurité invalide. Veuillez réessayer.";
    } else {
        if ($action === 'add') {
            $nom = trim($_POST['nom'] ?? '');
            $prenom = trim($_POST['prenom'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';
            $role = $_POST['role'] ?? '';
            $statut = $_POST['statut'] ?? '';

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = "Adresse email invalide.";
            } elseif (!isEmailUnique($conn, $email)) {
                $error = "Cet email est déjà utilisé.";
            } elseif (strlen($password) < 8) {
                $error = "Le mot de passe doit contenir au moins 8 caractères.";
            } elseif (!in_array($role, ['admin', 'gestionnaire', 'caissier'])) {
                $error = "Rôle invalide.";
            } elseif (!in_array($statut, ['actif', 'inactif'])) {
                $error = "Statut invalide.";
            } else {
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("INSERT INTO utilisateurs (nom, prenom, email, mot_de_passe, role, statut) 
                                       VALUES (?, ?, ?, ?, ?, ?)");
                if ($stmt) {
                    $stmt->bind_param("ssssss", $nom, $prenom, $email, $hashedPassword, $role, $statut);
                    if ($stmt->execute()) {
                        logAction($conn, $utilisateur_id, 'Ajout utilisateur', "Utilisateur $email ajouté");
                        $success = "Utilisateur ajouté avec succès.";
                        header("Location: gestion_utilisateurs.php?success=" . urlencode($success));
                        exit;
                    } else {
                        $error = "Erreur lors de l'ajout de l'utilisateur : " . $stmt->error;
                    }
                    $stmt->close();
                } else {
                    $error = "Erreur de préparation de la requête : " . $conn->error;
                }
            }
        } elseif ($action === 'edit') {
            $id = intval($_POST['id'] ?? 0);
            $nom = trim($_POST['nom'] ?? '');
            $prenom = trim($_POST['prenom'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $role = $_POST['role'] ?? '';
            $statut = $_POST['statut'] ?? '';
            $password = $_POST['password'] ?? '';

            if (!$id) {
                $error = "ID utilisateur invalide.";
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = "Adresse email invalide.";
            } elseif (!isEmailUnique($conn, $email, $id)) {
                $error = "Cet email est déjà utilisé par un autre utilisateur.";
            } elseif (!in_array($role, ['admin', 'gestionnaire', 'caissier'])) {
                $error = "Rôle invalide.";
            } elseif (!in_array($statut, ['actif', 'inactif'])) {
                $error = "Statut invalide.";
            } else {
                $query = "UPDATE utilisateurs SET nom = ?, prenom = ?, email = ?, role = ?, statut = ?";
                $params = [$nom, $prenom, $email, $role, $statut];
                $types = "sssss";

                if (!empty($password)) {
                    if (strlen($password) < 8) {
                        $error = "Le mot de passe doit contenir au moins 8 caractères.";
                    } else {
                        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                        $query .= ", mot_de_passe = ?";
                        $params[] = $hashedPassword;
                        $types .= "s";
                    }
                }

                if (empty($error)) {
                    $query .= " WHERE id = ?";
                    $params[] = $id;
                    $types .= "i";

                    $stmt = $conn->prepare($query);
                    if ($stmt) {
                        $stmt->bind_param($types, ...$params);
                        if ($stmt->execute()) {
                            logAction($conn, $utilisateur_id, 'Modification utilisateur', "Utilisateur ID $id modifié");
                            $success = "Utilisateur modifié avec succès.";
                            header("Location: gestion_utilisateurs.php?success=" . urlencode($success));
                            exit;
                        } else {
                            $error = "Erreur lors de la modification : " . $stmt->error;
                        }
                        $stmt->close();
                    } else {
                        $error = "Erreur de préparation de la requête : " . $conn->error;
                    }
                }
            }
        } elseif ($action === 'delete') {
            $id = intval($_POST['id'] ?? 0);
            if ($id) {
                $stmt = $conn->prepare("DELETE FROM utilisateurs WHERE id = ?");
                if ($stmt) {
                    $stmt->bind_param("i", $id);
                    if ($stmt->execute()) {
                        logAction($conn, $utilisateur_id, 'Suppression utilisateur', "Utilisateur ID $id supprimé");
                        $success = "Utilisateur supprimé avec succès.";
                        header("Location: gestion_utilisateurs.php?success=" . urlencode($success));
                        exit;
                    } else {
                        $error = "Erreur lors de la suppression : " . $stmt->error;
                    }
                    $stmt->close();
                } else {
                    $error = "Erreur de préparation de la requête : " . $conn->error;
                }
            } else {
                $error = "ID utilisateur invalide.";
            }
        } elseif ($action === 'change_status') {
            $id = intval($_POST['id'] ?? 0);
            $newStatus = $_POST['new_status'] ?? '';
            
            if ($id && in_array($newStatus, ['actif', 'inactif'])) {
                $stmt = $conn->prepare("UPDATE utilisateurs SET statut = ? WHERE id = ?");
                if ($stmt) {
                    $stmt->bind_param("si", $newStatus, $id);
                    if ($stmt->execute()) {
                        $statusText = $newStatus === 'actif' ? 'activé' : 'désactivé';
                        logAction($conn, $utilisateur_id, 'Changement statut', "Utilisateur ID $id $statusText");
                        $success = "Statut utilisateur modifié avec succès.";
                        header("Location: gestion_utilisateurs.php?success=" . urlencode($success));
                        exit;
                    } else {
                        $error = "Erreur lors de la modification du statut : " . $stmt->error;
                    }
                    $stmt->close();
                } else {
                    $error = "Erreur de préparation de la requête : " . $conn->error;
                }
            } else {
                $error = "Paramètres invalides pour le changement de statut.";
            }
        }
    }
}

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

include '../sidebar.php';
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des Utilisateurs - Gestionnaire Magasin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* Enhanced CSS with modern design */
        :root {
            --primary: #4361ee;
            --primary-dark: #3a56d4;
            --secondary: #6c757d;
            --success: #28a745;
            --danger: #dc3545;
            --warning: #ffc107;
            --info: #17a2b8;
            --light:rgb(255, 255, 255);
            --dark: #343a40;
            --gray: #6c757d;
            --light-gray: #e9ecef;
            --border: #dee2e6;
            --card-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
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
            margin-bottom: 25px;
            background: white;
            padding: 20px 25px;
            border-radius: 12px;
            box-shadow: var(--card-shadow);
            height:80px;
        }

        .header-left h1 {
            font-size: 26px;
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 5px;
        }

        .header-left p {
            color: #6c757d;
            font-size: 14px;
        }

        .header-right {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .search-container {
            position: relative;
        }

        .search-input {
            padding: 10px 15px 10px 40px;
            border-radius: 50px;
            border: 1px solid var(--border);
            font-size: 14px;
            width: 250px;
            transition: all 0.3s;
        }

        .search-input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.15);
            width: 300px;
        }

        .search-icon {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray);
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 10px 18px;
            border-radius: 8px;
            font-weight: 500;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.3s ease;
            border: none;
            gap: 8px;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(67, 97, 238, 0.25);
        }

        .btn-outline {
            background: transparent;
            border: 1px solid var(--border);
            color: var(--dark);
        }

        .btn-outline:hover {
            background: var(--light);
            border-color: var(--primary);
            color: var(--primary);
        }

        .alert {
            padding: 14px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
            text-align: center;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .alert-success {
            background: rgba(40, 167, 69, 0.1);
            color: var(--success);
            border: 1px solid rgba(40, 167, 69, 0.2);
        }

        .alert-error {
            background: rgba(220, 53, 69, 0.1);
            color: var(--danger);
            border: 1px solid rgba(220, 53, 69, 0.2);
        }

        .card {
            background: white;
            border-radius: 12px;
            box-shadow: var(--card-shadow);
            padding: 25px;
            margin-bottom: 25px;
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
            color: #2c3e50;
        }

        .table-responsive {
            overflow-x: auto;
            border-radius: 8px;
        }

        table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            font-size: 14px;
            min-width: 800px;
        }

        thead {
            background: var(--light);
        }

        th {
            padding: 15px;
            text-align: left;
            font-weight: 600;
            color: var(--dark);
            border-bottom: 2px solid var(--border);
        }

        td {
            padding: 15px;
            text-align: left;
            border-bottom: 1px solid var(--border);
            color: #495057;
        }

        tr:last-child td {
            border-bottom: none;
        }

        tr:hover td {
            background-color: rgba(67, 97, 238, 0.03);
        }

        .status-badge {
            padding: 6px 12px;
            border-radius: 50px;
            font-size: 12px;
            font-weight: 500;
            display: inline-block;
        }

        .status-actif {
            background: rgba(40, 167, 69, 0.15);
            color: var(--success);
        }

        .status-inactif {
            background: rgba(220, 53, 69, 0.15);
            color: var(--danger);
        }

        .role-badge {
            padding: 5px 10px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 500;
            display: inline-block;
        }

        .role-admin {
            background: rgba(67, 97, 238, 0.15);
            color: var(--primary);
        }

        .role-gestionnaire {
            background: rgba(23, 162, 184, 0.15);
            color: var(--info);
        }

        .role-caissier {
            background: rgba(255, 193, 7, 0.15);
            color: var(--warning);
        }

        .action-buttons {
            display: flex;
            gap: 8px;
        }

        .btn-icon {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s;
            border: none;
            font-size: 14px;
        }

        .btn-icon:hover {
            transform: translateY(-2px);
        }

        .btn-edit {
            background: rgba(67, 97, 238, 0.1);
            color: var(--primary);
        }

        .btn-edit:hover {
            background: rgba(67, 97, 238, 0.2);
        }

        .btn-delete {
            background: rgba(220, 53, 69, 0.1);
            color: var(--danger);
        }

        .btn-delete:hover {
            background: rgba(220, 53, 69, 0.2);
        }

        .btn-status {
            background: rgba(108, 117, 125, 0.1);
            color: var(--secondary);
        }

        .btn-status:hover {
            background: rgba(108, 117, 125, 0.2);
        }

        .pagination {
            display: flex;
            justify-content: center;
            margin-top: 25px;
            gap: 8px;
        }

        .page-item {
            display: inline-block;
        }

        .page-link {
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 36px;
            height: 36px;
            padding: 0 12px;
            border-radius: 8px;
            background: white;
            border: 1px solid var(--border);
            color: var(--dark);
            font-size: 14px;
            font-weight: 500;
            transition: all 0.2s;
        }

        .page-link:hover {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        .page-link.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        .page-link.disabled {
            opacity: 0.5;
            cursor: not-allowed;
            background: var(--light-gray);
        }

        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }

        .stat-card {
            background: white;
            border-radius: 12px;
            box-shadow: var(--card-shadow);
            padding: 20px;
            display: flex;
            flex-direction: column;
        }

        .stat-header {
            display: flex;
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
            margin-right: 15px;
            font-size: 20px;
        }

        .stat-content h3 {
            font-size: 24px;
            font-weight: 600;
            margin-bottom: 5px;
        }

        .stat-content p {
            color: var(--gray);
            font-size: 14px;
        }

        .icon-users {
            background: rgba(67, 97, 238, 0.15);
            color: var(--primary);
        }

        .icon-active {
            background: rgba(40, 167, 69, 0.15);
            color: var(--success);
        }

        .icon-inactive {
            background: rgba(220, 53, 69, 0.15);
            color: var(--danger);
        }

        .icon-admin {
            background: rgba(255, 193, 7, 0.15);
            color: var(--warning);
        }

        /* Modal styles */
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
            border-radius: 16px;
            padding: 30px;
            width: 90%;
            max-width: 500px;
            position: relative;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            animation: modalFadeIn 0.3s ease-out;
        }

        @keyframes modalFadeIn {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .modal-title {
            font-size: 20px;
            font-weight: 600;
            color: #2c3e50;
        }

        .modal-close {
            font-size: 24px;
            color: #6c757d;
            cursor: pointer;
            transition: color 0.3s;
            background: none;
            border: none;
        }

        .modal-close:hover {
            color: var(--dark);
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            font-size: 14px;
            font-weight: 500;
            color: #495057;
            margin-bottom: 8px;
        }

        .form-label .required {
            color: var(--danger);
        }

        .form-control {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: 14px;
            color: var(--dark);
            transition: all 0.3s;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.15);
        }

        .form-footer {
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            margin-top: 20px;
        }

        .btn-cancel {
            background: var(--light);
            color: var(--dark);
        }

        .btn-cancel:hover {
            background: var(--light-gray);
        }

        .btn-submit {
            background: var(--primary);
            color: white;
        }

        .btn-submit:hover {
            background: var(--primary-dark);
        }

        .password-toggle {
            position: absolute;
            right: 15px;
            top: 38px;
            transform: translateY(-50%);
            cursor: pointer;
            color: var(--gray);
        }

        /* Responsive adjustments */
        @media (max-width: 1200px) {
            .main-container {
                margin-left: 0;
                padding: 15px;
            }
            
            .search-input:focus {
                width: 250px;
            }
        }

        @media (max-width: 768px) {
            .header {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }
            
            .header-right {
                width: 100%;
                justify-content: space-between;
            }
            
            .search-container {
                flex-grow: 1;
            }
            
            .search-input {
                width: 100%;
            }
            
            .stats-container {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 576px) {
            .btn {
                padding: 8px 15px;
                font-size: 13px;
            }
            
            .card {
                padding: 15px;
            }
            
            .modal-content {
                padding: 20px;
            }
        }
    </style>
</head>
<body>
    <!-- Sidebar included via PHP -->

    <main class="main-container">
        <header class="header">
            <div class="header-left">
                <h1>Gestion des Utilisateurs</h1>
                <p>Administration des comptes système</p>
            </div>
            <div class="header-right">
                <div class="search-container">
                    <i class="fas fa-search search-icon"></i>
                    <form method="GET" action="gestion_utilisateurs.php">
                        <input type="text" class="search-input" name="search" placeholder="Rechercher utilisateur..." 
                               value="<?= htmlspecialchars($search) ?>">
                    </form>
                </div>
                <button class="btn btn-primary add-user-btn">
                    <i class="fas fa-plus-circle"></i>
                    <span>Nouvel Utilisateur</span>
                </button>
            </div>
        </header>

        <?php if ($error): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <?= htmlspecialchars($error) ?>
            </div>
        <?php elseif ($success || isset($_GET['success'])): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?= htmlspecialchars($success ?: $_GET['success']) ?>
            </div>
        <?php endif; ?>

        <!-- Statistics Cards -->
        <?php
        // Get user statistics
        $statsQuery = "SELECT 
                        COUNT(*) AS total,
                        SUM(statut = 'actif') AS active,
                        SUM(statut = 'inactif') AS inactive,
                        SUM(role = 'admin') AS admins
                      FROM utilisateurs";
        $statsResult = $conn->query($statsQuery);
        $stats = $statsResult->fetch_assoc();
        ?>
        <div class="stats-container">
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-icon icon-users">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-content">
                        <h3><?= $stats['total'] ?></h3>
                        <p>Utilisateurs</p>
                    </div>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-icon icon-active">
                        <i class="fas fa-user-check"></i>
                    </div>
                    <div class="stat-content">
                        <h3><?= $stats['active'] ?></h3>
                        <p>Comptes Actifs</p>
                    </div>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-icon icon-inactive">
                        <i class="fas fa-user-slash"></i>
                    </div>
                    <div class="stat-content">
                        <h3><?= $stats['inactive'] ?></h3>
                        <p>Comptes Inactifs</p>
                    </div>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-icon icon-admin">
                        <i class="fas fa-user-shield"></i>
                    </div>
                    <div class="stat-content">
                        <h3><?= $stats['admins'] ?></h3>
                        <p>Administrateurs</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h2 class="card-title">Liste des Utilisateurs</h2>
                <div class="card-actions">
                    <span class="text-muted"><?= $totalUsers ?> utilisateur(s) trouvé(s)</span>
                </div>
            </div>
            
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Nom</th>
                            <th>Email</th>
                            <th>Rôle</th>
                            <th>Statut</th>
                            <th>Dernière Connexion</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($users)): ?>
                            <?php foreach ($users as $user): ?>
                                <tr>
                                    <td><?= htmlspecialchars($user['id']) ?></td>
                                    <td><?= htmlspecialchars($user['nom'] . ' ' . $user['prenom']) ?></td>
                                    <td><?= htmlspecialchars($user['email']) ?></td>
                                    <td>
                                        <span class="role-badge role-<?= $user['role'] ?>">
                                            <?= htmlspecialchars(ucfirst($user['role'])) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?= strtolower($user['statut']) ?>">
                                            <?= htmlspecialchars(ucfirst($user['statut'])) ?>
                                        </span>
                                    </td>
                                    <td><?= $user['derniere_connexion'] ? date('d/m/Y H:i', strtotime($user['derniere_connexion'])) : 'Jamais' ?></td>
                                    <td>
                                        <div class="action-buttons">
                                            <button class="btn-icon btn-edit edit-user-btn"
                                                    data-id="<?= $user['id'] ?>" 
                                                    data-nom="<?= htmlspecialchars($user['nom'], ENT_QUOTES) ?>" 
                                                    data-prenom="<?= htmlspecialchars($user['prenom'], ENT_QUOTES) ?>" 
                                                    data-email="<?= htmlspecialchars($user['email'], ENT_QUOTES) ?>" 
                                                    data-role="<?= htmlspecialchars($user['role']) ?>" 
                                                    data-statut="<?= htmlspecialchars($user['statut']) ?>"
                                                    aria-label="Modifier">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            
                                            <button class="btn-icon btn-status status-toggle-btn"
                                                    data-id="<?= $user['id'] ?>"
                                                    data-current-status="<?= $user['statut'] ?>"
                                                    aria-label="Changer statut">
                                                <i class="fas fa-power-off"></i>
                                            </button>
                                            
                                            <button class="btn-icon btn-delete delete-user-btn"
                                                    data-id="<?= $user['id'] ?>"
                                                    data-name="<?= htmlspecialchars($user['nom'] . ' ' . $user['prenom']) ?>"
                                                    aria-label="Supprimer">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7" class="text-center py-5">
                                    <i class="fas fa-user-slash fa-2x mb-3" style="color: #6c757d;"></i>
                                    <p>Aucun utilisateur trouvé</p>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a class="page-link" href="?page=<?= $page - 1 ?><?= $search ? "&search=$search" : '' ?>">
                            <i class="fas fa-chevron-left"></i>
                        </a>
                    <?php else: ?>
                        <span class="page-link disabled"><i class="fas fa-chevron-left"></i></span>
                    <?php endif; ?>
                    
                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <a class="page-link <?= $i == $page ? 'active' : '' ?>" 
                           href="?page=<?= $i ?><?= $search ? "&search=$search" : '' ?>">
                            <?= $i ?>
                        </a>
                    <?php endfor; ?>
                    
                    <?php if ($page < $totalPages): ?>
                        <a class="page-link" href="?page=<?= $page + 1 ?><?= $search ? "&search=$search" : '' ?>">
                            <i class="fas fa-chevron-right"></i>
                        </a>
                    <?php else: ?>
                        <span class="page-link disabled"><i class="fas fa-chevron-right"></i></span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <!-- Add/Edit User Modal -->
    <div class="modal" id="userModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title" id="modalTitle">Ajouter un Utilisateur</h3>
                <button class="modal-close">&times;</button>
            </div>
            
            <form id="userForm" method="post">
                <input type="hidden" name="action" id="formAction" value="add">
                <input type="hidden" name="id" id="userId">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                
                <div class="form-group">
                    <label class="form-label" for="nom">Nom <span class="required">*</span></label>
                    <input type="text" class="form-control" name="nom" id="nom" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label" for="prenom">Prénom <span class="required">*</span></label>
                    <input type="text" class="form-control" name="prenom" id="prenom" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label" for="email">Email <span class="required">*</span></label>
                    <input type="email" class="form-control" name="email" id="email" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label" for="password">Mot de passe <span class="required" id="passwordRequired">*</span></label>
                    <input type="password" class="form-control" name="password" id="password" required>
                    <span class="password-toggle" id="passwordToggle">
                        <i class="fas fa-eye"></i>
                    </span>
                </div>
                
                <div class="form-group">
                    <label class="form-label" for="role">Rôle <span class="required">*</span></label>
                    <select class="form-control" name="role" id="role" required>
                        <option value="admin">Admin</option>
                        <option value="gestionnaire">Gestionnaire</option>
                        <option value="caissier">Caissier</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label" for="statut">Statut <span class="required">*</span></label>
                    <select class="form-control" name="statut" id="statut" required>
                        <option value="actif">Actif</option>
                        <option value="inactif">Inactif</option>
                    </select>
                </div>
                
                <div class="form-footer">
                    <button type="button" class="btn btn-cancel modal-close">Annuler</button>
                    <button type="submit" class="btn btn-submit">Enregistrer</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal" id="deleteModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Confirmer la suppression</h3>
                <button class="modal-close">&times;</button>
            </div>
            
            <form id="deleteForm" method="post">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" id="deleteUserId">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                
                <p>Voulez-vous vraiment supprimer l'utilisateur <strong id="deleteUserName"></strong> ?</p>
                <p class="text-danger"><i class="fas fa-exclamation-triangle"></i> Cette action est irréversible !</p>
                
                <div class="form-footer">
                    <button type="button" class="btn btn-cancel modal-close">Annuler</button>
                    <button type="submit" class="btn btn-danger">Supprimer</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Status Change Modal -->
    <div class="modal" id="statusModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Changer le statut</h3>
                <button class="modal-close">&times;</button>
            </div>
            
            <form id="statusForm" method="post">
                <input type="hidden" name="action" value="change_status">
                <input type="hidden" name="id" id="statusUserId">
                <input type="hidden" name="new_status" id="newStatus">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                
                <p>Changer le statut de l'utilisateur <strong id="statusUserName"></strong> à :</p>
                
                <div class="form-group">
                    <div class="d-flex gap-3">
                        <button type="button" class="btn btn-outline w-100" data-status="actif" id="activateBtn">
                            <i class="fas fa-check-circle"></i> Actif
                        </button>
                        <button type="button" class="btn btn-outline w-100" data-status="inactif" id="deactivateBtn">
                            <i class="fas fa-ban"></i> Inactif
                        </button>
                    </div>
                </div>
                
                <div class="form-footer">
                    <button type="button" class="btn btn-cancel modal-close">Annuler</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Elements
            const modals = document.querySelectorAll('.modal');
            const userModal = document.getElementById('userModal');
            const deleteModal = document.getElementById('deleteModal');
            const statusModal = document.getElementById('statusModal');
            const modalCloses = document.querySelectorAll('.modal-close');
            const addUserBtn = document.querySelector('.add-user-btn');
            const editUserBtns = document.querySelectorAll('.edit-user-btn');
            const deleteUserBtns = document.querySelectorAll('.delete-user-btn');
            const statusToggleBtns = document.querySelectorAll('.status-toggle-btn');
            const passwordToggle = document.getElementById('passwordToggle');
            const passwordInput = document.getElementById('password');
            const activateBtn = document.getElementById('activateBtn');
            const deactivateBtn = document.getElementById('deactivateBtn');
            
            // Open modal function
            function openModal(modal) {
                modal.style.display = 'flex';
                document.body.style.overflow = 'hidden';
            }
            
            // Close modal function
            function closeModals() {
                modals.forEach(modal => modal.style.display = 'none');
                document.body.style.overflow = 'auto';
            }
            
            // Open add user modal
            addUserBtn.addEventListener('click', () => {
                document.getElementById('modalTitle').textContent = 'Ajouter un Utilisateur';
                document.getElementById('formAction').value = 'add';
                document.getElementById('userId').value = '';
                document.getElementById('userForm').reset();
                document.getElementById('password').required = true;
                document.getElementById('passwordRequired').style.display = 'inline';
                openModal(userModal);
            });
            
            // Open edit user modal
            editUserBtns.forEach(btn => {
                btn.addEventListener('click', function() {
                    document.getElementById('modalTitle').textContent = 'Modifier l\'Utilisateur';
                    document.getElementById('formAction').value = 'edit';
                    document.getElementById('userId').value = this.dataset.id;
                    document.getElementById('nom').value = this.dataset.nom;
                    document.getElementById('prenom').value = this.dataset.prenom;
                    document.getElementById('email').value = this.dataset.email;
                    document.getElementById('role').value = this.dataset.role;
                    document.getElementById('statut').value = this.dataset.statut;
                    document.getElementById('password').required = false;
                    document.getElementById('passwordRequired').style.display = 'none';
                    openModal(userModal);
                });
            });
            
            // Open delete confirmation modal
            deleteUserBtns.forEach(btn => {
                btn.addEventListener('click', function() {
                    document.getElementById('deleteUserId').value = this.dataset.id;
                    document.getElementById('deleteUserName').textContent = this.dataset.name;
                    openModal(deleteModal);
                });
            });
            
            // Open status change modal
            statusToggleBtns.forEach(btn => {
                btn.addEventListener('click', function() {
                    const userId = this.dataset.id;
                    const currentStatus = this.dataset.currentStatus;
                    const userName = this.closest('tr').querySelector('td:nth-child(2)').textContent;
                    
                    document.getElementById('statusUserId').value = userId;
                    document.getElementById('statusUserName').textContent = userName;
                    
                    // Highlight current status
                    if (currentStatus === 'actif') {
                        activateBtn.classList.add('active');
                        deactivateBtn.classList.remove('active');
                    } else {
                        deactivateBtn.classList.add('active');
                        activateBtn.classList.remove('active');
                    }
                    
                    openModal(statusModal);
                });
            });
            
            // Handle status change
            activateBtn.addEventListener('click', function() {
                document.getElementById('newStatus').value = 'actif';
                document.getElementById('statusForm').submit();
            });
            
            deactivateBtn.addEventListener('click', function() {
                document.getElementById('newStatus').value = 'inactif';
                document.getElementById('statusForm').submit();
            });
            
            // Toggle password visibility
            passwordToggle.addEventListener('click', function() {
                const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                passwordInput.setAttribute('type', type);
                this.innerHTML = type === 'password' ? '<i class="fas fa-eye"></i>' : '<i class="fas fa-eye-slash"></i>';
            });
            
            // Close modals
            modalCloses.forEach(btn => {
                btn.addEventListener('click', closeModals);
            });
            
            // Close modal when clicking outside
            window.addEventListener('click', (e) => {
                modals.forEach(modal => {
                    if (e.target === modal) closeModals();
                });
            });
            
            // Form validation
            document.getElementById('userForm').addEventListener('submit', function(e) {
                const action = document.getElementById('formAction').value;
                const passwordRequired = action === 'add';
                
                if (passwordRequired && !passwordInput.value.trim()) {
                    e.preventDefault();
                    alert('Un mot de passe est requis pour ajouter un utilisateur.');
                    passwordInput.focus();
                }
            });
        });
    </script>
</body>
</html>