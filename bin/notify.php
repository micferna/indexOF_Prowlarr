<?php

declare(strict_types=1);

/**
 * Signale sur Discord les nouveautés des recherches enregistrées.
 *
 *   php bin/notify.php
 *
 * Conçu pour tourner en boucle dans un conteneur dédié (profil « notify » du
 * compose). Le flux RSS prévient qBittorrent ; ceci vous prévient, vous.
 *
 * Ne notifie que ce qui n'a jamais été signalé pour la recherche concernée :
 * la première exécution marque l'existant sans rien envoyer, sinon elle
 * déverserait des centaines de lignes d'un coup.
 */

require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/Store.php';
require_once __DIR__ . '/../src/Search.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

function journal(string $message): void
{
    fwrite(STDERR, '[' . gmdate('Y-m-d H:i:s') . '] ' . $message . PHP_EOL);
}

$config = load_config();

$webhook = trim((string) (getenv('DISCORD_WEBHOOK') ?: ''));
if ($webhook === '' || !str_starts_with($webhook, 'https://')) {
    journal('DISCORD_WEBHOOK absent ou non HTTPS — rien à faire.');
    exit(0);
}

$store = new Store($config['db_file']);
if (!$store->available()) {
    journal('Base indisponible.');
    exit(1);
}

$searches = $store->notifiedSearches();
if ($searches === []) {
    exit(0);
}

$client = new ProwlarrClient(
    $config['base_url'],
    $config['api_key'],
    $config['timeout'],
    $config['cache_ttl'],
    $config['cache_dir'],
);

/** Envoie un message Discord. Renvoie false en cas d'échec. */
function discord(string $webhook, string $content): bool
{
    $ch = curl_init($webhook);
    if ($ch === false) {
        return false;
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => (string) json_encode([
            'content' => $content,
            // Aucune mention ne doit pouvoir être déclenchée par un nom de
            // release : ils viennent des trackers.
            'allowed_mentions' => ['parse' => []],
        ]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
    ]);
    curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return $status >= 200 && $status < 300;
}

foreach ($searches as $s) {
    $id = (int) $s['id'];
    try {
        // La veille tourne hors session : elle applique la restriction du
        // propriétaire de la recherche, comme le flux RSS.
        $proprietaire = (string) ($s['owner'] ?? '');
        $found = perform_search($client, $store, $config, [
            'query'    => (string) $s['query'],
            'top'      => ((string) $s['query']) === '',
            'days'     => (int) $s['days'],
            'cats'     => (string) $s['cats'],
            'trackers' => (string) $s['trackers'],
            'safe'     => ((int) $s['safe']) !== 0,
            'allow'    => $store->userIndexers($proprietaire),
            'user'     => $proprietaire !== '' ? $proprietaire : null,
        ]);
    } catch (Throwable $e) {
        journal('« ' . $s['name'] . ' » : recherche impossible — ' . $e->getMessage());
        continue;
    }

    // Identité d'une release : son titre et son indexeur. Deux trackers qui
    // publient la même release ne doivent pas déclencher deux notifications.
    $parGuid = [];
    foreach ($found['results'] as $r) {
        $parGuid[Store::titleKey((string) $r['title'])] = $r;
    }
    if ($parGuid === []) {
        continue;
    }

    $neufs = $store->takeUnseen($id, array_keys($parGuid));
    if ($neufs === []) {
        continue;
    }

    // Première exécution : on marque l'existant sans inonder le salon.
    if (count($neufs) === count($parGuid) && count($neufs) > 5) {
        journal('« ' . $s['name'] . ' » : ' . count($neufs) . ' releases connues enregistrées, pas de notification initiale.');
        continue;
    }

    $lignes = [];
    foreach (array_slice($neufs, 0, 5) as $guid) {
        $r = $parGuid[$guid];
        $lignes[] = sprintf(
            '• `%s`  —  %s · %d seeders',
            mb_substr((string) $r['title'], 0, 110),
            (string) $r['sizeHuman'],
            (int) $r['seeders']
        );
    }
    $reste = count($neufs) - count($lignes);
    if ($reste > 0) {
        $lignes[] = sprintf('… et %d de plus', $reste);
    }

    $message = sprintf(
        "**%s** — %d nouveauté%s\n%s",
        (string) $s['name'],
        count($neufs),
        count($neufs) > 1 ? 's' : '',
        implode("\n", $lignes)
    );

    if (discord($webhook, $message)) {
        journal('« ' . $s['name'] . ' » : ' . count($neufs) . ' notifiée(s).');
    } else {
        journal('« ' . $s['name'] . ' » : envoi Discord refusé.');
    }
}
