<?php
require_once 'config.php'; // connexion $conn

// Création table utilisateurs
$sql = "CREATE TABLE IF NOT EXISTS utilisateurs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(100) NOT NULL UNIQUE,
    mot_de_passe VARCHAR(255) NOT NULL,
    role ENUM('admin','employe') NOT NULL,
    nom VARCHAR(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

if ($conn->query($sql) === TRUE) {
    echo "Table utilisateurs créée ou déjà existante.\n";
} else {
    die("Erreur création table : " . $conn->error);
}

// Insertion utilisateurs de base
$admin_email = 'admin@magasin.com';
$admin_mdp = password_hash('admin123', PASSWORD_DEFAULT);
$employe_email = 'employe@magasin.com';
$employe_mdp = password_hash('employe123', PASSWORD_DEFAULT);

$stmt = $conn->prepare("INSERT INTO utilisateurs (email, mot_de_passe, role, nom) VALUES (?, ?, ?, ?)");

// Admin
$stmt->bind_param("ssss", $admin_email, $admin_mdp, $role, $nom);
$role = 'admin';
$nom = 'Administrateur';
$stmt->execute();

// Employé
$stmt->bind_param("ssss", $employe_email, $employe_mdp, $role, $nom);
$role = 'employe';
$nom = 'Employé';
$stmt->execute();

echo "Utilisateurs insérés avec succès.\n";
?>
