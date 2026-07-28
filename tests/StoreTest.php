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

    /**
     * Le suivi des nouveautés décide de ce qui part en notification : une
     * release déjà signalée ne doit jamais l'être deux fois.
     */
    public function testTakeUnseenReturnsOnlyNewOnesAndRemembers(): void
    {
        $store = new Store($this->file);
        $id = $store->saveSearch([
            'name' => 'Veille', 'query' => 'dune', 'days' => 0,
            'cats' => '', 'trackers' => '', 'safe' => true,
        ]);

        $premiers = $store->takeUnseen($id, ['a', 'b', 'c']);
        $this->assertSame(['a', 'b', 'c'], $premiers);

        // Rejouées, elles ne ressortent pas ; seule la nouvelle apparaît.
        $this->assertSame(['d'], $store->takeUnseen($id, ['a', 'b', 'c', 'd']));
        $this->assertSame([], $store->takeUnseen($id, ['a', 'd']));

        // Le suivi est propre à chaque recherche.
        $autre = $store->saveSearch([
            'name' => 'Autre', 'query' => 'x', 'days' => 0,
            'cats' => '', 'trackers' => '', 'safe' => true,
        ]);
        $this->assertSame(['a'], $store->takeUnseen($autre, ['a']));

        // Supprimer la recherche efface son suivi.
        $store->deleteSearch($id);
        $this->assertSame(['a'], $store->takeUnseen($id, ['a']));
    }

    public function testNotifyToggle(): void
    {
        $store = new Store($this->file);
        $id = $store->saveSearch([
            'name' => 'Veille', 'query' => 'dune', 'days' => 0,
            'cats' => '', 'trackers' => '', 'safe' => true,
        ]);
        $this->assertSame([], $store->notifiedSearches(), 'désactivé par défaut');

        $store->setNotify($id, true);
        $actives = $store->notifiedSearches();
        $this->assertCount(1, $actives);
        $this->assertSame('Veille', $actives[0]['name']);

        $store->setNotify($id, false);
        $this->assertSame([], $store->notifiedSearches());
    }

    public function testUserLifecycleAndPasswordCheck(): void
    {
        $store = new Store($this->file);
        $this->assertSame(0, $store->userCount());

        $this->assertNull($store->addUser('alice', 'motdepasse-tres-long'));
        $this->assertSame(1, $store->userCount());

        $this->assertSame('alice', $store->checkUser('alice', 'motdepasse-tres-long'));
        $this->assertNull($store->checkUser('alice', 'mauvais'), 'mauvais mot de passe');
        $this->assertNull($store->checkUser('inconnu', 'motdepasse-tres-long'), 'compte inexistant');
        $this->assertNull($store->checkUser('alice', ''), 'mot de passe vide');

        // Le nom n'est pas sensible à la casse : « Alice » et « alice » sont
        // la même personne, sinon on créerait deux comptes homographes.
        $this->assertSame('alice', $store->checkUser('ALICE', 'motdepasse-tres-long'));

        $id = $store->users()[0]['id'];
        $store->deleteUser($id);
        $this->assertSame(0, $store->userCount());
        $this->assertNull($store->checkUser('alice', 'motdepasse-tres-long'));
    }

    public function testUserCreationRules(): void
    {
        $store = new Store($this->file);

        $this->assertNotNull($store->addUser('a', 'motdepasse-tres-long'), 'nom trop court');
        $this->assertNotNull($store->addUser('a b', 'motdepasse-tres-long'), 'espace interdit');
        $this->assertNotNull($store->addUser('../etc', 'motdepasse-tres-long'), 'caractères interdits');
        $this->assertNotNull($store->addUser('bob', 'court'), 'mot de passe trop court');

        $this->assertNull($store->addUser('bob', 'motdepasse-tres-long'));
        $this->assertNotNull($store->addUser('bob', 'motdepasse-tres-long'), 'nom déjà pris');
        $this->assertSame(1, $store->userCount());
    }

    public function testPasswordIsHashedNotStored(): void
    {
        $store = new Store($this->file);
        $store->addUser('alice', 'motdepasse-tres-long');

        // La liste exposée à l'interface ne doit contenir aucune empreinte.
        $this->assertArrayNotHasKey('pass_hash', $store->users()[0]);

        // Et le fichier ne contient pas le mot de passe en clair.
        $this->assertStringNotContainsString(
            'motdepasse-tres-long',
            (string) file_get_contents($this->file)
        );
    }

    public function testSendIsAttributedToItsAuthor(): void
    {
        $store = new Store($this->file);
        $store->recordSend('Release.A', 'Tracker', 'qbit', '', 'alice');
        $store->recordSend('Release.B', 'Tracker', 'qbit');

        $history = $store->history();
        $this->assertSame('', $history[0]['user'], 'sans compte nommé, pas d\'auteur');
        $this->assertSame('alice', $history[1]['user']);
    }

    /**
     * Le cloisonnement par indexeur est une frontière de sécurité : sur un
     * tracker privé, laisser quelqu'un chercher avec les identifiants d'un
     * autre lui fait porter le ratio et les sanctions.
     */
    public function testIndexerRestrictionIsSanitisedAndReported(): void
    {
        $store = new Store($this->file);
        $store->addUser('alice', 'motdepasse-tres-long');
        $id = $store->users()[0]['id'];

        // Sans liste, aucune restriction.
        $this->assertNull($store->userIndexers('alice'));

        // Les valeurs non numériques sont écartées, et le retour dit ce qui a
        // réellement été retenu — pas ce qui a été demandé.
        $retenus = $store->setUserIndexers($id, array_map('intval', ['1', '../etc', 'DROP', '0', '-4']));
        $this->assertSame([1], $retenus);
        $this->assertSame([1], $store->userIndexers('alice'));

        // Liste vidée = retour à l'accès complet.
        $this->assertSame([], $store->setUserIndexers($id, []));
        $this->assertNull($store->userIndexers('alice'));
    }

    public function testUnknownUserGetsNothingNotEverything(): void
    {
        $store = new Store($this->file);

        // Compte inexistant (supprimé pendant sa session) : on ferme tout.
        // Renvoyer null ici ouvrirait l'accès à tous les indexeurs.
        $this->assertSame([], $store->userIndexers('fantome'));

        // L'administrateur, lui, n'a pas de restriction.
        $this->assertNull($store->userIndexers(''));
    }

    public function testSearchesAreScopedToTheirOwner(): void
    {
        $store = new Store($this->file);
        $store->saveSearch(['name' => 'A elle', 'query' => 'x', 'days' => 0,
            'cats' => '', 'trackers' => '', 'safe' => true, 'owner' => 'alice']);
        $store->saveSearch(['name' => 'A lui', 'query' => 'y', 'days' => 0,
            'cats' => '', 'trackers' => '', 'safe' => true, 'owner' => '']);

        $this->assertCount(2, $store->searches(), 'sans portée, tout est visible (administrateur)');
        $this->assertSame(['A elle'], array_column($store->searches('alice'), 'name'));
        $this->assertSame(['A lui'], array_column($store->searches(''), 'name'));

        // Le propriétaire suit le jeton du flux : c'est lui qui décide des
        // indexeurs interrogés, le flux s'exécutant sans session.
        $token = (string) $store->searches('alice')[0]['token'];
        $this->assertSame('alice', $store->searchByToken($token)['owner']);
    }

    public function testHistoryIsScopedToItsUser(): void
    {
        $store = new Store($this->file);
        $store->recordSend('A', 'T', 'qbit', '', 'alice');
        $store->recordSend('B', 'T', 'qbit', '', '');

        $this->assertCount(2, $store->history(), 'sans portée, tout est visible');
        $this->assertSame(['A'], array_column($store->history(200, 'alice'), 'title'));
        $this->assertArrayHasKey(Store::titleKey('A'), $store->sentIndex(2000, 'alice'));
        $this->assertArrayNotHasKey(Store::titleKey('B'), $store->sentIndex(2000, 'alice'));
    }

    public function testCategoryIsSanitisedBeforeReachingTheDownloadClient(): void
    {
        $store = new Store($this->file);
        $this->assertNull($store->addUser('alice', 'motdepasse-tres-long'));
        $id = (int) $store->users()[0]['id'];

        // Ni séparateur de chemin, ni caractère de commande : qBittorrent dérive
        // un dossier de ce nom.
        $this->assertSame('etc rm -rf', $store->setUserCategory($id, '../../etc; rm -rf /'));
        // Un nom qui ne commence pas par un caractère utile ne donne rien.
        $this->assertSame('', $store->setUserCategory($id, '...'));
        $this->assertSame('cache', $store->setUserCategory($id, '.cache'));
        $this->assertSame(40, mb_strlen($store->setUserCategory($id, str_repeat('a', 80))));
        // Les accents et les tirets restent : ce sont des noms de dossier valides.
        $this->assertSame('Séries-alice', $store->setUserCategory($id, 'Séries-alice'));

        $this->assertSame('Séries-alice', (new Store($this->file))->userCategory('alice'));
        // L'administrateur et les inconnus n'ont aucune catégorie imposée.
        $this->assertSame('', $store->userCategory(''));
        $this->assertSame('', $store->userCategory('bob'));
    }

    public function testUserExistsFollowsAccountDeletion(): void
    {
        $store = new Store($this->file);
        $this->assertNull($store->addUser('alice', 'motdepasse-tres-long'));
        $id = (int) $store->users()[0]['id'];

        $this->assertTrue($store->userExists('alice'));
        $this->assertTrue($store->userExists('ALICE'), 'la casse ne doit pas créer un compte fantôme');
        $this->assertFalse($store->userExists('bob'));
        // '' est l'administrateur : il n'a pas de ligne en base et existe toujours.
        $this->assertTrue($store->userExists(''));

        $store->deleteUser($id);
        $this->assertFalse((new Store($this->file))->userExists('alice'));
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
        $this->assertSame([], $store->takeUnseen(1, ['a']));
        $this->assertSame([], $store->users());
        $this->assertSame(0, $store->userCount());
        $this->assertNotNull($store->addUser('alice', 'motdepasse-tres-long'));
        $this->assertNull($store->checkUser('alice', 'motdepasse-tres-long'));
        $store->deleteUser(1);
        $this->assertSame([], $store->setUserIndexers(1, [1]));
        $this->assertSame('', $store->setUserCategory(1, 'alice-dl'));
        $this->assertSame('', $store->userCategory('alice'));
        // Base injoignable : on ne déconnecte pas tout le monde pour autant.
        $this->assertTrue($store->userExists('alice'));
        $this->assertSame([], $store->notifiedSearches());
        $store->setNotify(1, true);
        // Ne doit lever aucune exception.
        $store->recordSend('Titre', 'Indexeur', 'qbit');
        $store->deleteSearch(1);
        $store->clearHistory();
    }
}
