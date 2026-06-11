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
