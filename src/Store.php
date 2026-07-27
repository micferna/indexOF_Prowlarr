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
                user       TEXT NOT NULL DEFAULT "",
                created_at INTEGER NOT NULL
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_sends_key ON sends (title_key)');

        // Bases créées avant les comptes : on complète sans les recréer.
        $scols = [];
        $sinfo = $pdo->query('PRAGMA table_info(sends)');
        foreach ($sinfo === false ? [] : $sinfo->fetchAll() as $c) {
            $scols[] = (string) ($c['name'] ?? '');
        }
        if (!in_array('user', $scols, true)) {
            $pdo->exec('ALTER TABLE sends ADD COLUMN user TEXT NOT NULL DEFAULT ""');
        }
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS searches (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                name       TEXT NOT NULL,
                query      TEXT NOT NULL DEFAULT "",
                days       INTEGER NOT NULL DEFAULT 0,
                cats       TEXT NOT NULL DEFAULT "",
                trackers   TEXT NOT NULL DEFAULT "",
                safe       INTEGER NOT NULL DEFAULT 1,
                token      TEXT NOT NULL DEFAULT "",
                notify     INTEGER NOT NULL DEFAULT 0,
                created_at INTEGER NOT NULL
            )'
        );

        // Bases créées avant l'ajout des flux : on complète sans les recréer.
        $cols = [];
        $info = $pdo->query('PRAGMA table_info(searches)');
        foreach ($info === false ? [] : $info->fetchAll() as $c) {
            $cols[] = (string) ($c['name'] ?? '');
        }
        if (!in_array('token', $cols, true)) {
            $pdo->exec('ALTER TABLE searches ADD COLUMN token TEXT NOT NULL DEFAULT ""');
        }
        if (!in_array('notify', $cols, true)) {
            $pdo->exec('ALTER TABLE searches ADD COLUMN notify INTEGER NOT NULL DEFAULT 0');
        }
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_searches_token ON searches (token)');

        // Comptes nommés. Facultatifs : APP_PASSWORD reste toujours valable et
        // donne l'accès administrateur — c'est ce qui rend impossible de se
        // verrouiller dehors en créant ou supprimant des comptes.
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS users (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                name       TEXT NOT NULL UNIQUE,
                pass_hash  TEXT NOT NULL,
                created_at INTEGER NOT NULL,
                last_login INTEGER NOT NULL DEFAULT 0
            )'
        );

        // Releases déjà signalées, pour ne notifier que la nouveauté.
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS seen (
                search_id  INTEGER NOT NULL,
                guid       TEXT NOT NULL,
                created_at INTEGER NOT NULL,
                PRIMARY KEY (search_id, guid)
            )'
        );
    }

    /** Clé de rapprochement : le titre débarrassé de sa ponctuation et de sa casse. */
    public static function titleKey(string $title): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', strtolower($title));
    }

    public function recordSend(string $title, string $indexer, string $target, string $hash = '', string $user = ''): void
    {
        $pdo = $this->db();
        if ($pdo === null || $title === '') {
            return;
        }
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO sends (title, title_key, indexer, target, hash, user, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$title, self::titleKey($title), $indexer, $target, $hash, $user, time()]);
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
                'SELECT id, title, indexer, target, hash, user, created_at
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
                'INSERT INTO searches (name, query, days, cats, trackers, safe, token, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $s['name'], $s['query'], $s['days'], $s['cats'], $s['trackers'],
                $s['safe'] ? 1 : 0, bin2hex(random_bytes(16)), time(),
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
                'SELECT id, name, query, days, cats, trackers, safe, token, notify, created_at
                 FROM searches ORDER BY name COLLATE NOCASE'
            );
            return $stmt === false ? [] : $stmt->fetchAll();
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Recherche désignée par son jeton de flux, ou null.
     *
     * @return array<string,mixed>|null
     */
    public function searchByToken(string $token): ?array
    {
        $pdo = $this->db();
        if ($pdo === null || preg_match('/^[a-f0-9]{32}$/', $token) !== 1) {
            return null;
        }
        try {
            $stmt = $pdo->prepare(
                'SELECT id, name, query, days, cats, trackers, safe, token
                 FROM searches WHERE token = ? LIMIT 1'
            );
            $stmt->execute([$token]);
            $row = $stmt->fetch();
            return is_array($row) ? $row : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /** Un jeton de flux valide existe-t-il ? Sert à autoriser un téléchargement hors session. */
    public function feedTokenExists(string $token): bool
    {
        return $this->searchByToken($token) !== null;
    }

    /** Active ou coupe la notification d'une recherche. */
    public function setNotify(int $id, bool $on): void
    {
        $pdo = $this->db();
        if ($pdo === null) {
            return;
        }
        try {
            $pdo->prepare('UPDATE searches SET notify = ? WHERE id = ?')->execute([$on ? 1 : 0, $id]);
        } catch (Throwable $e) {
            error_log('[indexof] mise à jour impossible : ' . $e->getMessage());
        }
    }

    /**
     * Parmi ces identifiants, ceux qui n'ont jamais été signalés — et les marque
     * aussitôt. Faire les deux d'un coup évite qu'un plantage entre les deux
     * étapes ne provoque une seconde notification.
     *
     * @param array<int,string> $guids
     * @return array<int,string>
     */
    public function takeUnseen(int $searchId, array $guids): array
    {
        $pdo = $this->db();
        if ($pdo === null || $guids === []) {
            return [];
        }
        try {
            $check = $pdo->prepare('SELECT 1 FROM seen WHERE search_id = ? AND guid = ?');
            $mark  = $pdo->prepare('INSERT OR IGNORE INTO seen (search_id, guid, created_at) VALUES (?, ?, ?)');
            $now   = time();
            $neufs = [];
            $pdo->beginTransaction();
            foreach ($guids as $g) {
                $check->execute([$searchId, $g]);
                if ($check->fetchColumn() === false) {
                    $neufs[] = $g;
                }
                $mark->execute([$searchId, $g, $now]);
            }
            $pdo->commit();
            return $neufs;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[indexof] suivi des nouveautés impossible : ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Recherches dont la notification est active.
     *
     * @return array<int,array<string,mixed>>
     */
    public function notifiedSearches(): array
    {
        return array_values(array_filter($this->searches(), static fn (array $s): bool => (int) $s['notify'] === 1));
    }

    /* ------------------------------------------------------------------ *
     * Comptes nommés
     * ------------------------------------------------------------------ */

    /** Nom d'utilisateur acceptable : lisible, sans ambiguïté, borné. */
    public static function validName(string $name): bool
    {
        return preg_match('/^[a-zA-Z0-9._-]{2,32}$/', $name) === 1;
    }

    /** @return array<int,array{id:int,name:string,created_at:int,last_login:int}> */
    public function users(): array
    {
        $pdo = $this->db();
        if ($pdo === null) {
            return [];
        }
        try {
            $stmt = $pdo->query('SELECT id, name, created_at, last_login FROM users ORDER BY name COLLATE NOCASE');
            $rows = $stmt === false ? [] : $stmt->fetchAll();
        } catch (Throwable $e) {
            return [];
        }
        return array_map(static fn (array $r): array => [
            'id'         => (int) $r['id'],
            'name'       => (string) $r['name'],
            'created_at' => (int) $r['created_at'],
            'last_login' => (int) $r['last_login'],
        ], $rows);
    }

    public function userCount(): int
    {
        return count($this->users());
    }

    /**
     * Crée un compte. Renvoie un message d'erreur, ou null en cas de succès.
     */
    public function addUser(string $name, string $password): ?string
    {
        $pdo = $this->db();
        if ($pdo === null) {
            return 'Base indisponible.';
        }
        if (!self::validName($name)) {
            return 'Nom invalide (2 à 32 caractères : lettres, chiffres, . _ -).';
        }
        if (strlen($password) < 12) {
            return 'Mot de passe trop court (12 caractères minimum).';
        }
        try {
            $stmt = $pdo->prepare('INSERT INTO users (name, pass_hash, created_at) VALUES (?, ?, ?)');
            $stmt->execute([$name, password_hash($password, PASSWORD_DEFAULT), time()]);
            return null;
        } catch (Throwable $e) {
            return 'Ce nom est déjà pris.';
        }
    }

    public function deleteUser(int $id): void
    {
        $pdo = $this->db();
        if ($pdo === null) {
            return;
        }
        try {
            $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
        } catch (Throwable $e) {
            error_log('[indexof] suppression de compte impossible : ' . $e->getMessage());
        }
    }

    /**
     * Vérifie un couple nom/mot de passe. Renvoie le nom exact en cas de succès.
     *
     * Le hachage est calculé même quand le compte n'existe pas : sans ça, le
     * temps de réponse dirait à un attaquant quels noms existent.
     */
    public function checkUser(string $name, string $password): ?string
    {
        $pdo = $this->db();
        if ($pdo === null || $password === '') {
            return null;
        }
        $factice = '$2y$10$usqI7Xf2Vp4Wlm0Hn8kQdurL3iZ5vJ2cGm1yTqQe7Rn0aPz9sXwCu';
        try {
            $stmt = $pdo->prepare('SELECT name, pass_hash FROM users WHERE name = ? COLLATE NOCASE LIMIT 1');
            $stmt->execute([$name]);
            $row = $stmt->fetch();
        } catch (Throwable $e) {
            return null;
        }
        $hash = is_array($row) ? (string) $row['pass_hash'] : $factice;
        if (!password_verify($password, $hash) || !is_array($row)) {
            return null;
        }
        try {
            $pdo->prepare('UPDATE users SET last_login = ? WHERE name = ?')->execute([time(), $row['name']]);
        } catch (Throwable $e) {
            // Sans conséquence : la connexion est déjà validée.
        }
        return (string) $row['name'];
    }

    public function deleteSearch(int $id): void
    {
        $pdo = $this->db();
        if ($pdo === null) {
            return;
        }
        try {
            $pdo->prepare('DELETE FROM searches WHERE id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM seen WHERE search_id = ?')->execute([$id]);
        } catch (Throwable $e) {
            error_log('[indexof] suppression impossible : ' . $e->getMessage());
        }
    }
}
