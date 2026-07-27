<?php

declare(strict_types=1);

/**
 * Proxy de téléchargement de fichiers .torrent.
 *
 * L'URL réelle n'est jamais exposée : le client présente un jeton scellé par le
 * serveur, seul capable de l'ouvrir. La récupération elle-même, avec toutes ses
 * défenses anti-SSRF, vit dans src/TorrentFetcher.php — partagée avec l'aperçu
 * du contenu pour qu'elles ne puissent pas diverger.
 */

require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/Store.php';
require_once __DIR__ . '/../src/TorrentFetcher.php';

$config = load_config();

// Un lecteur RSS ne peut pas ouvrir de session : le jeton du flux tient lieu
// d'autorisation. Il ne donne accès qu'à ce que le flux propose déjà, et le
// lien lui-même reste scellé par le serveur.
$feed = (string) ($_GET['feed'] ?? '');
if ($feed !== '') {
    if (!(new Store($config['db_file']))->feedTokenExists($feed)) {
        fail(403, 'Flux inconnu.');
    }
} else {
    require_auth($config, 'html');
}

security_headers();

function fail(int $code, string $message): never
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
    exit;
}

// Jeton opaque chiffré (l'URL réelle — clé API Prowlarr incluse — n'est jamais
// exposée au navigateur). Seul le serveur peut l'ouvrir.
$token = (string) ($_GET['token'] ?? '');
if ($token === '') {
    fail(400, "Requête invalide : jeton manquant.");
}
$url = open_url($token, $config['secret']);
if ($url === null) {
    fail(403, "Lien invalide ou expiré — relancez la recherche.");
}

try {
    $body = fetch_torrent($url, $config);
} catch (TorrentFetchError $e) {
    fail($e->getCode() >= 400 ? $e->getCode() : 502, $e->getMessage());
}

// Nom de fichier dérivé de l'URL, assaini.
$basename = basename((string) parse_url($url, PHP_URL_PATH));
$filename = preg_replace('/[^A-Za-z0-9._-]/', '_', $basename) ?: 'file';
if (!str_ends_with(strtolower($filename), '.torrent')) {
    $filename .= '.torrent';
}

header('Content-Type: application/x-bittorrent');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($body));
echo $body;
