<?php

declare(strict_types=1);

/**
 * Fonctions utilitaires : échappement, validation d'URL (anti-XSS / anti-SSRF),
 * signature HMAC du proxy de téléchargement, et formatage d'affichage.
 */

/** Échappement HTML systématique (ENT_QUOTES couvre " et '). */
function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
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

/** Signe une URL pour le proxy de téléchargement. */
function sign_url(string $url, string $secret): string
{
    return hash_hmac('sha256', $url, $secret);
}

/** Vérifie la signature d'une URL (comparaison à temps constant). */
function verify_url_signature(string $url, string $signature, string $secret): bool
{
    return hash_equals(sign_url($url, $secret), $signature);
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

/** Scelle une chaîne (URL/magnet) en jeton opaque lié au secret applicatif. */
function seal_url(string $plain, string $secret): string
{
    $key = hash('sha256', 'indexof-seal|' . $secret, true);
    $iv  = random_bytes(12);
    $tag = '';
    $ct  = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($ct === false) {
        return '';
    }
    return b64url_encode($iv . $tag . $ct);
}

/** Ouvre un jeton scellé ; renvoie null si invalide/altéré (échec d'auth GCM). */
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
    return $pt === false ? null : $pt;
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
    return $dir . '/throttle_' . preg_replace('/[^a-z0-9_]/i', '', $key) . '.json';
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
