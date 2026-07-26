<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/auth.php';

start_session();

// CSRF : sans jeton valide, un `<img src="logout.php">` sur un site tiers
// déconnecterait l'utilisateur. Nuisance mineure, mais le garde-fou est gratuit.
if (!verify_csrf($_GET['csrf'] ?? ($_POST['csrf'] ?? null))) {
    header('Location: index.php');
    exit;
}

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    $name = (string) session_name();
    setcookie($name, '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
}
session_destroy();

header('Location: login.php');
exit;
