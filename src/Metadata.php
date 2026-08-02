<?php

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

/**
 * Reconnaissance d'une release : du nom de fichier à la fiche du média.
 *
 * Le problème résolu ici : « The.Matrix.1999.MULTi.2160p.BluRay.x265-SESKAPiLE »
 * ne dit pas de quel film il s'agit tant qu'on ne l'a pas lu ligne à ligne. Pour
 * le savoir, il fallait ouvrir la fiche du tracker — donc s'y connecter. On
 * rapatrie l'affiche et le résumé pour que le coup d'œil suffise.
 *
 * Source : Radarr / Sonarr, déjà configurés pour l'envoi. Leurs endpoints de
 * recherche (`movie/lookup`, `series/lookup`) interrogent TMDB / TheTVDB via le
 * serveur de métadonnées des *arr — aucune clé API supplémentaire à obtenir, et
 * aucune requête du navigateur vers un service tiers.
 *
 * Prowlarr ne peut pas s'en charger : il ne renvoie pas d'affiche, et moins d'un
 * quart de ses résultats portent un identifiant IMDb ou TMDB.
 */

/**
 * Tokens techniques d'un nom de release : tout ce qui décrit le fichier plutôt
 * que l'œuvre. Le premier rencontré marque la fin du titre lisible.
 */
const META_TECH_RE = '/^(2160p|1080p|720p|480p|4k|4klight|4klighthi|uhd|hdlight|hdrip|remux|blu-?ray|bdrip|bdremux|brrip|dvdrip|dvdscr|web-?dl|webrip|web|hdtv|vod|x26[45]|h\.?26[45]|hevc|avc|xvid|divx|av1|10bit|8bit|hdr10\+?|hdr|dolby|dovi|dv|vision|atmos|truehd|dts|ddp?5|ac3|e-?ac3|eac3|aac|flac|opus|mp3|multi|multilang|vostfr|vost|truefrench|trufrench|french|vff|vf2|vfi|vfq|vo|subfrench|imax|proper|repack|extended|remastered|remaster|custom|unrated|integrale|complete|collection|limited|internal|md|ad|amzn|nf|dsnp|atvp|itunes|cp)$/i';

/**
 * Mots qui décrivent un LOT plutôt qu'une œuvre : « Avatar Trilogie »,
 * « GAME OF THRONES INTEGRAL », « Kaamelott Intégrale + Bonus ». Ils survivent à
 * la découpe parce qu'ils sont collés au titre, et ils suffisent à faire échouer
 * la recherche — alors que le titre nu, lui, se trouve du premier coup.
 */
const META_PACK_RE = '/^(trilogie|trilogy|duologie|quadrilogie|pentalogie|integrale?|intégrale?|complete|completa|collection|coffret|saga|anthologie|anthology|bonus|serie|série|series|3d|edition|édition|uncut)$/iu';

/** Marqueurs de série : saison/épisode sous toutes leurs graphies courantes. */
const META_EPISODE_RE  = '/(?:^|[\s.\-_\[(])(?:s(\d{1,2})[\s._-]?e(\d{1,3})|(\d{1,2})x(\d{2}))(?=[\s.\-_\])]|$)/i';
const META_SEASON_RE   = '/(?:^|[\s.\-_\[(])(?:s(\d{1,2})|saisons?[\s._-]*(\d{1,2})|seasons?[\s._-]*(\d{1,2}))(?=[\s.\-_\])]|$)/i';
const META_YEAR_RE     = '/(?:^|[\s.\-_\[(])((?:19|20)\d{2})(?=[\s.\-_\])]|$)/';

/**
 * Répare un texte UTF-8 qui a été relu comme du Latin-1 puis ré-encodé.
 *
 * Beaucoup de trackers publient leurs titres déjà cassés : « Deuxième » y arrive
 * en « DeuxiÃ¨me » (C3 83 C2 A8 au lieu de C3 A8). Tant qu'on cherche avec ces
 * octets-là, aucune base de métadonnées ne reconnaît le titre — et c'était la
 * première cause d'affiche manquante, loin devant toutes les autres.
 */
function repair_double_utf8(string $s): string
{
    // Signature du double-encodage : Ã ou Â suivi d'un caractère de la plage
    // 0x80-0xBF. Sans elle, on ne touche à rien.
    if (preg_match('/(?:Ã|Â)[\x{0080}-\x{00BF}]/u', $s) !== 1) {
        return $s;
    }
    // Tout doit tenir sur un octet : un titre qui mêle du vrai CJK à du
    // mojibake serait détruit par la conversion.
    if (preg_match('/[^\x{0000}-\x{00FF}]/u', $s) === 1) {
        return $s;
    }
    $octets = mb_convert_encoding($s, 'ISO-8859-1', 'UTF-8');
    return mb_check_encoding($octets, 'UTF-8') ? $octets : $s;
}

