<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/Store.php';

/**
 * La base sert à reconnaître une release déjà prise et à rejouer une recherche.
 * Elle doit surtout ne jamais faire tomber l'application quand elle est
 * inaccessible.
 */
final class StoreTest extends TestCase
{
    private string $file = '';

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/indexof_test_' . bin2hex(random_bytes(6)) . '.sqlite';
    }

    protected function tearDown(): void
    {
        foreach ([$this->file, $this->file . '-wal', $this->file . '-shm'] as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
    }

    public function testTitleKeyIgnoresCaseAndPunctuation(): void
    {
        $a = Store::titleKey('The.Matrix.1999.MULTi-REBiRTH');
        $b = Store::titleKey('the matrix 1999 multi rebirth');
        $this->assertSame($a, $b);
        $this->assertNotSame($a, Store::titleKey('The.Matrix.Reloaded.2003'));
    }

    public function testRecordedSendIsFoundAgain(): void
    {
        $store = new Store($this->file);
        $this->assertTrue($store->available());

        $title = 'The.Matrix.1999.MULTi.2160p.BluRay-REBiRTH';
        $store->recordSend($title, 'Torr9', 'qbit');

        $index = $store->sentIndex();
        $this->assertArrayHasKey(Store::titleKey($title), $index);
        $this->assertSame('qbit', $index[Store::titleKey($title)]['target']);

        // Le rapprochement doit résister à une ponctuation différente.
        $this->assertArrayHasKey(
            Store::titleKey('the matrix 1999 multi 2160p bluray rebirth'),
            $index
        );
    }

    public function testHistoryIsMostRecentFirstAndClearable(): void
    {
        $store = new Store($this->file);
        $store->recordSend('Ancien', 'A', 'qbit');
        $store->recordSend('Récent', 'B', 'sonarr');

        $history = $store->history();
        $this->assertCount(2, $history);
        $this->assertSame('Récent', $history[0]['title']);
        $this->assertSame('sonarr', $history[0]['target']);

        $store->clearHistory();
        $this->assertSame([], $store->history());
        $this->assertSame([], $store->sentIndex());
    }

    public function testSearchRoundTripAndDeletion(): void
    {
        $store = new Store($this->file);
        $id = $store->saveSearch([
            'name' => 'Films 4K', 'query' => 'dune', 'days' => 7,
            'cats' => '2000', 'trackers' => '1,2', 'safe' => false,
        ]);
        $this->assertGreaterThan(0, $id);

        $searches = $store->searches();
        $this->assertCount(1, $searches);
        $this->assertSame('Films 4K', $searches[0]['name']);
        $this->assertSame('dune', $searches[0]['query']);
        $this->assertSame(7, (int) $searches[0]['days']);
        $this->assertSame(0, (int) $searches[0]['safe']);

        $store->deleteSearch($id);
        $this->assertSame([], $store->searches());
    }

    public function testFeedTokenIsGeneratedAndResolves(): void
    {
        $store = new Store($this->file);
        $id = $store->saveSearch([
            'name' => 'Flux', 'query' => 'dune', 'days' => 0,
            'cats' => '', 'trackers' => '', 'safe' => true,
        ]);
        $this->assertGreaterThan(0, $id);

        $token = (string) $store->searches()[0]['token'];
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $token);

        $found = $store->searchByToken($token);
        $this->assertNotNull($found);
        $this->assertSame('dune', $found['query']);
        $this->assertTrue($store->feedTokenExists($token));

        // Un jeton mal formé ou inconnu ne doit rien révéler.
        $this->assertNull($store->searchByToken('pas-un-jeton'));
        $this->assertNull($store->searchByToken(str_repeat('0', 32)));
        $this->assertFalse($store->feedTokenExists(''));

        // Supprimer la recherche révoque le flux.
        $store->deleteSearch($id);
        $this->assertFalse($store->feedTokenExists($token));
    }

    public function testUnavailableStoreDegradesInsteadOfThrowing(): void
    {
        // Chemin impossible à créer : l'application doit continuer de tourner.
        $store = new Store('/proc/indexof-impossible/db.sqlite');

        $this->assertFalse($store->available());
        $this->assertSame([], $store->history());
        $this->assertSame([], $store->sentIndex());
        $this->assertSame([], $store->searches());
        $this->assertSame(0, $store->saveSearch([
            'name' => 'x', 'query' => '', 'days' => 0,
            'cats' => '', 'trackers' => '', 'safe' => true,
        ]));
        $this->assertNull($store->searchByToken(str_repeat('a', 32)));
        $this->assertFalse($store->feedTokenExists(str_repeat('a', 32)));
        // Ne doit lever aucune exception.
        $store->recordSend('Titre', 'Indexeur', 'qbit');
        $store->deleteSearch(1);
        $store->clearHistory();
    }
}
