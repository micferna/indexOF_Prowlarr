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
                owner      TEXT NOT NULL DEFAULT "",
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
        // Une recherche enregistrée doit savoir à qui elle appartient : son flux
        // RSS s'exécute sans session, c'est le propriétaire qui détermine les
        // indexeurs autorisés. Sans ça, un flux contournerait le cloisonnement.
        if (!in_array('owner', $cols, true)) {
            $pdo->exec('ALTER TABLE searches ADD COLUMN owner TEXT NOT NULL DEFAULT ""');
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
                last_login INTEGER NOT NULL DEFAULT 0,
                indexers   TEXT NOT NULL DEFAULT "",
                category   TEXT NOT NULL DEFAULT ""
            )'
        );

        // Bases créées avant le cloisonnement par indexeur.
        $ucols = [];
        $uinfo = $pdo->query('PRAGMA table_info(users)');
        foreach ($uinfo === false ? [] : $uinfo->fetchAll() as $c) {
            $ucols[] = (string) ($c['name'] ?? '');
        }
        if (!in_array('indexers', $ucols, true)) {
            $pdo->exec('ALTER TABLE users ADD COLUMN indexers TEXT NOT NULL DEFAULT ""');
        }
        if (!in_array('category', $ucols, true)) {
            $pdo->exec('ALTER TABLE users ADD COLUMN category TEXT NOT NULL DEFAULT ""');
        }

        // Fichiers masqués de la bibliothèque. Ce n'est PAS une suppression :
        // le fichier reste sur le disque et continue d'être partagé. C'est la
        // distinction qui compte sur un tracker privé — on veut désencombrer sa
        // vue sans casser son ratio.
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS hidden (
                rel        TEXT PRIMARY KEY,
                user       TEXT NOT NULL DEFAULT "",
                created_at INTEGER NOT NULL
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
    public function sentIndex(int $limit = 2000, ?string $user = null): array
    {
        $pdo = $this->db();
        if ($pdo === null) {
            return [];
        }
        try {
            if ($user === null) {
                $stmt = $pdo->query(
                    'SELECT title_key, MAX(created_at) AS at, target
                     FROM sends GROUP BY title_key
                     ORDER BY at DESC LIMIT ' . max(1, $limit)
                );
                $rows = $stmt === false ? [] : $stmt->fetchAll();
            } else {
                $stmt = $pdo->prepare(
                    'SELECT title_key, MAX(created_at) AS at, target
                     FROM sends WHERE user = ? GROUP BY title_key
                     ORDER BY at DESC LIMIT ' . max(1, $limit)
                );
                $stmt->execute([$user]);
                $rows = $stmt->fetchAll();
            }
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
    public function history(int $limit = 200, ?string $user = null): array
    {
        $pdo = $this->db();
        if ($pdo === null) {
            return [];
        }
        try {
            if ($user === null) {
                $stmt = $pdo->query(
                    'SELECT id, title, indexer, target, hash, user, created_at
                     FROM sends ORDER BY created_at DESC, id DESC LIMIT ' . max(1, $limit)
                );
                return $stmt === false ? [] : $stmt->fetchAll();
            }
            $stmt = $pdo->prepare(
                'SELECT id, title, indexer, target, hash, user, created_at
                 FROM sends WHERE user = ? ORDER BY created_at DESC, id DESC LIMIT ' . max(1, $limit)
            );
            $stmt->execute([$user]);
            return $stmt->fetchAll();
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
     * @param array{name:string,query:string,days:int,cats:string,trackers:string,safe:bool,owner?:string} $s
     */
    public function saveSearch(array $s): int
    {
        $pdo = $this->db();
        if ($pdo === null) {
            return 0;
        }
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO searches (name, query, days, cats, trackers, safe, token, owner, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $s['name'], $s['query'], $s['days'], $s['cats'], $s['trackers'],
                $s['safe'] ? 1 : 0, bin2hex(random_bytes(16)), $s['owner'] ?? '', time(),
            ]);
            return (int) $pdo->lastInsertId();
        } catch (Throwable $e) {
            error_log('[indexof] enregistrement impossible : ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Recherches enregistrées. $owner = null renvoie tout (administrateur) ;
     * sinon seules celles de cette personne.
     *
     * @return array<int,array<string,mixed>>
     */
    public function searches(?string $owner = null): array
    {
        $pdo = $this->db();
        if ($pdo === null) {
            return [];
        }
        try {
            if ($owner === null) {
                $stmt = $pdo->query(
                    'SELECT id, name, query, days, cats, trackers, safe, token, notify, owner, created_at
                     FROM searches ORDER BY name COLLATE NOCASE'
                );
                return $stmt === false ? [] : $stmt->fetchAll();
            }
            $stmt = $pdo->prepare(
                'SELECT id, name, query, days, cats, trackers, safe, token, notify, owner, created_at
                 FROM searches WHERE owner = ? ORDER BY name COLLATE NOCASE'
            );
            $stmt->execute([$owner]);
            return $stmt->fetchAll();
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
                'SELECT id, name, query, days, cats, trackers, safe, token, owner
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

    /** @return array<int,array{id:int,name:string,created_at:int,last_login:int,indexers:string,category:string}> */
    public function users(): array
    {
        $pdo = $this->db();
        if ($pdo === null) {
            return [];
        }
        try {
            $stmt = $pdo->query('SELECT id, name, created_at, last_login, indexers, category FROM users ORDER BY name COLLATE NOCASE');
            $rows = $stmt === false ? [] : $stmt->fetchAll();
        } catch (Throwable $e) {
            return [];
        }
        return array_map(static fn (array $r): array => [
            'id'         => (int) $r['id'],
            'name'       => (string) $r['name'],
            'created_at' => (int) $r['created_at'],
            'last_login' => (int) $r['last_login'],
            // Chaîne vide = aucune restriction (comportement par défaut).
            'indexers'   => (string) $r['indexers'],
            // Catégorie qBittorrent imposée : sépare les téléchargements de
            // chacun au lieu de tout déverser au même endroit.
            'category'   => (string) $r['category'],
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

    /**
     * Indexeurs autorisés pour un compte : null = aucune restriction.
     *
     * C'est une frontière de sécurité, pas un filtre d'affichage : sur un
     * tracker privé, laisser quelqu'un chercher avec les identifiants d'un
     * autre lui fait porter le ratio et les sanctions.
     *
     * @return array<int,int>|null
     */
    public function userIndexers(string $name): ?array
    {
        if ($name === '') {
            return null; // administrateur : tous les indexeurs
        }
        foreach ($this->users() as $u) {
            if (strcasecmp($u['name'], $name) !== 0) {
                continue;
            }
            $raw = trim($u['indexers']);
            if ($raw === '') {
                return null;
            }
            $ids = array_values(array_filter(
                array_map('intval', explode(',', $raw)),
                static fn (int $n): bool => $n > 0
            ));
            // Une liste devenue vide après nettoyage reste une restriction :
            // renvoyer null ici ouvrirait tout, exactement l'inverse du besoin.
            return $ids;
        }
        // Compte inconnu : on n'autorise rien plutôt que tout.
        return [];
    }

    /**
     * Enregistre la liste autorisée et renvoie ce qui a réellement été retenu.
     *
     * Le retour compte : l'appelant doit rendre compte de l'état enregistré,
     * pas de ce qu'on lui a demandé — sinon l'interface annonce une restriction
     * qui n'est pas celle qui s'applique.
     *
     * @param array<int,int> $ids
     * @return array<int,int>
     */
    public function setUserIndexers(int $id, array $ids): array
    {
        $pdo = $this->db();
        if ($pdo === null) {
            return [];
        }
        $clean = array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn (int $n): bool => $n > 0 && $n < 100000
        )));
        $clean = array_slice($clean, 0, 100);
        try {
            $pdo->prepare('UPDATE users SET indexers = ? WHERE id = ?')
                ->execute([implode(',', $clean), $id]);
            return $clean;
        } catch (Throwable $e) {
            error_log('[indexof] restriction non enregistrée : ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Ce compte nommé existe-t-il encore ?
     *
     * Sans base accessible on répond « oui » : couper toutes les sessions
     * nommées à la moindre indisponibilité de SQLite serait une panne, pas une
     * sécurité — le cloisonnement, lui, reste fermé par défaut.
     */
    public function userExists(string $name): bool
    {
        if ($name === '' || !$this->available()) {
            return true;
        }
        foreach ($this->users() as $u) {
            if (strcasecmp($u['name'], $name) === 0) {
                return true;
            }
        }
        return false;
    }

    /** Catégorie qBittorrent imposée à un compte ('' = libre choix). */
    public function userCategory(string $name): string
    {
        if ($name === '') {
            return '';
        }
        foreach ($this->users() as $u) {
            if (strcasecmp($u['name'], $name) === 0) {
                return $u['category'];
            }
        }
        return '';
    }

    public function setUserCategory(int $id, string $category): string
    {
        $pdo = $this->db();
        if ($pdo === null) {
            return '';
        }
        // Une catégorie qBittorrent reste un nom simple : on ne relaie pas
        // n'importe quoi au client de téléchargement.
        $clean = preg_replace('/[^\p{L}\p{N} ._-]/u', '', trim($category)) ?? '';
        // qBittorrent dérive un dossier du nom de catégorie : un nom qui commence
        // par un point donnerait un répertoire caché, et une suite de points n'a
        // aucun sens comme nom de dossier.
        $clean = trim($clean, " ._-");
        $clean = mb_substr($clean, 0, 40);
        try {
            $pdo->prepare('UPDATE users SET category = ? WHERE id = ?')->execute([$clean, $id]);
            return $clean;
        } catch (Throwable $e) {
            error_log('[indexof] catégorie non enregistrée : ' . $e->getMessage());
            return '';
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

    /**
     * Chemins masqués de la bibliothèque.
     *
     * @return array<string,true> indexé par chemin, pour un test en O(1)
     */
    public function hiddenFiles(): array
    {
        $pdo = $this->db();
        if ($pdo === null) {
            return [];
        }
        try {
            $rows = $pdo->query('SELECT rel FROM hidden');
            $out = [];
            foreach ($rows === false ? [] : $rows->fetchAll() as $r) {
                $out[(string) $r['rel']] = true;
            }
            return $out;
        } catch (Throwable $e) {
            return [];
        }
    }

    /** Masque ou réaffiche un fichier. Le fichier lui-même n'est jamais touché. */
    public function setHidden(string $rel, bool $hidden, string $user = ''): void
    {
        $pdo = $this->db();
        if ($pdo === null || $rel === '') {
            return;
        }
        try {
            if ($hidden) {
                $pdo->prepare('INSERT OR REPLACE INTO hidden (rel, user, created_at) VALUES (?, ?, ?)')
                    ->execute([$rel, $user, time()]);
            } else {
                $pdo->prepare('DELETE FROM hidden WHERE rel = ?')->execute([$rel]);
            }
        } catch (Throwable $e) {
            error_log('[indexof] masquage impossible : ' . $e->getMessage());
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
            $pdo->prepare('DELETE FROM seen WHERE search_id = ?')->execute([$id]);
        } catch (Throwable $e) {
            error_log('[indexof] suppression impossible : ' . $e->getMessage());
        }
    }
}
