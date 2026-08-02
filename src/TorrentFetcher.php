<?php

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

/**
 * Récupération d'un fichier distant (.torrent, affiche) depuis une source
 * externe.
 *
 * C'est la surface la plus sensible du projet : on va chercher une URL pour le
 * compte de l'utilisateur. Toutes les défenses vivent ici, en un seul endroit —
 * le proxy de téléchargement, l'aperçu du contenu et le proxy d'affiches s'en
 * servent tous les trois, et ne peuvent donc pas diverger.
 *
 * Défenses :
 *  1. Schémas http/https uniquement (CURLOPT_PROTOCOLS).
 *  2. Rejet des IP privées et réservées (anti-métadonnées cloud, anti-SSRF).
 *  3. Épinglage de l'IP validée (CURLOPT_RESOLVE) : cURL ne re-résout jamais,
 *     donc pas de DNS rebinding entre la vérification et la connexion.
 *  4. Suivi manuel des redirections, chaque saut étant re-validé.
 *  5. Plafond de taille et délais stricts.
 *
 * L'URL elle-même vient toujours d'un jeton scellé par le serveur : l'appelant
 * ne peut pas en fabriquer une.
 */
final class TorrentFetchError extends RuntimeException
{
}

const TORRENT_MAX_BYTES     = 25 * 1024 * 1024; // 25 Mo
const POSTER_MAX_BYTES      = 3 * 1024 * 1024;  // 3 Mo : une affiche en fait ~50 Ko
const TORRENT_MAX_REDIRECTS = 3;

/**
 * Télécharge un .torrent et renvoie son contenu brut.
 *
 * @param array<string,mixed> $config
 * @throws TorrentFetchError avec un code HTTP utilisable tel quel.
 */
function fetch_torrent(string $url, array $config): string
{
    return fetch_remote($url, $config, TORRENT_MAX_BYTES)['body'];
}

/**
 * Télécharge une ressource distante en appliquant toutes les défenses ci-dessus.
 *
 * @param array<string,mixed> $config
 * @return array{body:string,type:string}
 * @throws TorrentFetchError avec un code HTTP utilisable tel quel.
 */
function fetch_remote(string $url, array $config, int $maxBytes): array
{
    // Le backend Prowlarr (admin-configuré) est de confiance : ses liens de
    // téléchargement pointent souvent vers lui-même, sur une IP privée (réseau
    // interne / Docker). On l'autorise explicitement, mais lui seul.
    $trustedHost = (string) parse_url((string) $config['base_url'], PHP_URL_HOST);
    $current = $url;

    for ($hop = 0; ; $hop++) {
        if ($hop > TORRENT_MAX_REDIRECTS) {
            throw new TorrentFetchError('Trop de redirections.', 502);
        }

        $parts  = parse_url($current);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host   = (string) ($parts['host'] ?? '');

        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new TorrentFetchError("Schéma d'URL non autorisé.", 400);
        }

        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

        $ip = resolve_to_public_ip($host);
        if ($ip === null && $trustedHost !== '' && strcasecmp($host, $trustedHost) === 0) {
            $ip = resolve_host_ip($host);
        }
        if ($ip === null) {
            throw new TorrentFetchError('Cible interdite (adresse interne/réservée).', 403);
        }

        $ch = curl_init($current);
        if ($ch === false) {
            throw new TorrentFetchError("Impossible d'initialiser le téléchargement.", 500);
        }

        $downloaded = 0;
        $buffer     = '';

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => max(15, (int) $config['timeout']),
            CURLOPT_FOLLOWLOCATION => false, // suivi manuel avec re-validation
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_RESOLVE        => ["{$host}:{$port}:{$ip}"], // épingle l'IP validée
            CURLOPT_USERAGENT      => 'indexOF-Prowlarr/2.0',
            // Accumule le corps, coupe si dépassement du plafond.
            CURLOPT_WRITEFUNCTION  => function ($ch, string $chunk) use (&$downloaded, &$buffer, $maxBytes): int {
                $downloaded += strlen($chunk);
                if ($downloaded > $maxBytes) {
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
        $type     = strtolower(trim(explode(';', (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE))[0]));
        curl_close($ch);

        if ($downloaded > $maxBytes) {
            throw new TorrentFetchError('Fichier trop volumineux.', 413);
        }
        if ($ok === false || $errno !== 0) {
            throw new TorrentFetchError('Échec du téléchargement depuis la source.', 502);
        }

        // Redirection : on reboucle pour re-valider la cible.
        if ($status >= 300 && $status < 400) {
            if ($redirect === '') {
                throw new TorrentFetchError('Redirection invalide.', 502);
            }
            $current = $redirect;
            continue;
        }
        if ($status < 200 || $status >= 300) {
            throw new TorrentFetchError("La source a répondu HTTP {$status}.", 502);
        }
        if ($buffer === '') {
            throw new TorrentFetchError('Réponse vide de la source.', 502);
        }

        return ['body' => $buffer, 'type' => $type];
    }
}
