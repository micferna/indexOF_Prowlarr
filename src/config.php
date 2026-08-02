<?php

declare(strict_types=1);

/**
 * Cibles *arr configurées : SONARR_URL + SONARR_API_KEY, RADARR_*, etc.
 *
 * @param callable(string,?string):?string $get
 * @return array<string,array{label:string,api:string,url:string,key:string}>
 */
function arr_targets(callable $get): array
{
    require_once __DIR__ . '/ArrClient.php';

    $targets = [];
    foreach (ArrClient::TARGETS as $name => $meta) {
        $url = rtrim((string) $get(strtoupper($name) . '_URL', ''), '/');
        $key = (string) $get(strtoupper($name) . '_API_KEY', '');
        if ($url === '' || $key === '') {
            continue;
        }
        $targets[$name] = $meta + ['url' => $url, 'key' => $key];
    }
    return $targets;
}

/**
 * Interrompt le démarrage sur un écran de diagnostic.
 *
 * Une installation neuve se trompe presque toujours sur les mêmes quatre
 * lignes ; autant les nommer toutes, avec le remède, plutôt que de renvoyer un
 * « Configuration manquante » sec.
 *
 * Cet écran ne fait que lire et rendre compte : il n'écrit RIEN. Un formulaire
 * de configuration accessible sans authentification — puisqu'il n'y a pas
 * encore de mot de passe — serait une porte ouverte sur l'hôte.
 *
 * @param array<int,array{cle:string,probleme:string,remede:string,exemple:string}> $manques
 */
function config_incomplete(array $manques): never
{
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "Configuration incomplète :" . PHP_EOL);
        foreach ($manques as $m) {
            fwrite(STDERR, sprintf("  - %s : %s%s    %s%s", $m['cle'], $m['probleme'], PHP_EOL, $m['remede'], PHP_EOL));
        }
        exit(1);
    }

    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'");
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');

    $esc = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    $lignes = '';
    foreach ($manques as $m) {
        $lignes .= '<li><code>' . $esc($m['cle']) . '</code> <em>' . $esc($m['probleme']) . '</em>'
            . '<p>' . $esc($m['remede']) . '</p>'
            . '<pre>' . $esc($m['exemple']) . '</pre></li>';
    }

    $n = count($manques);
    echo '<!doctype html><html lang="fr"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>Configuration à compléter — indexOF</title><style>'
        . 'body{margin:0;padding:32px 20px;background:#0f141b;color:#e2e8f0;'
        . 'font:15px/1.6 system-ui,-apple-system,"Segoe UI",sans-serif}'
        . 'main{max-width:640px;margin:0 auto}'
        . 'h1{font-size:20px;margin:0 0 6px}'
        . '.sub{color:#94a3b8;margin:0 0 26px}'
        . 'ol{list-style:none;margin:0;padding:0}'
        . 'li{border:1px solid #1e293b;border-left:3px solid #f59e0b;border-radius:8px;'
        . 'padding:14px 16px;margin-bottom:12px;background:#141b24}'
        . 'code{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;color:#f59e0b;font-size:14px}'
        . 'em{color:#94a3b8;font-style:normal;font-size:13px;margin-left:8px}'
        . 'li p{margin:8px 0 0;color:#cbd5e1;font-size:13.5px}'
        . 'pre{margin:8px 0 0;padding:8px 10px;background:#0f141b;border:1px solid #1e293b;'
        . 'border-radius:6px;overflow-x:auto;font-size:12.5px;color:#e2e8f0}'
        . 'footer{margin-top:24px;color:#64748b;font-size:13px}'
        . '</style></head><body><main>'
        . '<h1>Il manque ' . $n . ' réglage' . ($n > 1 ? 's' : '') . '</h1>'
        . '<p class="sub">Complétez le fichier <code>.env</code> à la racine, puis redémarrez&nbsp;: '
        . '<code>docker compose up -d</code></p>'
        . '<ol>' . $lignes . '</ol>'
        . '<footer>Cet écran lit la configuration, il ne la modifie pas&nbsp;: sans mot de passe défini, '
        . 'un formulaire ici serait accessible à n\'importe qui. Partez de <code>.env.example</code>.</footer>'
        . '</main></body></html>';
    exit;
}

