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

    public function testSignatureRoundTrip(): void
    {
        $secret = 's3cret';
        $url = 'https://tracker.example/dl?id=42';
        $sig = sign_url($url, $secret);

        $this->assertTrue(verify_url_signature($url, $sig, $secret));
        $this->assertFalse(verify_url_signature($url, 'deadbeef', $secret));
        $this->assertFalse(verify_url_signature($url . 'x', $sig, $secret));
        $this->assertFalse(verify_url_signature($url, $sig, 'other-secret'));
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
}