/**
 * Titre alternatif que l'uploadeur a mis entre parenthèses en fin de nom.
 *
 * « … x265-QTZ (Dune Part Two) », « … -RONIN (D@bbe: The Possession) » : c'est
 * une convention répandue pour donner le titre international d'une release
 * publiée sous son titre local. Quand le titre principal ne donne rien, celui-ci
 * est souvent le bon.
 */
function release_alt_title(string $title): string
{
    if (preg_match_all('/\(([^()]{3,80})\)/u', $title, $m) < 1) {
        return '';
    }
    foreach (array_reverse($m[1]) as $candidat) {
        $c = trim($candidat);
        // Ni une année, ni une plage d'années, ni une mention technique.
        if (preg_match('/^[\d\s\-–.]+$/u', $c) === 1) {
            continue;
        }
        if (preg_match(META_TECH_RE, str_replace(' ', '', $c)) === 1) {
            continue;
        }
        // Il faut de quoi chercher : au moins trois lettres.
        if (preg_match_all('/\p{L}/u', $c) < 3) {
            continue;
        }
        return $c;
    }
    return '';
}

/**
 * Forme comparable d'un titre : sans casse, sans accent, sans ponctuation.
 *
 * Sert à reconnaître « Game of Thrones » dans « GAME OF THRONES INTEGRAL ».
 * On n'utilise pas iconv//TRANSLIT : sous musl (Alpine) il rend des points
 * d'interrogation là où la glibc rend des lettres.
 */
function meta_normalize(string $s): string
{
    $s = repair_double_utf8($s);
    $s = strtr(mb_strtolower($s, 'UTF-8'), [
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a', 'æ' => 'ae',
        'ç' => 'c', 'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
        'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
        'ñ' => 'n', 'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o', 'ø' => 'o', 'œ' => 'oe',
        'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ý' => 'y', 'ÿ' => 'y', 'ß' => 'ss',
    ]);
    $s = (string) preg_replace('/[^a-z0-9]+/', ' ', $s);
    return trim((string) preg_replace('/\s+/', ' ', $s));
}

/**
 * La fiche proposée porte-t-elle bien le titre qu'on cherchait ?
 *
 * Égalité, ou le titre du candidat préfixe le nôtre sur une frontière de mot :
 * « Game of Thrones » répond à « GAME OF THRONES INTEGRAL », « Game of Thrones
 * Talk » non. Les titres alternatifs comptent — c'est par eux que « Dune :
 * Deuxième partie » rejoint « Dune: Part Two ».
 *
 * Le sens inverse serait un piège : notre nom peut traîner du bruit de release,
 * mais un candidat PLUS LONG que ce qu'on cherche est une autre œuvre. « Le Roi
 * Lion » attrapait ainsi « Le Roi Lion - Les nouvelles Aventures », et gagnait
 * contre le vrai « The Lion King » de la même année.
 *
 * @param array<int,string> $recherches formes normalisées de ce qu'on cherchait
 * @param array<string,mixed> $candidat
 */
function meta_title_matches(array $recherches, array $candidat): bool
{
    $titres = [(string) ($candidat['title'] ?? ''), (string) ($candidat['originalTitle'] ?? '')];
    foreach ((array) ($candidat['alternateTitles'] ?? []) as $alt) {
        $titres[] = is_array($alt) ? (string) ($alt['title'] ?? '') : (string) $alt;
    }

    foreach ($titres as $titre) {
        $b = meta_normalize($titre);
        if ($b === '') {
            continue;
        }
        foreach ($recherches as $a) {
            if ($a === '') {
                continue;
            }
            if ($a === $b || str_starts_with($a, $b . ' ')) {
                return true;
            }
        }
    }
    return false;
}

/**
 * Retire d'un titre les mots qui désignent le lot plutôt que l'œuvre.
 *
 * Sert de repli, jamais de premier essai : « The Complete Unknown » ou « Saga »
 * peuvent faire partie d'un vrai titre, et on ne les ampute qu'une fois le titre
 * intact resté sans réponse.
 */
