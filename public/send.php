<?php

declare(strict_types=1);

/**
 * Envoie une release vers un client de téléchargement.
 *
 *   POST send.php  token=<jeton scellé>  [to=qbit|sonarr|radarr|lidarr|readarr]
 *
 * `to=qbit` ajoute directement le torrent à qBittorrent. Les autres cibles
 * poussent la release vers l'application *arr correspondante, qui la rapproche
 * de ce qu'elle suit, applique ses profils et la confie à son propre client.
 *
 * POST uniquement, protégé par authentification + CSRF. La cible réelle vient
 * toujours d'un jeton scellé par l'application : impossible d'injecter une URL
 * arbitraire.
 */

require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/QbittorrentClient.php';
require_once __DIR__ . '/../src/ArrClient.php';
require_once __DIR__ . '/../src/Store.php';

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

$to = (string) ($_POST['to'] ?? 'qbit');
if ($to !== 'qbit' && !ArrClient::isKnown($to)) {
    json_response(['error' => 'Destination inconnue.'], 400);
}
if ($to === 'qbit' && !QbittorrentClient::isConfigured($config['qbit_url'])) {
    json_response(['error' => "qBittorrent n'est pas configuré."], 400);
}
if ($to !== 'qbit' && !isset($config['arr'][$to])) {
    json_response(['error' => ArrClient::TARGETS[$to]['label'] . " n'est pas configuré."], 400);
}

// Jeton opaque chiffré scellant la cible (magnet ou .torrent HTTP) réellement
// proposée par l'app : empêche l'injection d'un torrent arbitraire ET cache la
// clé API Prowlarr embarquée dans les liens HTTP.
$token    = (string) ($_POST['token'] ?? '');
$category = trim((string) ($_POST['category'] ?? '')) ?: null;

// Une catégorie imposée à un compte prime sur celle choisie côté client :
// sinon la séparation des téléchargements ne tiendrait qu'à la bonne volonté
// du navigateur.
$store   = new Store($config['db_file']);
$imposee = $store->userCategory(current_user());
if ($imposee !== '') {
    $category = $imposee;
}

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
    // .torrent HTTP : c'est le client (qBittorrent ou l'app *arr) qui ira le
    // récupérer. On valide que la cible n'est pas une IP interne/réservée
    // (anti-SSRF par rebond), sauf l'hôte Prowlarr de confiance
    // (admin-configuré, souvent en IP privée).
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

/* ------------------------------------------------------------------ *
 * qBittorrent
 * ------------------------------------------------------------------ */
if ($to === 'qbit') {
    $qbit = new QbittorrentClient(
        $config['qbit_url'],
        $config['qbit_user'],
        $config['qbit_pass'],
        $config['qbit_timeout'],
    );

    try {
        $qbit->add($target, $category);
        // Mémorisé côté serveur : qBittorrent renomme les torrents et, sans
        // magnet, aucun hash n'est connu avant téléchargement. Sans cette trace,
        // impossible de savoir plus tard qu'on a déjà pris cette release.
        $store->recordSend(
            trim((string) ($_POST['title'] ?? '')),
            trim((string) ($_POST['indexer'] ?? '')),
            'qbit',
            '',
            current_user(),
        );
        json_response(['ok' => true, 'message' => 'Envoyé à qBittorrent']);
    } catch (Throwable $e) {
        error_log('[indexof] qbit error: ' . $e->getMessage());
        json_response(['error' => "Échec de l'envoi à qBittorrent."], 502);
    }
}

/* ------------------------------------------------------------------ *
 * Applications *arr
 *
 * Le titre, l'indexeur et la date servent au rapprochement côté Sonarr/Radarr.
 * Ils viennent du client : un utilisateur connecté pourrait les altérer, mais
 * il ne gagnerait rien qu'il ne puisse déjà faire en cherchant la release
 * lui-même — l'URL, elle, reste scellée par le serveur.
 * ------------------------------------------------------------------ */
$arr = $config['arr'][$to];
$title = trim((string) ($_POST['title'] ?? ''));
if ($title === '') {
    json_response(['error' => 'Titre de release manquant.'], 400);
}

$client = new ArrClient($arr['url'], $arr['key'], $arr['api'], $config['qbit_timeout']);

try {
    $message = $client->push(
        $title,
        $target,
        trim((string) ($_POST['indexer'] ?? '')),
        trim((string) ($_POST['publishDate'] ?? '')),
    );
    $store->recordSend(
        $title,
        trim((string) ($_POST['indexer'] ?? '')),
        $to,
        '',
        current_user(),
    );
    json_response(['ok' => true, 'message' => $arr['label'] . ' : ' . $message]);
} catch (Throwable $e) {
    error_log('[indexof] ' . $to . ' error: ' . $e->getMessage());
    json_response(['error' => "Échec de l'envoi à " . $arr['label'] . '.'], 502);
}
