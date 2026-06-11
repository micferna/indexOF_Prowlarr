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
<body class="login-body">
    <div class="aurora" aria-hidden="true"></div>
    <div class="login-glow" aria-hidden="true"></div>

    <main class="login-wrap">
        <form method="post" class="login-card<?php echo $error !== null ? ' shake' : ''; ?>">
            <div class="login-mark">⌕</div>
            <h1 class="login-title">index<span class="grad">OF</span></h1>
            <p class="login-sub">Espace privé — connexion requise</p>

            <input type="hidden" name="csrf" value="<?php echo e($token); ?>">

            <div class="field">
                <svg class="field-ic" viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="11" width="16" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>
                <input id="pw" type="password" name="password" placeholder="Mot de passe"
                       autocomplete="current-password" autofocus required class="field-input">
                <button type="button" id="reveal" class="reveal" aria-label="Afficher le mot de passe">
                    <svg class="ic-eye" viewBox="0 0 24 24" aria-hidden="true"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"/><circle cx="12" cy="12" r="3"/></svg>
                    <svg class="ic-eye-off" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 3l18 18M10.6 10.6a3 3 0 0 0 4.2 4.2M9.9 5.1A10.9 10.9 0 0 1 12 5c7 0 11 7 11 7a18.5 18.5 0 0 1-3.2 4M6.6 6.6A18.6 18.6 0 0 0 1 12s4 7 11 7a10.9 10.9 0 0 0 3.4-.5"/></svg>
                </button>
            </div>

            <?php if ($error !== null): ?>
                <p class="login-error"><?php echo e($error); ?></p>
            <?php endif; ?>

            <button type="submit" class="btn-primary login-btn">
                <span>Se connecter</span>
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
            </button>
        </form>
        <p class="login-foot">Recherche unifiée sur vos indexeurs Prowlarr</p>
    </main>

    <script src="assets/login.js" defer></script>
</body>
</html>
