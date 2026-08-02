<?php

declare(strict_types=1);

/**
 * API JSON consommée par le front (recherche dynamique sans rechargement).
 *
 *   GET  api.php?action=status
 *   GET  api.php?action=indexers
 *   GET  api.php?action=search&q=...&days=1&trackers=1,2&cats=2000,5000
 *   POST api.php?action=meta   items=[{title,kind,imdbId,tmdbId}, …]
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
require_once __DIR__ . '/../src/TorrentFetcher.php';
require_once __DIR__ . '/../src/Bencode.php';
require_once __DIR__ . '/../src/Metadata.php';
require_once __DIR__ . '/../src/Library.php';
require_once __DIR__ . '/../src/CastClient.php';
require_once __DIR__ . '/../src/Transcoder.php';
require_once __DIR__ . '/../src/Hls.php';

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

// Cloisonnement : la liste des indexeurs autorisés pour la session en cours.
// null = aucune restriction (administrateur, ou compte sans liste définie).
// Elle est appliquée à TOUS les points qui atteignent Prowlarr — recherche,
// liste d'indexeurs, statistiques — pas seulement au premier.
$me = current_user();
$allow = $store->userIndexers($me);
$scope = is_admin($config) ? null : $me;

/** Un indexeur est-il visible pour la session en cours ? */
$visible = static function (int $id) use ($allow): bool {
    return $allow === null || in_array($id, $allow, true);
};

$action = (string) ($_GET['action'] ?? 'search');

