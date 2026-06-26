<?php
session_start();
require_once 'config.php'; // Inclut la connexion à la base (mysqli $conn)

$error = "";
$remember_email = "";

// Check for remember me cookie
if(isset($_COOKIE['remember_email'])) {
    $remember_email = $_COOKIE['remember_email'];
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $email = trim($_POST["email"]);
    $mdp = $_POST["password"];
    $remember = isset($_POST['remember']) ? true : false;

    // Préparation de la requête pour éviter injection SQL
    $stmt = $conn->prepare("SELECT * FROM utilisateurs WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();
        // Vérification du mot de passe haché
        if (password_verify($mdp, $user["mot_de_passe"])) {
            // Set remember me cookie if checked
            if ($remember) {
                setcookie('remember_email', $email, time() + (86400 * 30), "/"); // 30 days
            } else {
                // Delete cookie if exists
                if(isset($_COOKIE['remember_email'])) {
                    setcookie('remember_email', '', time() - 3600, "/");
                }
            }
            
            $_SESSION["user_email"] = $user["email"];
            $_SESSION["user_role"] = $user["role"];
            $_SESSION["user_name"] = $user["nom"];
            $_SESSION['user_id'] = $user['id'];

            // Log the login action
            try {
                $stmt_log = $conn->prepare("INSERT INTO logs (utilisateur_id, action, details, ip_address, date_log) VALUES (?, ?, ?, ?, NOW())");
                $ip_address = filter_var($_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP) ?: '0.0.0.0';
                $details = 'Connexion de l\'utilisateur #' . $user['id'];
                $stmt_log->bind_param("isss", $user['id'], $action = 'Connexion', $details, $ip_address);
                $stmt_log->execute();
            } catch (Exception $e) {
                error_log("Erreur lors de l'insertion du log de connexion : " . $e->getMessage());
            }

            // Output JSON for AJAX handling
            echo json_encode([
                'success' => true,
                'redirect' => $user["role"] === "admin" ? 'dashboard/admin.php' : 'employe.php'
            ]);
            exit;
        } else {
            $error = "Mot de passe incorrect.";
        }
    } else {
        $error = "Utilisateur non trouvé.";
    }
    
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Connexion - Magasin Scolaire</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #4361ee;
            --primary-light: #6c7fff;
            --primary-dark: #3a56d4;
            --secondary: #64748b;
            --light: #f8fafc;
            --light-gray: #f1f5f9;
            --dark: #1e293b;
            --danger: #ef4444;
            --success: #22c55e;
            --warning: #f59e0b;
            --card-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.15);
            --transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            --border-radius: 16px;
            --font-primary: 'Segoe UI', 'Roboto', 'Helvetica Neue', sans-serif;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: var(--font-primary);
            background: linear-gradient(135deg, #f0f4ff, #e6f0ff);
            min-height: 100vh;
            color: var(--dark);
            line-height: 1.6;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            overflow-x: hidden;
            position: relative;
        }

        body::before {
            content: "";
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: radial-gradient(circle at 10% 20%, rgba(67, 97, 238, 0.08) 0%, transparent 20%),
                        radial-gradient(circle at 90% 80%, rgba(106, 130, 251, 0.1) 0%, transparent 20%);
            z-index: -1;
        }

        .container {
            max-width: 460px;
            width: 100%;
            perspective: 1000px;
        }

        .card {
            background: rgba(255, 255, 255, 0.92);
            backdrop-filter: blur(12px);
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            overflow: hidden;
            transition: var(--transition);
            transform-style: preserve-3d;
            animation: cardFloat 6s ease-in-out infinite;
            border: 1px solid rgba(255, 255, 255, 0.5);
        }

        @keyframes cardFloat {
            0%, 100% { transform: translateY(0) rotateY(0.5deg); }
            50% { transform: translateY(-10px) rotateY(-0.5deg); }
        }

        .card-header {
            background: linear-gradient(135deg, var(--primary), var(--primary-light));
            color: white;
            padding: 2rem;
            font-weight: 600;
            font-size: 1.8rem;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .card-header::before {
            content: "";
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.15) 0%, transparent 70%);
            animation: rotate 20s linear infinite;
        }

        @keyframes rotate {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        .card-body {
            padding: 2.5rem;
        }

        .logo {
            display: flex;
            justify-content: center;
            margin-bottom: 1.5rem;
        }

        .logo-circle {
            width: 70px;
            height: 70px;
            background: linear-gradient(135deg, var(--primary), var(--primary-light));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0% { box-shadow: 0 0 0 0 rgba(67, 97, 238, 0.4); }
            70% { box-shadow: 0 0 0 15px rgba(67, 97, 238, 0); }
            100% { box-shadow: 0 0 0 0 rgba(67, 97, 238, 0); }
        }

        .logo i {
            font-size: 2rem;
            color: white;
        }

        .form-group {
            margin-bottom: 1.8rem;
            position: relative;
            animation: fadeInUp 0.6s ease-out;
        }

        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .form-group:nth-child(1) { animation-delay: 0.1s; }
        .form-group:nth-child(2) { animation-delay: 0.2s; }
        .form-group:nth-child(3) { animation-delay: 0.3s; }
        .form-group:nth-child(4) { animation-delay: 0.4s; }

        .form-label {
            font-size: 0.95rem;
            font-weight: 500;
            color: var(--dark);
            margin-bottom: 0.7rem;
            display: block;
            padding-left: 0.5rem;
        }

        .input-wrapper {
            position: relative;
        }

        .form-control {
            width: 100%;
            padding: 1rem 1rem 1rem 3rem;
            border: 1px solid #dbeafe;
            border-radius: 12px;
            font-size: 1rem;
            background: var(--light-gray);
            transition: var(--transition);
            box-shadow: inset 0 2px 4px rgba(0,0,0,0.03);
        }

        .form-control:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.15);
            outline: none;
            background: white;
        }

        .form-icon {
            position: absolute;
            top: 50%;
            left: 1rem;
            color: var(--secondary);
            font-size: 1.2rem;
            transform: translateY(-50%);
            transition: var(--transition);
        }

        .form-control:focus ~ .form-icon {
            color: var(--primary);
            transform: translateY(-50%) scale(1.1);
        }

        .form-group.password .form-icon.toggle-password {
            left: auto;
            right: 1rem;
            cursor: pointer;
            color: var(--secondary);
            z-index: 10;
        }

        .form-group.password .form-icon.toggle-password:hover {
            color: var(--primary);
        }

        .options {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.8rem;
            font-size: 0.9rem;
        }

        .remember {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .remember input {
            width: 18px;
            height: 18px;
            accent-color: var(--primary);
            cursor: pointer;
        }

        .forgot-password {
            color: var(--primary);
            text-decoration: none;
            transition: var(--transition);
            font-weight: 500;
        }

        .forgot-password:hover {
            color: var(--primary-dark);
            text-decoration: underline;
        }

        .btn {
            width: 100%;
            padding: 1.1rem;
            border: none;
            border-radius: 12px;
            font-weight: 600;
            font-size: 1.1rem;
            cursor: pointer;
            background: linear-gradient(135deg, var(--primary), var(--primary-light));
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.8rem;
            transition: var(--transition);
            position: relative;
            overflow: hidden;
            box-shadow: 0 6px 15px rgba(67, 97, 238, 0.3);
        }

        .btn::after {
            content: "";
            position: absolute;
            top: -50%;
            left: -60%;
            width: 20px;
            height: 200%;
            background: rgba(255, 255, 255, 0.3);
            transform: rotate(25deg);
            transition: var(--transition);
        }

        .btn:hover::after {
            left: 120%;
        }

        .btn:hover, .btn:focus {
            transform: translateY(-4px);
            box-shadow: 0 10px 25px rgba(67, 97, 238, 0.4);
        }

        .btn:active {
            transform: translateY(0);
        }

        .error {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
            padding: 1rem;
            border-radius: 12px;
            font-size: 0.95rem;
            margin-bottom: 1.8rem;
            text-align: center;
            border-left: 4px solid var(--danger);
            animation: shake 0.5s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            20%, 60% { transform: translateX(-8px); }
            40%, 80% { transform: translateX(8px); }
        }

        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(255, 255, 255, 0.95);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            flex-direction: column;
            backdrop-filter: blur(5px);
        }

        .loading-spinner {
            width: 60px;
            height: 60px;
            border: 5px solid rgba(67, 97, 238, 0.2);
            border-top: 5px solid var(--primary);
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin-bottom: 1.5rem;
        }

        .loading-text {
            font-size: 1.2rem;
            font-weight: 500;
            color: var(--dark);
        }

        .success-check {
            font-size: 4rem;
            color: var(--success);
            animation: scaleIn 0.5s ease-out, pulseSuccess 1s 0.5s;
            display: none;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        @keyframes scaleIn {
            from { transform: scale(0); opacity: 0; }
            to { transform: scale(1); opacity: 1; }
        }

        @keyframes pulseSuccess {
            0% { transform: scale(1); }
            50% { transform: scale(1.1); }
            100% { transform: scale(1); }
        }

        .fade-in {
            animation: fadeIn 0.8s ease-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .footer {
            text-align: center;
            margin-top: 2rem;
            color: var(--secondary);
            font-size: 0.9rem;
            animation: fadeIn 1s ease-out;
        }

        .footer a {
            color: var(--primary);
            text-decoration: none;
            transition: var(--transition);
        }

        .footer a:hover {
            text-decoration: underline;
        }

        .particles {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: -1;
        }

        .particle {
            position: absolute;
            border-radius: 50%;
            background: rgba(67, 97, 238, 0.1);
            animation: float 15s infinite linear;
        }

        @keyframes float {
            0% { transform: translateY(0) translateX(0) rotate(0deg); }
            100% { transform: translateY(-100vh) translateX(100px) rotate(360deg); }
        }

        @media (max-width: 480px) {
            .container {
                padding: 0.5rem;
            }
            .card-body {
                padding: 1.8rem;
            }
            .card-header {
                padding: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <!-- Floating particles for background effect -->
    <div class="particles" id="particles"></div>
    
    <div class="container">
        <div class="card fade-in">
            <div class="card-header">
                <div class="logo">
                    <div class="logo-circle">
                        <i class="fas fa-school"></i>
                    </div>
                </div>
                Connexion au Magasin Scolaire
            </div>
            <div class="card-body">
                <?php if ($error): ?>
                    <div class="error">
                        <i class="fas fa-exclamation-circle"></i>
                        <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>
                <form id="login-form" method="POST" action="">
                    <div class="form-group">
                        <label for="email" class="form-label">Adresse Email</label>
                        <div class="input-wrapper">
                            <input type="email" id="email" name="email" class="form-control" required autocomplete="email" value="<?= htmlspecialchars($remember_email) ?>">
                            <div class="form-icon"><i class="fas fa-envelope"></i></div>
                        </div>
                    </div>
                    <div class="form-group password">
                        <label for="password" class="form-label">Mot de passe</label>
                        <div class="input-wrapper">
                            <input type="password" id="password" name="password" class="form-control" required autocomplete="current-password">
                            <div class="form-icon"><i class="fas fa-lock"></i></div>
                            <div class="form-icon toggle-password"><i class="fas fa-eye"></i></div>
                        </div>
                    </div>
                    <div class="options">
                        <div class="remember">
                            <input type="checkbox" id="remember" name="remember" <?= $remember_email ? 'checked' : '' ?>>
                            <label for="remember">Se souvenir de moi</label>
                        </div>
                    </div>
                    <button type="submit" class="btn">
                        <i class="fas fa-sign-in-alt"></i> Se connecter
                    </button>
                </form>
               
            </div>
        </div>
    </div>

    <div class="loading-overlay" id="loading-overlay">
        <div class="loading-spinner"></div>
        <div class="loading-text">Authentification en cours...</div>
        <div class="success-check" id="success-check"><i class="fas fa-check-circle"></i></div>
    </div>

    <script>
        // Create floating particles
        function createParticles() {
            const particlesContainer = document.getElementById('particles');
            const particleCount = 15;
            
            for (let i = 0; i < particleCount; i++) {
                const particle = document.createElement('div');
                particle.classList.add('particle');
                
                // Random size between 10px and 60px
                const size = Math.random() * 50 + 10;
                particle.style.width = `${size}px`;
                particle.style.height = `${size}px`;
                
                // Random position
                particle.style.left = `${Math.random() * 100}%`;
                particle.style.top = `${Math.random() * 100}%`;
                
                // Random animation duration
                const duration = Math.random() * 20 + 10;
                particle.style.animationDuration = `${duration}s`;
                
                // Random delay
                particle.style.animationDelay = `${Math.random() * 5}s`;
                
                // Random opacity
                particle.style.opacity = Math.random() * 0.4 + 0.1;
                
                particlesContainer.appendChild(particle);
            }
        }
        
        // Initialize particles on page load
        window.addEventListener('load', createParticles);
        
        // Form submission with AJAX
        document.getElementById('login-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            const form = e.target;
            const overlay = document.getElementById('loading-overlay');
            const spinner = document.querySelector('.loading-spinner');
            const loadingText = document.querySelector('.loading-text');
            const check = document.getElementById('success-check');
            
            // Show loading overlay
            overlay.style.display = 'flex';
            spinner.style.display = 'block';
            loadingText.style.display = 'block';
            check.style.display = 'none';
            
            // Remove any existing error messages
            const existingError = document.querySelector('.error');
            if (existingError) existingError.remove();
            
            // Send form data via AJAX
            try {
                const formData = new FormData(form);
                const response = await fetch(form.action, {
                    method: form.method,
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.success) {
                    // Show success animation after 1.5 seconds
                    setTimeout(() => {
                        spinner.style.display = 'none';
                        loadingText.style.display = 'none';
                        check.style.display = 'block';
                        
                        // Update loading text to success message
                        loadingText.textContent = 'Connexion réussie ! Redirection...';
                        loadingText.style.display = 'block';
                        
                        // Redirect after another 1.5 seconds
                        setTimeout(() => {
                            window.location.href = data.redirect;
                        }, 1500);
                    }, 1500);
                } else {
                    // Hide overlay and show error
                    overlay.style.display = 'none';
                    const errorDiv = document.createElement('div');
                    errorDiv.className = 'error';
                    errorDiv.innerHTML = `<i class="fas fa-exclamation-circle"></i> ${data.error || 'Une erreur est survenue.'}`;
                    form.prepend(errorDiv);
                    
                    // Add shake animation to form
                    form.classList.add('shake');
                    setTimeout(() => form.classList.remove('shake'), 500);
                }
            } catch (err) {
                // Hide overlay and show error
                overlay.style.display = 'none';
                const errorDiv = document.createElement('div');
                errorDiv.className = 'error';
                errorDiv.innerHTML = `<i class="fas fa-exclamation-circle"></i> Erreur de connexion au serveur.`;
                form.prepend(errorDiv);
            }
        });
        
        // Toggle password visibility
        const togglePassword = document.querySelector('.toggle-password');
        const passwordInput = document.getElementById('password');
        togglePassword.addEventListener('click', () => {
            const type = passwordInput.type === 'password' ? 'text' : 'password';
            passwordInput.type = type;
            togglePassword.querySelector('i').classList.toggle('fa-eye');
            togglePassword.querySelector('i').classList.toggle('fa-eye-slash');
        });
        
        // Add shake animation to form on error
        const form = document.getElementById('login-form');
        form.classList.remove('shake');
    </script>
</body>
</html>