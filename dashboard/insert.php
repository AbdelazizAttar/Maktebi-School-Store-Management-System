<?php
// Database connection
$pdo = new PDO("mysql:host=localhost;dbname=magasin;charset=utf8", "root", "");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// 1️⃣ Ensure categories exist and get IDs
$categories = ['Alimentation', 'Boissons', 'Électronique', 'Vêtements', 'Maison'];
$categoryIds = [];

// Check existing categories
$stmt = $pdo->query("SELECT id, nom FROM categories");
$existingCategories = $stmt->fetchAll(PDO::FETCH_KEY_PAIR); // id => nom

foreach ($categories as $catName) {
    if (!in_array($catName, $existingCategories)) {
        $stmtInsert = $pdo->prepare("INSERT INTO categories (nom) VALUES (?)");
        $stmtInsert->execute([$catName]);
        $categoryIds[] = $pdo->lastInsertId();
    } else {
        // Add existing category IDs
        $id = array_search($catName, $existingCategories);
        $categoryIds[] = $id;
    }
}

// 2️⃣ Insert random products
$numberOfProducts = 2000; // change to 500 if needed
for ($i = 1; $i <= $numberOfProducts; $i++) {

    $nom = "Produit_" . $i . "_" . rand(1000, 999999);
    $description = "Description pour " . $nom;
    $prix_achat = rand(5, 100);
    $prix_vente = $prix_achat + rand(1, 50);
    $quantite_stock = rand(0, 50);
    $categorie_id = $categoryIds[array_rand($categoryIds)]; // pick a valid category
    $stock = $quantite_stock;
    $code_barres = strval(rand(1000000000000, 9999999999999));
    $quantite = rand(1, 100);
    $seuil_alerte = rand(1, 10);
    $fournisseur = "Fournisseur_" . rand(1, 20);
    $image = "product_" . $i . "_" . rand(1000000, 9999999) . ".png";
    $date_creation = date('Y-m-d H:i:s');

    $stmt = $pdo->prepare("
        INSERT INTO produits 
        (nom, description, prix_achat, prix_vente, quantite_stock, categorie_id, date_creation, stock, code_barres, quantite, seuil_alerte, fournisseur, image)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    $stmt->execute([
        $nom, $description, $prix_achat, $prix_vente, $quantite_stock, 
        $categorie_id, $date_creation, $stock, $code_barres, $quantite, 
        $seuil_alerte, $fournisseur, $image
    ]);
}

echo "✅ $numberOfProducts random products inserted successfully!";
?>
