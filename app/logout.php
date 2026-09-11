<?php
require_once __DIR__ . '/../config/services.php';
if (auth()->check()) {
    activityLog()->log('deconnexion', null, auth()->user()['nom'] ?? null);
}
auth()->logout();
header('Location: login.php');
exit;
