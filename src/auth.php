<?php

declare(strict_types=1);

/**
 * Authentification minimale (mot de passe unique) + protection CSRF.
 *
 * L'auth est active uniquement si APP_PASSWORD est défini. Le jeton CSRF est
 * toujours émis : il protège les endpoints d'écriture (envoi qBittorrent).
 */

/** Démarre la session avec des paramètres de cookie durcis. */
function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $https = (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    // Anti-fixation : rejette tout ID de session non initialisé par le serveur ;
    // cookie strictement serveur, Secure quand on est en HTTPS.
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    if ($https) {
        ini_set('session.cookie_secure', '1');
    }

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => $https,
    ]);
    session_name('indexof_sid');
    session_start();

    // Expiration par inactivité (8 h) : une session oubliée ne vit pas indéfiniment.
    $now = time();
    if (isset($_SESSION['last']) && ($now - (int) $_SESSION['last']) > 8 * 3600) {
        $_SESSION = [];
        session_regenerate_id(true);
    }
    $_SESSION['last'] = $now;
}

/** @param array<string,mixed> $config */
function auth_enabled(array $config): bool
{
    return ($config['password'] ?? '') !== '';
}

/** @param array<string,mixed> $config */
function is_authenticated(array $config): bool
{
    return !auth_enabled($config) || ($_SESSION['auth'] ?? false) === true;
}

/**
 * Vérifie le mot de passe (comparaison à temps constant).
 *
 * @param array<string,mixed> $config
 */
function check_password(array $config, string $candidate): bool
{
    return hash_equals((string) $config['password'], $candidate);
}

/** Jeton CSRF de la session (créé si absent). */
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION['csrf'];
}

function verify_csrf(?string $token): bool
{
    return $token !== null && $token !== '' && hash_equals(csrf_token(), $token);
}

/**
 * Garde d'accès. $mode = 'html' (redirige vers le login) ou 'json' (401).
 *
 * @param array<string,mixed> $config
 */
function require_auth(array $config, string $mode = 'html'): void
{
    start_session();
    if (is_authenticated($config)) {
        return;
    }
    if ($mode === 'json') {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Authentification requise.', 'auth' => true]);
    } else {
        header('Location: login.php');
    }
    exit;
}
