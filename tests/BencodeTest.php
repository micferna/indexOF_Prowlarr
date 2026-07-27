<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/Bencode.php';

/**
 * L'analyseur lit des fichiers venus de trackers : l'entrée est hostile par
 * défaut. Il doit refuser proprement plutôt que deviner, et ne jamais partir
 * en boucle ou en mémoire.
 */
final class BencodeTest extends TestCase
{
    private static function str(string $s): string
    {
        return strlen($s) . ':' . $s;
    }

    public function testSingleFileTorrent(): void
    {
        $torrent = 'd4:infod6:lengthi1048576e4:name' . self::str('film.mkv') . 'ee';

        $summary = Bencode::summarize($torrent);
        $this->assertNotNull($summary);
        $this->assertSame('film.mkv', $summary['name']);
        $this->assertSame(1048576, $summary['size']);
        $this->assertCount(1, $summary['files']);
        $this->assertSame('film.mkv', $summary['files'][0]['path']);
    }

    public function testMultiFileTorrentJoinsPathSegments(): void
    {
        $file = static fn (int $len, array $path): string =>
            'd6:lengthi' . $len . 'e4:pathl' . implode('', array_map(
                static fn (string $p): string => self::str($p),
                $path
            )) . 'ee';

        $torrent = 'd4:infod4:name' . self::str('Saison') . '5:filesl'
            . $file(700, ['Saison 1', 'E01.mkv'])
            . $file(800, ['Saison 1', 'E02.mkv'])
            . $file(12, ['lisezmoi.txt'])
            . 'eee';

        $summary = Bencode::summarize($torrent);
        $this->assertNotNull($summary);
        $this->assertSame('Saison', $summary['name']);
        $this->assertSame(1512, $summary['size'], 'la taille totale additionne tous les fichiers');
        $this->assertCount(3, $summary['files']);
        $this->assertSame('Saison 1/E01.mkv', $summary['files'][0]['path']);
        $this->assertSame(800, $summary['files'][1]['size']);
    }

    public function testFileListIsCapped(): void
    {
        $files = '';
        for ($i = 0; $i < 50; $i++) {
            $files .= 'd6:lengthi10e4:pathl' . self::str("f{$i}.mkv") . 'ee';
        }
        $summary = Bencode::summarize('d4:infod4:name' . self::str('pack') . '5:filesl' . $files . 'eee', 10);

        $this->assertNotNull($summary);
        $this->assertCount(10, $summary['files'], 'la liste affichée est plafonnée');
        $this->assertSame(500, $summary['size'], 'la taille totale reste complète');
    }

    /**
     * @return array<string,array{0:string}>
     */
    public static function entreesInvalides(): array
    {
        return [
            'vide'                 => [''],
            'octet inattendu'      => ['xyz'],
            'chaîne tronquée'      => ['d4:infod4:name99:court'],
            'entier non terminé'   => ['d4:infod6:lengthi123'],
            'dictionnaire ouvert'  => ['d4:infod6:lengthi1e'],
            'longueur démesurée'   => ['d4:infod4:name99999999999:x'],
            'liste non fermée'     => ['d4:infod5:filesl'],
            'pas un dictionnaire'  => ['i42e'],
            'imbrication profonde' => [str_repeat('l', 200) . str_repeat('e', 200)],
        ];
    }

    #[DataProvider('entreesInvalides')]
    public function testInvalidInputReturnsNullInsteadOfThrowing(string $entree): void
    {
        $this->assertNull(Bencode::summarize($entree));
    }

    public function testBinaryContentDoesNotBreakParsing(): void
    {
        // Les vrais .torrent contiennent un champ « pieces » binaire.
        $pieces = random_bytes(60);
        $torrent = 'd4:infod6:lengthi10e4:name' . self::str('a.mkv') . '6:pieces' . self::str($pieces) . 'ee';

        $summary = Bencode::summarize($torrent);
        $this->assertNotNull($summary);
        $this->assertSame('a.mkv', $summary['name']);
    }
}
