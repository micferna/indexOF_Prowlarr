<?php

declare(strict_types=1);

/**
 * Flux RSS d'une recherche enregistrée.
 *
 *   GET rss.php?t=<jeton du flux>
 *
 * Pensé pour être consommé par qBittorrent (Vue RSS → ajouter un flux) ou tout
 * lecteur : l'application travaille alors sans qu'on l'ouvre.
 *
 * Un lecteur RSS ne se connecte pas : l'accès repose donc sur le jeton secret
 * de l'URL, propre à chaque recherche enregistrée et révocable en supprimant
 * celle-ci. Le flux ne contient jamais l'URL Prowlarr réelle — les pièces
 * jointes pointent vers le proxy de téléchargement, avec un lien scellé.
 */

require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/Store.php';
require_once __DIR__ . '/../src/Search.php';

$config = load_config();

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');

function rss_fail(int $code, string $message): never
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
    exit;
}

$token = (string) ($_GET['t'] ?? '');
$store = new Store($config['db_file']);
$search = $store->searchByToken($token);
if ($search === null) {
    // Même réponse pour un jeton mal formé, inconnu ou une base absente :
    // rien à apprendre en sondant l'endpoint.
    rss_fail(404, 'Flux introuvable.');
}

$client = new ProwlarrClient(
    $config['base_url'],
    $config['api_key'],
    $config['timeout'],
    $config['cache_ttl'],
    $config['cache_dir'],
);

try {
    // Un flux s'exécute sans session : c'est le propriétaire de la recherche qui
    // détermine les indexeurs interrogeables. Sans ça, créer un flux suffirait à
    // contourner son propre cloisonnement.
    $proprietaire = (string) ($search['owner'] ?? '');
    $found = perform_search($client, $store, $config, [
        'query'    => (string) $search['query'],
        'top'      => ((string) $search['query']) === '',
        'days'     => (int) $search['days'],
        'cats'     => (string) $search['cats'],
        'trackers' => (string) $search['trackers'],
        'safe'     => ((int) $search['safe']) !== 0,
        'allow'    => $store->userIndexers($proprietaire),
        'user'     => $proprietaire !== '' ? $proprietaire : null,
    ]);
} catch (Throwable $e) {
    error_log('[indexof] rss error: ' . $e->getMessage());
    rss_fail(503, 'Recherche momentanément indisponible.');
}

// Le lecteur récupère la pièce jointe sans session : on lui passe le jeton du
// flux, que le proxy de téléchargement sait reconnaître.
$base = rtrim(feed_base_url(), '/');

header('Content-Type: application/rss+xml; charset=utf-8');
echo '<?xml version="1.0" encoding="UTF-8"?>', "\n";
?>
<rss version="2.0">
  <channel>
    <title>indexOF · <?php echo xml_text((string) $search['name']); ?></title>
    <link><?php echo xml_text($base . '/index.php'); ?></link>
    <description>Recherche enregistrée « <?php echo xml_text((string) $search['name']); ?> » — <?php echo count($found['results']); ?> résultat(s)</description>
    <language>fr</language>
    <lastBuildDate><?php echo gmdate('D, d M Y H:i:s') . ' GMT'; ?></lastBuildDate>
<?php foreach ($found['results'] as $r): ?>
<?php
    // Sans lien de téléchargement, l'entrée n'a pas d'intérêt pour un client
    // BitTorrent : on la passe.
    $enclosure = '';
    if (!empty($r['dl']['token'])) {
        $enclosure = $base . '/download_torrent.php?token=' . rawurlencode((string) $r['dl']['token'])
            . '&feed=' . rawurlencode((string) $search['token']);
    } elseif (!empty($r['magnet'])) {
        $enclosure = (string) $r['magnet'];
    }
    if ($enclosure === '') {
        continue;
    }
    $published = strtotime((string) $r['publishDate']);
?>
    <item>
      <title><?php echo xml_text((string) $r['title']); ?></title>
      <link><?php echo xml_text($r['infoUrl'] !== '#' ? (string) $r['infoUrl'] : $enclosure); ?></link>
      <guid isPermaLink="false"><?php echo xml_text(sha1((string) $r['title'] . '|' . (string) $r['indexer'])); ?></guid>
      <pubDate><?php echo gmdate('D, d M Y H:i:s', $published !== false ? $published : time()) . ' GMT'; ?></pubDate>
      <description><?php echo xml_text(sprintf(
          '%s · %s · %d seeders',
          (string) $r['indexer'],
          (string) $r['sizeHuman'],
          (int) $r['seeders']
      )); ?></description>
      <enclosure url="<?php echo xml_text($enclosure); ?>" length="<?php echo (int) $r['size']; ?>" type="application/x-bittorrent"/>
    </item>
<?php endforeach; ?>
  </channel>
</rss>