function strip_pack_words(string $name): string
{
    $mots = array_values(array_filter(
        explode(' ', $name),
        static fn (string $w): bool => $w !== '' && preg_match(META_PACK_RE, $w) !== 1
    ));
    // « The Complete Series » laisse « The » orphelin en fin de titre.
    while ($mots !== [] && preg_match('/^(the|le|la|les|de|du|des|of|et|and|\+|-|–)$/iu', (string) end($mots)) === 1) {
        array_pop($mots);
    }
    return trim((string) preg_replace('/[\s:\-–_,;+]+$/u', '', implode(' ', $mots)));
}

/**
 * Découpe un nom de release en titre d'œuvre exploitable.
 *
 * On coupe au premier repère qui n'appartient plus à l'œuvre : marqueur de
 * saison, année, ou token technique — dans cet ordre, car « Show.2020.S01E02 »
 * doit se lire comme une série et non comme un film de 2020.
 *
 * @return array{name:string,year:?int,season:?int,episode:?int,kind:?string,alt:string}
 */
function parse_release_title(string $title): array
{
    $vide = ['name' => '', 'year' => null, 'season' => null, 'episode' => null, 'kind' => null, 'alt' => ''];

    // Avant toute chose : remettre les accents d'aplomb. Tout ce qui suit —
    // découpe, comparaison, recherche — travaille sur du texte lisible.
    $title = repair_double_utf8($title);
    $alt = release_alt_title($title);

    // Étiquettes de site collées en tête : « [ Torrent911.gg ] », « www.X.com - ».
    $t = (string) preg_replace('/^\s*[\[(][^\]\)]{1,60}[\])]\s*/u', '', $title);
    $t = (string) preg_replace('/^\s*(?:www\.)?[a-z0-9-]+\.[a-z]{2,6}\s*[-–]\s*/i', '', $t);

    // Les noms de release s'écrivent en points ou en espaces. On ramène tout aux
    // espaces : la coupe se fait de toute façon avant la partie technique, où
    // les points ont un sens (« DTS.HD.MA.7.1 »).
    $plat = str_replace(['.', '_'], ' ', $t);
    // « S.W.A.T. » devenu « S W A T » : on recolle les sigles, sinon plus rien
    // ne correspond côté métadonnées.
    $plat = (string) preg_replace_callback(
        '/\b(?:[A-Za-z] ){2,}[A-Za-z]\b/',
        static fn (array $m): string => str_replace(' ', '', $m[0]),
        $plat
    );
    $plat = trim((string) preg_replace('/\s+/', ' ', $plat));
    if ($plat === '') {
        return $vide;
    }

    $season = null;
    $episode = null;
    $kind = null;
    $coupe = -1;

    if (preg_match(META_EPISODE_RE, $plat, $m, PREG_OFFSET_CAPTURE) === 1) {
        $season  = (int) ($m[1][0] !== '' ? $m[1][0] : $m[3][0]);
        $episode = (int) ($m[2][0] !== '' ? $m[2][0] : $m[4][0]);
        $kind    = 'tv';
        $coupe   = (int) $m[0][1];
    } elseif (preg_match(META_SEASON_RE, $plat, $m, PREG_OFFSET_CAPTURE) === 1) {
        // Trois graphies, un seul groupe rempli : « S02 », « Saison 2 », « Season 2 ».
        foreach ([1, 2, 3] as $g) {
            if (($m[$g][0] ?? '') !== '') {
                $season = (int) $m[$g][0];
                break;
            }
        }
        $kind  = 'tv';
        $coupe = (int) $m[0][1];
    }

    // L'année sert de repère même quand une saison a déjà tranché : elle peut la
    // précéder (« Show 2020 S01 »), et c'est alors elle qui borne le titre.
    // Une année en toute première position EST le titre (« 1917 2019 1080p ») :
    // on cherche donc la première qui ne soit pas en tête.
    $year = null;
    if (preg_match_all(META_YEAR_RE, $plat, $ans, PREG_OFFSET_CAPTURE) > 0) {
        foreach ($ans[0] as $i => $brut) {
            $pos = (int) $brut[1];
            if ($pos === 0) {
                continue;
            }
            $year = (int) $ans[1][$i][0];
            if ($coupe < 0 || $pos < $coupe) {
                $coupe = $pos;
            }
            break;
        }
    }

    if ($coupe < 0) {
        // Ni saison ni année : le titre s'arrête au premier token technique.
        $pos = 0;
        foreach (explode(' ', $plat) as $mot) {
            if ($pos > 0 && preg_match(META_TECH_RE, $mot) === 1) {
                $coupe = $pos;
                break;
            }
            $pos += strlen($mot) + 1;
        }
    }

    $name = $coupe > 0 ? substr($plat, 0, $coupe) : $plat;

    // Titre alternatif entre parenthèses au milieu du nom : « Dune: Part One
    // (Dune : Première Partie) ». Il est déjà retenu à part comme repli — le
    // garder ici ne ferait que brouiller la recherche principale.
    $name = (string) preg_replace('/\([^()]*\)/u', ' ', $name);

    // Restes techniques accrochés à la fin du titre (« … REMASTERED 2003 »),
    // puis ponctuation orpheline (« The Matrix : », « Alien - »).
    $mots = array_filter(explode(' ', $name), static fn (string $w): bool => $w !== '');
    while ($mots !== [] && preg_match(META_TECH_RE, (string) end($mots)) === 1) {
        array_pop($mots);
    }
    $name = trim((string) preg_replace('/[\s:\-–_,;(\[]+$/u', '', implode(' ', $mots)));

    if ($name === '') {
        return $vide;
    }

    return [
        'name' => $name, 'year' => $year, 'season' => $season,
        'episode' => $episode, 'kind' => $kind, 'alt' => $alt,
    ];
}

