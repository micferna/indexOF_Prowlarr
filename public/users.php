<?php

declare(strict_types=1);

/**
 * Gestion des comptes nommés.
 *
 *   POST users.php  op=add     name=… password=…
 *   POST users.php  op=delete  id=…
 *
 * Réservé à l'administrateur, c'est-à-dire à celui qui s'est connecté avec
 * APP_PASSWORD. Un compte nommé ne peut ni en créer ni en supprimer : sans
 * cette limite, n'importe quel utilisateur pourrait s'octroyer un accès ou
 * évincer les autres.
 *
 * La lecture de la liste passe par `api.php?action=users`.
 */

require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/Store.php';

$config = load_config();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
security_headers();

require_auth($config, 'json');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_response(['error' => 'Méthode non autorisée.'], 405);
}
if (!verify_csrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf'] ?? null))) {
    json_response(['error' => 'Jeton CSRF invalide.'], 403);
}
if (!is_admin($config)) {
    json_response(['error' => "Réservé à l'administrateur (connexion par mot de passe partagé)."], 403);
}

$store = new Store($config['db_file']);
if (!$store->available()) {
    json_response(['error' => 'Base indisponible : les comptes ne peuvent pas être gérés.'], 503);
}

$op = (string) ($_POST['op'] ?? '');

if ($op === 'delete') {
    $id = (int) ($_POST['id'] ?? 0);
    if ($id <= 0) {
        json_response(['error' => 'Compte invalide.'], 400);
    }
    $store->deleteUser($id);
    json_response(['ok' => true, 'message' => 'Compte supprimé']);
}

if ($op !== 'add') {
    json_response(['error' => 'Action inconnue.'], 400);
}

$erreur = $store->addUser(
    trim((string) ($_POST['name'] ?? '')),
    (string) ($_POST['password'] ?? '')
);
if ($erreur !== null) {
    json_response(['error' => $erreur], 400);
}
json_response(['ok' => true, 'message' => 'Compte créé']);
