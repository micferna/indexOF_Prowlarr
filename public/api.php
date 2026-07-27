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
require_once __DIR__ . '/../src/Store.php';
require_once __DIR__ . '/../src/Search.php';

$config = load_config();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
security_headers();

require_auth($config, 'json');

$client = new ProwlarrClient(
    $config['base_url'],
    $config['api_key'],
    $config['timeout'],
    $config['cache_ttl'],
    $config['cache_dir'],
);

$store = new Store($config['db_file']);

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
            // Sans base accessible, les recherches enregistrées et l'historique
            // n'ont pas lieu d'apparaître dans l'interface.
            'store'          => $store->available(),
        ]);
    }

    if ($action === 'searches') {
        json_response(['searches' => $store->searches()]);
    }

    if ($action === 'history') {
        json_response(['history' => $store->history()]);
    }

    // Santé des indexeurs : latence, volume, échecs, et lesquels sont
    // actuellement désactivés par le backoff de Prowlarr.
    if ($action === 'health') {
        $failing = [];
        try {
            $failing = $client->failingIndexers();
        } catch (Throwable $e) {
            error_log('[indexof] statut indexeurs indisponible : ' . $e->getMessage());
        }

        $rows = [];
        foreach ($client->indexerStats(30) as $st) {
            $name = (string) ($st['indexerName'] ?? '');
            $queries = (int) ($st['numberOfQueries'] ?? 0) + (int) ($st['numberOfRssQueries'] ?? 0);
            $failed  = (int) ($st['numberOfFailedQueries'] ?? 0) + (int) ($st['numberOfFailedRssQueries'] ?? 0);
            $rows[] = [
                'name'     => $name,
                'latency'  => (int) ($st['averageResponseTime'] ?? 0),
                'queries'  => $queries,
                'failed'   => $failed,
                'grabs'    => (int) ($st['numberOfGrabs'] ?? 0),
                'disabled' => in_array($name, $failing, true),
            ];
        }
        usort($rows, static fn (array $a, array $b): int => $b['latency'] <=> $a['latency']);
        json_response(['indexers' => $rows]);
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
        json_response(perform_search($client, $store, $config, [
            'query'    => (string) ($_GET['q'] ?? ''),
            'top'      => (string) ($_GET['top'] ?? '') === '1',
            'days'     => (int) ($_GET['days'] ?? 1),
            'cats'     => (string) ($_GET['cats'] ?? ''),
            'trackers' => (string) ($_GET['trackers'] ?? ''),
            'safe'     => ((string) ($_GET['safe'] ?? '1')) !== '0',
        ]));
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
