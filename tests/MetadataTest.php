<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/Metadata.php';

/**
 * Tests du découpage des noms de release.
 *
 * C'est la pièce fragile de la reconnaissance : tout le reste (affiche, résumé)
 * en dépend, et les trackers écrivent leurs titres comme ils veulent. Chaque cas
 * ci-dessous vient d'un titre réellement rencontré.
 */
final class MetadataTest extends TestCase
{
    /** @return array<string,array{0:string,1:string,2:?int}> */
    public static function filmProvider(): array
    {
        return [
            'points et année'      => ['The.Matrix.1999.MULTi.2160p.UHD.BluRay.x265-SESKAPiLE', 'The Matrix', 1999],
            'année entre parenthèses' => ['Matrix (1999) MULTi VFI 2160p 10bit BluRay x265-QTZ', 'Matrix', 1999],
            'espaces'              => ['Alien Romulus 2024 MULTI VFF 1080p WEB x264', 'Alien Romulus', 2024],
            'token avant l\'année' => ['The.Matrix.III.Revolutions.REMASTERED.2003.MULTi.1080p', 'The Matrix III Revolutions', 2003],
            'préfixe de site'      => ['[ Torrent911.gg ] The.Substance.2024.FRENCH.1080p', 'The Substance', 2024],
            'domaine en tête'      => ['www.Torrenting.com - Dune.Part.Two.2024.2160p.WEB-DL', 'Dune Part Two', 2024],
            'sans année'           => ['Kaamelott.Livre.I.MULTI.1080p.BluRay', 'Kaamelott Livre I', null],
        ];
    }

    #[DataProvider('filmProvider')]
    public function testDecoupeUnFilm(string $titre, string $attendu, ?int $annee): void
    {
        $p = parse_release_title($titre);
        $this->assertSame($attendu, $p['name']);
        $this->assertSame($annee, $p['year']);
        $this->assertNull($p['season']);
    }

    /**
     * Un titre qui EST une année (« 1917 », « 2012 ») ne doit pas être vidé par
     * sa propre coupe : c'est la deuxième année qui borne le nom.
     */
    public function testAnneeEnTeteFaitPartieDuTitre(): void
    {
        $p = parse_release_title('1917.2019.MULTi.1080p.BluRay.x264-VENUE');
        $this->assertSame('1917', $p['name']);
        $this->assertSame(2019, $p['year']);

        $p = parse_release_title('2012.2009.MULTI.1080p.BluRay');
        $this->assertSame('2012', $p['name']);
        $this->assertSame(2009, $p['year']);
    }

    /** @return array<string,array{0:string,1:string,2:?int,3:?int}> */
    public static function serieProvider(): array
    {
        return [
            'S01E01'          => ['Breaking.Bad.S01E01.1080p.BluRay.x264-GROUP', 'Breaking Bad', 1, 1],
            '2x03'            => ['Severance.2x03.iTALiAN.1080p', 'Severance', 2, 3],
            'saison seule'    => ['Game of Thrones - Saison 1 MULTI 1080p BluRay', 'Game of Thrones', 1, null],
            'season anglaise' => ['The.Office.Season.3.COMPLETE.720p.WEB', 'The Office', 3, null],
            'sigle recollé'   => ['S.W.A.T.S05E10.FRENCH.720p.HDTV', 'SWAT', 5, 10],
        ];
    }

    #[DataProvider('serieProvider')]
    public function testDecoupeUneSerie(string $titre, string $attendu, ?int $saison, ?int $episode): void
    {
        $p = parse_release_title($titre);
        $this->assertSame($attendu, $p['name']);
        $this->assertSame($saison, $p['season']);
        $this->assertSame($episode, $p['episode']);
        // Le découpage prime sur la catégorie de l'indexeur : un S01E01 rangé
        // en « Films » reste une série.
        $this->assertSame('tv', $p['kind']);
    }

    /**
     * Beaucoup de trackers publient leurs titres en UTF-8 déjà relu comme du
     * Latin-1. Tant qu'on cherchait avec ces octets-là, aucune base ne
     * reconnaissait le titre : c'était la première cause d'affiche manquante.
     */
    public function testAccentsDoubleEncodesRepares(): void
    {
        // « Deuxième » cassé : C3 A8 (è) ré-encodé en C3 83 C2 A8.
        $casse = "Dune : Deuxi\u{00C3}\u{00A8}me partie (2024) MULTi VFF 2160p BluRay x265-QTZ";
        $this->assertSame('Dune : Deuxième partie', parse_release_title($casse)['name']);

        // Un titre déjà sain ne doit pas être touché.
        $sain = 'Les Visiteurs, La Révolution (2016) VOF 1080p Bluray x265-ASKO';
        $this->assertSame('Les Visiteurs, La Révolution', parse_release_title($sain)['name']);

        // Ni un titre sans le moindre accent.
        $this->assertSame('The Matrix', parse_release_title('The.Matrix.1999.1080p.BluRay')['name']);
    }

