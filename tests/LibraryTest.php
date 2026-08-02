<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/Library.php';

/**
 * Tests de la bibliothèque.
 *
 * L'essentiel porte sur `resolve()` : c'est la frontière de sécurité du
 * lecteur. Le chemin arrive dans un jeton scellé, mais un jeton reste une
 * donnée — il ne doit jamais pouvoir désigner autre chose qu'un fichier de la
 * bibliothèque.
 */
final class LibraryTest extends TestCase
{
    private string $racine = '';
    private string $cache = '';

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . '/indexof-lib-' . bin2hex(random_bytes(6));
        $this->racine = $base . '/media';
        $this->cache  = $base . '/cache';
        mkdir($this->racine . '/Film.2020.1080p.BluRay-TEST', 0700, true);
        mkdir($this->cache, 0700, true);

        // 5 Mo : au-dessus du plancher, en dessous duquel c'est un artefact.
        $gros = str_repeat('x', 5 * 1024 * 1024);
        file_put_contents($this->racine . '/Film.2020.1080p.BluRay-TEST/Film.2020.1080p.BluRay-TEST.mkv', $gros);
        file_put_contents($this->racine . '/Film.2020.1080p.BluRay-TEST/sample.mkv', $gros);
        file_put_contents($this->racine . '/Film.2020.1080p.BluRay-TEST/notes.txt', $gros);
        file_put_contents($this->racine . '/minuscule.mp4', 'trop petit');
        file_put_contents($base . '/secret.mkv', $gros); // hors bibliothèque
    }

    protected function tearDown(): void
    {
        $base = dirname($this->racine);
        if (is_dir($base)) {
            exec('rm -rf ' . escapeshellarg($base));
        }
    }

    private function lib(): Library
    {
        return new Library($this->racine, $this->cache, 0);
    }

    public function testScanNeGardeQueLesVideosUtiles(): void
    {
        $noms = array_column($this->lib()->scan(), 'rel');

        $this->assertContains('Film.2020.1080p.BluRay-TEST/Film.2020.1080p.BluRay-TEST.mkv', $noms);
        // Extrait, fichier texte et fichier minuscule n'ont rien à faire là.
        $this->assertNotContains('Film.2020.1080p.BluRay-TEST/sample.mkv', $noms);
        $this->assertNotContains('Film.2020.1080p.BluRay-TEST/notes.txt', $noms);
        $this->assertNotContains('minuscule.mp4', $noms);
        $this->assertCount(1, $noms);
    }

    public function testScanExposeLeDossierPourRetrouverLAffiche(): void
    {
        $f = $this->lib()->scan()[0];
        $this->assertSame('Film.2020.1080p.BluRay-TEST', $f['folder']);
        $this->assertSame('mkv', $f['ext']);
        $this->assertFalse($f['web'], 'un MKV ne se lit pas dans un navigateur');
    }

    public function testResolveAccepteUnFichierDeLaBibliotheque(): void
    {
        $r = $this->lib()->resolve('Film.2020.1080p.BluRay-TEST/Film.2020.1080p.BluRay-TEST.mkv');
        $this->assertNotNull($r);
        $this->assertSame('mkv', $r['ext']);
        $this->assertSame(5 * 1024 * 1024, $r['size']);
    }

    /** @return array<string,array{0:string}> */
    public static function evasionProvider(): array
    {
        return [
            'remontée simple'   => ['../secret.mkv'],
            'remontée profonde' => ['../../../../etc/passwd'],
            'remontée au milieu' => ['Film.2020.1080p.BluRay-TEST/../../secret.mkv'],
            'chemin absolu'     => ['/etc/passwd'],
            'vide'              => [''],
            'répertoire'        => ['Film.2020.1080p.BluRay-TEST'],
            'octet nul'         => ["Film.2020.1080p.BluRay-TEST/f.mkv\0.txt"],
            'extension refusée' => ['Film.2020.1080p.BluRay-TEST/notes.txt'],
        ];
    }

    #[PHPUnit\Framework\Attributes\DataProvider('evasionProvider')]
    public function testResolveRefuseToutCeQuiSortDeLaBibliotheque(string $chemin): void
    {
        $this->assertNull($this->lib()->resolve($chemin));
    }

    /**
     * Un lien symbolique posé dans le dossier de téléchargements ne doit pas
     * ouvrir le reste du disque : realpath() ramène la cible réelle, et elle
     * doit rester sous la racine.
     */
    public function testResolveRefuseUnLienSortantDeLaBibliotheque(): void
    {
        $lien = $this->racine . '/evasion.mkv';
        if (!@symlink(dirname($this->racine) . '/secret.mkv', $lien)) {
            $this->markTestSkipped('liens symboliques indisponibles');
        }
        $this->assertNull($this->lib()->resolve('evasion.mkv'));
    }

    public function testFormatsLisiblesParLeNavigateur(): void
    {
        $this->assertTrue(Library::isPlayable('mp4'));
        $this->assertTrue(Library::isPlayable('MP4'));
        $this->assertTrue(Library::isPlayable('webm'));
        $this->assertFalse(Library::isPlayable('mkv'));
        $this->assertFalse(Library::isPlayable('avi'));
        $this->assertFalse(Library::isPlayable('txt'));

        $this->assertSame('video/mp4', Library::mimeFor('mp4'));
        $this->assertSame('video/x-matroska', Library::mimeFor('mkv'));
        // Un type inconnu ne doit jamais être annoncé comme lisible.
        $this->assertSame('application/octet-stream', Library::mimeFor('exe'));
    }

    /**
     * Le rapprochement décide de ce qu'on efface : il doit être exact, et
     * refuser plutôt que deviner.
     */
    public function testRapprochementAvecUnTorrent(): void
    {
        $torrents = [
            ['hash' => 'aaa', 'name' => 'Film.2020.1080p.BluRay-TEST',
             'content_path' => '/downloads/Film.2020.1080p.BluRay-TEST'],
            ['hash' => 'bbb', 'name' => 'Autre.Chose.2019',
             'content_path' => '/downloads/Autre.Chose.2019'],
        ];

        // Par le dossier de la release.
        $t = match_torrent($torrents, 'Film.2020.1080p.BluRay-TEST/Film.2020.1080p.BluRay-TEST.mkv');
        $this->assertSame('aaa', $t['hash'] ?? null);

        // La casse ne doit pas empêcher la correspondance.
        $t = match_torrent($torrents, 'film.2020.1080p.bluray-test/x.mkv');
        $this->assertSame('aaa', $t['hash'] ?? null);

        // Fichier isolé, torrent mono-fichier.
        $seul = [['hash' => 'ccc', 'name' => 'Solo.2021.mkv', 'content_path' => '/downloads/Solo.2021.mkv']];
        $this->assertSame('ccc', match_torrent($seul, 'Solo.2021.mkv')['hash'] ?? null);
    }

    /**
     * Dans le doute, on ne supprime rien : un choix arbitraire efface le mauvais
     * fichier, et c'est irréversible.
     */
    public function testRapprochementRefuseLAmbiguite(): void
    {
        // Aucun torrent ne correspond.
        $this->assertNull(match_torrent(
            [['hash' => 'aaa', 'name' => 'Autre', 'content_path' => '/downloads/Autre']],
            'Film.2020/film.mkv'
        ));

        // Deux torrents portent le même nom : impossible de trancher.
        $doublon = [
            ['hash' => 'aaa', 'name' => 'Film.2020', 'content_path' => '/downloads/Film.2020'],
            ['hash' => 'bbb', 'name' => 'Film.2020', 'content_path' => '/autre/Film.2020'],
        ];
        $this->assertNull(match_torrent($doublon, 'Film.2020/film.mkv'));

        // Entrées inexploitables.
        $this->assertNull(match_torrent([], 'Film.2020/film.mkv'));
        $this->assertNull(match_torrent([['name' => 'Film.2020']], 'Film.2020/film.mkv'));
        $this->assertNull(match_torrent(
            [['hash' => 'aaa', 'name' => 'Film.2020', 'content_path' => '/downloads/Film.2020']], ''
        ));
    }

    public function testBibliothequeAbsenteSeDesactive(): void
    {
        $absente = new Library('/n/existe/pas', $this->cache, 0);
        $this->assertFalse($absente->available());
        $this->assertSame([], $absente->scan());
        $this->assertNull($absente->resolve('x.mkv'));

        $this->assertTrue($this->lib()->available());
    }
}
