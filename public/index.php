<?php

declare(strict_types=1);

require __DIR__ . '/../src/config.php';
require __DIR__ . '/../src/functions.php';
require __DIR__ . '/../src/auth.php';

$config = load_config();
require_auth($config, 'html');

header(
    "Content-Security-Policy: default-src 'self'; img-src 'self' data:; "
    . "style-src 'self'; script-src 'self'; connect-src 'self'; "
    . "form-action 'self'; base-uri 'none'; frame-ancestors 'none'"
);
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');

$csrf = csrf_token();
$showLogout = auth_enabled($config);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="dark">
    <meta name="csrf" content="<?php echo e($csrf); ?>">
    <title>indexOF · Recherche Prowlarr</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🔍</text></svg>">
    <link rel="stylesheet" href="assets/app.css">
</head>
<body>
    <div class="aurora" aria-hidden="true"></div>

    <div class="topbar">
        <span id="status" class="status" title="État de la connexion">
            <span class="status-dot"></span><span class="status-text">…</span>
        </span>
        <?php if ($showLogout): ?>
            <a href="logout.php" class="logout">Déconnexion</a>
        <?php endif; ?>
    </div>

    <header class="hero">
        <div class="brand">
            <span class="brand-mark">⌕</span>
            <h1>index<span class="grad">OF</span></h1>
        </div>
        <p class="tagline">Recherche unifiée sur vos indexeurs Prowlarr</p>

        <form id="search-form" class="command-bar" autocomplete="off">
            <svg class="cb-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M21 21l-4.3-4.3M11 19a8 8 0 1 1 0-16 8 8 0 0 1 0 16z"/></svg>
            <input id="q" name="q" type="search" placeholder="Rechercher un titre…" aria-label="Recherche" list="history">
            <datalist id="history"></datalist>
            <button type="submit" class="btn-primary"><span class="btn-label">Rechercher</span></button>
        </form>

        <div class="controls">
            <div id="days" class="segmented" role="group" aria-label="Ancienneté"></div>
            <button type="button" id="mask-toggle" class="mask-btn" aria-pressed="false"
                    title="Masquer / révéler les noms d'indexeurs">
                <svg class="ic-eye" viewBox="0 0 24 24" aria-hidden="true"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"/><circle cx="12" cy="12" r="3"/></svg>
                <svg class="ic-eye-off" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 3l18 18M10.6 10.6a3 3 0 0 0 4.2 4.2M9.9 5.1A10.9 10.9 0 0 1 12 5c7 0 11 7 11 7a18.5 18.5 0 0 1-3.2 4M6.6 6.6A18.6 18.6 0 0 0 1 12s4 7 11 7a10.9 10.9 0 0 0 3.4-.5"/></svg>
                <span class="mask-label">Indexeurs</span>
            </button>
        </div>

        <div id="categories" class="chips chips-cat" aria-label="Filtrer par catégorie"></div>
        <div id="trackers" class="chips" aria-label="Filtrer par indexeur"></div>
    </header>

    <main id="results" class="results" aria-live="polite"></main>

    <noscript><p style="text-align:center;color:#9aa">JavaScript est requis pour cette application.</p></noscript>

    <script src="assets/app.js" defer></script>
</body>
</html>