/**
 * Fiches de médias (affiche, résumé, année) via Radarr / Sonarr.
 */
final class MetadataClient
{
    /**
     * Hôtes d'images autorisés pour le proxy d'affiches.
     *
     * Les URLs viennent des *arr, donc d'une source de confiance — mais le
     * scellement d'un jeton ne dit rien de sa cible. Cette liste borne ce que le
     * serveur acceptera d'aller chercher, quoi qu'il arrive en amont.
     */
    public const POSTER_HOSTS = [
        'image.tmdb.org',
        'artworks.thetvdb.com',
        'assets.fanart.tv',
        'thetvdb.com',
    ];

    /** Barreaux de l'échelle de repli (cf. context()). */
    private const MAX_PASSES = 5;
    /** Temps total accordé aux replis, au-delà du premier essai. */
    private const BUDGET_SECONDS = 25;

    /** Une fiche trouvée ne change quasiment jamais : on la garde longtemps. */
    private const TTL_HIT  = 30 * 86400;
    /** Une absence peut n'être qu'un titre mal découpé : on réessaie plus tôt. */
    private const TTL_MISS = 12 * 3600;

    /**
     * @param array<string,array{label:string,api:string,url:string,key:string}> $arr
     */
    public function __construct(
        private readonly array $arr,
        private readonly string $secret,
        private readonly string $cacheDir,
        private readonly int $timeout = 12,
    ) {
    }

    /**
     * @param array<string,array{label:string,api:string,url:string,key:string}> $arr
     */
    public static function isConfigured(array $arr): bool
    {
        return isset($arr['radarr']) || isset($arr['sonarr']);
    }

    /** L'hôte fait-il partie des sources d'images autorisées ? */
    public static function isPosterHost(string $host): bool
    {
        return in_array(strtolower($host), self::POSTER_HOSTS, true);
    }

    /**
     * Résout un lot de releases. Les titres qui désignent la même œuvre ne
     * donnent lieu qu'à une seule requête amont — quarante releases de Matrix,
     * c'est une recherche, pas quarante.
     *
     * @param array<int,array{title:string,kind?:string,imdbId?:string,tmdbId?:int}> $items
     * @return array<string,array<string,mixed>|null> indexé par titre demandé
     */
    public function lookupMany(array $items): array
    {
        $parKey  = [];   // clé de cache => contexte de recherche
        $parTitre = [];  // titre demandé => clé de cache

        foreach ($items as $item) {
            $titre = (string) $item['title'];
            if ($titre === '' || array_key_exists($titre, $parTitre)) {
                continue;
            }
            $ctx = $this->context($item);
            if ($ctx === null) {
                $parTitre[$titre] = null;
                continue;
            }
            $parTitre[$titre] = $ctx['key'];
            $parKey[$ctx['key']] ??= $ctx;
        }

        $resolu = [];
        $aChercher = [];
        foreach ($parKey as $key => $ctx) {
            $cache = $this->readCache($key);
            if ($cache !== null) {
                $resolu[$key] = $cache;
            } else {
                $aChercher[$key] = $ctx;
            }
        }

        foreach ($this->fetchAll($aChercher) as $key => $fiche) {
            $this->writeCache($key, $fiche);
            $resolu[$key] = $fiche;
        }

        $sortie = [];
        foreach ($parTitre as $titre => $key) {
            $fiche = $key !== null ? ($resolu[$key] ?? null) : null;
            // false = cherché, rien trouvé. Le client ne distingue pas les deux,
            // mais le cache si : c'est ce qui évite de rechercher indéfiniment
            // ce qui n'existe pas.
            $sortie[(string) $titre] = is_array($fiche) ? $this->present($fiche) : null;
        }
        return $sortie;
    }

