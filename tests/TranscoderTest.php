<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/Transcoder.php';

/**
 * Tests de la décision de lecture.
 *
 * C'est le cœur économique de la fonctionnalité : chaque fichier classé à tort
 * en « full » est un cœur de processeur mobilisé pour rien, et chaque fichier
 * classé à tort en « direct » est une vidéo noire chez l'utilisateur.
 */
final class TranscoderTest extends TestCase
{
    private function t(): Transcoder
    {
        return new Transcoder();
    }

    /** @return array{video:string,audio:string,pix:string,duration:float,width:int,height:int} */
    private static function flux(string $video, string $audio, string $pix = 'yuv420p'): array
    {
        return ['video' => $video, 'audio' => $audio, 'pix' => $pix,
                'duration' => 5400.0, 'width' => 1920, 'height' => 1080];
    }

    /** @return array<string,array{0:string,1:string,2:string,3:string,4:string}> */
    public static function decisionProvider(): array
    {
        return [
            // [vidéo, audio, extension, profil, mode attendu]
            'MP4 H.264/AAC : rien à faire'      => ['h264', 'aac', 'mp4', 'cast', 'direct'],
            'MKV H.264/AAC : conteneur seul'    => ['h264', 'aac', 'mkv', 'cast', 'remux'],
            'MKV H.264/AC3 : audio seul'        => ['h264', 'ac3', 'mkv', 'cast', 'audio'],
            'MKV H.264/DTS : audio seul'        => ['h264', 'dts', 'mkv', 'cast', 'audio'],
            'MP4 H.264/EAC3 : audio seul'       => ['h264', 'eac3', 'mp4', 'cast', 'audio'],
            'MKV HEVC/AAC : tout'               => ['hevc', 'aac', 'mkv', 'cast', 'full'],
            'MKV HEVC/DTS : tout'               => ['hevc', 'dts', 'mkv', 'cast', 'full'],
            'AVI XviD/MP3 : tout'               => ['mpeg4', 'mp3', 'avi', 'cast', 'full'],
            // Le navigateur accepte davantage que le récepteur Cast.
            'WebM VP9/Opus au navigateur'       => ['vp9', 'opus', 'webm', 'browser', 'direct'],
            'WebM VP9/Opus au Cast'             => ['vp9', 'opus', 'webm', 'cast', 'full'],
            'MP4 AV1 au navigateur'             => ['av1', 'aac', 'mp4', 'browser', 'direct'],
            'MP4 AV1 au Cast'                   => ['av1', 'aac', 'mp4', 'cast', 'full'],
            'MKV H.264/FLAC au navigateur'      => ['h264', 'flac', 'mkv', 'browser', 'remux'],
            'MKV H.264/FLAC au Cast'            => ['h264', 'flac', 'mkv', 'cast', 'audio'],
        ];
    }

    #[DataProvider('decisionProvider')]
    public function testDecision(string $v, string $a, string $ext, string $profil, string $attendu): void
    {
        $this->assertSame($attendu, $this->t()->decide(self::flux($v, $a), $ext, $profil));
    }

    /**
     * Le nom du codec ne suffit pas : un récepteur Cast, comme un navigateur,
     * refuse le H.264 en 10 bits ou 4:4:4 — que ffprobe annonce pourtant comme
     * « h264 ». Sans cette vérification ces fichiers partaient en simple
     * changement de conteneur, puis étaient rejetés par le téléviseur.
     */
    public function testH264ExotiqueDoitEtreReencode(): void
    {
        // Hi10P (fansubs) et 4:2:2 / 4:4:4 : aucun décodeur grand public.
        foreach (['yuv420p10le', 'yuv422p', 'yuv444p', 'yuv420p12le'] as $pix) {
            $this->assertSame('full', $this->t()->decide(self::flux('h264', 'aac', $pix), 'mkv', 'cast'),
                "{$pix} devrait être réencodé");
            $this->assertSame('full', $this->t()->decide(self::flux('h264', 'aac', $pix), 'mp4', 'browser'),
                "{$pix} devrait être réencodé même en MP4");
        }

        // Le 8 bits 4:2:0 reste le cas normal, et ne doit rien coûter.
        $this->assertSame('direct', $this->t()->decide(self::flux('h264', 'aac', 'yuv420p'), 'mp4', 'cast'));
        $this->assertSame('remux', $this->t()->decide(self::flux('h264', 'aac', 'nv12'), 'mkv', 'cast'));

        // Format inconnu : on tente plutôt que de réencoder sur une hypothèse.
        $this->assertSame('direct', $this->t()->decide(self::flux('h264', 'aac', ''), 'mp4', 'cast'));
    }

