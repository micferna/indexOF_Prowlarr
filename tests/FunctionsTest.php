<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests des helpers critiques (validation d'URL, signature, anti-SSRF, formatage).
 */
final class FunctionsTest extends TestCase
{
    public function testSafeUrlAllowsHttpAndBlocksDangerousSchemes(): void
    {
        $this->assertSame('https://example.com/', safe_url('https://example.com/'));
        $this->assertSame('http://example.com/', safe_url('http://example.com/'));
        $this->assertSame('#', safe_url('javascript:alert(1)'));
        $this->assertSame('#', safe_url('file:///etc/passwd'));
        $this->assertSame('#', safe_url('data:text/html,<script>'));
        $this->assertSame('#', safe_url(null));
        $this->assertSame('#', safe_url(''));
    }

    public function testSafeUrlMagnetScheme(): void
    {
        $magnet = 'magnet:?xt=urn:btih:abc';
        $this->assertSame($magnet, safe_url($magnet, ['magnet']));
        $this->assertSame('#', safe_url('https://x.com/', ['magnet']));
    }

    public function testIpIsPublicBlocksPrivateAndReserved(): void
    {
        $this->assertTrue(ip_is_public('8.8.8.8'));
        $this->assertTrue(ip_is_public('1.1.1.1'));
        $this->assertFalse(ip_is_public('127.0.0.1'));
        $this->assertFalse(ip_is_public('10.0.0.1'));
        $this->assertFalse(ip_is_public('172.16.0.1'));
        $this->assertFalse(ip_is_public('192.168.1.1'));
        $this->assertFalse(ip_is_public('169.254.169.254'));
        $this->assertFalse(ip_is_public('::1'));
        $this->assertFalse(ip_is_public('not-an-ip'));
    }

    public function testResolvePublicIpForLiteral(): void
    {
        $this->assertSame('8.8.8.8', resolve_to_public_ip('8.8.8.8'));
        $this->assertNull(resolve_to_public_ip('127.0.0.1'));
        $this->assertSame('127.0.0.1', resolve_host_ip('127.0.0.1'));
    }

    public function testFormatSize(): void
    {
        $this->assertSame('N/A', format_size(0));
        $this->assertSame('N/A', format_size(null));
        $this->assertStringContainsString('Go', format_size(2 * 1024 ** 3));
        $this->assertStringContainsString('Mo', format_size(5 * 1024 ** 2));
        $this->assertStringContainsString('Ko', format_size(2048));
    }

    public function testDaysSince(): void
    {
        $this->assertNull(days_since(null));
        $this->assertNull(days_since(''));
        $this->assertNull(days_since('pas-une-date'));
        $this->assertSame(0, days_since(date('c')));
        $this->assertSame(2, days_since(date('c', time() - 2 * 86400)));
    }

    public function testQualityBadges(): void
    {
        $badges = quality_badges('Film.2024.1080p.WEB-DL.x265.FLAC.MULTI-GROUP');
        $this->assertContains('1080p', $badges);
        $this->assertContains('WEB', $badges);
        $this->assertContains('x265', $badges);
        $this->assertContains('FLAC', $badges);
        $this->assertContains('MULTI', $badges);
        $this->assertSame([], quality_badges('un titre sans qualité'));
    }

    public function testEscaping(): void
    {
        $this->assertSame('&lt;b&gt;', e('<b>'));
        $this->assertSame('&quot;&#039;', e('"\''));
        $this->assertSame('', e(null));
    }

    public function testSealOpenRoundTrip(): void
    {
        $secret = str_repeat('a', 32);
        $url = 'http://prowlarr:9696/1/download?apikey=SECRET123&link=abc';

        $token = seal_url($url, $secret);
        $this->assertNotSame('', $token);
        // Le secret (apikey) ne doit jamais apparaître en clair dans le jeton.
        $this->assertStringNotContainsString('SECRET123', $token);
        $this->assertStringNotContainsString('apikey', $token);

        $this->assertSame($url, open_url($token, $secret));
        // Mauvais secret => null (échec d'authentification GCM).
        $this->assertNull(open_url($token, 'autre-secret-de-32-caracteres!!'));
        // Jeton altéré => null.
        $this->assertNull(open_url($token . 'AA', $secret));
        $this->assertNull(open_url('nimportequoi', $secret));
        $this->assertNull(open_url('', $secret));
    }

    public function testSealTokenExpires(): void
    {
        $secret = str_repeat('b', 32);
        $url = 'https://tracker.example/dl?id=42';

        // Jeton déjà expiré (TTL négatif) => refusé.
        $this->assertNull(open_url(seal_url($url, $secret, -10), $secret));
        // Jeton valide dans la fenêtre.
        $this->assertSame($url, open_url(seal_url($url, $secret, 60), $secret));
        // TTL 0 = pas d'expiration.
        $this->assertSame($url, open_url(seal_url($url, $secret, 0), $secret));
    }

    public function testThrottleKeysDoNotCollide(): void
    {
        $dir = sys_get_temp_dir() . '/indexof_test_' . bin2hex(random_bytes(4));

        // Deux IP distinctes qui se réduisaient au même nom de fichier.
        $this->assertNotSame(throttle_file($dir, '192.168.0.11'), throttle_file($dir, '192.168.01.1'));
        // IPv6 : ne doit pas produire une clé vide/identique.
        $this->assertNotSame(throttle_file($dir, '2001:db8::1'), throttle_file($dir, '2001:db8::2'));

        throttle_record($dir, '10.0.0.1', 900);
        $this->assertSame(1, throttle_failures($dir, '10.0.0.1', 900));
        $this->assertSame(0, throttle_failures($dir, '10.0.0.2', 900));
        throttle_reset($dir, '10.0.0.1');
        $this->assertSame(0, throttle_failures($dir, '10.0.0.1', 900));

        array_map('unlink', glob($dir . '/*') ?: []);
        @rmdir($dir);
    }

    public function testPruneDirRemovesStaleFiles(): void
    {
        $dir = sys_get_temp_dir() . '/indexof_test_' . bin2hex(random_bytes(4));
        mkdir($dir, 0700, true);

        $old = $dir . '/old.json';
        $new = $dir . '/new.json';
        file_put_contents($old, '[]');
        file_put_contents($new, '[]');
        touch($old, time() - 7200);

        prune_dir($dir, 3600, '*.json', 1); // chance 1/1 => purge certaine

        $this->assertFileDoesNotExist($old);
        $this->assertFileExists($new);

        array_map('unlink', glob($dir . '/*') ?: []);
        @rmdir($dir);
    }

    public function testIsAdultResult(): void
    {
        $this->assertTrue(is_adult_result(['categories' => [['id' => 6000, 'name' => 'XXX']]]));
        $this->assertTrue(is_adult_result(['categories' => [['id' => 6010, 'name' => 'Movies/Adult']]]));
        $this->assertTrue(is_adult_result(['categories' => [['id' => 100, 'name' => 'Porn']]]));
        $this->assertFalse(is_adult_result(['categories' => [['id' => 2000, 'name' => 'Movies']]]));
        $this->assertFalse(is_adult_result(['categories' => []]));
        $this->assertFalse(is_adult_result([]));
    }
}