    public function testMojibakeIgnoreQuandLeTexteEstDejaCorrect(): void
    {
        foreach (['Amélie', 'Les Visiteurs', '君の名は', 'Dune: Part Two', ''] as $texte) {
            $this->assertSame($texte, repair_double_utf8($texte));
        }
    }

    /**
     * Les uploadeurs mettent le titre international entre parenthèses en fin de
     * nom. C'est le repli qui rattrape les releases publiées sous un titre local.
     */
    public function testTitreAlternatifEntreParentheses(): void
    {
        $p = parse_release_title('Dune : Deuxieme partie (2024) MULTi VFF 2160p x265-QTZ (Dune Part Two)');
        $this->assertSame('Dune Part Two', $p['alt']);

        $p = parse_release_title('Dabbe4.Cin.Carpmasi.2013.1080p.NF.WEB-DL.x264-RONIN (D@bbe: The Possession)');
        $this->assertSame('D@bbe: The Possession', $p['alt']);

        // Une simple année entre parenthèses n'est pas un titre.
        $this->assertSame('', parse_release_title('The.Matrix.(1999).1080p.BluRay')['alt']);
        // Une mention technique non plus.
        $this->assertSame('', parse_release_title('Film.2020.1080p.(REMASTERED)')['alt']);
    }

    /**
     * Un titre alternatif au milieu du nom brouillerait la recherche
     * principale : il est retenu à part, pas laissé dans le nom.
     */
    public function testTitreAlternatifRetireDuNom(): void
    {
        $p = parse_release_title('Dune: Part One (Dune : Premiere Partie) (2021) ISO 2160p HDR10 MULTi');
        $this->assertSame('Dune: Part One', $p['name']);
        $this->assertSame('Dune : Premiere Partie', $p['alt']);
    }

    /**
     * Les packs (« Trilogie », « INTEGRAL », « The Complete Series ») portent
     * des mots qui décrivent le lot, pas l'œuvre — et suffisent à faire échouer
     * la recherche alors que le titre nu se trouve du premier coup.
     */
    public function testMotsDeLotRetires(): void
    {
        $this->assertSame('Avatar', strip_pack_words('Avatar Trilogie'));
        $this->assertSame('GAME OF THRONES', strip_pack_words('GAME OF THRONES INTEGRAL'));
        $this->assertSame('Game of Thrones', strip_pack_words('Game of Thrones The Complete Series'));
        $this->assertSame('Kaamelott', strip_pack_words('Kaamelott Intégrale + Bonus'));
        $this->assertSame('Inception', strip_pack_words('BONUS Inception'));
        $this->assertSame('Le Roi Lion Disney', strip_pack_words('Le Roi Lion 3D Disney'));

        // Un titre sans mot de lot ressort intact.
        $this->assertSame('The Matrix', strip_pack_words('The Matrix'));
        $this->assertSame('Les Visiteurs', strip_pack_words('Les Visiteurs'));
    }

    /**
     * Le premier résultat n'est pas le bon : pour « GAME OF THRONES INTEGRAL »,
     * Sonarr propose « Game of Thrones Talk » avant le vrai « Game of Thrones ».
     * C'est cette comparaison qui les départage.
     */
    public function testReconnaissanceDuBonTitre(): void
    {
        $cherche = [meta_normalize('GAME OF THRONES INTEGRAL')];
        $this->assertTrue(meta_title_matches($cherche, ['title' => 'Game of Thrones']));
        $this->assertFalse(meta_title_matches($cherche, ['title' => 'Game of Thrones Talk']));
        $this->assertFalse(meta_title_matches($cherche, ['title' => 'Game of Roles']));

        $cherche = [meta_normalize('Kaamelott Integrale + Bonus')];
        $this->assertTrue(meta_title_matches($cherche, ['title' => 'Kaamelott']));
        $this->assertFalse(meta_title_matches($cherche, ['title' => 'Hotel Paradise: Rajski Bonus']));

        // Un titre alternatif compte : c'est par lui que le titre local rejoint
        // le titre international.
        $cherche = [meta_normalize('Dune : Deuxième partie')];
        $this->assertFalse(meta_title_matches($cherche, ['title' => 'Dune: Part Two']));
        $this->assertTrue(meta_title_matches($cherche, [
            'title' => 'Dune: Part Two',
            'alternateTitles' => [['title' => 'Dune : Deuxième partie']],
        ]));
    }

