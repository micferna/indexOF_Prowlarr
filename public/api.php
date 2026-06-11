<?php

declare(strict_types=1);

/**
 * API JSON consommée par le front (recherche dynamique sans rechargement).
 *
 *   GET api.php?action=indexers
 *   GET api.php?action=search&q=...&days=1&trackers=1,2
 *
 * Les liens de téléchargement sont signés (HMAC) côté serveur : le secret ne
 * quitte jamais le backend.
 */

require __DIR__ . '/../src/config.php';
require __DIR__ . '/../src/functions.php';
require __DIR__ . '/../src/ProwlarrClient.php';

$config = load_config();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');

/**
 * @param array<string,mixed> $data
 */
function json_out(array $data, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Transforme une release Prowlarr brute en objet d'affichage (avec lien signé).
 *
 * @param array<string,mixed> $r
 * @return array<string,mixed>
 */
function map_result(array $r, string $secret): array
{
    // Prowlarr range les liens dans downloadUrl/magnetUrl sans garantie de schéma :
    // magnetUrl peut être une vraie URL magnet: OU une URL HTTP de son endpoint de
    // download. On classe donc par schéma réel, pas par nom de champ.
    $magnet = '';
    $http   = '';
    foreach ([(string) ($r['downloadUrl'] ?? ''), (string) ($r['magnetUrl'] ?? '')] as $u) {
        if ($u === '') {
            continue;
        }
        if ($magnet === '' && str_starts_with($u, 'magnet:')) {
            $magnet = $u;
        } elseif ($http === '' && safe_url($u) !== '#') {
            $http = $u;
        }
    }

    if ($http !== '') {
        // Téléchargement .torrent via le proxy signé (résout aussi les liens proxy Prowlarr).
        $action = [
            'type' => 'torrent',
            'href' => 'download_torrent.php?' . http_build_query([
                'url' => $http,
                'sig' => sign_url($http, $secret),
            ]),
        ];
    } elseif ($magnet !== '') {
        $action = ['type' => 'magnet', 'href' => $magnet];
    } else {
        $action = ['type' => null, 'href' => null];
    }

    $size = (int) ($r['size'] ?? 0);

    return [
        'indexer'     => (string) ($r['indexer'] ?? 'N/A'),
        'title'       => (string) ($r['title'] ?? 'N/A'),
        'infoUrl'     => safe_url($r['infoUrl'] ?? null),
        'size'        => $size,
        'sizeHuman'   => format_size($size),
        'seeders'     => (int) ($r['seeders'] ?? 0),
        'publishDate' => (string) ($r['publishDate'] ?? ''),
        'daysOld'     => days_since($r['publishDate'] ?? null),
        'action'      => $action,
    ];
}

$client = new ProwlarrClient(
    $config['base_url'],
    $config['api_key'],
    $config['timeout'],
    $config['cache_ttl'],
    $config['cache_dir'],
);

$action = (string) ($_GET['action'] ?? 'search');

try {
    if ($action === 'indexers') {
        json_out(['indexers' => $client->indexers()]);
    }

    if ($action === 'search') {
        $query = trim((string) ($_GET['q'] ?? ''));
        if ($query === '') {
            json_out(['query' => '', 'count' => 0, 'results' => []]);
        }

        $allowedDays = [0, 1, 7, 30, 90];
        $days = in_array((int) ($_GET['days'] ?? 1), $allowedDays, true) ? (int) $_GET['days'] : 1;

        $trackers = array_values(array_filter(
            array_map('intval', explode(',', (string) ($_GET['trackers'] ?? ''))),
            static fn (int $id): bool => $id > 0
        ));

        $raw = $client->search($query, $trackers, $days);
        $results = array_map(
            static fn (array $r): array => map_result($r, $config['secret']),
            $raw
        );

        json_out(['query' => $query, 'count' => count($results), 'results' => $results]);
    }

    json_out(['error' => 'Action inconnue.'], 400);
} catch (Throwable $exception) {
    json_out(['error' => $exception->getMessage()], 502);
}
