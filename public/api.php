<?php

declare(strict_types=1);

/**
 * API JSON consommée par le front (recherche dynamique sans rechargement).
 *
 *   GET api.php?action=status
 *   GET api.php?action=indexers
 *   GET api.php?action=search&q=...&days=1&trackers=1,2&cats=2000,5000
 *
 * Les liens de téléchargement sont scellés (chiffrés) côté serveur : les URLs
 * Prowlarr — qui contiennent la clé API — ne quittent jamais le backend.
 */

require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/ProwlarrClient.php';
require_once __DIR__ . '/../src/QbittorrentClient.php';

$config = load_config();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
security_headers();

require_auth($config, 'json');

/**
 * Transforme une release Prowlarr brute en objet d'affichage (liens scellés).
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

    // Jeton opaque pour le téléchargement du .torrent (proxy navigateur).
    // L'URL Prowlarr embarque la clé API : elle est CHIFFRÉE, jamais exposée au
    // client. Seul le serveur peut l'ouvrir.
    $dl = $http !== '' ? ['token' => seal_url($http, $secret)] : null;

    // Jeton d'envoi qBittorrent : cible préférée (magnet si dispo, sinon .torrent
    // signé). Empêche l'injection d'un torrent arbitraire non issu de l'app.
    $sendTarget = $magnet !== '' ? $magnet : $http;
    $send = $sendTarget !== '' ? seal_url($sendTarget, $secret) : null;

    $title = (string) ($r['title'] ?? 'N/A');

    // Catégorie la plus parlante (première de la liste) + détection adulte (XXX).
    $category = '';
    if (!empty($r['categories']) && is_array($r['categories'])) {
        $first = $r['categories'][0] ?? null;
        if (is_array($first)) {
            $category = (string) ($first['name'] ?? '');
        }
    }
    $adult = is_adult_result($r);

    // Freeleech via les flags d'indexeur.
    $freeleech = false;
    if (!empty($r['indexerFlags']) && is_array($r['indexerFlags'])) {
        foreach ($r['indexerFlags'] as $flag) {
            if (stripos((string) $flag, 'freeleech') !== false) {
                $freeleech = true;
                break;
            }
        }
    }

    $size = (int) ($r['size'] ?? 0);

    return [
        'indexer'     => (string) ($r['indexer'] ?? 'N/A'),
        'title'       => $title,
        'infoUrl'     => safe_url($r['infoUrl'] ?? null),
        'size'        => $size,
        'sizeHuman'   => format_size($size),
        'seeders'     => (int) ($r['seeders'] ?? 0),
        'leechers'    => (int) ($r['leechers'] ?? 0),
        'publishDate' => (string) ($r['publishDate'] ?? ''),
        'daysOld'     => days_since($r['publishDate'] ?? null),
        'category'    => $category,
        'adult'       => $adult,
        'badges'      => quality_badges($title),
        'freeleech'   => $freeleech,
        'magnet'      => $magnet !== '' ? $magnet : null,
        'dl'          => $dl,
        'send'        => $send,
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
    if ($action === 'status') {
        $count = null;
        $connected = false;
        $errors = [];
        try {
            $count = count($client->indexers());
            $connected = true;
            $errors = $client->failingIndexers();
        } catch (Throwable $e) {
            $connected = false;
        }

        $qbitOn = QbittorrentClient::isConfigured($config['qbit_url']);
        $qbitCats = [];
        if ($qbitOn) {
            $qc = new QbittorrentClient(
                $config['qbit_url'],
                $config['qbit_user'],
                $config['qbit_pass'],
                $config['qbit_timeout'],
            );
            $qbitCats = $qc->categories();
        }

        json_response([
            'connected'      => $connected,
            'indexers'       => $count,
            'indexerErrors'  => $errors,
            'qbit'           => $qbitOn,
            'qbitCategories' => $qbitCats,
            // Cibles *arr actives : le front n'affiche que ces boutons-là.
            'arr'            => array_map(static fn (array $t): string => $t['label'], $config['arr']),
            'authEnabled'    => auth_enabled($config),
        ]);
    }

    if ($action === 'indexers') {
        json_response(['indexers' => $client->indexers()]);
    }

    // Transferts en cours : qBittorrent est la source de vérité, on ne duplique
    // rien. Sert à la fois à la vue « Transferts » et au marquage des résultats
    // déjà présents dans le client.
    if ($action === 'transfers') {
        if (!QbittorrentClient::isConfigured($config['qbit_url'])) {
            json_response(['torrents' => [], 'qbit' => false]);
        }
        $qc = new QbittorrentClient(
            $config['qbit_url'],
            $config['qbit_user'],
            $config['qbit_pass'],
            $config['qbit_timeout'],
        );
        $torrents = [];
        foreach ($qc->torrents() as $t) {
            $size = (int) ($t['size'] ?? 0);
            $torrents[] = [
                'hash'      => (string) ($t['hash'] ?? ''),
                'name'      => (string) ($t['name'] ?? ''),
                'state'     => (string) ($t['state'] ?? ''),
                'progress'  => round((float) ($t['progress'] ?? 0), 4),
                'size'      => $size,
                'sizeHuman' => format_size($size),
                'ratio'     => round((float) ($t['ratio'] ?? 0), 2),
                'dlspeed'   => (int) ($t['dlspeed'] ?? 0),
                'upspeed'   => (int) ($t['upspeed'] ?? 0),
                'eta'       => (int) ($t['eta'] ?? 0),
                'category'  => (string) ($t['category'] ?? ''),
                'seeds'     => (int) ($t['num_seeds'] ?? 0),
                'peers'     => (int) ($t['num_leechs'] ?? 0),
            ];
        }
        json_response(['torrents' => $torrents, 'qbit' => true]);
    }

    if ($action === 'search') {
        // Mode découverte (Top) : requête vide autorisée, tri par seeders côté client.
        $top   = (string) ($_GET['top'] ?? '') === '1';
        $query = trim((string) ($_GET['q'] ?? ''));
        if ($query === '' && !$top) {
            json_response(['query' => '', 'count' => 0, 'capped' => false, 'results' => []]);
        }

        $allowedDays = [0, 1, 7, 30, 90];
        $days = in_array((int) ($_GET['days'] ?? 1), $allowedDays, true) ? (int) $_GET['days'] : 1;

        // IDs bornés : un identifiant hors plage faisait échouer Prowlarr (502),
        // et une liste sans limite laissait construire une requête amont énorme.
        $parseIds = static fn (string $raw): array => array_slice(
            array_values(array_unique(array_filter(
                array_map('intval', explode(',', $raw)),
                static fn (int $n): bool => $n > 0 && $n < 100000
            ))),
            0,
            50
        );
        $trackers   = $parseIds((string) ($_GET['trackers'] ?? ''));
        $categories = $parseIds((string) ($_GET['cats'] ?? ''));

        // Un indexeur inconnu fait échouer toute la recherche côté Prowlarr : on
        // restreint à la liste réelle (en cache). Si plus rien ne subsiste, la
        // sélection ne désigne aucun indexeur existant => résultat vide.
        if ($trackers !== []) {
            try {
                $known = array_column($client->indexers(), 'id');
                $filtered = array_values(array_intersect($trackers, $known));
                if ($filtered === []) {
                    json_response(['query' => $query, 'total' => 0, 'count' => 0, 'capped' => false, 'results' => []]);
                }
                $trackers = $filtered;
            } catch (Throwable $e) {
                // Liste indisponible : on laisse la recherche tenter sa chance.
                error_log('[indexof] liste indexeurs indisponible : ' . $e->getMessage());
            }
        }

        // Safe-mode (-18) APPLIQUÉ CÔTÉ SERVEUR : par défaut le contenu XXX n'est
        // pas renvoyé. Il n'apparaît que si explicitement demandé (safe=0) ou si
        // une catégorie adulte (6000-6999) est sélectionnée. Filtrage AVANT la
        // limite de pagination (sinon le quota fausse les compteurs).
        $safe = ((string) ($_GET['safe'] ?? '1')) !== '0';
        $wantAdult = (bool) array_filter($categories, static fn (int $c): bool => $c >= 6000 && $c < 7000);

        $limit = (int) $config['limit'];
        $all   = $client->search($query, $trackers, $days, $categories, $top);
        if ($safe && !$wantAdult) {
            $all = array_values(array_filter($all, static fn (array $r): bool => !is_adult_result($r)));
        }
        $total = count($all);
        $page  = array_slice($all, 0, $limit);
        $results = array_map(
            static fn (array $r): array => map_result($r, $config['secret']),
            $page
        );

        json_response([
            'query'   => $query,
            'total'   => $total,
            'count'   => count($results),
            'capped'  => $total > $limit,
            'results' => $results,
        ]);
    }

    json_response(['error' => 'Action inconnue.'], 400);
} catch (Throwable $exception) {
    // Détail loggé côté serveur uniquement (les messages cURL peuvent contenir
    // des hôtes/IP internes — pas de fuite au client).
    error_log('[indexof] api error: ' . $exception->getMessage());

    // Le délai dépassé mérite un message actionnable : c'est presque toujours un
    // indexeur en erreur qui bloque la recherche, pas une panne de l'app.
    if ($exception->getCode() === 504) {
        json_response([
            'error' => "Délai dépassé : un indexeur ne répond pas. Réessaie, "
                . "ou décoche l'indexeur signalé en erreur (⚠) dans les filtres.",
        ], 504);
    }
    json_response(['error' => 'Service momentanément indisponible.'], 502);
}
