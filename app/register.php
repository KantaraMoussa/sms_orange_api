<?php
require_once __DIR__ . '/../config/services.php';

if (auth()->check()) {
    header('Location: index.php?page=dashdoards');
    exit;
}

$error = null;
$old = ['organisation_nom' => '', 'secteur' => '', 'nom' => '', 'email' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old['organisation_nom'] = trim($_POST['organisation_nom'] ?? '');
    $old['secteur'] = trim($_POST['secteur'] ?? '');
    $old['nom'] = trim($_POST['nom'] ?? '');
    $old['email'] = trim($_POST['email'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');

    if ($old['organisation_nom'] === '' || $old['nom'] === '' || $old['email'] === '' || $password === '') {
        $error = 'Tous les champs marqués * sont obligatoires.';
    } elseif (!filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
        $error = 'Adresse email invalide.';
    } elseif (strlen($password) < 8) {
        $error = 'Le mot de passe doit contenir au moins 8 caractères.';
    } elseif ($password !== $passwordConfirm) {
        $error = 'Les deux mots de passe ne correspondent pas.';
    } else {
        try {
            auth()->registerOrganization(
                ['nom' => $old['organisation_nom'], 'secteur' => $old['secteur'] ?: null, 'email' => $old['email']],
                $old['nom'],
                $old['email'],
                $password
            );
            auth()->attempt($old['email'], $password);
            activityLog()->log('creation_organisation', null, $old['nom'], $old['organisation_nom']);
            header('Location: index.php?page=dashdoards');
            exit;
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Créer votre entreprise | SMS_ORANGE</title>
    <link rel="icon" href="../assets/images/favicon.svg" type="image/svg+xml" />
    <link rel="stylesheet" href="../assets/css/plugins/bootstrap.min.css" />
    <style>
        body { min-height: 100vh; display: flex; align-items: center; justify-content: center; background: linear-gradient(135deg, #ff7900 0%, #ff9e40 100%); padding: 24px 0; }
        .register-card { max-width: 460px; width: 100%; }
        .btn-primary { --bs-btn-bg: #ff7900; --bs-btn-border-color: #ff7900; --bs-btn-hover-bg: #d96700; --bs-btn-hover-border-color: #d96700; --bs-btn-active-bg: #d96700; --bs-btn-active-border-color: #d96700; }
    </style>
</head>
<body>
    <div class="card register-card shadow-lg border-0">
        <div class="card-body p-4">
            <div class="text-center mb-2"><img src="../assets/images/sms-orange-logo.svg" alt="" width="48" height="48"></div>
            <h4 class="text-center mb-1">Créer votre entreprise</h4>
            <p class="text-center text-muted mb-4">Votre espace SMS_ORANGE, prêt en une minute.</p>
            <?php if ($error): ?>
                <div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <form method="post">
                <div class="mb-3">
                    <label for="organisation_nom" class="form-label">Nom de l'entreprise *</label>
                    <input type="text" id="organisation_nom" name="organisation_nom" class="form-control" value="<?= htmlspecialchars($old['organisation_nom']) ?>" required autofocus>
                </div>
                <div class="mb-3">
                    <label for="secteur" class="form-label">Secteur d'activité</label>
                    <input type="text" id="secteur" name="secteur" class="form-control" placeholder="Ex. Éducation, Commerce, Santé…" value="<?= htmlspecialchars($old['secteur']) ?>">
                </div>
                <hr>
                <div class="mb-3">
                    <label for="nom" class="form-label">Votre nom *</label>
                    <input type="text" id="nom" name="nom" class="form-control" value="<?= htmlspecialchars($old['nom']) ?>" required>
                </div>
                <div class="mb-3">
                    <label for="email" class="form-label">Votre email *</label>
                    <input type="email" id="email" name="email" class="form-control" value="<?= htmlspecialchars($old['email']) ?>" required>
                </div>
                <div class="mb-3">
                    <label for="password" class="form-label">Mot de passe *</label>
                    <input type="password" id="password" name="password" class="form-control" minlength="8" required>
                </div>
                <div class="mb-3">
                    <label for="password_confirm" class="form-label">Confirmer le mot de passe *</label>
                    <input type="password" id="password_confirm" name="password_confirm" class="form-control" minlength="8" required>
                </div>
                <div class="d-grid">
                    <button type="submit" class="btn btn-primary">Créer mon entreprise</button>
                </div>
            </form>
            <p class="text-center text-muted mt-3 mb-0 small">Déjà inscrit ? <a href="login.php">Se connecter</a></p>
        </div>
    </div>
</body>
</html>
