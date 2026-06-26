<?php
// Enable strict error reporting
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/profile_errors.log');

// Start secure session
session_start([
    'cookie_lifetime' => 86400,
    'cookie_secure' => true,
    'cookie_httponly' => true,
    'cookie_samesite' => 'Strict',
    'use_strict_mode' => true
]);

// Regenerate session ID to prevent fixation
if (!isset($_SESSION['initiated'])) {
    session_regenerate_id(true);
    $_SESSION['initiated'] = true;
}

// Redirect to login if not authenticated or unauthorized role
if (!isset($_SESSION['user_role']) || !isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// Include configuration
require_once 'config.php';



// Generate and validate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function validateCsrfToken() {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        error_log("CSRF token validation failed");
        die("Erreur de sécurité. Veuillez rafraîchir la page et réessayer.");
    }
    // Regenerate CSRF token after successful validation
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Fetch user data
$user_id = (int)$_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT username, role, profile_picture FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if (!$user) {
    error_log("User not found for ID: $user_id");
    header("Location: logout.php");
    exit;
}

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_profile') {
    validateCsrfToken();

    try {
        $pdo->beginTransaction();

        $username = substr(strip_tags($_POST['username']), 0, 50);

        // Validate username
        if (strlen($username) < 3 || strlen($username) > 50) {
            $_SESSION['error_message'] = "Le pseudonyme doit contenir entre 3 et 50 caractères.";
        } else {
            // Check username uniqueness
            $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
            $stmt->execute([$username, $user_id]);
            if ($stmt->fetch()) {
                $_SESSION['error_message'] = "Ce pseudonyme est déjà utilisé.";
            } else {
                // Update username
                $stmt = $pdo->prepare("UPDATE users SET username = ? WHERE id = ?");
                $stmt->execute([$username, $user_id]);
                $_SESSION['username'] = $username;

                // Handle profile picture upload
                if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
                    $file = $_FILES['profile_picture'];
                    $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
                    $max_size = 2 * 1024 * 1024; // 2MB

                    if (!in_array($file['type'], $allowed_types) || $file['size'] > $max_size) {
                        $_SESSION['error_message'] = "Image invalide (format: JPEG/PNG/GIF, taille max: 2MB).";
                    } else {
                        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
                        $filename = 'profile_' . $user_id . '_' . time() . '.' . $ext;
                        $upload_path = './Uploads/' . $filename;

                        if (move_uploaded_file($file['tmp_name'], $upload_path)) {
                            // Delete old profile picture if exists
                            if ($user['profile_picture'] && file_exists('./Uploads/' . $user['profile_picture'])) {
                                unlink('./Uploads/' . $user['profile_picture']);
                            }

                            $stmt = $pdo->prepare("UPDATE users SET profile_picture = ? WHERE id = ?");
                            $stmt->execute([$filename, $user_id]);
                            $user['profile_picture'] = $filename;
                            $_SESSION['success_message'] = "Pseudonyme et image mis à jour avec succès.";
                        } else {
                            $_SESSION['error_message'] = "Erreur lors du téléchargement de l'image.";
                        }
                    }
                } else {
                    $_SESSION['success_message'] = "Pseudonyme mis à jour avec succès.";
                }
            }
        }

        $pdo->commit();
        header("Location: profil.php");
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Profile update failed: " . $e->getMessage());
        $_SESSION['error_message'] = "Une erreur s'est produite. Veuillez réessayer.";
        header("Location: profil.php");
        exit;
    }
}

