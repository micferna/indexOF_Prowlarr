<?php

declare(strict_types=1);

/**
 * Fonctions utilitaires : échappement, validation d'URL (anti-XSS / anti-SSRF),
 * signature HMAC du proxy de téléchargement, et formatage d'affichage.
 */

/**
 * Politique de sécurité du contenu : tout vient de l'origine (aucun CDN, aucun
 * inline), pas d'encadrement en iframe, pas de <base> injectable.
 */
const APP_CSP = "default-src 'self'; img-src 'self' data:; style-src 'self'; "
    . "script-src 'self'; connect-src 'self'; form-action 'self'; "
    . "base-uri 'none'; frame-ancestors 'none'";

/** En-têtes de sécurité communs à toutes les réponses. */
function security_headers(): void
{
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
}

/** En-têtes des réponses HTML (CSP + anti-framing en plus). */
function html_security_headers(): void
{
    security_headers();
    header('Content-Security-Policy: ' . APP_CSP);
    header('X-Frame-Options: DENY');
}

/**
 * Réponse JSON terminale (les endpoints d'API n'ont rien à ajouter après).
 *
 * @param array<string,mixed> $data
 */
function json_response(array $data, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * URL d'un asset suffixée par sa date de modification.
 *
 * nginx sert les fichiers statiques avec `max-age=86400` : sans ce suffixe, un
 * navigateur continue de servir l'ancien CSS/JS pendant 24 h après un
 * déploiement — et un CSS neuf avec un JS périmé donne une page cassée.
 */
function asset(string $path): string
{
    $file = __DIR__ . '/../public/' . ltrim($path, '/');
    $version = is_file($file) ? (string) filemtime($file) : '0';
    return $path . '?v=' . $version;
}

/**
 * URL de base de l'application, déduite de la requête.
 *
 * Sert à écrire des liens absolus dans les flux RSS : un lecteur externe n'a
 * aucune notion de chemin relatif. L'en-tête Host vient du client, on le
 * restreint donc à ce qui peut réellement composer un nom d'hôte.
 */
function feed_base_url(): string
{
    $https = (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    if (preg_match('/^[A-Za-z0-9.\-:\[\]]{1,255}$/', $host) !== 1) {
        $host = 'localhost';
    }
    $dir = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
    return ($https ? 'https://' : 'http://') . $host . $dir;
}

/**
 * Adresse de l'application telle qu'un téléviseur devra la joindre.
 *
 * Le Cast ne pousse pas la vidéo : il envoie une URL, et l'appareil va la
 * chercher tout seul. Cette URL doit donc être valable DEPUIS LE SALON, pas
 * depuis le serveur — c'est la première cause d'échec d'un envoi qui « part »
 * sans rien afficher.
 *
 * Par défaut on la déduit de la requête : si vous consultez l'app depuis un
 * autre appareil, l'adresse que vous utilisez est justement celle qui marche.
 *
 * @param array<string,mixed> $config
 */
function cast_base_url(array $config): string
{
    $configuree = (string) ($config['public_url'] ?? '');
    return $configuree !== '' ? $configuree : feed_base_url();
}

/**
 * Cette adresse a-t-elle une chance d'être joignable depuis un autre appareil ?
 *
 * « localhost » et la boucle locale désignent la machine qui pose la question :
 * un téléviseur qui les reçoit cherchera chez lui, et ne trouvera rien.
 */
function cast_base_reachable(string $base): bool
{
    $hote = (string) parse_url($base, PHP_URL_HOST);
    if ($hote === '') {
        return false;
    }
    $hote = strtolower(trim($hote, '[]'));
    if ($hote === 'localhost' || str_ends_with($hote, '.localhost')) {
        return false;
    }
    if (filter_var($hote, FILTER_VALIDATE_IP)) {
        return !in_array($hote, ['127.0.0.1', '::1'], true)
            && !str_starts_with($hote, '127.');
    }
    return true; // un nom d'hôte réel : c'est au DNS de la maison de trancher
}

/** Échappement HTML systématique (ENT_QUOTES couvre " et '). */
function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Prépare une valeur pour un document XML.
 *
 * Les titres et les magnets viennent des trackers : n'importe qui peut y placer
 * ce qu'il veut. `e()` neutralise les guillemets et les chevrons, mais pas les
 * caractères de contrôle, qui sont **interdits** en XML 1.0 même échappés — un
 * seul suffit à rendre un flux illisible pour tous ses abonnés. On les retire
 * avant d'échapper.
 */
function xml_text(?string $value): string
{
    $clean = preg_replace('/[^\x09\x0A\x0D\x20-\x{10FFFF}]/u', '', $value ?? '');
    return e($clean === null ? '' : $clean);
}

/**
 * Renvoie l'URL si son schéma est autorisé, sinon '#'.
 * Empêche l'injection de liens `javascript:` / `data:` (XSS).
 *
 * @param array<int,string> $allowedSchemes
 */
function safe_url(?string $url, array $allowedSchemes = ['http', 'https']): string
{
    if ($url === null || $url === '') {
        return '#';
    }
    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
    return in_array($scheme, $allowedSchemes, true) ? $url : '#';
}

/* ------------------------------------------------------------------ *
 * Jetons opaques (chiffrement authentifié AES-256-GCM)
 *
 * Les liens de téléchargement Prowlarr embarquent la clé API (et parfois un
 * passkey tracker). Pour ne JAMAIS exposer ces secrets au navigateur, on ne
 * transmet plus l'URL : on transmet un jeton chiffré que seul le serveur peut
 * ouvrir. Confidentialité + intégrité + anti-forge (remplace la signature HMAC
 * réflective pour le flux de download/envoi).
 * ------------------------------------------------------------------ */
function b64url_encode(string $bytes): string
{
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
}

function b64url_decode(string $s): string
{
    return (string) base64_decode(strtr($s, '-_', '+/'), true);
}

/** Durée de vie par défaut d'un jeton scellé (24 h). */
const SEAL_TTL = 86400;

/**
 * Scelle une chaîne (URL/magnet) en jeton opaque lié au secret applicatif.
 *
 * Le jeton porte une date d'expiration : un lien qui fuite (historique, presse-
 * papiers, logs d'un proxy) ne reste pas exploitable indéfiniment. $ttl = 0
 * désactive l'expiration.
 */
function seal_url(string $plain, string $secret, int $ttl = SEAL_TTL): string
{
    $payload = ($ttl !== 0 ? (string) (time() + $ttl) : '0') . '|' . $plain;
    $key = hash('sha256', 'indexof-seal|' . $secret, true);
    $iv  = random_bytes(12);
    $tag = '';
    $ct  = openssl_encrypt($payload, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($ct === false) {
        return '';
    }
    return b64url_encode($iv . $tag . $ct);
}

/**
 * Ouvre un jeton scellé ; renvoie null si invalide, altéré (échec d'auth GCM)
 * ou expiré.
 */
function open_url(string $token, string $secret): ?string
{
    $raw = b64url_decode($token);
    if (strlen($raw) < 28) { // 12 (iv) + 16 (tag) + >=1
        return null;
    }
    $key = hash('sha256', 'indexof-seal|' . $secret, true);
    $iv  = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $ct  = substr($raw, 28);
    $pt  = openssl_decrypt($ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($pt === false) {
        return null;
    }

    $sep = strpos($pt, '|');
    if ($sep === false) {
        return null;
    }
    $expiry = (int) substr($pt, 0, $sep);
    if ($expiry !== 0 && $expiry < time()) {
        return null;
    }
    return substr($pt, $sep + 1);
}

/**
 * Détecte une release adulte (catégorie newznab XXX 6000-6999, ou nom de
 * catégorie explicite). Sert au filtrage côté serveur (safe-mode).
 *
 * @param array<string,mixed> $r
 */
function is_adult_result(array $r): bool
{
    if (empty($r['categories']) || !is_array($r['categories'])) {
        return false;
    }
    foreach ($r['categories'] as $c) {
        $id = is_array($c) ? (int) ($c['id'] ?? 0) : 0;
        if ($id >= 6000 && $id < 7000) {
            return true;
        }
        $name = is_array($c) ? strtolower((string) ($c['name'] ?? '')) : '';
        if ($name !== '' && (str_contains($name, 'xxx') || str_contains($name, 'adult') || str_contains($name, 'porn'))) {
            return true;
        }
    }
    return false;
}

/* ------------------------------------------------------------------ *
 * Limitation de débit (anti-brute-force) — compteur d'échecs par clé,
 * persisté dans le répertoire de cache. Best-effort applicatif ; la limite
 * faisant autorité est posée au niveau nginx (limit_req sur l'IP réelle).
 * ------------------------------------------------------------------ */
/** IP du client (REMOTE_ADDR ; X-Forwarded-For seulement si TRUST_PROXY=1). */
function client_ip(): string
{
    $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    if (getenv('TRUST_PROXY') === '1') {
        $xff = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($xff !== '') {
            $first = trim(explode(',', $xff)[0]);
            if (filter_var($first, FILTER_VALIDATE_IP)) {
                return $first;
            }
        }
    }
    return $remote !== '' ? $remote : 'unknown';
}

function throttle_file(string $dir, string $key): string
{
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    // Hachage (et non filtrage de caractères) : « 192.168.0.11 » et
    // « 192.168.01.1 » — ou deux IPv6 — se réduisaient au même nom de fichier,
    // donc au même compteur (blocage croisé entre clients / contournement).
    return $dir . '/throttle_' . hash('sha256', $key) . '.json';
}

/**
 * Purge best-effort des fichiers de travail expirés (cache Prowlarr, compteurs
 * de throttle). Ces répertoires vivent en tmpfs (RAM) : sans purge ils
 * grossissent indéfiniment. Déclenché de façon probabiliste (1 requête sur
 * $chanceOverN) pour ne pas pénaliser chaque requête.
 */
function prune_dir(string $dir, int $maxAgeSec, string $glob = '*.json', int $chanceOverN = 50): void
{
    if ($maxAgeSec <= 0 || !is_dir($dir) || random_int(1, max(1, $chanceOverN)) !== 1) {
        return;
    }
    $cut = time() - $maxAgeSec;
    foreach (glob($dir . '/' . $glob) ?: [] as $file) {
        if ((int) @filemtime($file) < $cut) {
            @unlink($file);
        }
    }
}

/** @return array<int,int> */
function throttle_read(string $file): array
{
    if (!is_file($file)) {
        return [];
    }
    $d = json_decode((string) @file_get_contents($file), true);
    return is_array($d) ? array_map('intval', $d) : [];
}

/** Nombre d'échecs récents dans la fenêtre. */
function throttle_failures(string $dir, string $key, int $windowSec): int
{
    $cut = time() - $windowSec;
    return count(array_filter(throttle_read(throttle_file($dir, $key)), static fn ($t) => $t >= $cut));
}

/** Enregistre un échec ; renvoie le total dans la fenêtre. */
function throttle_record(string $dir, string $key, int $windowSec): int
{
    $file = throttle_file($dir, $key);
    $cut  = time() - $windowSec;
    $ts   = array_values(array_filter(throttle_read($file), static fn ($t) => $t >= $cut));
    $ts[] = time();
    @file_put_contents($file, json_encode($ts), LOCK_EX);
    return count($ts);
}

function throttle_reset(string $dir, string $key): void
{
    @unlink(throttle_file($dir, $key));
}

/**
 * Résout un hôte en liste d'IP (ou l'IP elle-même si c'est déjà un littéral).
 *
 * @return array<int,string>
 */
function dns_lookup_ips(string $host): array
{
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return [$host];
    }

    $ips = [];
    $records = @dns_get_record($host, DNS_A | DNS_AAAA);
    if (is_array($records)) {
        foreach ($records as $record) {
            if (isset($record['ip'])) {
                $ips[] = (string) $record['ip'];
            }
            if (isset($record['ipv6'])) {
                $ips[] = (string) $record['ipv6'];
            }
        }
    }
    if ($ips === []) {
        // Repli IPv4 si dns_get_record échoue (certains résolveurs).
        $ipv4 = gethostbynamel($host);
        if (is_array($ipv4)) {
            $ips = $ipv4;
        }
    }
    return $ips;
}

/**
 * Renvoie une IP publique vérifiée pour l'hôte, ou null si aucune.
 *
 * L'IP renvoyée sert à épingler la connexion cURL (CURLOPT_RESOLVE) : on se
 * connecte exactement à l'IP qu'on a validée, ce qui déjoue le DNS rebinding
 * (la résolution ne peut pas changer entre la vérification et la connexion).
 * Bloque loopback, plages privées et réservées (anti-SSRF / métadonnées cloud).
 */
function resolve_to_public_ip(string $host): ?string
{
    foreach (dns_lookup_ips($host) as $ip) {
        if (ip_is_public($ip)) {
            return $ip;
        }
    }
    return null;
}

/**
 * Renvoie la première IP résolue (toute plage confondue), pour un hôte de
 * confiance explicitement autorisé (ex. le backend Prowlarr admin-configuré).
 */
function resolve_host_ip(string $host): ?string
{
    return dns_lookup_ips($host)[0] ?? null;
}

/**
 * L'adresse appartient-elle à un vrai réseau domestique ?
 *
 * « Non publique » ne suffit pas : cette catégorie contient aussi la boucle
 * locale et le lien-local — dont 169.254.169.254, le point de métadonnées des
 * hébergeurs cloud. Un téléviseur vit dans une plage RFC 1918 (ou en IPv6
 * unique-local) ; on n'accepte que celles-là.
 */
function ip_is_lan(string $ip): bool
{
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
        $n = ip2long($ip);
        if ($n === false) {
            return false;
        }
        return ($n & 0xFF000000) === 0x0A000000          // 10.0.0.0/8
            || ($n & 0xFFF00000) === 0xAC100000          // 172.16.0.0/12
            || ($n & 0xFFFF0000) === 0xC0A80000;         // 192.168.0.0/16
    }
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
        $paquet = @inet_pton($ip);
        // fc00::/7 — adresses locales uniques.
        return $paquet !== false && (ord($paquet[0]) & 0xFE) === 0xFC;
    }
    return false;
}

/** True si l'IP n'est ni privée ni réservée. */
function ip_is_public(string $ip): bool
{
    return (bool) filter_var(
        $ip,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
    );
}

/** Formate une taille en octets de façon lisible. */
function format_size(mixed $bytes): string
{
    $bytes = (float) ($bytes ?? 0);
    if ($bytes <= 0) {
        return 'N/A';
    }
    $units = ['o', 'Ko', 'Mo', 'Go', 'To', 'Po'];
    $i = (int) floor(log($bytes, 1024));
    $i = max(0, min($i, count($units) - 1));
    return number_format($bytes / (1024 ** $i), 2, ',', ' ') . ' ' . $units[$i];
}

/**
 * Extrait des badges de qualité depuis le titre d'une release
 * (résolution, source, codec, audio, HDR, langue).
 *
 * @return array<int,string>
 */
function quality_badges(string $title): array
{
    $patterns = [
        '/\b(2160p|4k|uhd)\b/i'                     => '2160p',
        '/\b1080p\b/i'                              => '1080p',
        '/\b720p\b/i'                               => '720p',
        '/\b(480p|sd)\b/i'                          => '480p',
        '/\bremux\b/i'                              => 'REMUX',
        '/\b(bluray|blu-ray|bdrip|brrip)\b/i'       => 'BluRay',
        '/\b(web-?dl|webrip|web)\b/i'               => 'WEB',
        '/\bhdtv\b/i'                               => 'HDTV',
        '/\b(x265|h\.?265|hevc)\b/i'                => 'x265',
        '/\b(x264|h\.?264|avc)\b/i'                 => 'x264',
        '/\bav1\b/i'                                => 'AV1',
        '/\b(dolby\s?vision|dovi|\bdv\b)\b/i'       => 'DV',
        '/\bhdr10?\+?\b/i'                          => 'HDR',
        '/\b(atmos|truehd)\b/i'                     => 'Atmos',
        '/\b(dts(-hd)?)\b/i'                        => 'DTS',
        '/\bflac\b/i'                               => 'FLAC',
        '/\b(multi|multilang)\b/i'                  => 'MULTI',
        '/\b(truefrench|vff|vfq|vfi|french)\b/i'    => 'FR',
        '/\bvostfr\b/i'                             => 'VOSTFR',
    ];

    $badges = [];
    foreach ($patterns as $regex => $label) {
        if (preg_match($regex, $title) && !in_array($label, $badges, true)) {
            $badges[] = $label;
        }
    }
    return $badges;
}

/** Nombre de jours écoulés depuis une date ISO, ou null si invalide. */
function days_since(?string $date): ?int
{
    if ($date === null || $date === '') {
        return null;
    }
    $ts = strtotime($date);
    if ($ts === false) {
        return null;
    }
    return (int) floor((time() - $ts) / 86400);
}
