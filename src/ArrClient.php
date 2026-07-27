<?php

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

/**
 * Client pour les applications *arr (Sonarr, Radarr, Lidarr, Readarr).
 *
 * On utilise l'endpoint « release/push » : il sert justement à annoncer une
 * release trouvée ailleurs. L'application la parse, la rapproche de ce qu'elle
 * suit déjà, applique ses profils de qualité, puis la confie à son client de
 * téléchargement. Nous ne décidons rien à sa place — c'est tout l'intérêt de
 * l'intégrer plutôt que de la réimplémenter.
 *
 * L'URL poussée est l'URL Prowlarr réelle (clé API comprise) : elle transite de
 * serveur à serveur, jamais par le navigateur.
 */
final class ArrClient
{
    /**
     * Applications reconnues et version d'API correspondante.
     *
     * @var array<string,array{label:string,api:string}>
     */
    public const TARGETS = [
        'sonarr'  => ['label' => 'Sonarr',  'api' => 'v3'],
        'radarr'  => ['label' => 'Radarr',  'api' => 'v3'],
        'lidarr'  => ['label' => 'Lidarr',  'api' => 'v1'],
        'readarr' => ['label' => 'Readarr', 'api' => 'v1'],
    ];

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly string $api = 'v3',
        private readonly int $timeout = 15,
    ) {
    }

    public static function isKnown(string $target): bool
    {
        return isset(self::TARGETS[$target]);
    }

    /**
     * Pousse une release. Renvoie le message à afficher à l'utilisateur.
     *
     * Un refus n'est pas une erreur : « série non suivie » ou « qualité déjà
     * présente » sont des réponses légitimes, et c'est ce que l'utilisateur a
     * besoin de savoir.
     *
     * @throws RuntimeException si l'application est injoignable ou refuse la requête.
     */
    public function push(string $title, string $target, string $indexer, string $publishDate): string
    {
        $payload = [
            'title'       => $title,
            'protocol'    => 'Torrent',
            'indexer'     => $indexer !== '' ? $indexer : 'indexOF',
            'publishDate' => $publishDate !== '' ? $publishDate : gmdate('c'),
        ];
        // Un magnet ne se télécharge pas : il se transmet dans son propre champ.
        if (str_starts_with($target, 'magnet:')) {
            $payload['magnetUrl'] = $target;
        } else {
            $payload['downloadUrl'] = $target;
        }

        $ch = curl_init($this->baseUrl . '/api/' . $this->api . '/release/push');
        if ($ch === false) {
            throw new RuntimeException("Impossible d'initialiser la requête.");
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => (string) json_encode($payload),
            CURLOPT_CONNECTTIMEOUT => min(10, $this->timeout),
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
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
            throw new RuntimeException("Application injoignable : {$error}");
        }
        if ($status === 401) {
            throw new RuntimeException('Clé API refusée.');
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException("L'application a répondu HTTP {$status}.");
        }

        return self::describe((string) $body);
    }

    /**
     * Traduit la réponse en une phrase utile. L'API renvoie soit un objet, soit
     * un tableau d'objets selon la version.
     */
    private static function describe(string $body): string
    {
        $data = json_decode($body, true);
        if (!is_array($data)) {
            return 'Release transmise.';
        }
        $release = isset($data[0]) && is_array($data[0]) ? $data[0] : $data;

        $rejections = [];
        foreach ((array) ($release['rejections'] ?? []) as $r) {
            $rejections[] = is_array($r) ? (string) ($r['reason'] ?? '') : (string) $r;
        }
        $rejections = array_values(array_filter($rejections));

        if (($release['rejected'] ?? false) === true || $rejections !== []) {
            return $rejections === []
                ? 'Release refusée (aucun média suivi ne correspond).'
                : 'Refusée : ' . implode(' · ', array_slice($rejections, 0, 2));
        }
        if (($release['approved'] ?? false) === true) {
            return 'Release acceptée et mise en téléchargement.';
        }
        return 'Release transmise.';
    }
}
