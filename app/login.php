<?php
require_once __DIR__ . '/../config/services.php';

if (auth()->check()) {
    header('Location: index.php');
    exit;
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = (string) ($_POST['password'] ?? '');

    if ($email !== '' && $password !== '' && auth()->attempt($email, $password)) {
        header('Location: index.php');
        exit;
    }

    $lockedFor = auth()->lockedForSeconds();
    if ($lockedFor !== null) {
        $minutes = (int) ceil($lockedFor / 60);
        $error = "Trop de tentatives échouées. Compte verrouillé, réessayez dans $minutes minute" . ($minutes > 1 ? 's' : '') . ".";
    } else {
        $error = "Email ou mot de passe incorrect.";
    }
}
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Connexion | SMS_ORANGE</title>
    <link rel="stylesheet" href="../assets/css/plugins/bootstrap.min.css" />
    <style>
        body { min-height: 100vh; display: flex; align-items: center; justify-content: center; background: linear-gradient(135deg, #ff7900 0%, #ff9e40 100%); }
        .login-card { max-width: 380px; width: 100%; }
        .btn-primary { --bs-btn-bg: #ff7900; --bs-btn-border-color: #ff7900; --bs-btn-hover-bg: #d96700; --bs-btn-hover-border-color: #d96700; --bs-btn-active-bg: #d96700; --bs-btn-active-border-color: #d96700; }
    </style>
</head>
<body>
    <div class="card login-card shadow-lg border-0">
        <div class="card-body p-4">
            <h4 class="text-center mb-1">SMS_ORANGE</h4>
            <p class="text-center text-muted mb-4">Connexion administrateur</p>
            <?php if ($error): ?>
                <div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <form method="post">
                <div class="mb-3">
                    <label for="email" class="form-label">Email</label>
                    <input type="email" id="email" name="email" class="form-control" required autofocus>
                </div>
                <div class="mb-3">
                    <label for="password" class="form-label">Mot de passe</label>
                    <input type="password" id="password" name="password" class="form-control" required>
                </div>
                <div class="d-grid">
                    <button type="submit" class="btn btn-primary">Se connecter</button>
                </div>
            </form>
        </div>
    </div>
</body>
</html>
