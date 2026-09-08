<?php
// Informations de connexion PostgreSQL
$host = '127.0.0.1';       // IP ou localhost du serveur
$port = '5432';            // Port PostgreSQL
$dbname = 'school';        // Nom de la base de données
$user = 'userdbSchool';         // Nom d'utilisateur
$password = 'stratus05@1993'; // Mot de passe

try {
    // Création d'une instance PDO pour PostgreSQL
    $dsn = "pgsql:host=$host;port=$port;dbname=$dbname";
    $pdo = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);

    echo "✅ Connexion à la base de données réussie !";

} catch (PDOException $e) {
    echo "❌ Échec de la connexion : " . $e->getMessage();
}
?>
