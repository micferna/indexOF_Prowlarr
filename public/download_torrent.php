<?php

declare(strict_types=1);

/**
 * Proxy de téléchargement de fichiers .torrent.
 *
 * Défenses contre le SSRF / open proxy (cf. AUDIT.md, V1) :
 *  1. Signature HMAC : seules les URLs générées par l'application sont acceptées.
 *  2. Schémas http/https uniquement (CURLOPT_PROTOCOLS).
 *  3. Rejet des IP privées/réservées (anti-métadonnées cloud, anti-SSRF interne).
 *  4. Plafond de taille et timeouts (anti-DoS).
 */

require __DIR__ . '/../src/config.php';
require __DIR__ . '/../src/functions.php';

const MAX_BYTES     = 25 * 1024 * 1024; // 25 Mo
const MAX_REDIRECTS = 3;

$config = load_config();

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

function fail(int $code, string $message): never
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
    exit;
}

$url = (string) ($_GET['url'] ?? '');
$sig = (string) ($_GET['sig'] ?? '');

if ($url === '' || $sig === '') {
    fail(400, "Requête invalide : URL ou signature manquante.");
}
if (!verify_url_signature($url, $sig, $config['secret'])) {
    fail(403, "Signature invalide : URL non autorisée.");
}

// Suivi manuel des redirections : chaque saut est re-validé (schéma + IP publique)
// et la connexion est épinglée à l'IP vérifiée — pas de SSRF via redirect ni
// via DNS rebinding (cURL ne re-résout jamais de lui-même).
$current = $url;
$body    = '';

for ($hop = 0; ; $hop++) {
    if ($hop > MAX_REDIRECTS) {
        fail(502, "Trop de redirections.");
    }

    $parts  = parse_url($current);
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $host   = (string) ($parts['host'] ?? '');

    if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
        fail(400, "Schéma d'URL non autorisé.");
    }

    $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
    $ip   = resolve_to_public_ip($host);
    if ($ip === null) {
        fail(403, "Cible interdite (adresse interne/réservée).");
    }

    $ch = curl_init($current);
    if ($ch === false) {
        fail(500, "Impossible d'initialiser le téléchargement.");
    }

    $downloaded = 0;
    $buffer     = '';

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => max(15, $config['timeout']),
        CURLOPT_FOLLOWLOCATION => false, // suivi manuel avec re-validation
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_RESOLVE        => ["{$host}:{$port}:{$ip}"], // épingle l'IP validée
        CURLOPT_USERAGENT      => 'indexOF-Prowlarr/2.0',
        // Accumule le corps, coupe si dépassement du plafond.
        CURLOPT_WRITEFUNCTION  => function ($ch, string $chunk) use (&$downloaded, &$buffer): int {
            $downloaded += strlen($chunk);
            if ($downloaded > MAX_BYTES) {
                return -1; // abandon de cURL
            }
            $buffer .= $chunk;
            return strlen($chunk);
        },
    ]);

    $ok       = curl_exec($ch);
    $errno    = curl_errno($ch);
    $status   = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $redirect = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    curl_close($ch);

    if ($downloaded > MAX_BYTES) {
        fail(413, "Fichier trop volumineux.");
    }
    if ($ok === false || $errno !== 0) {
        fail(502, "Échec du téléchargement depuis la source.");
    }

    // Redirection : on reboucle pour re-valider la cible.
    if ($status >= 300 && $status < 400) {
        if ($redirect === '') {
            fail(502, "Redirection invalide.");
        }
        $current = $redirect;
        continue;
    }
    if ($status < 200 || $status >= 300) {
        fail(502, "La source a répondu HTTP {$status}.");
    }
    if ($buffer === '') {
        fail(502, "Réponse vide de la source.");
    }

    $body = $buffer;
    break;
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