    /**
     * Contexte de recherche d'une release : quoi demander, à qui, sous quelle
     * clé de cache. Renvoie null si la release n'est ni un film ni une série.
     *
     * `terms` est une échelle de repli, essayée dans l'ordre et seulement tant
     * que rien n'a été trouvé : la grande majorité des releases s'arrête au
     * premier barreau.
     *
     * @param array{title:string,kind?:string,imdbId?:string,tmdbId?:int} $item
     * @return array{key:string,kind:string,terms:array<int,string>,name:string,year:?int}|null
     */
    private function context(array $item): ?array
    {
        $parsed = parse_release_title((string) $item['title']);
        if ($parsed['name'] === '') {
            return null;
        }

        // Le découpage du titre fait autorité sur la catégorie de l'indexeur :
        // un « S01E01 » rangé en « Films » reste une série.
        $kind = $parsed['kind'] ?? (string) ($item['kind'] ?? '');
        if ($kind !== 'movie' && $kind !== 'tv') {
            return null;
        }
        if (!isset($this->arr[$kind === 'tv' ? 'sonarr' : 'radarr'])) {
            return null;
        }

        // Un identifiant fourni par l'indexeur vaut mieux que n'importe quel
        // découpage de nom : c'est une correspondance exacte.
        //
        // Prowlarr livre l'identifiant IMDb en entier nu (133093) là où les *arr
        // attendent sa forme canonique (tt0133093). Sans cette remise en forme,
        // les identifiants exacts étaient tous ignorés en silence et tout
        // repassait par le nom.
        $imdb = trim((string) ($item['imdbId'] ?? ''));
        if ($imdb !== '' && ctype_digit($imdb)) {
            $imdb = 'tt' . str_pad($imdb, 7, '0', STR_PAD_LEFT);
        }
        $tmdb = (int) ($item['tmdbId'] ?? 0);
        if ($kind === 'movie' && $tmdb > 0) {
            return ['key' => 'movie_tmdb_' . $tmdb, 'kind' => $kind, 'terms' => ['tmdb:' . $tmdb],
                    'name' => $parsed['name'], 'year' => $parsed['year']];
        }
        if ($imdb !== '' && preg_match('/^tt\d{6,10}$/', $imdb) === 1) {
            return ['key' => $kind . '_imdb_' . $imdb, 'kind' => $kind, 'terms' => ['imdb:' . $imdb],
                    'name' => $parsed['name'], 'year' => $parsed['year']];
        }

        $term = $parsed['name'];
        // L'année désambiguïse les remakes ; sur une série elle est plus souvent
        // celle de l'épisode que celle de la série, donc on s'en passe.
        if ($kind === 'movie' && $parsed['year'] !== null) {
            $term .= ' ' . $parsed['year'];
        }

        $terms = [$term];
        // Repli 1 : le titre international mis entre parenthèses par
        // l'uploadeur. C'est lui qui rattrape les titres publiés en turc, en
        // français ou en japonais.
        if ($parsed['alt'] !== '') {
            $terms[] = $parsed['alt'];
        }
        // Repli 2 : le titre débarrassé des mots qui décrivent le lot et non
        // l'œuvre. « Avatar Trilogie » devient « Avatar », « Game of Thrones
        // The Complete Series » devient « Game of Thrones ».
        $nu = strip_pack_words($parsed['name']);
        if ($nu !== '' && $nu !== $parsed['name']) {
            $terms[] = $kind === 'movie' && $parsed['year'] !== null ? $nu . ' ' . $parsed['year'] : $nu;
        }
        // Replis 3 et 4 : le nom amputé de son dernier mot, puis réduit à ses
        // deux premiers. Rattrape le nom de réalisateur ou le qualificatif
        // ajouté au titre (« Les Visiteurs Elia Kazan »). En dessous de trois
        // mots, tronquer chercherait n'importe quoi.
        //
        // Ces barreaux-là ne peuvent pas se tromper en silence : au-delà du
        // premier, une fiche n'est retenue que si son année correspond
        // (cf. pick()). Une mauvaise affiche est pire qu'une case vide.
        //
        // Un tronçon sans le moindre mot porteur ne cherche rien : « Le Roi
        // Lion 3D Disney » réduit à « Le Roi » ramenait vingt films sans rapport.
        $porteur = static fn (string $t): bool => preg_match('/(?:^| )[^ ]{4,}(?: |$)/u', $t) === 1;
        $mots = explode(' ', $parsed['name']);
        if (count($mots) >= 3) {
            $court = implode(' ', array_slice($mots, 0, count($mots) - 1));
            if ($porteur($court)) {
                $terms[] = $court;
            }
        }
        if (count($mots) >= 4) {
            $court = implode(' ', array_slice($mots, 0, 2));
            if ($porteur($court)) {
                $terms[] = $court;
            }
        }

        return [
            // La clé suit le terme principal : c'est lui qui identifie l'œuvre,
            // quel que soit le barreau auquel la recherche a fini par aboutir.
            'key'   => $kind . '_' . md5(mb_strtolower($term, 'UTF-8')),
            'kind'  => $kind,
            'terms' => array_values(array_unique($terms)),
            'name'  => $parsed['name'],
            'year'  => $parsed['year'],
        ];
    }

