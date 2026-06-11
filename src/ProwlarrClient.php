<?php

declare(strict_types=1);

/**
 * Client HTTP pour l'API Prowlarr v1.
 *
 * - Authentification via l'en-tête X-Api-Key (jamais dans l'URL).
 * - Timeouts stricts (anti-DoS) et gestion d'erreurs explicite.
 * - Cache fichier optionnel (TTL) pour les recherches et la liste d'indexeurs.
 */
final class ProwlarrClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly int $timeout = 15,
        private readonly int $cacheTtl = 120,
        private readonly ?string $cacheDir = null,
    ) {
    }

    /**
     * Liste des indexeurs configurés.
     *
     * @return array<int,array{id:int,name:string}>
     */
    public function indexers(): array
    {
        $data = $this->cached('indexers', function (): array {
            return $this->request('/api/v1/indexer');
        });

        $indexers = [];
        foreach ($data as $item) {
            if (isset($item['id'], $item['name'])) {
                $indexers[] = ['id' => (int) $item['id'], 'name' => (string) $item['name']];
            }
        }
        usort($indexers, static fn ($a, $b) => strcasecmp($a['name'], $b['name']));
        return $indexers;
    }

    /**
     * Recherche de releases.
     *
     * @param array<int,int> $indexerIds  IDs d'indexeurs (vide = tous)
     * @param array<int,int> $categories  catégories newznab (vide = toutes)
     * @param int            $limit       cap du nombre de résultats (0 = illimité)
     * @return array<int,array<string,mixed>>
     */
    public function search(
        string $query,
        array $indexerIds = [],
        int $maxAgeDays = 0,
        array $categories = [],
        int $limit = 0
    ): array {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $ids  = array_values(array_unique(array_map('intval', $indexerIds)));
        $cats = array_values(array_unique(array_map('intval', $categories)));

        // Prowlarr (ASP.NET) attend des clés répétées : indexerIds=1&indexerIds=2.
        $queryString = http_build_query(['query' => $query, 'type' => 'search']);
        foreach ($ids as $id) {
            $queryString .= '&indexerIds=' . $id;
        }
        foreach ($cats as $cat) {
            $queryString .= '&categories=' . $cat;
        }

        $key = 'search_' . md5($query . '|' . implode(',', $ids) . '|' . implode(',', $cats));
        $results = $this->cached($key, function () use ($queryString): array {
            return $this->request('/api/v1/search?' . $queryString);
        });

        if ($maxAgeDays > 0) {
            $cutoff = time() - ($maxAgeDays * 86400);
            $results = array_values(array_filter($results, static function ($r) use ($cutoff): bool {
                $ts = isset($r['publishDate']) ? strtotime((string) $r['publishDate']) : false;
                return $ts === false || $ts >= $cutoff;
            }));
        }

        // Tri par date décroissante pour que le cap conserve les plus récents.
        usort($results, static function ($a, $b): int {
            return (strtotime((string) ($b['publishDate'] ?? '')) ?: 0)
                <=> (strtotime((string) ($a['publishDate'] ?? '')) ?: 0);
        });

        if ($limit > 0 && count($results) > $limit) {
            $results = array_slice($results, 0, $limit);
        }

        return $results;
    }

    /**
     * Exécute une requête GET et renvoie le tableau JSON décodé.
     *
     * @return array<int|string,mixed>
     * @throws RuntimeException en cas d'erreur réseau, HTTP ou JSON.
     */
    private function request(string $path): array
    {
        $ch = curl_init($this->baseUrl . $path);
        if ($ch === false) {
            throw new RuntimeException("Impossible d'initialiser la requête.");
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => min(10, $this->timeout),
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'X-Api-Key: ' . $this->apiKey,
            ],
        ]);

        $body   = curl_exec($ch);
        $errno  = curl_errno($ch);
        $error  = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($errno !== 0 || $body === false) {
            throw new RuntimeException("Prowlarr injoignable : {$error}");
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException("Prowlarr a répondu HTTP {$status}.");
        }

        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Réponse JSON invalide de Prowlarr.');
        }

        return $decoded;
    }

    /**
     * Cache fichier avec TTL. Renvoie le résultat de $producer (et le met en cache).
     *
     * @param callable():array<int|string,mixed> $producer
     * @return array<int|string,mixed>
     */
    private function cached(string $key, callable $producer): array
    {
        if ($this->cacheTtl <= 0 || $this->cacheDir === null) {
            return $producer();
        }

        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0700, true);
        }
        $file = $this->cacheDir . '/' . preg_replace('/[^a-z0-9_]/i', '', $key) . '.json';

        if (is_file($file) && (time() - filemtime($file)) < $this->cacheTtl) {
            $cached = json_decode((string) file_get_contents($file), true);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $data = $producer();
        @file_put_contents($file, json_encode($data), LOCK_EX);
        return $data;
    }
}
