<?php

declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/ProwlarrClient.php';
require_once __DIR__ . '/Store.php';

/**
 * Exécution d'une recherche, partagée par l'API JSON et les flux RSS.
 *
 * Les deux doivent appliquer exactement les mêmes règles — filtrage -18,
 * validation des indexeurs, plafond de résultats, scellement des liens. Les
 * dupliquer, c'est se garantir qu'elles divergeront.
 */

/**
 * Normalise une liste d'identifiants venue de l'extérieur.
 *
 * Un identifiant hors plage faisait échouer toute la recherche côté Prowlarr,
 * et une liste sans limite laissait construire une requête amont énorme.
 *
 * @return array<int,int>
 */
function parse_id_list(string $raw): array
{
    return array_slice(
        array_values(array_unique(array_filter(
            array_map('intval', explode(',', $raw)),
            static fn (int $n): bool => $n > 0 && $n < 100000
        ))),
        0,
        50
    );
}

/**
 * Transforme une release Prowlarr brute en objet d'affichage (liens scellés).
 *
 * @param array<string,mixed> $r
 * @return array<string,mixed>
 */
function map_result(array $r, string $secret): array
{
    // Prowlarr range les liens dans downloadUrl/magnetUrl sans garantie de schéma :
    // magnetUrl peut être une vraie URL magnet: OU une URL HTTP de son endpoint de
    // download. On classe donc par schéma réel, pas par nom de champ.
    $magnet = '';
    $http   = '';
    foreach ([(string) ($r['downloadUrl'] ?? ''), (string) ($r['magnetUrl'] ?? '')] as $u) {
        if ($u === '') {
            continue;
        }
        if ($magnet === '' && str_starts_with($u, 'magnet:')) {
            $magnet = $u;
        } elseif ($http === '' && safe_url($u) !== '#') {
            $http = $u;
        }
    }

    // Jeton opaque pour le téléchargement du .torrent (proxy navigateur).
    // L'URL Prowlarr embarque la clé API : elle est CHIFFRÉE, jamais exposée au
    // client. Seul le serveur peut l'ouvrir.
    $dl = $http !== '' ? ['token' => seal_url($http, $secret)] : null;

    // Jeton d'envoi : cible préférée (magnet si dispo, sinon .torrent).
    // Empêche l'injection d'un torrent arbitraire non issu de l'app.
    $sendTarget = $magnet !== '' ? $magnet : $http;
    $send = $sendTarget !== '' ? seal_url($sendTarget, $secret) : null;

    $title = (string) ($r['title'] ?? 'N/A');

    // Catégorie la plus parlante (première de la liste) + détection adulte (XXX).
    $category = '';
    if (!empty($r['categories']) && is_array($r['categories'])) {
        $first = $r['categories'][0] ?? null;
        if (is_array($first)) {
            $category = (string) ($first['name'] ?? '');
        }
    }

    // Freeleech via les flags d'indexeur.
    $freeleech = false;
    if (!empty($r['indexerFlags']) && is_array($r['indexerFlags'])) {
        foreach ($r['indexerFlags'] as $flag) {
            if (stripos((string) $flag, 'freeleech') !== false) {
                $freeleech = true;
                break;
            }
        }
    }

    $size = (int) ($r['size'] ?? 0);

    return [
        'indexer'     => (string) ($r['indexer'] ?? 'N/A'),
        'title'       => $title,
        'infoUrl'     => safe_url($r['infoUrl'] ?? null),
        'size'        => $size,
        'sizeHuman'   => format_size($size),
        'seeders'     => (int) ($r['seeders'] ?? 0),
        'leechers'    => (int) ($r['leechers'] ?? 0),
        'publishDate' => (string) ($r['publishDate'] ?? ''),
        'daysOld'     => days_since($r['publishDate'] ?? null),
        'category'    => $category,
        'adult'       => is_adult_result($r),
        'badges'      => quality_badges($title),
        'freeleech'   => $freeleech,
        'magnet'      => $magnet !== '' ? $magnet : null,
        'dl'          => $dl,
        'send'        => $send,
    ];
}

