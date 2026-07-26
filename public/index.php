<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/auth.php';

$config = load_config();
require_auth($config, 'html');

html_security_headers();

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

    <header class="appbar">
        <a class="brand-mini" href="index.php" aria-label="indexOF — accueil">
            <span class="brand-mark">⌕</span>
            <span class="brand-name">index<span class="grad">OF</span></span>
        </a>

        <form id="search-form" class="command-bar" autocomplete="off">
            <svg class="cb-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M21 21l-4.3-4.3M11 19a8 8 0 1 1 0-16 8 8 0 0 1 0 16z"/></svg>
            <input id="q" name="q" type="search" placeholder="Rechercher un titre…" aria-label="Recherche" list="history">
            <datalist id="history"></datalist>
            <button type="submit" class="btn-primary"><span class="btn-label">Rechercher</span></button>
        </form>

        <button type="button" id="top-btn" class="btn-top" title="Derniers uploads de tous les trackers, sans recherche">
            <span class="btn-top-fire" aria-hidden="true">🔥</span><span class="btn-top-label">Top</span>
        </button>

        <div class="filters-wrap">
            <button type="button" id="filters-btn" class="filters-btn" aria-expanded="false" aria-controls="filters-panel">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 5h18M6 12h12M10 19h4"/></svg>
                <span class="filters-lbl">Filtres</span><span id="filters-count" class="filters-count"></span>
                <span class="tk-caret" aria-hidden="true">▾</span>
            </button>
            <div id="filters-panel" class="filters-panel" hidden>
                <div class="fp-section">
                    <span class="fp-label">Période</span>
                    <div id="days" class="segmented" role="group" aria-label="Ancienneté"></div>
                </div>
                <div class="fp-section">
                    <span class="fp-label">Catégories</span>
                    <div id="categories" class="chips chips-cat" aria-label="Filtrer par catégorie"></div>
                </div>
                <div class="fp-section">
                    <span class="fp-label">Indexeurs</span>
                    <div id="trackers" class="chips" aria-label="Filtrer par indexeur"></div>
                </div>
                <div class="fp-foot">
                    <button type="button" id="mask-toggle" class="mask-btn" aria-pressed="false"
                            title="Masquer / révéler les noms d'indexeurs">
                        <svg class="ic-eye" viewBox="0 0 24 24" aria-hidden="true"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"/><circle cx="12" cy="12" r="3"/></svg>
                        <svg class="ic-eye-off" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 3l18 18M10.6 10.6a3 3 0 0 0 4.2 4.2M9.9 5.1A10.9 10.9 0 0 1 12 5c7 0 11 7 11 7a18.5 18.5 0 0 1-3.2 4M6.6 6.6A18.6 18.6 0 0 0 1 12s4 7 11 7a10.9 10.9 0 0 0 3.4-.5"/></svg>
                        <span class="mask-label">Masquer noms</span>
                    </button>
                    <button type="button" id="filters-reset" class="link-btn">Réinitialiser</button>
                </div>
            </div>
        </div>

        <div class="appbar-right">
            <span id="status" class="status" title="État de la connexion">
                <span class="status-dot"></span><span class="status-text">…</span>
            </span>
            <button type="button" id="safe-toggle" class="safe-btn" aria-pressed="true"
                    title="Contenu -18 masqué — cliquer pour l'afficher">🔞</button>
            <label id="qbit-cat-wrap" class="qbit-cat" hidden>
                <svg viewBox="0 0 24 24" aria-hidden="true" class="qbit-ic"><path d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z"/></svg>
                <select id="qbit-cat" aria-label="Catégorie qBittorrent"></select>
            </label>
            <?php if ($showLogout): ?>
                <a href="logout.php?csrf=<?php echo e($csrf); ?>" class="logout">Déconnexion</a>
            <?php endif; ?>
        </div>
    </header>

    <section id="facets" class="facets" hidden aria-label="Filtres"></section>
    <main id="results" class="results" aria-live="polite"></main>

    <noscript><p class="noscript">JavaScript est requis pour cette application.</p></noscript>

    <script src="assets/app.js" defer></script>
</body>
</html>
