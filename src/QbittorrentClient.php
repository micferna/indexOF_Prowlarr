<?php

declare(strict_types=1);

/**
 * Client minimal pour l'API Web v2 de qBittorrent (login + ajout par URL/magnet).
 *
 * Réutilise un seul handle cURL pour conserver le cookie de session (SID).
 */
final class QbittorrentClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $username,
        private readonly string $password,
        private readonly int $timeout = 15,
    ) {
    }

    public static function isConfigured(string $baseUrl): bool
    {
        return $baseUrl !== '';
    }

    /**
     * Ajoute un torrent par URL (http .torrent) ou lien magnet.
     * qBittorrent récupère lui-même la ressource.
     *
     * Tente l'ajout directement (cas où l'IP est en liste blanche qBittorrent) ;
     * ne se connecte avec les identifiants que si l'auth est exigée (401/403).
     *
     * @throws RuntimeException en cas d'échec d'auth ou d'ajout.
     */
    public function add(string $url, ?string $category = null): void
    {
        $ch = curl_init();
        if ($ch === false) {
            throw new RuntimeException("Impossible d'initialiser la connexion à qBittorrent.");
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => min(10, $this->timeout),
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_COOKIEFILE     => '', // active le moteur de cookies en mémoire
            CURLOPT_REFERER        => $this->baseUrl,
        ]);

        $fields = ['urls' => $url];
        if ($category !== null && $category !== '') {
            $fields['category'] = $category;
        }

        try {
            [$status] = $this->post($ch, '/api/v2/torrents/add', $fields);

            // Auth requise : on se connecte puis on réessaie.
            if ($status === 401 || $status === 403) {
                $this->login($ch);
                [$status] = $this->post($ch, '/api/v2/torrents/add', $fields);
            }

            // /torrents/add renvoie 200 (corps souvent vide) en cas de succès,
            // 415 si le torrent est invalide.
            if ($status < 200 || $status >= 300) {
                throw new RuntimeException('qBittorrent a refusé le torrent.');
            }
        } finally {
            curl_close($ch);
        }
    }

    /**
     * Liste des catégories qBittorrent (best-effort, [] si indisponible).
     *
     * @return array<int,string>
     */
    public function categories(): array
    {
        $ch = curl_init();
        if ($ch === false) {
            return [];
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => min(10, $this->timeout),
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_COOKIEFILE     => '',
            CURLOPT_REFERER        => $this->baseUrl,
        ]);

        try {
            $body = $this->get($ch, '/api/v2/torrents/categories');
            if ($body === null) { // auth requise
                $this->login($ch);
                $body = $this->get($ch, '/api/v2/torrents/categories');
            }
            $data = json_decode((string) $body, true);
            if (!is_array($data)) {
                return [];
            }
            return array_map('strval', array_keys($data));
        } catch (Throwable $e) {
            return [];
        } finally {
            curl_close($ch);
        }
    }

    /**
     * @param \CurlHandle $ch
     * @return string|null  null si l'authentification est requise (401/403)
     */
    private function get($ch, string $path): ?string
    {
        curl_setopt($ch, CURLOPT_HTTPGET, true);
        curl_setopt($ch, CURLOPT_URL, $this->baseUrl . $path);

        $response = curl_exec($ch);
        if ($response === false || curl_errno($ch) !== 0) {
            throw new RuntimeException('qBittorrent injoignable : ' . curl_error($ch));
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if ($status === 401 || $status === 403) {
            return null;
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException("qBittorrent a répondu HTTP {$status}.");
        }
        return (string) $response;
    }

    /** @param \CurlHandle $ch */
    private function login($ch): void
    {
        [$status, $body] = $this->post($ch, '/api/v2/auth/login', [
            'username' => $this->username,
            'password' => $this->password,
        ]);
        if ($status < 200 || $status >= 300 || stripos($body, 'Ok.') === false) {
            throw new RuntimeException('Authentification qBittorrent refusée.');
        }
    }

    /**
     * @param \CurlHandle $ch
     * @param array<string,string> $fields
     * @return array{0:int,1:string} [code HTTP, corps]
     */
    private function post($ch, string $path, array $fields): array
    {
        curl_setopt($ch, CURLOPT_URL, $this->baseUrl . $path);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));

        $response = curl_exec($ch);
        if ($response === false || curl_errno($ch) !== 0) {
            throw new RuntimeException('qBittorrent injoignable : ' . curl_error($ch));
        }
        return [(int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), (string) $response];
    }
}
