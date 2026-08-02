<?php

declare(strict_types=1);

/**
 * Proxy d'affiches.
 *
 * Le navigateur ne contacte jamais TMDB ni TheTVDB : il demande l'image ici, et
 * c'est le serveur qui va la chercher. Trois raisons, dans cet ordre :
 *
 *  1. Rien ne fuit. Un `<img src="https://image.tmdb.org/…">` annonce à un tiers
 *     l'adresse IP de qui regarde, et quoi. Sur cette application-là, c'est
 *     exactement ce qu'on ne veut pas.
 *  2. La CSP reste `img-src 'self'` : aucune origine externe à ouvrir.
 *  3. Une affiche déjà vue n'est plus retéléchargée, par personne.
 *
 * L'URL réelle arrive scellée (chiffrée) : le client ne peut pas en fabriquer
 * une. Et même ouverte, elle est re-confrontée à la liste d'hôtes autorisés —
 * un jeton valide ne vaut pas blanc-seing.
 */

require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/Metadata.php';
require_once __DIR__ . '/../src/TorrentFetcher.php';

$config = load_config();
require_auth($config, 'json');
security_headers();

/** Réponse d'échec : une image cassée, pas une page d'erreur. */
function poster_fail(int $code): never
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    exit;
}

$url = open_url((string) ($_GET['t'] ?? ''), $config['secret']);
if ($url === null) {
    poster_fail(403);
}

// Deuxième garde, indépendante du scellement : seules ces sources d'images sont
// atteignables, quoi qu'un jeton prétende.
$host = (string) parse_url($url, PHP_URL_HOST);
if ($host === '' || !MetadataClient::isPosterHost($host)) {
    error_log('[indexof] affiche refusée, hôte non autorisé : ' . $host);
    poster_fail(403);
}

/** Type MIME déduit des octets de tête (on ne croit pas l'en-tête distant). */
function sniff_image(string $bytes): ?string
{
    if (str_starts_with($bytes, "\xFF\xD8\xFF")) {
        return 'image/jpeg';
    }
    if (str_starts_with($bytes, "\x89PNG\r\n\x1A\n")) {
        return 'image/png';
    }
    if (str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP') {
        return 'image/webp';
    }
    if (str_starts_with($bytes, 'GIF87a') || str_starts_with($bytes, 'GIF89a')) {
        return 'image/gif';
    }
    return null;
}

$dir  = $config['data_dir'] . '/posters';
$hash = hash('sha256', $url);
$file = $dir . '/' . $hash . '.bin';

$body = null;
$type = null;

// Cache disque : une affiche ne change pas. Le volume est persistant, donc elle
// survit aux redéploiements — contrairement au cache de recherche, en tmpfs.
if (is_file($file)) {
    $cached = (string) @file_get_contents($file);
    if ($cached !== '') {
        $type = sniff_image($cached);
        if ($type !== null) {
            $body = $cached;
        }
    }
}

if ($body === null) {
    try {
        $fetched = fetch_remote($url, $config, POSTER_MAX_BYTES);
    } catch (TorrentFetchError $e) {
        error_log('[indexof] affiche indisponible : ' . $e->getMessage());
        poster_fail(502);
    }

    $type = sniff_image($fetched['body']);
    if ($type === null) {
        // La source a répondu autre chose qu'une image (page d'erreur, HTML) :
        // on ne le relaie pas, et on ne le met surtout pas en cache.
        poster_fail(502);
    }
    $body = $fetched['body'];

    if (is_dir($dir) || @mkdir($dir, 0700, true) || is_dir($dir)) {
        // Purge des affiches qu'on n'a plus regardées depuis longtemps : le
        // volume ne doit pas grossir indéfiniment.
        prune_dir($dir, 90 * 86400, '*.bin');
        @file_put_contents($file, $body, LOCK_EX);
    }
}

// L'affiche est immuable (l'URL porte l'empreinte du fichier distant) : le
// navigateur peut la garder sans jamais revalider.
$etag = '"' . substr($hash, 0, 32) . '"';
if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
    http_response_code(304);
    header('ETag: ' . $etag);
    header('Cache-Control: private, max-age=604800, immutable');
    exit;
}

header('Content-Type: ' . $type);
header('Content-Length: ' . strlen($body));
header('ETag: ' . $etag);
header('Cache-Control: private, max-age=604800, immutable');
// Le contenu vient d'un tiers : on le sert inerte, incapable de rien exécuter
// s'il se révélait n'être pas tout à fait une image.
header("Content-Security-Policy: default-src 'none'; sandbox");
header('Content-Disposition: inline');
echo $body;
