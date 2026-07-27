<?php

declare(strict_types=1);

/**
 * Actions sur les transferts qBittorrent.
 *
 *   POST transfers.php  op=start|stop|delete  hash=<hash>  [files=1]
 *
 * POST uniquement, protégé par authentification + CSRF. La lecture de la liste
 * passe par `api.php?action=transfers` : ici on n'écrit que des actions.
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
    json_response(['error' => "qBittorrent n'est pas configuré."], 400);
}

$op = (string) ($_POST['op'] ?? '');
if (!in_array($op, ['start', 'stop', 'delete'], true)) {
    json_response(['error' => 'Action inconnue.'], 400);
}

// Un hash de torrent est un SHA-1 (40) ou SHA-256 (64) hexadécimal. Les valider
// un par un évite de relayer n'importe quoi à qBittorrent, « all » compris.
// Plusieurs hashes sont acceptés pour les actions groupées, séparés par « | »
// comme le fait l'API de qBittorrent.
$raw = strtolower(trim((string) ($_POST['hash'] ?? '')));
$hashes = [];
foreach (array_slice(explode('|', $raw), 0, 200) as $h) {
    $h = trim($h);
    if (preg_match('/^[a-f0-9]{40}([a-f0-9]{24})?$/', $h) === 1) {
        $hashes[] = $h;
    }
}
if ($hashes === []) {
    json_response(['error' => 'Torrent invalide.'], 400);
}
$hash = implode('|', $hashes);

$deleteFiles = ($_POST['files'] ?? '') === '1';

$qbit = new QbittorrentClient(
    $config['qbit_url'],
    $config['qbit_user'],
    $config['qbit_pass'],
    $config['qbit_timeout'],
);

$n = count($hashes);
$messages = [
    'start'  => $n > 1 ? "{$n} transferts relancés" : 'Transfert relancé',
    'stop'   => $n > 1 ? "{$n} transferts arrêtés" : 'Transfert arrêté',
    'delete' => $n > 1 ? "{$n} torrents supprimés" : 'Torrent supprimé',
];

try {
    $qbit->control($op, $hash, $deleteFiles);
    $message = $messages[$op];
    if ($op === 'delete' && $deleteFiles) {
        $message = $n > 1 ? "{$n} torrents et leurs fichiers supprimés" : 'Torrent et fichiers supprimés';
    }
    json_response(['ok' => true, 'message' => $message]);
} catch (Throwable $e) {
    error_log('[indexof] transfer error: ' . $e->getMessage());
    json_response(['error' => "Échec de l'action sur le transfert."], 502);
}
