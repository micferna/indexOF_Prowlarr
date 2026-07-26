<?php

declare(strict_types=1);

/**
 * Envoie un torrent (URL .torrent signée, ou lien magnet) vers qBittorrent.
 * POST uniquement. Protégé par auth + CSRF ; les URLs HTTP doivent être signées.
 */

require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/QbittorrentClient.php';

$config = load_config();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
security_headers();

require_auth($config, 'json');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_response(['error' => 'Méthode non autorisée.'], 405);
}
if (!verify_csrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf'] ?? null))) {
    json_response(['error' => 'Jeton CSRF invalide.'], 403);
}
if (!QbittorrentClient::isConfigured($config['qbit_url'])) {
    json_response(['error' => 'qBittorrent n\'est pas configuré.'], 400);
}

// Jeton opaque chiffré scellant la cible (magnet ou .torrent HTTP) réellement
// proposée par l'app : empêche l'injection d'un torrent arbitraire ET cache la
// clé API Prowlarr embarquée dans les liens HTTP.
$token = (string) ($_POST['token'] ?? '');
$category = trim((string) ($_POST['category'] ?? '')) ?: null;

if ($token === '') {
    json_response(['error' => 'Requête invalide.'], 400);
}
$target = open_url($token, $config['secret']);
if ($target === null) {
    json_response(['error' => 'Lien invalide ou expiré — relancez la recherche.'], 403);
}

if (str_starts_with($target, 'magnet:')) {
    // Magnet (identifiant public) issu d'un jeton de l'app : accepté.
} elseif (safe_url($target) !== '#') {
    // .torrent HTTP : c'est qBittorrent qui ira le récupérer. On valide que la
    // cible n'est pas une IP interne/réservée (anti-SSRF via qBittorrent), sauf
    // l'hôte Prowlarr de confiance (admin-configuré, souvent en IP privée).
    $host = (string) parse_url($target, PHP_URL_HOST);
    $trustedHost = (string) parse_url($config['base_url'], PHP_URL_HOST);
    $allowed = $host !== '' && resolve_to_public_ip($host) !== null;
    if (!$allowed && $trustedHost !== '' && strcasecmp($host, $trustedHost) === 0) {
        $allowed = true;
    }
    if (!$allowed) {
        json_response(['error' => 'Cible interdite (adresse interne/réservée).'], 403);
    }
} else {
    json_response(['error' => 'URL non autorisée.'], 400);
}

$qbit = new QbittorrentClient(
    $config['qbit_url'],
    $config['qbit_user'],
    $config['qbit_pass'],
    $config['timeout'],
);

try {
    $qbit->add($target, $category);
    json_response(['ok' => true]);
} catch (Throwable $e) {
    error_log('[indexof] qbit error: ' . $e->getMessage());
    json_response(['error' => "Échec de l'envoi à qBittorrent."], 502);
}
