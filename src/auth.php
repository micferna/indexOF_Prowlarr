<?php

declare(strict_types=1);

/**
 * Authentification + protection CSRF.
 *
 * Deux façons d'entrer :
 *  - APP_PASSWORD seul, sans nom d'utilisateur. Toujours valable, et donne
 *    l'accès administrateur (gestion des comptes).
 *  - un compte nommé, si des comptes existent.
 *
 * APP_PASSWORD reste accepté même quand des comptes existent, et c'est
 * délibéré : c'est ce qui rend impossible de se verrouiller dehors en
 * supprimant un compte ou en oubliant un mot de passe. Sa force est donc ce qui
 * protège l'ensemble.
 *
 * Le jeton CSRF est toujours émis : il protège les endpoints d'écriture.
 */

require_once __DIR__ . '/Store.php';

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

/**
 * Tente une connexion. Renvoie le nom du compte, '' pour l'administrateur
 * (mot de passe partagé), ou null en cas d'échec.
 *
 * @param array<string,mixed> $config
 */
function attempt_login(array $config, ?Store $store, string $name, string $password): ?string
{
    // Sans nom : c'est le mot de passe partagé, donc l'administrateur.
    if (trim($name) === '') {
        return check_password($config, $password) ? '' : null;
    }
    // Avec un nom : un compte nommé, s'il en existe.
    return $store !== null ? $store->checkUser(trim($name), $password) : null;
}

/** Ouvre la session pour un utilisateur donné ('' = administrateur). */
function open_session(string $user): void
{
    session_regenerate_id(true);
    unset($_SESSION['csrf']); // nouveau jeton après élévation de privilège
    $_SESSION['auth']  = true;
    $_SESSION['user']  = $user;
    $_SESSION['admin'] = $user === '';
}

/** Nom de l'utilisateur connecté ('' pour l'administrateur). */
function current_user(): string
{
    return (string) ($_SESSION['user'] ?? '');
}

/**
 * L'administrateur est celui qui s'est connecté avec le mot de passe partagé.
 * Lui seul gère les comptes : un compte nommé ne peut ni en créer ni en
 * supprimer, et ne peut donc pas s'arroger l'accès.
 *
 * @param array<string,mixed> $config
 */
function is_admin(array $config): bool
{
    return !auth_enabled($config) || ($_SESSION['admin'] ?? false) === true;
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

/** Ferme la session en cours (déconnexion, ou compte disparu). */
function close_session(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie((string) session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $p['path'],
            'domain'   => $p['domain'],
            'secure'   => $p['secure'],
            'httponly' => $p['httponly'],
            'samesite' => $p['samesite'],
        ]);
    }
    session_destroy();
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
        // Un compte supprimé — ou effacé par une restauration de sauvegarde —
        // ne doit pas continuer à circuler avec sa session ouverte. Le
        // cloisonnement le priverait déjà de tout indexeur, mais laisser vivre
        // une session sans compte derrière est une incohérence, pas une
        // protection.
        $nom = current_user();
        if ($nom !== '' && !(new Store($config['db_file']))->userExists($nom)) {
            close_session();
        } else {
            return;
        }
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
