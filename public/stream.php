<?php

declare(strict_types=1);

/**
 * Lecture d'un fichier de la bibliothèque.
 *
 *   GET stream.php?t=<jeton scellé>[&dl=1]
 *
 * PHP autorise, nginx envoie. Un film de 20 Go relayé par php-fpm mobiliserait
 * un worker pendant toute la lecture et obligerait à réimplémenter les requêtes
 * Range — celles qui permettent d'avancer dans la vidéo. On valide le jeton, on
 * renvoie un `X-Accel-Redirect` vers un emplacement interne, et nginx fait le
 * reste : Range, ETag, reprise, tout est natif.
 *
 * Le jeton FAIT AUTORITÉ, sans session. C'est délibéré et nécessaire : VLC sur
 * une télévision n'a pas le cookie du navigateur. Il est donc chiffré
 * (AES-256-GCM), à durée de vie bornée, et ne désigne qu'un chemin relatif déjà
 * vérifié comme appartenant à la bibliothèque.
 */

require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/Library.php';
require_once __DIR__ . '/../src/Transcoder.php';
require_once __DIR__ . '/../src/Hls.php';

$config = load_config();
security_headers();

function stream_fail(int $code, string $message): never
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo $message;
    exit;
}

/**
 * Diffuse la sortie de ffmpeg au fil de l'eau.
 *
 * Contrairement à la lecture directe, ce chemin-là ne peut pas passer par
 * nginx : il faut un processus par lecture, et donc un worker php-fpm mobilisé
 * tout du long. C'est le prix de la conversion, et la raison du plafond de
 * lectures simultanées : trois transcodages complets suffisent à saturer une
 * machine modeste.
 *
 * @param array{path:string,rel:string,size:int,ext:string} $fichier
 * @param array<string,mixed> $config
 */
function stream_transcoded(
    Transcoder $transcoder,
    array $fichier,
    string $mode,
    string $profil,
    int $start,
    array $config
): never {
    $verrou = transcode_slot($config);
    if ($verrou === null) {
        stream_fail(503, "Trop de conversions en cours. Réessayez dans un instant.");
    }

    $cmd = $transcoder->command($fichier['path'], $mode, $profil, max(0, $start));

    $pipes = [];
    $process = @proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        stream_fail(500, 'Conversion impossible à démarrer.');
    }

    // Filet indispensable : quand le client s'en va, PHP interrompt le script
    // là où il est — le nettoyage écrit en fin de fonction n'est jamais atteint,
    // et ffmpeg continue de convertir le film pour personne. Une fonction
    // d'arrêt, elle, s'exécute dans tous les cas.
    $tue = static function () use (&$process): void {
        if (is_resource($process)) {
            proc_terminate($process, 9);
            proc_close($process);
            $process = null;
        }
    };
    register_shutdown_function($tue);

    // La conversion dure aussi longtemps que le film : ni PHP ni la mise en
    // tampon ne doivent s'y opposer.
    @set_time_limit(0);
    ignore_user_abort(false);
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: video/mp4');
    header('Accept-Ranges: none');
    header('Cache-Control: no-store');
    // Un flux produit au fil de l'eau ne doit pas être accumulé par nginx :
    // sans ça, la lecture ne démarre qu'une fois le film entièrement converti.
    header('X-Accel-Buffering: no');
    header('Content-Disposition: inline');
    // Utile au débogage, et honnête : l'utilisateur peut voir ce qui se passe.
    header('X-Indexof-Mode: ' . $mode);

    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $erreurs = '';

    while (true) {
        // Le navigateur a fermé l'onglet, ou le téléviseur a coupé : sans cette
        // vérification, ffmpeg continuerait de tourner jusqu'à la fin du film.
        if (connection_aborted() !== 0) {
            break;
        }
        $morceau = fread($pipes[1], 65536);
        if ($morceau !== false && $morceau !== '') {
            echo $morceau;
            flush();
            continue;
        }
        $erreurs .= (string) stream_get_contents($pipes[2]);
        $statut = proc_get_status($process);
        if (!$statut['running']) {
            // Reste éventuel du tampon après la fin du processus.
            while (($fin = fread($pipes[1], 65536)) !== false && $fin !== '') {
                echo $fin;
            }
            flush();
            break;
        }
        usleep(20_000);
    }

    foreach ($pipes as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }
    // Fin normale : on termine tout de suite au lieu d'attendre la fonction
    // d'arrêt. proc_close seul attendrait la fin du film si le client est parti.
    $tue();

    if (trim($erreurs) !== '') {
        error_log('[indexof] ffmpeg (' . $mode . ') : ' . substr(trim($erreurs), 0, 500));
    }
    fclose($verrou);
    exit;
}