// Display messages
$success_message = $_SESSION['success_message'] ?? null;
$error_message = $_SESSION['error_message'] ?? null;
unset($_SESSION['success_message'], $_SESSION['error_message']);
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profil Employé - Magasin Scolaire</title>
    <link rel="icon" href="favicon-32x32.png" sizes="32x32" type="image/png">
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #1e40af;
            --primary-light: #3b82f6;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --gray: #6b7280;
            --light-gray: #e5e7eb;
            --card-shadow: 0 6px 16px rgba(0,0,0,0.1);
            --transition: all 0.3s ease;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        body {
            background: linear-gradient(to bottom, #f3f4f6, #e5e7eb);
            color: #1f2937;
            font-size: 15px;
        }

        .main-container {
            max-width: 600px;
            margin: 0 auto;
            padding: 24px;
        }

        .header {
            background: linear-gradient(135deg, var(--primary), var(--primary-light));
            padding: 24px;
            border-radius: 16px;
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
            position: relative;
            overflow: hidden;
        }

        .header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: radial-gradient(circle at 10% 20%, rgba(255,255,255,0.2), transparent);
            opacity: 0.3;
        }

        .header h1 {
            font-size: 28px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .profile-card {
            background: white;
            border-radius: 16px;
            box-shadow: var(--card-shadow);
            padding: 24px;
            animation: fadeIn 0.5s ease-out;
        }

        .profile-picture {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid var(--light-gray);
            transition: var(--transition);
        }

        .profile-picture:hover {
            transform: scale(1.05);
            border-color: var(--primary);
        }

        .image-upload-container {
            border: 2px dashed var(--light-gray);
            border-radius: 12px;
            padding: 16px;
            text-align: center;
            margin-top: 16px;
            transition: var(--transition);
        }

        .image-upload-container:hover {
            border-color: var(--primary);
            background: rgba(59, 130, 246, 0.05);
        }

        .image-upload-container.dragover {
            border-color: var(--success);
            background: rgba(16, 185, 129, 0.1);
        }

        .preview-img {
            max-width: 100%;
            max-height: 80px;
            border-radius: 8px;
            margin-top: 12px;
            border: 1px solid var(--light-gray);
        }

        .btn-primary {
            background: linear-gradient(145deg, var(--primary), var(--primary-light));
            border: none;
            transition: var(--transition);
            padding: 10px 16px;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(30, 64, 175, 0.3);
        }

        .btn-outline-primary {
            color: var(--primary);
            border-color: var(--primary);
            transition: var(--transition);
            padding: 10px 16px;
        }

        .btn-outline-primary:hover {
            background: var(--primary);
            color: white;
            transform: translateY(-2px);
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @media (max-width: 768px) {
            .main-container {
                padding: 16px;
            }

            .profile-picture {
                width: 80px;
                height: 80px;
            }
        }
    </style>
</head>
<body>
    <div class="main-container fade-in">
        <div class="header">
            <h1><i class="fas fa-user-circle"></i> Modifier Profil</h1>
            <button type="button" class="btn btn-outline-primary" onclick="history.back()">
                <i class="fas fa-arrow-left mr-2"></i> Retour
            </button>
        </div>

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

        <div class="profile-card">
            <div class="flex flex-col items-center">
                <div class="text-center mb-6">
                    <?php if (!empty($user['profile_picture'])): ?>
                        <img src="./Uploads/<?= htmlspecialchars($user['profile_picture']) ?>" alt="Profile Picture" class="profile-picture mb-4">
                    <?php else: ?>
                        <i class="fas fa-user-circle text-6xl text-gray-400 mb-4"></i>
                    <?php endif; ?>
                    <h2 class="text-xl font-semibold mb-2"><?= htmlspecialchars($user['username']) ?></h2>
                    <p class="text-sm text-gray-600">Rôle: <?= ucfirst($user['role']) ?></p>
                </div>
                <form method="POST" enctype="multipart/form-data" class="w-full max-w-md">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <input type="hidden" name="action" value="update_profile">
                    <div class="mb-4">
                        <label class="block text-sm font-semibold mb-2">Pseudonyme</label>
                        <input type="text" name="username" value="<?= htmlspecialchars($user['username']) ?>" class="form-control w-full p-2 border rounded-lg" required minlength="3" maxlength="50">
                    </div>
                    <div class="image-upload-container" id="imageUploadContainer">
                        <p class="text-sm mb-2">Ajouter/Modifier la photo ou l'icône de profil</p>
                        <input type="file" id="profilePictureInput" name="profile_picture" accept="image/*" class="hidden">
                        <button type="button" class="btn btn-outline-primary btn-sm" onclick="document.getElementById('profilePictureInput').click()">
                            <i class="fas fa-image mr-2"></i> Choisir une image
                        </button>
                        <img id="previewImage" class="preview-img hidden" src="" alt="Aperçu de l'image">
                    </div>
                    <div class="flex gap-2 mt-6">
                        <button type="submit" class="btn btn-primary flex-grow">
                            <i class="fas fa-save mr-2"></i> Enregistrer
                        </button>
                        <button type="button" class="btn btn-outline-primary flex-grow" onclick="history.back()">
                            <i class="fas fa-arrow-left mr-2"></i> Retour
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const profilePictureInput = document.getElementById('profilePictureInput');
            const previewImage = document.getElementById('previewImage');
            const imageUploadContainer = document.getElementById('imageUploadContainer');

            // Handle file input change
            profilePictureInput.addEventListener('change', function(e) {
                const file = e.target.files[0];
                if (file) {
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        previewImage.src = e.target.result;
                        previewImage.classList.remove('hidden');
                    };
                    reader.readAsDataURL(file);
                }
            });

            // Drag and drop handling
            ['dragover', 'dragenter'].forEach(event => {
                imageUploadContainer.addEventListener(event, (e) => {
                    e.preventDefault();
                    imageUploadContainer.classList.add('dragover');
                });
            });

            ['dragleave', 'dragend'].forEach(event => {
                imageUploadContainer.addEventListener(event, () => {
                    imageUploadContainer.classList.remove('dragover');
                });
            });

            imageUploadContainer.addEventListener('drop', (e) => {
                e.preventDefault();
                imageUploadContainer.classList.remove('dragover');
                const file = e.dataTransfer.files[0];
                if (file) {
                    profilePictureInput.files = e.dataTransfer.files;
                    profilePictureInput.dispatchEvent(new Event('change'));
                }
            });
        });
    </script>
</body>
</html>