    /**
     * Résout un lot en descendant l'échelle de repli : passe 1 avec le titre
     * principal, passe 2 avec le titre alternatif, passe 3 avec le nom
     * raccourci. Chaque passe ne porte que sur ce que la précédente n'a pas
     * trouvé — un lot où tout aboutit du premier coup ne coûte qu'un aller-retour.
     *
     * @param array<string,array{key:string,kind:string,terms:array<int,string>,name:string,year:?int}> $ctxs
     * @return array<string,array<string,mixed>|false> false = cherché, non trouvé
     */
    private function fetchAll(array $ctxs): array
    {
        $fiches  = [];
        $restant = $ctxs;
        $debut   = time();
        // Correspondances seulement plausibles, gardées de côté : elles servent
        // si aucun barreau suivant ne donne mieux. Sans ça, un « Game of Thrones
        // Talk » renvoyé en tête arrêterait la recherche sur la mauvaise fiche.
        $faibles = [];

        for ($passe = 0; $passe < self::MAX_PASSES && $restant !== []; $passe++) {
            // Chaque passe coûte au plus un timeout. Sans ce garde-fou, un lot
            // récalcitrant pourrait les empiler jusqu'à dépasser le délai de
            // nginx — et rendre une page blanche là où trois affiches
            // manquantes n'auraient dérangé personne.
            if ($passe > 0 && (time() - $debut) > self::BUDGET_SECONDS) {
                break;
            }
            $lot = [];
            foreach ($restant as $key => $ctx) {
                if (isset($ctx['terms'][$passe])) {
                    $lot[$key] = $ctx;
                }
            }
            if ($lot === []) {
                break;
            }

            foreach ($this->fetchPass($lot, $passe) as $key => $fiche) {
                // Correspondance vérifiée : on s'arrête là.
                if (is_array($fiche) && ($fiche['strong'] ?? false) === true) {
                    $fiches[$key] = $fiche;
                    unset($restant[$key], $faibles[$key]);
                    continue;
                }
                if (is_array($fiche)) {
                    $faibles[$key] ??= $fiche;
                }
                // Ni échec ni correspondance vérifiée n'est définitif tant qu'il
                // reste un barreau à essayer.
                if (isset($restant[$key]['terms'][$passe + 1])) {
                    continue;
                }
                $fiches[$key] = $faibles[$key] ?? false;
                unset($restant[$key], $faibles[$key]);
            }
        }

        // Échelle interrompue par le budget de temps : mieux vaut la
        // correspondance plausible déjà trouvée que rien du tout.
        foreach ($restant as $key => $_) {
            if (isset($faibles[$key])) {
                $fiches[$key] = $faibles[$key];
            }
        }

        return $fiches;
    }

