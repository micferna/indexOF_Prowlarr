<?php

declare(strict_types=1);

/**
 * Envoie un torrent (URL .torrent signée, ou lien magnet) vers qBittorrent.
 * POST uniquement. Protégé par auth + CSRF ; les URLs HTTP doivent être signées.
 */

require __DIR__ . '/../src/config.php';
require __DIR__ . '/../src/functions.php';
require __DIR__ . '/../src/auth.php';
require __DIR__ . '/../src/QbittorrentClient.php';

$config = load_config();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

require_auth($config, 'json');

/** @param array<string,mixed> $data */
function send_out(array $data, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    send_out(['error' => 'Méthode non autorisée.'], 405);
}
if (!verify_csrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf'] ?? null))) {
    send_out(['error' => 'Jeton CSRF invalide.'], 403);
}
if (!QbittorrentClient::isConfigured($config['qbit_url'])) {
    send_out(['error' => 'qBittorrent n\'est pas configuré.'], 400);
}

$url = (string) ($_POST['url'] ?? '');
$sig = (string) ($_POST['sig'] ?? '');
$category = trim((string) ($_POST['category'] ?? '')) ?: null;

if ($url === '') {
    send_out(['error' => 'URL manquante.'], 400);
}

// Un magnet est un identifiant public (pas de proxy) : accepté tel quel.
// Une URL HTTP doit être signée par l'application (anti-injection d'URL arbitraire).
if (str_starts_with($url, 'magnet:')) {
    $target = $url;
} elseif (safe_url($url) !== '#') {
    if (!verify_url_signature($url, $sig, $config['secret'])) {
        send_out(['error' => 'Signature invalide.'], 403);
    }
    $target = $url;
} else {
    send_out(['error' => 'URL non autorisée.'], 400);
}

$qbit = new QbittorrentClient(
    $config['qbit_url'],
    $config['qbit_user'],
    $config['qbit_pass'],
    $config['timeout'],
);

try {
    $qbit->add($target, $category);
    send_out(['ok' => true]);
} catch (Throwable $e) {
    send_out(['error' => $e->getMessage()], 502);
}