    /**
     * Un candidat PLUS LONG que ce qu'on cherche est une autre œuvre. Sans cette
     * asymétrie, « Le Roi Lion » captait « Le Roi Lion - Les nouvelles
     * Aventures » et l'emportait sur le vrai film de la même année.
     */
    public function testUnCandidatPlusLongEstUneAutreOeuvre(): void
    {
        $cherche = [meta_normalize('Le Roi Lion')];
        $this->assertFalse(meta_title_matches($cherche, [
            'title' => 'King of the Animals',
            'alternateTitles' => [['title' => 'Le Roi Lion - Les nouvelles Aventures']],
        ]));
        $this->assertTrue(meta_title_matches($cherche, ['title' => 'Le Roi Lion']));

        $cherche = [meta_normalize('Avatar')];
        $this->assertFalse(meta_title_matches($cherche, ['title' => 'Avatar: The Last Airbender']));
    }

    public function testNormalisationDesTitres(): void
    {
        $this->assertSame('dune deuxieme partie', meta_normalize('Dune : Deuxième partie'));
        $this->assertSame('game of thrones', meta_normalize('GAME  OF  THRONES!'));
        $this->assertSame('les visiteurs', meta_normalize('Les Visiteurs'));
        $this->assertSame('', meta_normalize('   ...   '));
        // Un titre au codage cassé se compare comme s'il était sain.
        $this->assertSame('kaamelott integrale', meta_normalize("Kaamelott Int\u{00C3}\u{00A9}grale"));
    }

    public function testTitreVideOuIllisible(): void
    {
        foreach (['', '   ', '...', '[ x ]'] as $titre) {
            $p = parse_release_title($titre);
            $this->assertSame('', $p['name'], "« {$titre} » devrait rester sans nom");
        }
    }

    /**
     * Le proxy d'affiches n'accepte que des sources d'images connues. C'est la
     * garde qui tient même si un jeton scellé désigne autre chose.
     */
    public function testSeulsLesHotesDImagesConnusSontAcceptes(): void
    {
        $this->assertTrue(MetadataClient::isPosterHost('image.tmdb.org'));
        $this->assertTrue(MetadataClient::isPosterHost('IMAGE.TMDB.ORG'));
        $this->assertTrue(MetadataClient::isPosterHost('artworks.thetvdb.com'));

        $this->assertFalse(MetadataClient::isPosterHost('evil.example.com'));
        $this->assertFalse(MetadataClient::isPosterHost('127.0.0.1'));
        $this->assertFalse(MetadataClient::isPosterHost('169.254.169.254'));
        $this->assertFalse(MetadataClient::isPosterHost('image.tmdb.org.evil.com'));
        $this->assertFalse(MetadataClient::isPosterHost(''));
    }

    /**
     * Prowlarr livre l'identifiant IMDb en entier nu ; les *arr le veulent sous
     * sa forme canonique. Sans la remise en forme, toutes les correspondances
     * exactes retombaient silencieusement sur la recherche par nom.
     */
    public function testIdentifiantImdbNuRemisEnForme(): void
    {
        $this->assertSame('tt0133093', 'tt' . str_pad('133093', 7, '0', STR_PAD_LEFT));

        $client = new MetadataClient(
            ['radarr' => ['label' => 'Radarr', 'api' => 'v3', 'url' => 'http://radarr', 'key' => 'k']],
            'secret-de-test-suffisamment-long',
            sys_get_temp_dir() . '/indexof-test-meta',
        );
        $lire = new ReflectionMethod($client, 'context');
        $ctx = $lire->invoke($client, ['title' => 'The.Matrix.1999.1080p', 'kind' => 'movie', 'imdbId' => '133093']);

        $this->assertSame(['imdb:tt0133093'], $ctx['terms']);
    }

    public function testFonctionnaliteInactiveSansRadarrNiSonarr(): void
    {
        $this->assertFalse(MetadataClient::isConfigured([]));
        $this->assertFalse(MetadataClient::isConfigured([
            'lidarr' => ['label' => 'Lidarr', 'api' => 'v1', 'url' => 'http://x', 'key' => 'k'],
        ]));
        $this->assertTrue(MetadataClient::isConfigured([
            'radarr' => ['label' => 'Radarr', 'api' => 'v3', 'url' => 'http://x', 'key' => 'k'],
        ]));
    }
}
