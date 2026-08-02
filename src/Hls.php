<?php

declare(strict_types=1);

require_once __DIR__ . '/Transcoder.php';

/**
 * Lecture HLS : segments et liste de lecture.
 *
 * Partagé entre le point de lecture (stream.php) et l'envoi vers un téléviseur
 * (api.php) : les deux doivent préparer la session exactement de la même façon,
 * et une session déjà produite doit être réutilisée plutôt que refaite.
 */

/**
 * Prépare une lecture HLS et renvoie la clé de sa session (null si échec).
 *
 * Pourquoi HLS plutôt que le flux continu : un récepteur Cast attend une taille
 * annoncée et des requêtes Range. Un MP4 fragmenté produit au fil de l'eau n'a
 * ni l'une ni les autres — la Mi Box en lit une quinzaine de secondes, referme
 * la connexion, et reste bloquée. Découpé en segments avec une liste de lecture,
 * il enchaîne sans difficulté, et le déplacement redevient possible.
 *
 * L'adresse produite est donnée TELLE QUELLE au lecteur : un récepteur Cast ne
 * suit pas les redirections sur un média — mesuré, il redemande trois fois et
 * abandonne. Il faut donc lui remettre l'URL finale, pas un renvoi.
 *
 * ffmpeg tourne DÉTACHÉ : la conversion survit à la requête, sinon elle
 * s'arrêterait dès que le navigateur a récupéré la liste. C'est le seul endroit
 * de l'application où un processus dépasse la durée d'une requête.
 *
 * @param array{path:string,rel:string,size:int,ext:string} $fichier
 * @param array<string,mixed> $config
 */
function hls_prepare(Transcoder $transcoder, array $fichier, string $mode, string $profil, array $config): ?string
{
    $racine = (string) $config['transcode_dir'];
    if ($racine === '' || (!is_dir($racine) && !@mkdir($racine, 0755, true)) || !is_writable($racine)) {
        error_log('[indexof] hls : espace de conversion indisponible (' . $racine . ')');
        return null;
    }
    hls_prune($racine);

    // La session est désignée par le contenu : rejouer le même fichier dans le
    // même mode réutilise les segments déjà produits au lieu de tout refaire.
    $cle = substr(hash_hmac('sha256', $fichier['rel'] . '|' . $mode . '|' . $profil, (string) $config['secret']), 0, 32);
    $dossier = $racine . '/' . $cle;
    $liste   = $dossier . '/index.m3u8';

    if (!is_dir($dossier) && !@mkdir($dossier, 0755, true) && !is_dir($dossier)) {
        return null;
    }

    if (!is_file($liste)) {
        $verrou = @fopen($dossier . '/.lock', 'c');
        // Deux lecteurs qui démarrent en même temps ne doivent pas lancer deux
        // conversions sur le même fichier.
        if ($verrou !== false && flock($verrou, LOCK_EX | LOCK_NB)) {
            $cmd = $transcoder->hlsCommand($fichier['path'], $mode, $profil, $dossier);
            hls_spawn($cmd, $dossier);
            // Le verrou reste tenu par le processus détaché le temps qu'il vive.
            fclose($verrou);
        }
    }

    // On attend d'avoir de quoi commencer : une liste et au moins deux segments,
    // sinon le lecteur démarre sur du vide et abandonne.
    $limite = microtime(true) + 30;
    while (microtime(true) < $limite) {
        if (is_file($liste) && count(glob($dossier . '/seg*.ts') ?: []) >= 2) {
            break;
        }
        usleep(300_000);
    }
    if (!is_file($liste)) {
        $erreur = @file_get_contents($dossier . '/ffmpeg.log') ?: '';
        error_log('[indexof] hls sans liste : ' . substr(trim($erreur), 0, 300));
        return null;
    }

    return $cle;
}

/** Adresse de la liste de lecture, servie directement par nginx. */
function hls_url(string $base, string $cle): string
{
    return rtrim($base, '/') . '/hls/' . rawurlencode($cle) . '/index.m3u8';
}

/**
 * Lance ffmpeg détaché du cycle de vie de la requête.
 *
 * @param list<string> $cmd
 */
function hls_spawn(array $cmd, string $dossier): void
{
    // setsid : le processus survit à la fin de la requête PHP. Sans lui, la
    // conversion mourrait avec le worker php-fpm et la liste resterait vide.
    $shell = 'setsid ' . implode(' ', array_map('escapeshellarg', $cmd))
        . ' > /dev/null 2> ' . escapeshellarg($dossier . '/ffmpeg.log') . ' &';
    @exec($shell);
}

/**
 * Efface les sessions de conversion abandonnées.
 *
 * Un film converti pèse autant que l'original : sans ménage, le disque se
 * remplit en quelques lectures.
 */
function hls_prune(string $racine, int $maxAge = 6 * 3600): void
{
    // Probabiliste : inutile de balayer le disque à chaque requête.
    if (random_int(1, 20) !== 1) {
        return;
    }
    foreach (glob($racine . '/*', GLOB_ONLYDIR) ?: [] as $dossier) {
        $liste = $dossier . '/index.m3u8';
        $vu = is_file($liste) ? (int) filemtime($liste) : (int) filemtime($dossier);
        if (time() - $vu < $maxAge) {
            continue;
        }
        foreach (glob($dossier . '/*') ?: [] as $f) {
            @unlink($f);
        }
        foreach (glob($dossier . '/.*') ?: [] as $f) {
            if (!str_ends_with($f, '/.') && !str_ends_with($f, '/..')) {
                @unlink($f);
            }
        }
        @rmdir($dossier);
    }
}
