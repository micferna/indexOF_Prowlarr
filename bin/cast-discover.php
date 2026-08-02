#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Service de découverte des récepteurs Cast.
 *
 * Tourne à part, en `network_mode: host`, parce que le multicast mDNS ne
 * traverse pas le réseau bridge de Docker : depuis le conteneur applicatif, on
 * n'entend rien. Ce service écoute pour l'application et dépose ce qu'il trouve
 * dans un fichier du volume partagé.
 *
 * Il ne fait que lire le réseau et écrire une liste. Il n'ouvre aucun port,
 * n'expose aucune API, et l'application ne lui parle jamais.
 */

require_once __DIR__ . '/../src/CastDiscovery.php';

$sortie   = rtrim((string) (getenv('DATA_DIR') ?: '/var/lib/indexof'), '/') . '/cast-devices.json';
$interval = max(30, (int) (getenv('CAST_SCAN_INTERVAL') ?: 60));
$duree    = max(2, (int) (getenv('CAST_SCAN_SECONDS') ?: 4));

// Sortir en erreur ferait redémarrer le conteneur en boucle et noierait les
// journaux : on le dit une fois, puis on s'arrête proprement.
if (!function_exists('socket_create')) {
    fwrite(STDERR, "[cast] extension PHP « sockets » absente : découverte impossible.\n");
    exit(0);
}

fwrite(STDERR, "[cast] découverte toutes les {$interval}s, écriture dans {$sortie}\n");

$discovery = new CastDiscovery();

while (true) {
    $debut = time();
    try {
        $appareils = $discovery->discover($duree);
    } catch (Throwable $e) {
        fwrite(STDERR, '[cast] échec de la découverte : ' . $e->getMessage() . "\n");
        $appareils = [];
    }

    // Écriture atomique : l'application lit ce fichier à tout moment, elle ne
    // doit jamais tomber sur un JSON à moitié écrit.
    $temporaire = $sortie . '.tmp';
    $contenu = json_encode(['at' => time(), 'devices' => $appareils], JSON_UNESCAPED_UNICODE);
    if (@file_put_contents($temporaire, (string) $contenu, LOCK_EX) !== false) {
        @rename($temporaire, $sortie);
    } else {
        fwrite(STDERR, "[cast] écriture impossible dans {$sortie}\n");
    }

    fwrite(STDERR, '[cast] ' . count($appareils) . " appareil(s)\n");

    $reste = $interval - (time() - $debut);
    if ($reste > 0) {
        sleep($reste);
    }
}