/**
 * Réserve une place de conversion, ou null si le plafond est atteint.
 *
 * Un verrou exclusif par place : le descripteur reste ouvert le temps de la
 * lecture, et le système le libère même si le processus meurt brutalement —
 * un compteur en fichier, lui, resterait bloqué à jamais.
 *
 * @param array<string,mixed> $config
 * @return resource|null
 */
function transcode_slot(array $config)
{
    $dir = (string) $config['cache_dir'];
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        return null;
    }
    for ($i = 0; $i < max(1, (int) $config['transcode_max']); $i++) {
        $fp = @fopen($dir . '/transcode-' . $i . '.lock', 'c');
        if ($fp === false) {
            continue;
        }
        if (flock($fp, LOCK_EX | LOCK_NB)) {
            return $fp;
        }
        fclose($fp);
    }
    return null;
}

$claim = open_url((string) ($_GET['t'] ?? ''), $config['secret']);
if ($claim === null) {
    stream_fail(403, 'Lien de lecture invalide ou expiré.');
}

// Le jeton scelle « media|<chemin relatif> » : le préfixe empêche qu'un jeton
// émis pour un autre usage (téléchargement .torrent, affiche) serve ici.
if (!str_starts_with($claim, 'media|')) {
    stream_fail(403, 'Lien de lecture invalide.');
}
$rel = substr($claim, 6);

$library = new Library($config['media_dir'], $config['cache_dir']);
if (!$library->available()) {
    stream_fail(503, "La bibliothèque n'est pas configurée.");
}

$fichier = $library->resolve($rel);
if ($fichier === null) {
    stream_fail(404, 'Fichier introuvable.');
}

$nom = basename($fichier['rel']);
$telecharger = ((string) ($_GET['dl'] ?? '')) === '1';

/* ------------------------------------------------------------------ *
 * Conversion à la volée
 *
 * « Si l'appareil sait lire, il lit ; sinon on convertit. » La décision se
 * prend sur les codecs réels, pas sur l'extension : un MKV en H.264/AAC se
 * recopie sans rien réencoder, et seul ce qui doit l'être passe par ffmpeg.
 *
 * Le téléchargement (`dl=1`) n'est jamais converti : on rend le fichier tel
 * qu'il est sur le disque.
 * ------------------------------------------------------------------ */
$profil = (string) ($_GET['p'] ?? '');
$transcoder = new Transcoder($config['ffmpeg'], $config['ffprobe']);
$mode = 'direct';

if (!$telecharger && $config['transcode'] && Transcoder::isProfile($profil) && $transcoder->available()) {
    $mode = $transcoder->decide($transcoder->probe($fichier['path']), $fichier['ext'], $profil);
}

// HLS : demandé explicitement, et seulement s'il y a à convertir. Le Cast, lui,
// reçoit l'adresse finale directement (cf. api.php) — il ne suit pas les renvois.
if (((string) ($_GET['hls'] ?? '')) === '1' && $mode !== 'direct') {
    $cle = hls_prepare($transcoder, $fichier, $mode, $profil, $config);
    if ($cle === null) {
        stream_fail(503, "La conversion n'a pas pu démarrer.");
    }
    header('Location: ' . hls_url('', $cle), true, 302);
    header('Cache-Control: no-store');
    exit;
}

if ($mode !== 'direct') {
    stream_transcoded($transcoder, $fichier, $mode, $profil, (int) ($_GET['start'] ?? 0), $config);
}

header('Content-Type: ' . Library::mimeFor($fichier['ext']));
header('Accept-Ranges: bytes');
// Privé : c'est le contenu d'un utilisateur, jamais mutualisable par un proxy.
header('Cache-Control: private, max-age=3600');
header(
    'Content-Disposition: ' . ($telecharger ? 'attachment' : 'inline')
    . '; filename="' . preg_replace('/[^A-Za-z0-9._ -]/', '_', $nom) . '"'
);

// Emplacement interne côté nginx (cf. docker/nginx.conf). Chaque segment est
// encodé : nginx décode l'URI, et un nom de fichier contenant « ? » ou « # »
// tronquerait le chemin.
$uri = implode('/', array_map('rawurlencode', explode('/', $fichier['rel'])));
header('X-Accel-Redirect: /media-interne/' . $uri);
