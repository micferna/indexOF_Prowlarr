<?php

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

/**
 * Bibliothèque : ce que le client de téléchargement a effectivement posé sur le
 * disque.
 *
 * indexOF sait chercher et envoyer ; il ne savait pas montrer le résultat. Cette
 * vue lit le dossier de téléchargements — en lecture seule, l'application n'a
 * aucune raison de pouvoir effacer un film — et le présente avec les mêmes
 * affiches et résumés que les résultats de recherche.
 *
 * Elle ne remplace pas un serveur de médias : pas de transcodage, pas de reprise
 * de lecture, pas de gestion de bibliothèque. Elle répond à une question simple :
 * « qu'est-ce que j'ai, et comment je le regarde tout de suite ? »
 */
final class Library
{
    /**
     * Conteneurs vidéo reconnus, et lisibles ou non par un navigateur.
     *
     * `true` = le navigateur a de bonnes chances d'y arriver en lecture directe.
     * Les MKV et AVI passent rarement (HEVC, DTS, XviD) : mieux vaut le dire
     * avant que l'utilisateur ne clique sur une vidéo muette ou noire.
     *
     * @var array<string,array{mime:string,web:bool}>
     */
    private const FORMATS = [
        'mp4'  => ['mime' => 'video/mp4',       'web' => true],
        'm4v'  => ['mime' => 'video/mp4',       'web' => true],
        'webm' => ['mime' => 'video/webm',      'web' => true],
        'ogv'  => ['mime' => 'video/ogg',       'web' => true],
        'mkv'  => ['mime' => 'video/x-matroska', 'web' => false],
        'avi'  => ['mime' => 'video/x-msvideo', 'web' => false],
        'mov'  => ['mime' => 'video/quicktime', 'web' => false],
        'wmv'  => ['mime' => 'video/x-ms-wmv',  'web' => false],
        'flv'  => ['mime' => 'video/x-flv',     'web' => false],
        'mpg'  => ['mime' => 'video/mpeg',      'web' => false],
        'mpeg' => ['mime' => 'video/mpeg',      'web' => false],
        'ts'   => ['mime' => 'video/mp2t',      'web' => false],
        'm2ts' => ['mime' => 'video/mp2t',      'web' => false],
    ];

    /**
     * Plancher de taille. Volontairement bas : c'est le nom qui écarte les
     * extraits (« sample », « trailer »), pas la taille — un sample fait souvent
     * 50 Mo, et un épisode court peut en faire moins. Ce seuil ne sert qu'à
     * écarter les artefacts.
     */
    private const MIN_BYTES = 1024 * 1024;

    /** Garde-fou : une arborescence pathologique ne doit pas bloquer la page. */
    private const MAX_FILES = 5000;
    private const MAX_DEPTH = 8;

    public function __construct(
        private readonly string $root,
        private readonly string $cacheDir,
        private readonly int $cacheTtl = 60,
    ) {
    }

    public function available(): bool
    {
        return $this->root !== '' && is_dir($this->root) && is_readable($this->root);
    }

    public static function isPlayable(string $ext): bool
    {
        return (self::FORMATS[strtolower($ext)] ?? ['web' => false])['web'];
    }

    public static function mimeFor(string $ext): string
    {
        return (self::FORMATS[strtolower($ext)] ?? ['mime' => 'application/octet-stream'])['mime'];
    }

    /**
     * Vérifie qu'un chemin relatif désigne bien un fichier de la bibliothèque.
     *
     * C'est la frontière de sécurité du lecteur : le chemin vient d'un jeton
     * scellé, mais un jeton reste une donnée. On repasse par realpath() et on
     * exige que la cible soit sous la racine — un lien symbolique posé dans le
     * dossier de téléchargements ne doit pas ouvrir le reste du disque.
     *
     * @return array{path:string,rel:string,size:int,ext:string}|null
     */
    public function resolve(string $rel): ?array
    {
        $racine = realpath($this->root);
        if ($racine === false || $rel === '') {
            return null;
        }
        // Un chemin absolu ou remontant est refusé avant même d'être résolu.
        if (str_starts_with($rel, '/') || str_contains($rel, "\0") || preg_match('~(^|/)\.\.(/|$)~', $rel) === 1) {
            return null;
        }

        $cible = realpath($racine . '/' . $rel);
        if ($cible === false || !is_file($cible) || !is_readable($cible)) {
            return null;
        }
        if ($cible !== $racine && !str_starts_with($cible, $racine . '/')) {
            return null;
        }

        $ext = strtolower(pathinfo($cible, PATHINFO_EXTENSION));
        if (!isset(self::FORMATS[$ext])) {
            return null;
        }

        return [
            'path' => $cible,
            'rel'  => substr($cible, strlen($racine) + 1),
            'size' => (int) filesize($cible),
            'ext'  => $ext,
        ];
    }

