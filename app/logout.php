<?php
require_once __DIR__ . '/../config/services.php';
auth()->logout();
header('Location: login.php');
exit;