    /**
     * Une passe : interroge les *arr en parallèle. En série, quinze titres
     * distincts coûteraient plusieurs secondes ; en parallèle, le temps du plus
     * lent.
     *
     * @param array<string,array{key:string,kind:string,terms:array<int,string>,name:string,year:?int}> $ctxs
     * @return array<string,array<string,mixed>|false>
     */
    private function fetchPass(array $ctxs, int $passe): array
    {
        if ($ctxs === []) {
            return [];
        }

        $multi   = curl_multi_init();
        $handles = [];

        foreach ($ctxs as $key => $ctx) {
            $cible = $this->arr[$ctx['kind'] === 'tv' ? 'sonarr' : 'radarr'];
            $path  = $ctx['kind'] === 'tv' ? '/series/lookup' : '/movie/lookup';
            $url   = $cible['url'] . '/api/' . $cible['api'] . $path
                . '?' . http_build_query(['term' => $ctx['terms'][$passe]]);

            $ch = curl_init($url);
            if ($ch === false) {
                continue;
            }
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => min(5, $this->timeout),
                CURLOPT_TIMEOUT        => $this->timeout,
                CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_HTTPHEADER     => [
                    'Accept: application/json',
                    'X-Api-Key: ' . $cible['key'],
                ],
            ]);
            curl_multi_add_handle($multi, $ch);
            $handles[$key] = $ch;
        }

        do {
            $status = curl_multi_exec($multi, $running);
            if ($running > 0) {
                curl_multi_select($multi, 1.0);
            }
        } while ($running > 0 && $status === CURLM_OK);

        $fiches = [];
        foreach ($handles as $key => $ch) {
            $body = (string) curl_multi_getcontent($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_multi_remove_handle($multi, $ch);
            curl_close($ch);

            if ($code < 200 || $code >= 300) {
                error_log('[indexof] lookup ' . $ctxs[$key]['kind'] . " HTTP {$code} pour « "
                    . $ctxs[$key]['terms'][$passe] . ' »');
                continue; // pas de mise en cache : c'est une panne, pas une absence
            }
            $data = json_decode($body, true);
            if (!is_array($data)) {
                continue;
            }
            $fiches[$key] = $this->pick($data, $ctxs[$key], $ctxs[$key]['terms'][$passe], $passe > 0) ?? false;
        }
        curl_multi_close($multi);

        return $fiches;
    }

    /**
     * Choisit la fiche la plus plausible du lot renvoyé et n'en garde que ce qui
     * s'affiche.
     *
     * Le premier de la liste n'est PAS le bon : pour « GAME OF THRONES
     * INTEGRAL », Sonarr propose « Game of Thrones Talk », puis le vrai « Game
     * of Thrones » en quatrième position. On classe donc les candidats au lieu
     * de faire confiance à l'ordre.
     *
     * Le drapeau `strong` dit si la correspondance est vérifiée (par le titre ou
     * par l'année) ou seulement plausible. Une correspondance faible n'arrête pas
     * l'échelle de repli : elle est gardée de côté au cas où rien de mieux ne
     * vienne. Et `$strict` — posé dès qu'on a quitté le titre exact de la release
     * — refuse tout net le simplement plausible : à ce stade, une affiche fausse
     * serait pire qu'une case vide.
     *
     * @param array<int|string,mixed> $data
     * @param array{kind:string,name:string,year:?int} $ctx
     * @return array<string,mixed>|null
     */
    private function pick(array $data, array $ctx, string $terme, bool $strict = false): ?array
    {
        $liste = array_values(array_filter(
            isset($data[0]) || $data === [] ? $data : [$data],
            'is_array'
        ));
        if ($liste === []) {
            return null;
        }

        // Ce qu'on cherchait, sous forme comparable : le nom extrait de la
        // release, et le terme réellement envoyé (ils diffèrent sur les replis).
        $recherches = array_values(array_unique(array_filter([
            meta_normalize($ctx['name']),
            meta_normalize((string) preg_replace('/\s+(?:19|20)\d{2}$/', '', $terme)),
        ])));

        $parTitreEtAnnee = null;
        $parTitre        = null;
        $parAnnee        = null;

        foreach ($liste as $c) {
            $titreOk = meta_title_matches($recherches, $c);
            // Deux œuvres peuvent porter le même nom, rarement la même année.
            $anneeOk = $ctx['year'] !== null && abs(((int) ($c['year'] ?? 0)) - $ctx['year']) <= 1;

            if ($titreOk && $anneeOk) {
                $parTitreEtAnnee = $c;
                break;
            }
            $parTitre ??= $titreOk ? $c : null;
            $parAnnee ??= $anneeOk ? $c : null;
        }

        // Sur un repli, l'année connue doit être confirmée : « Le Roi Lion 3D »
        // trouve bien « The Lion King », mais celui de 2019 quand la release est
        // de 1994 — deux affiches différentes pour deux films différents.
        $choisie = $strict && $ctx['year'] !== null
            ? $parTitreEtAnnee
            : ($parTitreEtAnnee ?? $parTitre ?? $parAnnee);
        $strong  = $choisie !== null;
        if ($choisie === null) {
            // Une année connue et démentie par tous les candidats : c'est un
            // désaccord, pas une hésitation. « Le Roi Lion 3D Disney » de 1994
            // ramenait ainsi « No One Will Know » de 2025.
            if ($strict || $ctx['year'] !== null) {
                return null;
            }
            // Sans année, il ne reste que la parole du moteur — et elle vaut
            // quelque chose : Sonarr rapproche les titres alternatifs de son
            // côté même quand il ne les renvoie pas. C'est ainsi que « Le
            // Seigneur des anneaux : Les Anneaux de pouvoir » retrouve « The
            // Rings of Power », qu'aucune comparaison de texte ne peut relier.
            $choisie = $liste[0];
        }

        $poster = (string) ($choisie['remotePoster'] ?? '');
        if ($poster === '') {
            foreach ((array) ($choisie['images'] ?? []) as $img) {
                if (is_array($img) && ($img['coverType'] ?? '') === 'poster') {
                    $poster = (string) ($img['remoteUrl'] ?? '');
                    break;
                }
            }
        }

        $genres = array_slice(array_values(array_filter(
            array_map('strval', (array) ($choisie['genres'] ?? [])),
            static fn (string $g): bool => $g !== ''
        )), 0, 3);

        // Note : IMDb d'abord (la plus lue), repli sur TMDB. Radarr la classe
        // par source, Sonarr la donne à plat — les deux formes existent.
        $note = null;
        $ratings = (array) ($choisie['ratings'] ?? []);
        foreach (['imdb', 'tmdb'] as $source) {
            $v = is_array($ratings[$source] ?? null) ? (float) ($ratings[$source]['value'] ?? 0) : 0.0;
            if ($v > 0) {
                $note = round($v, 1);
                break;
            }
        }
        if ($note === null && is_numeric($ratings['value'] ?? null) && (float) $ratings['value'] > 0) {
            $note = round((float) $ratings['value'], 1);
        }

        return [
            'title'    => (string) ($choisie['title'] ?? $ctx['name']),
            'year'     => (int) ($choisie['year'] ?? 0) ?: null,
            'overview' => mb_substr(trim((string) ($choisie['overview'] ?? '')), 0, 600),
            'genres'   => $genres,
            'runtime'  => (int) ($choisie['runtime'] ?? 0) ?: null,
            'rating'   => $note,
            'kind'     => $ctx['kind'],
            'imdbId'   => (string) ($choisie['imdbId'] ?? ''),
            'posterUrl' => $poster,
            // Interne : décide si l'échelle de repli s'arrête ici (cf. fetchAll).
            'strong'   => $strong,
        ];
    }

    /**
     * Fiche prête pour le navigateur : l'URL de l'affiche devient un jeton
     * scellé, jamais l'adresse réelle. Le navigateur ne contacte donc aucun
     * service tiers, et rien ne fuit de ce qu'on regarde.
     *
     * @param array<string,mixed> $fiche
     * @return array<string,mixed>
     */
    private function present(array $fiche): array
    {
        $poster = (string) ($fiche['posterUrl'] ?? '');
        $token  = null;
        if ($poster !== '') {
            $host = (string) parse_url($poster, PHP_URL_HOST);
            if ($host !== '' && self::isPosterHost($host)) {
                // Les affiches d'origine font 2 à 5 Mo. La vignette et la fiche
                // se contentent largement de 342 px de large.
                $poster = (string) preg_replace('~/t/p/(original|w\d+)/~', '/t/p/w342/', $poster);
                $token  = seal_url($poster, $this->secret, 7 * 86400);
            }
        }

        $sortie = $fiche;
        unset($sortie['posterUrl'], $sortie['strong']);
        $sortie['poster'] = $token;
        return $sortie;
    }

    private function cacheFile(string $key): string
    {
        return $this->cacheDir . '/meta_' . preg_replace('/[^a-z0-9_]/i', '', $key) . '.json';
    }

    /**
     * @return array<string,mixed>|false|null  null = rien en cache
     */
    private function readCache(string $key): array|false|null
    {
        $file = $this->cacheFile($key);
        if (!is_file($file)) {
            return null;
        }
        $entree = json_decode((string) @file_get_contents($file), true);
        if (!is_array($entree)) {
            return null;
        }
        $trouve = ($entree['found'] ?? false) === true;
        $age    = time() - (int) ($entree['at'] ?? 0);
        if ($age > ($trouve ? self::TTL_HIT : self::TTL_MISS)) {
            return null;
        }
        return $trouve && is_array($entree['meta'] ?? null) ? $entree['meta'] : false;
    }

    /** @param array<string,mixed>|false $fiche */
    private function writeCache(string $key, array|false $fiche): void
    {
        if (!is_dir($this->cacheDir) && !@mkdir($this->cacheDir, 0700, true) && !is_dir($this->cacheDir)) {
            return;
        }
        prune_dir($this->cacheDir, self::TTL_HIT + 86400, 'meta_*.json');
        @file_put_contents(
            $this->cacheFile($key),
            json_encode(['at' => time(), 'found' => $fiche !== false, 'meta' => $fiche ?: null]),
            LOCK_EX
        );
    }
}