/**
 * Charge la configuration depuis les variables d'environnement (priorité Docker)
 * avec repli sur un fichier .env à la racine du projet pour le développement local.
 *
 * @return array{
 *   api_key:string, base_url:string, secret:string,
 *   timeout:int, qbit_timeout:int, cache_ttl:int, cache_dir:string,
 *   password:string, limit:int,
 *   media_dir:string, stream_ttl:int, public_url:string,
 *   transcode:bool, transcode_max:int, transcode_dir:string, ffmpeg:string, ffprobe:string,
 *   data_dir:string, db_file:string, qbit_url:string, qbit_user:string, qbit_pass:string,
 *   arr:array<string,array{label:string,api:string,url:string,key:string}>
 * }
 */
function load_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $env = [];
    foreach ([__DIR__ . '/../.env', __DIR__ . '/../../.env'] as $envFile) {
        if (is_readable($envFile)) {
            $parsed = @parse_ini_file($envFile, false, INI_SCANNER_RAW);
            if (is_array($parsed)) {
                $env = $parsed;
            }
            break;
        }
    }

    $get = static function (string $key, ?string $default = null) use ($env): ?string {
        $value = getenv($key);
        if ($value === false || $value === '') {
            $value = $env[$key] ?? $default;
        }
        return $value !== null ? trim((string) $value) : null;
    };

    // Tous les manques sont relevés d'un coup : corriger sa configuration une
    // ligne à la fois, en rechargeant entre chaque, est une perte de temps.
    $manques = [];

    $apiKey  = (string) $get('PROWLARR_API_KEY', '');
    $baseUrl = (string) $get('PROWLARR_BASE_URL', '');

    if ($apiKey === '') {
        $manques[] = [
            'cle'      => 'PROWLARR_API_KEY',
            'probleme' => 'absente',
            'remede'   => 'Prowlarr → Settings → General → API Key.',
            'exemple'  => 'PROWLARR_API_KEY=xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
        ];
    }
    if ($baseUrl === '') {
        $manques[] = [
            'cle'      => 'PROWLARR_BASE_URL',
            'probleme' => 'absente',
            'remede'   => "L'adresse de Prowlarr vue depuis ce conteneur, pas depuis votre navigateur.",
            'exemple'  => 'PROWLARR_BASE_URL=http://prowlarr:9696',
        ];
    } elseif (!preg_match('~^https?://~i', $baseUrl)) {
        $manques[] = [
            'cle'      => 'PROWLARR_BASE_URL',
            'probleme' => 'sans schéma http:// ou https://',
            'remede'   => "L'adresse doit commencer par http:// ou https://.",
            'exemple'  => 'PROWLARR_BASE_URL=http://prowlarr:9696',
        ];
    }

    // APP_SECRET : obligatoire et fort. Pas de repli déterministe — il serait
    // dérivable de la clé API (exposée au backend) et permettrait de forger des
    // jetons de téléchargement.
    $secret = (string) $get('APP_SECRET', '');
    if ($secret === '' || strlen($secret) < 16 || str_starts_with($secret, 'changeme')) {
        $manques[] = [
            'cle'      => 'APP_SECRET',
            'probleme' => $secret === '' ? 'absente' : 'trop faible (32 caractères aléatoires attendus)',
            'remede'   => 'Cette clé chiffre les liens de téléchargement. Tirez-la au hasard, ne la choisissez pas :',
            'exemple'  => 'openssl rand -hex 32',
        ];
    }

    // Fail-closed : refuse de démarrer sans mot de passe, sauf désactivation
    // explicite et consciente de l'authentification.
    $password = (string) $get('APP_PASSWORD', '');
    $authDisabled = $get('AUTH_DISABLED', '0') === '1';
    if ($password === '' && !$authDisabled) {
        $manques[] = [
            'cle'      => 'APP_PASSWORD',
            'probleme' => 'absente',
            'remede'   => "Le mot de passe d'entrée, qui donne aussi les droits d'administration. "
                . 'Pour ouvrir volontairement le site à tous : AUTH_DISABLED=1.',
            'exemple'  => 'openssl rand -base64 24',
        ];
    }

    if ($manques !== []) {
        config_incomplete($manques);
    }

    $config = [
        'api_key'      => $apiKey,
        'base_url'     => rtrim($baseUrl, '/'),
        'secret'       => $secret,
        // Une recherche interroge les trackers en direct : 1 à 5 s en temps
        // normal, bien plus quand Prowlarr retente un indexeur en erreur. Trop
        // court = 502 alors que la recherche aurait abouti.
        'timeout'      => max(1, (int) $get('PROWLARR_TIMEOUT', '45')),
        // qBittorrent est local : inutile de le laisser bloquer aussi longtemps.
        'qbit_timeout' => max(1, (int) $get('QBITTORRENT_TIMEOUT', '15')),
        'cache_ttl'    => max(0, (int) $get('CACHE_TTL', '120')),
        'cache_dir'    => $get('CACHE_DIR', sys_get_temp_dir() . '/indexof_cache'),
        // Bibliothèque : le dossier où le client de téléchargement dépose les
        // fichiers, monté en lecture seule. Absent ou vide = la vue disparaît
        // de l'interface, le reste continue de fonctionner.
        'media_dir'    => rtrim((string) $get('MEDIA_DIR', '/media'), '/'),
        // Durée de validité d'un lien de lecture. Il vaut autorisation à lui
        // seul (VLC sur une télévision n'a pas de session), donc il expire —
        // assez long pour un film et ses pauses, pas plus.
        'stream_ttl'   => max(600, (int) $get('STREAM_TTL', '43200')),
        // Adresse de l'application VUE DEPUIS LE RÉSEAU LOCAL. C'est elle qu'on
        // donne au téléviseur : il ira chercher la vidéo lui-même, et il ne
        // saura pas quoi faire d'un « localhost ». Vide = déduite de la requête,
        // ce qui est juste dès qu'on n'accède pas à l'app depuis la machine elle-même.
        'public_url'   => rtrim((string) $get('PUBLIC_BASE_URL', ''), '/'),
        // Conversion à la volée quand l'appareil ne sait pas lire le fichier.
        // À 0, la lecture reste directe : ce qui passe passe, le reste ne passe
        // pas — mais aucun processeur n'est mobilisé.
        'transcode'    => $get('TRANSCODE', '1') !== '0',
        // Plafond de conversions simultanées. Chacune occupe un worker php-fpm
        // et un cœur : sans limite, trois lectures suffisent à figer la machine.
        'transcode_max' => max(1, (int) $get('TRANSCODE_MAX', '2')),
        // Espace de travail des segments HLS. Inscriptible, sur un disque avec
        // de l'espace : un film converti pèse autant que l'original.
        'transcode_dir' => rtrim((string) $get('TRANSCODE_DIR_CONTAINER', '/transcode'), '/'),
        'ffmpeg'       => (string) $get('FFMPEG_BIN', 'ffmpeg'),
        'ffprobe'      => (string) $get('FFPROBE_BIN', 'ffprobe'),
        // Volume persistant : base SQLite, fiches de médias et affiches. /tmp
        // n'irait pas — il est en tmpfs, donc en RAM et vidé à chaque
        // redémarrage, alors qu'une affiche se garde des semaines.
        'data_dir'     => rtrim((string) $get('DATA_DIR', '/var/lib/indexof'), '/'),
        // Base SQLite (historique d'envois, recherches enregistrées).
        // Inaccessible = les fonctionnalités concernées disparaissent,
        // l'app fonctionne quand même.
        'db_file'      => rtrim((string) $get('DATA_DIR', '/var/lib/indexof'), '/') . '/indexof.sqlite',
        // Authentification (mot de passe unique). Vide => exige AUTH_DISABLED=1.
        'password'     => $password,
        // Limite de résultats par page (pagination "charger plus").
        'limit'        => max(10, (int) $get('RESULT_LIMIT', '200')),
        // Client qBittorrent (Web API v2). URL vide = fonctionnalité désactivée.
        'qbit_url'     => rtrim((string) $get('QBITTORRENT_URL', ''), '/'),
        'qbit_user'    => (string) $get('QBITTORRENT_USER', ''),
        'qbit_pass'    => (string) $get('QBITTORRENT_PASS', ''),
        // Applications *arr : chaque cible n'est active que si son URL et sa
        // clé API sont renseignées. Vide = bouton absent de l'interface.
        'arr'          => arr_targets($get),
    ];

    return $config;
}
