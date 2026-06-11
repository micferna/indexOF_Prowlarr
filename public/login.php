<?php

declare(strict_types=1);

require __DIR__ . '/../src/config.php';
require __DIR__ . '/../src/functions.php';
require __DIR__ . '/../src/auth.php';

$config = load_config();
start_session();

header(
    "Content-Security-Policy: default-src 'self'; img-src 'self' data:; "
    . "style-src 'self'; script-src 'self'; form-action 'self'; "
    . "base-uri 'none'; frame-ancestors 'none'"
);
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');

// Pas d'auth configurée, ou déjà connecté : on file vers l'app.
if (!auth_enabled($config) || is_authenticated($config)) {
    header('Location: index.php');
    exit;
}

$error = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!verify_csrf($_POST['csrf'] ?? null)) {
        $error = 'Session expirée, réessayez.';
    } elseif (check_password($config, (string) ($_POST['password'] ?? ''))) {
        session_regenerate_id(true);
        $_SESSION['auth'] = true;
        header('Location: index.php');
        exit;
    } else {
        $error = 'Mot de passe incorrect.';
    }
}
$token = csrf_token();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="dark">
    <title>indexOF · Connexion</title>
    <link rel="stylesheet" href="assets/app.css">
</head>
<body>
    <div class="aurora" aria-hidden="true"></div>
    <div class="login-wrap">
        <form method="post" class="login-card">
            <div class="brand"><span class="brand-mark">⌕</span><h1>index<span class="grad">OF</span></h1></div>
            <p class="tagline">Connexion requise</p>
            <input type="hidden" name="csrf" value="<?php echo e($token); ?>">
            <input type="password" name="password" placeholder="Mot de passe" autofocus required
                   class="login-input">
            <?php if ($error !== null): ?>
                <p class="login-error"><?php echo e($error); ?></p>
            <?php endif; ?>
            <button type="submit" class="btn-primary login-btn">Se connecter</button>
        </form>
    </div>
</body>
</html>
