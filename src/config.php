<?php

declare(strict_types=1);

/**
 * Charge la configuration depuis les variables d'environnement (priorité Docker)
 * avec repli sur un fichier .env à la racine du projet pour le développement local.
 *
 * @return array{
 *   api_key:string, base_url:string, secret:string,
 *   timeout:int, qbit_timeout:int, cache_ttl:int, cache_dir:string,
 *   password:string, limit:int,
 *   qbit_url:string, qbit_user:string, qbit_pass:string
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

    $apiKey  = $get('PROWLARR_API_KEY');
    $baseUrl = $get('PROWLARR_BASE_URL');

    if ($apiKey === null || $apiKey === '' || $baseUrl === null || $baseUrl === '') {
        http_response_code(500);
        exit('Configuration manquante : définissez PROWLARR_API_KEY et PROWLARR_BASE_URL.');
    }

    // APP_SECRET : obligatoire et fort. Pas de repli déterministe — il serait
    // dérivable de la clé API (exposée au backend) et permettrait de forger des
    // jetons de téléchargement.
    $secret = $get('APP_SECRET');
    if ($secret === null || $secret === '' || strlen($secret) < 16 || str_starts_with($secret, 'changeme')) {
        http_response_code(500);
        exit("Configuration manquante : définissez un APP_SECRET fort (openssl rand -hex 32).");
    }

    // Fail-closed : refuse de démarrer sans mot de passe, sauf désactivation
    // explicite et consciente de l'authentification.
    $password = (string) $get('APP_PASSWORD', '');
    $authDisabled = $get('AUTH_DISABLED', '0') === '1';
    if ($password === '' && !$authDisabled) {
        http_response_code(500);
        exit("Configuration manquante : définissez APP_PASSWORD (ou AUTH_DISABLED=1 pour désactiver explicitement l'authentification).");
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
        // Authentification (mot de passe unique). Vide => exige AUTH_DISABLED=1.
        'password'     => $password,
        // Limite de résultats par page (pagination "charger plus").
        'limit'        => max(10, (int) $get('RESULT_LIMIT', '200')),
        // Client qBittorrent (Web API v2). URL vide = fonctionnalité désactivée.
        'qbit_url'     => rtrim((string) $get('QBITTORRENT_URL', ''), '/'),
        'qbit_user'    => (string) $get('QBITTORRENT_USER', ''),
        'qbit_pass'    => (string) $get('QBITTORRENT_PASS', ''),
    ];

    return $config;
}