    /** Un fichier muet ne doit pas être transcodé pour son son inexistant. */
    public function testFichierSansPisteAudio(): void
    {
        $muet = ['video' => 'h264', 'audio' => '', 'pix' => 'yuv420p',
                 'duration' => 60.0, 'width' => 1280, 'height' => 720];
        $this->assertSame('direct', $this->t()->decide($muet, 'mp4', 'cast'));
        $this->assertSame('remux', $this->t()->decide($muet, 'mkv', 'cast'));
    }

    /**
     * Sans analyse possible, on se rabat sur le conteneur : mieux vaut une
     * conversion inutile qu'une vidéo noire.
     */
    public function testSansAnalyse(): void
    {
        $this->assertSame('direct', $this->t()->decide(null, 'mp4', 'cast'));
        $this->assertSame('full', $this->t()->decide(null, 'mkv', 'cast'));
    }

    public function testProfilsConnus(): void
    {
        $this->assertTrue(Transcoder::isProfile('browser'));
        $this->assertTrue(Transcoder::isProfile('cast'));
        $this->assertFalse(Transcoder::isProfile('vlc'));
        $this->assertFalse(Transcoder::isProfile(''));
    }

    /** La commande recopie les flux quand elle le peut, et n'invente rien. */
    public function testCommandeRemuxNeReencodeRien(): void
    {
        $cmd = $this->t()->command('/media/film.mkv', 'remux', 'cast');
        $joint = implode(' ', $cmd);

        $this->assertStringContainsString('-c:v copy', $joint);
        $this->assertStringContainsString('-c:a copy', $joint);
        $this->assertStringNotContainsString('libx264', $joint);
        // MP4 fragmenté par durée : découpé aux images-clés, un encodage BluRay
        // donnait des fragments de 10 s que le récepteur Cast ne savait pas
        // enchaîner.
        $this->assertStringContainsString('empty_moov', $joint);
        $this->assertStringContainsString('-frag_duration 1000000', $joint);
        $this->assertStringNotContainsString('frag_keyframe', $joint);
        $this->assertSame('pipe:1', end($cmd));
    }

    public function testCommandeAudioSeulPreserveLImage(): void
    {
        $joint = implode(' ', $this->t()->command('/media/film.mkv', 'audio', 'cast'));
        $this->assertStringContainsString('-c:v copy', $joint);
        $this->assertStringContainsString('-c:a aac', $joint);
        $this->assertStringNotContainsString('libx264', $joint);
    }

    public function testCommandeCompleteImposeUnProfilLisible(): void
    {
        $joint = implode(' ', $this->t()->command('/media/film.mkv', 'full', 'cast'));
        $this->assertStringContainsString('-c:v libx264', $joint);
        // Un récepteur Cast refuse le 10 bits et le 4:2:2, même en H.264.
        $this->assertStringContainsString('-pix_fmt yuv420p', $joint);
        $this->assertStringContainsString('-profile:v high', $joint);
        $this->assertStringContainsString('-c:a aac', $joint);
    }

    /** Les sous-titres image ne rentrent pas dans un MP4 : les inclure fait échouer. */
    public function testSousTitresEtDonneesExclus(): void
    {
        $joint = implode(' ', $this->t()->command('/media/film.mkv', 'full', 'cast'));
        $this->assertStringContainsString('-sn', $joint);
        $this->assertStringContainsString('-dn', $joint);
    }

    /** La recherche doit précéder l'entrée, sinon ffmpeg décode tout depuis le début. */
    public function testRechercheRapideAvantLEntree(): void
    {
        $cmd = $this->t()->command('/media/film.mkv', 'full', 'cast', 120);
        $posSs = array_search('-ss', $cmd, true);
        $posI  = array_search('-i', $cmd, true);

        $this->assertIsInt($posSs);
        $this->assertIsInt($posI);
        $this->assertLessThan($posI, $posSs, '-ss doit venir avant -i');
        $this->assertSame('120', $cmd[$posSs + 1]);

        // Sans décalage, pas de -ss du tout.
        $this->assertNotContains('-ss', $this->t()->command('/media/film.mkv', 'full', 'cast'));
    }

    /** Un mode inconnu retombe sur la conversion complète, jamais sur une copie. */
    public function testModeInconnuTraiteCommeComplet(): void
    {
        $joint = implode(' ', $this->t()->command('/media/film.mkv', "n'importe quoi", 'cast'));
        $this->assertStringContainsString('-c:v libx264', $joint);
    }
}
