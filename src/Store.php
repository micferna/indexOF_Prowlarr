<?php

declare(strict_types=1);

/**
 * Petite base SQLite : un fichier, aucun service à ajouter.
 *
 * Elle ne sert qu'à ce que l'application ne peut pas déduire d'ailleurs :
 * ce qu'elle a envoyé (qBittorrent renomme les torrents, et sans magnet il n'y
 * a aucun hash avant téléchargement), et les recherches mises de côté.
 *
 * Tout est dégradable : si le fichier n'est pas accessible en écriture,
 * `available()` renvoie false et les fonctionnalités concernées disparaissent
 * de l'interface. Une base injoignable ne doit jamais empêcher de chercher.
 */
final class Store
{
    private ?PDO $pdo = null;
    private bool $failed = false;

    public function __construct(private readonly string $file)
    {
    }

    public function available(): bool
    {
        return $this->db() !== null;
    }

    private function db(): ?PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }
        if ($this->failed || $this->file === '') {
            return null;
        }

        try {
            $dir = dirname($this->file);
            if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
                throw new RuntimeException('répertoire de données inaccessible');
            }
            $pdo = new PDO('sqlite:' . $this->file, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            // WAL : plusieurs lectures pendant une écriture, sans verrou global.
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('PRAGMA busy_timeout = 3000');
            $this->migrate($pdo);
            $this->pdo = $pdo;
            return $pdo;
        } catch (Throwable $e) {
            error_log('[indexof] base indisponible : ' . $e->getMessage());
            $this->failed = true;
            return null;
        }
    }

    private function migrate(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS sends (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                title      TEXT NOT NULL,
                title_key  TEXT NOT NULL,
                indexer    TEXT NOT NULL DEFAULT "",
                target     TEXT NOT NULL DEFAULT "qbit",
                hash       TEXT NOT NULL DEFAULT "",
                created_at INTEGER NOT NULL
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_sends_key ON sends (title_key)');
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS searches (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                name       TEXT NOT NULL,
                query      TEXT NOT NULL DEFAULT "",
                days       INTEGER NOT NULL DEFAULT 0,
                cats       TEXT NOT NULL DEFAULT "",
                trackers   TEXT NOT NULL DEFAULT "",
                safe       INTEGER NOT NULL DEFAULT 1,
                created_at INTEGER NOT NULL
            )'
        );
    }

    /** Clé de rapprochement : le titre débarrassé de sa ponctuation et de sa casse. */
    public static function titleKey(string $title): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', strtolower($title));
    }

    public function recordSend(string $title, string $indexer, string $target, string $hash = ''): void
    {
        $pdo = $this->db();
        if ($pdo === null || $title === '') {
            return;
        }
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO sends (title, title_key, indexer, target, hash, created_at)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$title, self::titleKey($title), $indexer, $target, $hash, time()]);
        } catch (Throwable $e) {
            error_log('[indexof] écriture impossible : ' . $e->getMessage());
        }
    }

    /**
     * Clés des releases déjà envoyées, avec la date et la destination du dernier
     * envoi. Sert à marquer les résultats de recherche.
     *
     * @return array<string,array{at:int,target:string}>
     */
    public function sentIndex(int $limit = 2000): array
    {
        $pdo = $this->db();
        if ($pdo === null) {
            return [];
        }
        try {
            $stmt = $pdo->query(
                'SELECT title_key, MAX(created_at) AS at, target
                 FROM sends GROUP BY title_key
                 ORDER BY at DESC LIMIT ' . max(1, $limit)
            );
            $rows = $stmt === false ? [] : $stmt->fetchAll();
        } catch (Throwable $e) {
            return [];
        }

        $index = [];
        foreach ($rows as $r) {
            $index[(string) $r['title_key']] = [
                'at'     => (int) $r['at'],
                'target' => (string) $r['target'],
            ];
        }
        return $index;
    }

    /**
     * Historique des envois, du plus récent au plus ancien.
     *
     * @return array<int,array<string,mixed>>
     */
    public function history(int $limit = 200): array
    {
        $pdo = $this->db();
        if ($pdo === null) {
            return [];
        }
        try {
            $stmt = $pdo->query(
                'SELECT id, title, indexer, target, hash, created_at
                 FROM sends ORDER BY created_at DESC, id DESC LIMIT ' . max(1, $limit)
            );
            return $stmt === false ? [] : $stmt->fetchAll();
        } catch (Throwable $e) {
            return [];
        }
    }

    public function clearHistory(): void
    {
        $pdo = $this->db();
        if ($pdo === null) {
            return;
        }
        try {
            $pdo->exec('DELETE FROM sends');
        } catch (Throwable $e) {
            error_log('[indexof] purge impossible : ' . $e->getMessage());
        }
    }

    /**
     * Enregistre une recherche. Renvoie son identifiant, ou 0 si indisponible.
     *
     * @param array{name:string,query:string,days:int,cats:string,trackers:string,safe:bool} $s
     */
    public function saveSearch(array $s): int
    {
        $pdo = $this->db();
        if ($pdo === null) {
            return 0;
        }
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO searches (name, query, days, cats, trackers, safe, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $s['name'], $s['query'], $s['days'], $s['cats'], $s['trackers'],
                $s['safe'] ? 1 : 0, time(),
            ]);
            return (int) $pdo->lastInsertId();
        } catch (Throwable $e) {
            error_log('[indexof] enregistrement impossible : ' . $e->getMessage());
            return 0;
        }
    }

    /** @return array<int,array<string,mixed>> */
    public function searches(): array
    {
        $pdo = $this->db();
        if ($pdo === null) {
            return [];
        }
        try {
            $stmt = $pdo->query(
                'SELECT id, name, query, days, cats, trackers, safe, created_at
                 FROM searches ORDER BY name COLLATE NOCASE'
            );
            return $stmt === false ? [] : $stmt->fetchAll();
        } catch (Throwable $e) {
            return [];
        }
    }

    public function deleteSearch(int $id): void
    {
        $pdo = $this->db();
        if ($pdo === null) {
            return;
        }
        try {
            $pdo->prepare('DELETE FROM searches WHERE id = ?')->execute([$id]);
        } catch (Throwable $e) {
            error_log('[indexof] suppression impossible : ' . $e->getMessage());
        }
    }
}
