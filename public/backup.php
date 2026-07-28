<?php

declare(strict_types=1);

/**
 * Sauvegarde et restauration de la base.
 *
 *   GET  backup.php                 télécharge un instantané
 *   POST backup.php  (fichier)      restaure depuis un instantané
 *
 * Réservé à l'administrateur. Le fichier contient les empreintes de mots de
 * passe et les jetons de flux : c'est un secret, pas un export anodin.
 *
 * L'instantané est produit par `VACUUM INTO`, qui écrit une copie cohérente
 * même pendant une écriture — copier le fichier à chaud donnerait une base
 * potentiellement tronquée, le journal WAL vivant à côté.
 */

require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/Store.php';

$config = load_config();

security_headers();
header('Cache-Control: no-store');

require_auth($config, 'html');

function backup_fail(int $code, string $message): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!is_admin($config)) {
    backup_fail(403, "Réservé à l'administrateur.");
}

$fichier = (string) $config['db_file'];

/* ------------------------------------------------------------------ *
 * Téléchargement
 * ------------------------------------------------------------------ */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    if (!is_file($fichier)) {
        backup_fail(404, 'Aucune base à sauvegarder.');
    }

    // L'instantané est écrit à côté de la base, pas dans /tmp : il contient les
    // mêmes secrets, il mérite le même répertoire privé.
    $snapshot = dirname($fichier) . '/.backup-' . bin2hex(random_bytes(6)) . '.sqlite';
    try {
        $pdo = new PDO('sqlite:' . $fichier, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('VACUUM INTO ' . $pdo->quote($snapshot));
        @chmod($snapshot, 0600);
    } catch (Throwable $e) {
        @unlink($snapshot);
        error_log('[indexof] sauvegarde impossible : ' . $e->getMessage());
        backup_fail(500, 'Sauvegarde impossible.');
    }

    header('Content-Type: application/vnd.sqlite3');
    header('Content-Disposition: attachment; filename="indexof-' . gmdate('Y-m-d-Hi') . '.sqlite"');
    header('Content-Length: ' . (string) filesize($snapshot));
    readfile($snapshot);
    @unlink($snapshot);
    exit;
}

/* ------------------------------------------------------------------ *
 * Restauration
 * ------------------------------------------------------------------ */
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    backup_fail(405, 'Méthode non autorisée.');
}
if (!verify_csrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf'] ?? null))) {
    backup_fail(403, 'Jeton CSRF invalide.');
}

$envoi = $_FILES['fichier'] ?? null;
if (!is_array($envoi) || ($envoi['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    backup_fail(400, 'Aucun fichier reçu.');
}
if (!is_uploaded_file((string) $envoi['tmp_name'])) {
    backup_fail(400, 'Envoi invalide.');
}
if ((int) $envoi['size'] > 64 * 1024 * 1024) {
    backup_fail(413, 'Fichier trop volumineux.');
}

// On ne remplace la base qu'après avoir vérifié que le fichier EST une base
// indexOF : un fichier quelconque écraserait comptes et recherches sans retour.
$candidat = (string) $envoi['tmp_name'];
try {
    $test = new PDO('sqlite:' . $candidat, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $tables = [];
    foreach ($test->query("SELECT name FROM sqlite_master WHERE type='table'") ?: [] as $row) {
        $tables[] = (string) $row['name'];
    }
    unset($test);
} catch (Throwable $e) {
    backup_fail(400, "Ce fichier n'est pas une base SQLite lisible.");
}

foreach (['users', 'searches', 'sends'] as $attendue) {
    if (!in_array($attendue, $tables, true)) {
        backup_fail(400, "Ce fichier n'est pas une sauvegarde indexOF (table « {$attendue} » absente).");
    }
}

// La base courante est mise de côté avant remplacement : une restauration
// erronée reste réparable.
$precedente = $fichier . '.avant-restauration';
if (is_file($fichier) && !@copy($fichier, $precedente)) {
    backup_fail(500, 'Impossible de mettre la base actuelle de côté.');
}
// Cette copie contient les mêmes empreintes de mots de passe que la base : elle
// mérite les mêmes droits, pas ceux que laisse l'umask.
@chmod($precedente, 0600);

// Le journal WAL de l'ancienne base n'a plus de sens face au nouveau fichier.
foreach ([$fichier . '-wal', $fichier . '-shm'] as $annexe) {
    @unlink($annexe);
}
if (!@copy($candidat, $fichier)) {
    @copy($precedente, $fichier);
    backup_fail(500, 'Restauration impossible.');
}
@chmod($fichier, 0600);

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'ok' => true,
    'message' => 'Base restaurée. Reconnectez-vous : les comptes ont changé.',
], JSON_UNESCAPED_UNICODE);
