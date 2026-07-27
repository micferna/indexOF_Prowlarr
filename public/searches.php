<?php

declare(strict_types=1);

/**
 * Recherches enregistrées.
 *
 *   POST searches.php  op=save    name=… query=… days=… cats=… trackers=… safe=…
 *   POST searches.php  op=delete  id=…
 *   POST searches.php  op=clear-history
 *
 * La lecture passe par `api.php?action=searches`. POST uniquement, protégé par
 * authentification + CSRF.
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

$store = new Store($config['db_file']);
if (!$store->available()) {
    json_response(['error' => 'Enregistrement indisponible (base non accessible).'], 503);
}

$op = (string) ($_POST['op'] ?? '');

if ($op === 'delete') {
    $id = (int) ($_POST['id'] ?? 0);
    if ($id <= 0) {
        json_response(['error' => 'Recherche invalide.'], 400);
    }
    $store->deleteSearch($id);
    json_response(['ok' => true, 'message' => 'Recherche supprimée']);
}

if ($op === 'notify') {
    $id = (int) ($_POST['id'] ?? 0);
    if ($id <= 0) {
        json_response(['error' => 'Recherche invalide.'], 400);
    }
    $on = ((string) ($_POST['on'] ?? '0')) === '1';
    $store->setNotify($id, $on);
    json_response(['ok' => true, 'message' => $on ? 'Notifications activées' : 'Notifications coupées']);
}

if ($op === 'clear-history') {
    $store->clearHistory();
    json_response(['ok' => true, 'message' => 'Historique vidé']);
}

if ($op !== 'save') {
    json_response(['error' => 'Action inconnue.'], 400);
}

// Une recherche enregistrée doit être rejouable : on ne garde que des valeurs
// qui ont un sens pour l'API de recherche.
$name = trim((string) ($_POST['name'] ?? ''));
if ($name === '') {
    json_response(['error' => 'Donnez un nom à cette recherche.'], 400);
}
if (mb_strlen($name) > 80) {
    $name = mb_substr($name, 0, 80);
}

$idList = static fn (string $raw): string => implode(',', array_slice(
    array_values(array_unique(array_filter(
        array_map('intval', explode(',', $raw)),
        static fn (int $n): bool => $n > 0 && $n < 100000
    ))),
    0,
    50
));

$allowedDays = [0, 1, 7, 30, 90];
$days = (int) ($_POST['days'] ?? 0);

$id = $store->saveSearch([
    'name'     => $name,
    'query'    => mb_substr(trim((string) ($_POST['query'] ?? '')), 0, 200),
    'days'     => in_array($days, $allowedDays, true) ? $days : 0,
    'cats'     => $idList((string) ($_POST['cats'] ?? '')),
    'trackers' => $idList((string) ($_POST['trackers'] ?? '')),
    'safe'     => ((string) ($_POST['safe'] ?? '1')) !== '0',
]);

if ($id === 0) {
    json_response(['error' => "Impossible d'enregistrer la recherche."], 500);
}
json_response(['ok' => true, 'id' => $id, 'message' => 'Recherche enregistrée']);
