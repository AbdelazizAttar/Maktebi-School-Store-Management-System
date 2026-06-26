<?php
session_start();

// Charger la configuration de la base de données
require_once 'config.php';

// Connexion à la base de données
try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    // En cas d'erreur, afficher un message générique pour des raisons de sécurité
    error_log("Erreur de connexion à la base de données : " . $e->getMessage());
}

// Journalisation de la déconnexion si l'utilisateur est connecté
if (isset($_SESSION['user_id']) && is_numeric($_SESSION['user_id'])) {
    try {
        $stmt = $pdo->prepare("INSERT INTO logs (utilisateur_id, action, details, ip_address, date_log) VALUES (?, ?, ?, ?, NOW())");
        $ip_address = filter_var($_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP) ?: '0.0.0.0';
        $details = 'Déconnexion de l\'utilisateur #' . $_SESSION['user_id'];
        $stmt->execute([$_SESSION['user_id'], 'Déconnexion', $details, $ip_address]);
    } catch (PDOException $e) {
        error_log("Erreur lors de l'insertion du log de déconnexion : " . $e->getMessage());
    }
}

// Détruire la session
$_SESSION = []; // Vider toutes les variables de session
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}
session_destroy();

// Redirection côté serveur après 2 secondes (au cas où JavaScript est désactivé)
header("Refresh: 2; URL=/magasin/login.php");

?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="refresh" content="2;url=/magasin/login.php">
    <title>Déconnexion - Magasin Scolaire</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #4361ee;
            --primary-dark: #3a56d4;
            --secondary: #64748b;
            --light: #f8fafc;
            --dark: #1e293b;
            --card-shadow: 0 6px 20px rgba(0, 0, 0, 0.1);
            --transition: all 0.3s ease;
            --border-radius: 10px;
            --font-primary: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: var(--font-primary);
            background: linear-gradient(135deg, var(--light), #e2e8f0);
            min-height: 100vh;
            color: var(--dark);
            line-height: 1.6;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }

        .card {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            overflow: hidden;
            max-width: 400px;
            width: 100%;
            text-align: center;
            transition: var(--transition);
        }

        .card-header {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            padding: 1rem;
            font-weight: 600;
            font-size: 1.2rem;
        }

        .card-body {
            padding: 2rem;
        }

        .logout-icon {
            font-size: 2.5rem;
            color: var(--primary);
            margin-bottom: 1rem;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        .logout-title {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        .logout-message {
            font-size: 0.95rem;
            color: var(--secondary);
        }

        .fade-in {
            animation: fadeIn 0.5s ease-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
    </style>
</head>
<body>
    <div class="card fade-in">
        <div class="card-header">
            <i class="fas fa-sign-out-alt"></i> Déconnexion
        </div>
        <div class="card-body">
            <div class="logout-icon">
                <i class="fas fa-spinner"></i>
            </div>
            <h2 class="logout-title">Déconnexion en cours</h2>
            <p class="logout-message">Vous serez redirigé vers la page de connexion dans quelques instants...</p>
        </div>
    </div>

    <script>
        // Redirection automatique après 2 secondes
        setTimeout(() => {
            window.location.href = '../login.php';
        }, 2000);
    </script>
</body>
</html>