    /**
     * Liste les vidéos présentes.
     *
     * Le parcours est mis en cache quelques secondes : une arborescence de
     * plusieurs milliers de fichiers sur un disque qui dort coûte cher, et la
     * page est rechargée souvent.
     *
     * @return array<int,array<string,mixed>>
     */
    public function scan(): array
    {
        if (!$this->available()) {
            return [];
        }

        $cache = $this->cacheDir . '/library.json';
        if ($this->cacheTtl > 0 && is_file($cache)
            && (time() - (int) filemtime($cache)) < $this->cacheTtl) {
            $lu = json_decode((string) @file_get_contents($cache), true);
            if (is_array($lu)) {
                return $lu;
            }
        }

        $fichiers = [];
        $this->walk((string) realpath($this->root), '', 0, $fichiers);

        // Les plus récents d'abord : ce qu'on vient de télécharger est ce qu'on
        // vient chercher.
        usort($fichiers, static fn (array $a, array $b): int => $b['mtime'] <=> $a['mtime']);

        if ($this->cacheTtl > 0) {
            if (!is_dir($this->cacheDir)) {
                @mkdir($this->cacheDir, 0700, true);
            }
            @file_put_contents($cache, json_encode($fichiers), LOCK_EX);
        }
        return $fichiers;
    }

    /**
     * @param array<int,array<string,mixed>> $out
     */
    private function walk(string $dir, string $prefix, int $depth, array &$out): void
    {
        if ($depth > self::MAX_DEPTH || count($out) >= self::MAX_FILES) {
            return;
        }
        $entries = @scandir($dir);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) {
                continue;
            }
            $chemin = $dir . '/' . $entry;
            $rel    = $prefix === '' ? $entry : $prefix . '/' . $entry;

            // On ne suit pas les liens : ils pourraient pointer hors de la
            // bibliothèque, et resolve() les refuserait de toute façon.
            if (is_link($chemin)) {
                continue;
            }
            if (is_dir($chemin)) {
                $this->walk($chemin, $rel, $depth + 1, $out);
                continue;
            }
            if (!is_file($chemin)) {
                continue;
            }

            $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
            if (!isset(self::FORMATS[$ext])) {
                continue;
            }
            // Extraits et bandes-annonces : ils encombrent la vue sans jamais
            // être ce qu'on cherche.
            if (stripos($rel, 'sample') !== false || stripos($rel, 'trailer') !== false) {
                continue;
            }
            $taille = (int) @filesize($chemin);
            if ($taille < self::MIN_BYTES) {
                continue;
            }

            $out[] = [
                'rel'       => $rel,
                'name'      => pathinfo($entry, PATHINFO_FILENAME),
                // Le dossier porte souvent le nom de la release là où le fichier
                // ne porte qu'un numéro d'épisode : c'est lui qu'on interroge en
                // priorité pour retrouver l'affiche.
                'folder'    => $prefix === '' ? '' : basename($prefix),
                'ext'       => $ext,
                'size'      => $taille,
                'sizeHuman' => format_size($taille),
                'mtime'     => (int) @filemtime($chemin),
                'web'       => self::FORMATS[$ext]['web'],
            ];
            if (count($out) >= self::MAX_FILES) {
                return;
            }
        }
    }
}

/**
 * Retrouve le torrent qBittorrent correspondant à un fichier de la bibliothèque.
 *
 * Les deux vues pointent le même dossier de l'hôte, mais sous des chemins
 * différents (`/media` ici, `/downloads` là-bas) : comparer les chemins absolus
 * ne marche pas. On compare donc ce qui est commun — le nom du dossier de la
 * release, ou celui du fichier quand le torrent n'en contient qu'un.
 *
 * En cas de doute, on ne renvoie rien : mieux vaut refuser une suppression que
 * d'effacer le mauvais torrent.
 *
 * @param array<int,array<string,mixed>> $torrents
 * @return array<string,mixed>|null
 */
function match_torrent(array $torrents, string $rel): ?array
{
    $rel = trim($rel, '/');
    if ($rel === '') {
        return null;
    }
    $racine   = explode('/', $rel)[0];     // dossier de la release, ou le fichier
    $fichier  = basename($rel);

    $normalise = static fn (string $v): string => strtolower(trim(str_replace('\\', '/', $v), '/'));
    $cible     = $normalise($racine);
    $cibleFile = $normalise($fichier);

    $candidats = [];
    foreach ($torrents as $t) {
        if (($t['hash'] ?? '') === '') {
            continue;
        }
        $nom     = $normalise((string) ($t['name'] ?? ''));
        $contenu = $normalise(basename((string) ($t['content_path'] ?? '')));

        if ($nom === $cible || $contenu === $cible || $nom === $cibleFile || $contenu === $cibleFile) {
            $candidats[] = $t;
        }
    }

    // Une seule correspondance, ou rien : deux torrents portant le même nom
    // rendent le choix arbitraire, et un choix arbitraire efface un fichier.
    return count($candidats) === 1 ? $candidats[0] : null;
}