/**
 * Exécute une recherche complète et renvoie les résultats prêts à l'affichage.
 *
 * `allow` borne les indexeurs interrogeables : null = aucune restriction, un
 * tableau = uniquement ceux-là. C'est une frontière de sécurité — la sélection
 * demandée par le client est INTERSECTÉE avec elle, jamais réunie.
 *
 * @param array{query?:string,top?:bool,days?:int,cats?:string,trackers?:string,safe?:bool,allow?:array<int,int>|null,user?:string|null} $params
 * @param array<string,mixed> $config
 * @return array{query:string,total:int,count:int,capped:bool,results:array<int,array<string,mixed>>}
 */
function perform_search(ProwlarrClient $client, ?Store $store, array $config, array $params): array
{
    $top   = (bool) ($params['top'] ?? false);
    $query = trim((string) ($params['query'] ?? ''));

    $empty = ['query' => $query, 'total' => 0, 'count' => 0, 'capped' => false, 'results' => []];
    if ($query === '' && !$top) {
        return $empty;
    }

    $allowedDays = [0, 1, 7, 30, 90];
    $days = (int) ($params['days'] ?? 1);
    $days = in_array($days, $allowedDays, true) ? $days : 1;

    $trackers   = parse_id_list((string) ($params['trackers'] ?? ''));
    $categories = parse_id_list((string) ($params['cats'] ?? ''));

    // Cloisonnement : ce que le client demande est réduit à ce qui lui est
    // permis. Une liste autorisée vide n'ouvre rien — elle ferme tout.
    $allow = $params['allow'] ?? null;
    if (is_array($allow)) {
        if ($allow === []) {
            return $empty;
        }
        $trackers = $trackers === [] ? $allow : array_values(array_intersect($trackers, $allow));
        if ($trackers === []) {
            return $empty;
        }
    }

    // Un indexeur inconnu fait échouer toute la recherche côté Prowlarr : on
    // restreint à la liste réelle (en cache). Si plus rien ne subsiste, la
    // sélection ne désigne aucun indexeur existant => résultat vide.
    if ($trackers !== []) {
        try {
            $known = array_column($client->indexers(), 'id');
            $filtered = array_values(array_intersect($trackers, $known));
            if ($filtered === []) {
                return $empty;
            }
            $trackers = $filtered;
        } catch (Throwable $e) {
            error_log('[indexof] liste indexeurs indisponible : ' . $e->getMessage());
        }
    }

    // Safe-mode (-18) APPLIQUÉ CÔTÉ SERVEUR : par défaut le contenu XXX n'est
    // pas renvoyé. Il n'apparaît que si explicitement demandé ou si une
    // catégorie adulte (6000-6999) est sélectionnée. Filtrage AVANT la limite
    // de pagination (sinon le quota fausse les compteurs).
    $safe = (bool) ($params['safe'] ?? true);
    $wantAdult = (bool) array_filter($categories, static fn (int $c): bool => $c >= 6000 && $c < 7000);

    $limit = (int) $config['limit'];
    $all   = $client->search($query, $trackers, $days, $categories, $top);
    if ($safe && !$wantAdult) {
        $all = array_values(array_filter($all, static fn (array $r): bool => !is_adult_result($r)));
    }

    $total = count($all);
    $page  = array_slice($all, 0, $limit);

    // Marquage des releases déjà envoyées : rapprochement sur le titre que NOUS
    // avons enregistré, donc exact — contrairement au nom que le client de
    // téléchargement peut avoir réécrit.
    $sent = $store !== null ? $store->sentIndex(2000, $params['user'] ?? null) : [];
    $results = array_map(
        static function (array $r) use ($config, $sent): array {
            $mapped = map_result($r, $config['secret']);
            $mapped['sent'] = $sent[Store::titleKey($mapped['title'])] ?? null;
            return $mapped;
        },
        $page
    );

    return [
        'query'   => $query,
        'total'   => $total,
        'count'   => count($results),
        'capped'  => $total > $limit,
        'results' => $results,
    ];
}