try {
    if ($action === 'status') {
        $count = null;
        $connected = false;
        $errors = [];
        try {
            $mine = array_filter($client->indexers(), static fn (array $i): bool => $visible((int) $i['id']));
            $count = count($mine);
            $connected = true;
            $noms = array_column($mine, 'name');
            $errors = array_values(array_intersect($client->failingIndexers(), $noms));
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
            'user'           => current_user(),
            'admin'          => is_admin($config),
            // Sans webhook, la cloche des recherches n'aurait aucun effet.
            'notify'         => trim((string) (getenv('DISCORD_WEBHOOK') ?: '')) !== '',
            // Affiches et résumés : ils viennent de Radarr/Sonarr. Sans eux, pas
            // de fiche à afficher — et le bouton n'a pas lieu d'exister.
            'posters'        => MetadataClient::isConfigured($config['arr']),
            // Bibliothèque : sans dossier de téléchargements monté, il n'y a
            // rien à lister ni à lire.
            'library'        => (new Library($config['media_dir'], $config['cache_dir']))->available(),
            // Conversion à la volée : sans elle, seuls les formats que le
            // navigateur décode nativement sont proposés à la lecture.
            'transcode'      => $config['transcode']
                && (new Transcoder($config['ffmpeg'], $config['ffprobe']))->available(),
        ]);
    }

    if ($action === 'searches') {
        json_response(['searches' => $store->searches($scope)]);
    }

    if ($action === 'history') {
        json_response(['history' => $store->history(200, $scope)]);
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

        $autorises = null;
        if ($allow !== null) {
            $autorises = array_column(array_filter(
                $client->indexers(),
                static fn (array $i): bool => $visible((int) $i['id'])
            ), 'name');
        }

        $rows = [];
        foreach ($client->indexerStats(30) as $st) {
            $name = (string) ($st['indexerName'] ?? '');
            if ($autorises !== null && !in_array($name, $autorises, true)) {
                continue;
            }
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

    // Contenu d'un .torrent avant de le prendre : fichier unique ou pack de
    // quarante épisodes ? Sans ça, il faut télécharger pour savoir.
    if ($action === 'contents') {
        $url = open_url((string) ($_GET['token'] ?? ''), $config['secret']);
        if ($url === null) {
            json_response(['error' => 'Lien invalide ou expiré.'], 403);
        }
        try {
            $summary = Bencode::summarize(fetch_torrent($url, $config));
        } catch (TorrentFetchError $e) {
            error_log('[indexof] contents: ' . $e->getMessage());
            json_response(['error' => $e->getMessage()], $e->getCode() >= 400 ? $e->getCode() : 502);
        }
        if ($summary === null) {
            json_response(['error' => 'Fichier .torrent illisible.'], 422);
        }
        // Les plus gros fichiers d'abord : c'est le film, le reste est annexe.
        usort($summary['files'], static fn (array $a, array $b): int => $b['size'] <=> $a['size']);
        foreach ($summary['files'] as &$f) {
            $f['sizeHuman'] = format_size($f['size']);
        }
        unset($f);
        $summary['sizeHuman'] = format_size($summary['size']);
        json_response($summary);
    }

    // Comptes : visibles de l'administrateur seul, et sans aucun secret —
    // la liste ne contient jamais d'empreinte de mot de passe.
    if ($action === 'users') {
        if (!is_admin($config)) {
            json_response(['error' => "Réservé à l'administrateur."], 403);
        }
        json_response(['users' => $store->users()]);
    }

    // Fiches des releases affichées : affiche, résumé, année, note. C'est ce qui
    // évite d'ouvrir la page du tracker — donc de s'y connecter — juste pour
    // savoir de quel film il s'agit.
    //
    // En POST parce qu'un lot de soixante titres de release ne tient pas dans
    // une URL, et derrière le CSRF comme tout ce qui n'est pas une lecture
    // simple. Rien n'est écrit hors du cache.
    if ($action === 'meta') {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            json_response(['error' => 'Méthode non autorisée.'], 405);
        }
        if (!verify_csrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf'] ?? null))) {
            json_response(['error' => 'Jeton CSRF invalide.'], 403);
        }
        if (!MetadataClient::isConfigured($config['arr'])) {
            json_response(['meta' => [], 'enabled' => false]);
        }

        $brut = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($brut)) {
            $brut = json_decode((string) ($_POST['items'] ?? ''), true);
        }
        $items = [];
        // Plafond : un lot borne la charge sur Radarr/Sonarr, et le front n'en
        // demande de toute façon que ce qui est à l'écran.
        foreach (array_slice(is_array($brut) ? $brut : [], 0, 60) as $entree) {
            if (!is_array($entree) || !isset($entree['title'])) {
                continue;
            }
            $items[] = [
                'title'  => mb_substr((string) $entree['title'], 0, 300),
                'kind'   => in_array($entree['kind'] ?? null, ['movie', 'tv'], true) ? (string) $entree['kind'] : '',
                'imdbId' => (string) ($entree['imdbId'] ?? ''),
                'tmdbId' => (int) ($entree['tmdbId'] ?? 0),
            ];
        }
        // (object) et non [] : un tableau PHP vide s'encode en `[]`, et un
        // consommateur qui attend un dictionnaire indexé par titre s'y casse.
        if ($items === []) {
            json_response(['meta' => (object) [], 'enabled' => true]);
        }

        $meta = new MetadataClient(
            $config['arr'],
            $config['secret'],
            $config['data_dir'] . '/meta',
            min(15, (int) $config['timeout']),
        );
        // Idem : les titres servent de clés, et PHP transforme en tableau tout
        // dictionnaire dont les clés se trouvent être des entiers consécutifs.
        json_response(['meta' => (object) $meta->lookupMany($items), 'enabled' => true]);
    }

    // Bibliothèque : ce que le client de téléchargement a posé sur le disque.
    // Chaque entrée porte son lien de lecture, scellé et daté — c'est lui qui
    // sert d'autorisation à VLC, qui n'a pas de session.
    if ($action === 'library') {
        $library = new Library($config['media_dir'], $config['cache_dir']);
        if (!$library->available()) {
            json_response(['files' => [], 'enabled' => false]);
        }

        // Les fichiers masqués n'apparaissent plus, sauf demande explicite —
        // c'est ce qui permet de les réafficher.
        $masques = $store->hiddenFiles();
        $tout = ((string) ($_GET['all'] ?? '')) === '1';

        $fichiers = [];
        foreach ($library->scan() as $f) {
            $cache = isset($masques[$f['rel']]);
            if ($cache && !$tout) {
                continue;
            }
            $f['hidden'] = $cache;
            // Le dossier porte souvent le nom de la release là où le fichier ne
            // porte qu'un numéro d'épisode : c'est le meilleur candidat pour
            // retrouver l'affiche.
            $f['title'] = $f['folder'] !== '' ? $f['folder'] : $f['name'];
            $f['stream'] = seal_url('media|' . $f['rel'], $config['secret'], $config['stream_ttl']);
            unset($f['rel']);
            $fichiers[] = $f;
        }
        json_response([
            'files'   => $fichiers,
            'enabled' => true,
            'hiddenCount' => count($masques),
            // Le lien vaut autorisation : l'interface doit pouvoir dire combien
            // de temps il reste valable avant d'être recopié dans une télévision.
            'ttl'     => (int) $config['stream_ttl'],
        ]);
    }

    // Récepteurs Cast vus sur le réseau. La liste est produite par le service de
    // découverte (bin/cast-discover.php), qui seul est sur le réseau de la
    // maison — le multicast mDNS ne traverse pas le bridge Docker.
    if ($action === 'cast-devices') {
        $fichier = $config['data_dir'] . '/cast-devices.json';
        $appareils = [];
        $vu = null;
        if (is_file($fichier)) {
            $lu = json_decode((string) @file_get_contents($fichier), true);
            if (is_array($lu)) {
                $appareils = is_array($lu['devices'] ?? null) ? $lu['devices'] : [];
                $vu = (int) ($lu['at'] ?? 0) ?: null;
            }
        }
        json_response([
            'devices' => array_values($appareils),
            // null = le service de découverte n'a jamais écrit : il n'est pas
            // démarré. C'est différent de « aucun appareil trouvé ».
            'scannedAt' => $vu,
            'base'      => cast_base_url($config),
            // Un téléviseur ne sait pas joindre le « localhost » du serveur.
            // Autant le dire avant que l'utilisateur ne cherche pourquoi.
            'reachable' => cast_base_reachable(cast_base_url($config)),
        ]);
    }

    // Envoi d'une vidéo vers un récepteur. POST + CSRF : c'est une action, pas
    // une lecture, et elle allume une télévision.
    if ($action === 'cast') {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            json_response(['error' => 'Méthode non autorisée.'], 405);
        }
        if (!verify_csrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf'] ?? null))) {
            json_response(['error' => 'Jeton CSRF invalide.'], 403);
        }

        $hote = trim((string) ($_POST['host'] ?? ''));
        // L'hôte vient du client : on n'accepte qu'une adresse IP littérale, et
        // seulement dans une plage de réseau domestique. Un nom à résoudre, une
        // IP publique ou une adresse réservée feraient de cet endpoint un
        // scanner de ports à la demande — 169.254.169.254 en tête.
        if (!ip_is_lan($hote)) {
            json_response(['error' => 'Adresse de téléviseur invalide (réseau local attendu).'], 400);
        }
        $port = (int) ($_POST['port'] ?? 8009);
        if ($port < 1 || $port > 65535) {
            $port = 8009;
        }

        $commande = (string) ($_POST['command'] ?? '');
        $client = new CastClient($hote, $port, min(12, (int) $config['timeout']));

        try {
            if (in_array($commande, ['PLAY', 'PAUSE', 'STOP'], true)) {
                $client->control($commande);
                json_response(['ok' => true, 'message' => 'Commande envoyée.']);
            }

            // Lecture : la cible vient d'un jeton scellé par nous, jamais d'une
            // URL fournie par le client.
            $claim = open_url((string) ($_POST['token'] ?? ''), $config['secret']);
            if ($claim === null || !str_starts_with($claim, 'media|')) {
                json_response(['error' => 'Lien de lecture invalide ou expiré.'], 403);
            }
            $library = new Library($config['media_dir'], $config['cache_dir']);
            $fichier = $library->resolve(substr($claim, 6));
            if ($fichier === null) {
                json_response(['error' => 'Fichier introuvable.'], 404);
            }

            $base = cast_base_url($config);
            if (!cast_base_reachable($base)) {
                json_response([
                    'error' => "Le téléviseur ne pourra pas atteindre l'application à l'adresse « {$base} ». "
                        . "Renseignez PUBLIC_BASE_URL avec l'adresse de l'app sur votre réseau local.",
                ], 409);
            }

            // `p=cast` : le serveur décidera, sur les codecs réels, s'il faut
            // convertir. Quand il convertit, la sortie est du MP4 — c'est donc
            // ce type qu'on annonce au téléviseur, pas celui du fichier source.
            $transcoder = new Transcoder($config['ffmpeg'], $config['ffprobe']);
            $convertit = $config['transcode'] && $transcoder->available()
                && $transcoder->decide($transcoder->probe($fichier['path']), $fichier['ext'], 'cast') !== 'direct';

            // Quand il faut convertir, on passe par HLS : c'est le seul format
            // qu'un récepteur Cast enchaîne réellement (cf. Transcoder::hlsCommand).
            if ($convertit) {
                // La session HLS est préparée ici, et c'est son adresse FINALE
                // qu'on transmet : un récepteur Cast ne suit pas les
                // redirections sur un média.
                $mode = $transcoder->decide($transcoder->probe($fichier['path']), $fichier['ext'], 'cast');
                $cle = hls_prepare($transcoder, $fichier, $mode, 'cast', $config);
                if ($cle === null) {
                    json_response(['error' => "La conversion n'a pas pu démarrer."], 502);
                }
                $url = hls_url($base, $cle);
            } else {
                $url = $base . '/stream.php?' . http_build_query(['t' => (string) $_POST['token']]);
            }

            $affiche = trim((string) ($_POST['poster'] ?? ''));
            $affiche = $affiche !== ''
                ? $base . '/poster.php?' . http_build_query(['t' => $affiche])
                : '';

            $app = $client->play(
                $url,
                $convertit ? 'application/x-mpegURL' : Library::mimeFor($fichier['ext']),
                mb_substr(trim((string) ($_POST['title'] ?? '')), 0, 120),
                $affiche
            );
            json_response(['ok' => true, 'message' => "Envoyé au téléviseur ({$app})."]);
        } catch (CastError $e) {
            json_response(['error' => $e->getMessage()], 502);
        }
    }

    // Masquer un fichier de la bibliothèque — SANS y toucher. Il reste sur le
    // disque et continue d'être partagé : sur un tracker privé, désencombrer sa
    // vue ne doit pas coûter son ratio.
    if ($action === 'library-hide') {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            json_response(['error' => 'Méthode non autorisée.'], 405);
        }
        if (!verify_csrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf'] ?? null))) {
            json_response(['error' => 'Jeton CSRF invalide.'], 403);
        }
        if (!$store->available()) {
            json_response(['error' => "Base indisponible : le masquage ne peut pas être mémorisé."], 503);
        }

        $claim = open_url((string) ($_POST['token'] ?? ''), $config['secret']);
        if ($claim === null || !str_starts_with($claim, 'media|')) {
            json_response(['error' => 'Référence invalide ou expirée.'], 403);
        }
        $rel = substr($claim, 6);
        $masquer = ((string) ($_POST['on'] ?? '1')) !== '0';
        $store->setHidden($rel, $masquer, current_user());

        json_response([
            'ok' => true,
            'message' => $masquer
                ? 'Masqué de la bibliothèque — le fichier reste partagé.'
                : 'Réaffiché dans la bibliothèque.',
        ]);
    }

    // Suppression du fichier. Elle passe par qBittorrent, jamais par nous :
    //  - le dossier de médias est monté en LECTURE SEULE, délibérément ;
    //  - effacer un fichier dans le dos du client laisserait un torrent cassé,
    //    en erreur, et un partage rompu sans que personne ne l'ait demandé.
    // Sans torrent correspondant, on ne propose donc que le masquage.
    if ($action === 'library-delete') {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            json_response(['error' => 'Méthode non autorisée.'], 405);
        }
        if (!verify_csrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf'] ?? null))) {
            json_response(['error' => 'Jeton CSRF invalide.'], 403);
        }
        if (!QbittorrentClient::isConfigured($config['qbit_url'])) {
            json_response(['error' => "qBittorrent n'est pas configuré : suppression impossible."], 400);
        }

        $claim = open_url((string) ($_POST['token'] ?? ''), $config['secret']);
        if ($claim === null || !str_starts_with($claim, 'media|')) {
            json_response(['error' => 'Référence invalide ou expirée.'], 403);
        }
        $rel = substr($claim, 6);

        $qc = new QbittorrentClient(
            $config['qbit_url'],
            $config['qbit_user'],
            $config['qbit_pass'],
            $config['qbit_timeout'],
        );
        $torrent = match_torrent($qc->torrents(), $rel);
        if ($torrent === null) {
            json_response([
                'error' => "Aucun torrent ne correspond à ce fichier dans qBittorrent. "
                    . 'Il a pu être déplacé ou retiré : utilisez « Masquer », ou supprimez-le à la main.',
            ], 409);
        }

        try {
            $qc->control('delete', (string) $torrent['hash'], true);
        } catch (Throwable $e) {
            error_log('[indexof] suppression qbit : ' . $e->getMessage());
            json_response(['error' => 'qBittorrent a refusé la suppression.'], 502);
        }
        // Le masquage éventuel n'a plus d'objet une fois le fichier parti.
        $store->setHidden($rel, false);

        json_response(['ok' => true, 'message' => 'Torrent et fichier supprimés.']);
    }

    if ($action === 'indexers') {
        json_response(['indexers' => array_values(array_filter(
            $client->indexers(),
            static fn (array $i): bool => $visible((int) $i['id'])
        ))]);
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
            'offset'   => (int) ($_GET['offset'] ?? 0),
            'allow'    => $allow,
            'user'     => $scope,
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
