<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/CastProtocol.php';
require_once __DIR__ . '/../src/CastDiscovery.php';

/**
 * Tests du protocole Cast et de la découverte réseau.
 *
 * On ne peut pas brancher un téléviseur dans une suite de tests : ce qui est
 * éprouvé ici, c'est tout ce qui peut se tromper AVANT le réseau — l'encodage
 * des trames et la lecture des annonces mDNS.
 */
final class CastTest extends TestCase
{
    public function testVarintSuitLEncodageProtobuf(): void
    {
        // Valeurs de référence de l'encodage base 128.
        $this->assertSame("\x00", CastProtocol::varint(0));
        $this->assertSame("\x01", CastProtocol::varint(1));
        $this->assertSame("\x7F", CastProtocol::varint(127));
        $this->assertSame("\x80\x01", CastProtocol::varint(128));
        $this->assertSame("\xAC\x02", CastProtocol::varint(300));
    }

    public function testVarintAllerRetour(): void
    {
        foreach ([0, 1, 127, 128, 300, 65535, 1048576] as $n) {
            $pos = 0;
            $this->assertSame($n, CastProtocol::readVarint(CastProtocol::varint($n), $pos));
        }
    }

    public function testVarintTronqueOuAberrantEstRefuse(): void
    {
        $pos = 0;
        // Continuation sans octet final.
        $this->assertNull(CastProtocol::readVarint("\x80", $pos));
        $pos = 0;
        $this->assertNull(CastProtocol::readVarint('', $pos));
        $pos = 0;
        $this->assertNull(CastProtocol::readVarint(str_repeat("\x80", 12), $pos));
    }

    /**
     * La trame est ce que le téléviseur lit : longueur en gros-boutiste, puis
     * les six champs du CastMessage.
     */
    public function testTrameEtDecodageAllerRetour(): void
    {
        $trame = CastProtocol::frame(
            'sender-abc',
            CastProtocol::RECEIVER_ID,
            CastProtocol::NS_RECEIVER,
            '{"type":"LAUNCH"}'
        );

        /** @var array{1:int} $entete */
        $entete = unpack('N', substr($trame, 0, 4));
        $this->assertSame(strlen($trame) - 4, $entete[1], 'la longueur annoncée doit être exacte');

        $tampon = $trame;
        $msg = CastProtocol::shift($tampon);

        $this->assertNotNull($msg);
        $this->assertSame('sender-abc', $msg['source']);
        $this->assertSame('receiver-0', $msg['destination']);
        $this->assertSame(CastProtocol::NS_RECEIVER, $msg['namespace']);
        $this->assertSame('{"type":"LAUNCH"}', $msg['payload']);
        $this->assertSame('', $tampon, 'la trame consommée doit être retirée du tampon');
    }

    /**
     * TCP découpe où il veut : un message peut arriver en trois morceaux, et
     * deux messages dans un seul paquet.
     */
    public function testTrameArriveeEnPlusieursMorceaux(): void
    {
        $trame = CastProtocol::frame('s', 'd', 'ns', '{"a":1}');

        $tampon = substr($trame, 0, 3);
        $this->assertNull(CastProtocol::shift($tampon), 'en-tête incomplet');

        $tampon = substr($trame, 0, strlen($trame) - 2);
        $this->assertNull(CastProtocol::shift($tampon), 'corps incomplet');

        // Deux messages collés : les deux doivent sortir.
        $tampon = $trame . CastProtocol::frame('s', 'd', 'ns', '{"b":2}');
        $premier = CastProtocol::shift($tampon);
        $second  = CastProtocol::shift($tampon);
        $this->assertSame('{"a":1}', $premier['payload'] ?? null);
        $this->assertSame('{"b":2}', $second['payload'] ?? null);
        $this->assertSame('', $tampon);
    }

    /** Une longueur aberrante ne doit pas faire allouer des mégaoctets. */
    public function testTrameAberranteEstJetee(): void
    {
        $tampon = pack('N', 99999999) . 'xxxx';
        $this->assertNull(CastProtocol::shift($tampon));
        $this->assertSame('', $tampon, 'le flux désynchronisé doit être vidé');

        $tampon = pack('N', 0) . 'xxxx';
        $this->assertNull(CastProtocol::shift($tampon));
    }

    public function testDecodageDUnMessageMalforme(): void
    {
        // Type de fil inexistant dans CastMessage (3 = groupe déprécié).
        $this->assertNull(CastProtocol::decode(chr((2 << 3) | 3) . 'x'));
        // Longueur de chaîne dépassant le message.
        $this->assertNull(CastProtocol::decode(chr((2 << 3) | 2) . CastProtocol::varint(50) . 'court'));
    }

    /* ---------- découverte mDNS ---------- */

    /** Encode un nom DNS en labels. */
    private static function dnsName(string $n): string
    {
        $out = '';
        foreach (explode('.', $n) as $l) {
            $out .= chr(strlen($l)) . $l;
        }
        return $out . "\0";
    }

    /** @param array<int,string> $paires */
    private static function txtRdata(array $paires): string
    {
        $out = '';
        foreach ($paires as $p) {
            $out .= chr(strlen($p)) . $p;
        }
        return $out;
    }

    private static function rr(string $nom, int $type, string $rdata): string
    {
        return self::dnsName($nom) . pack('nnNn', $type, 1, 120, strlen($rdata)) . $rdata;
    }

    /**
     * Une annonce Cast complète, telle qu'un appareil l'émet : SRV pour l'hôte
     * et le port, TXT pour le nom lisible et le modèle, A pour l'adresse.
     */
    public function testDecouverteAssembleUneAnnonceComplete(): void
    {
        $instance = 'Salon-1234._googlecast._tcp.local';
        $paquet = pack('nnnnnn', 0, 0x8400, 0, 3, 0, 0)
            . self::rr($instance, 33, pack('nnn', 0, 0, 8009) . self::dnsName('mibox.local'))
            . self::rr($instance, 16, self::txtRdata(['id=abc123', 'fn=MiBox du salon', 'md=Mi Box S']))
            . self::rr('mibox.local', 1, inet_pton('192.168.1.42'));

        $appareils = CastDiscovery::devicesFrom([$paquet]);

        $this->assertCount(1, $appareils);
        $this->assertSame('MiBox du salon', $appareils[0]['name']);
        $this->assertSame('192.168.1.42', $appareils[0]['host']);
        $this->assertSame(8009, $appareils[0]['port']);
        $this->assertSame('Mi Box S', $appareils[0]['model']);
        $this->assertSame('abc123', $appareils[0]['id']);
    }

    /**
     * Les annonces arrivent souvent éclatées en plusieurs paquets — c'est le cas
     * normal, pas l'exception.
     */
    public function testDecouverteAssembleDesPaquetsSepares(): void
    {
        $instance = 'TV._googlecast._tcp.local';
        $p1 = pack('nnnnnn', 0, 0x8400, 0, 1, 0, 0)
            . self::rr($instance, 33, pack('nnn', 0, 0, 8009) . self::dnsName('tv.local'));
        $p2 = pack('nnnnnn', 0, 0x8400, 0, 1, 0, 0)
            . self::rr($instance, 16, self::txtRdata(['fn=Télé chambre']));
        $p3 = pack('nnnnnn', 0, 0x8400, 0, 1, 0, 0)
            . self::rr('tv.local', 1, inet_pton('10.0.0.7'));

        $appareils = CastDiscovery::devicesFrom([$p1, $p2, $p3]);
        $this->assertCount(1, $appareils);
        $this->assertSame('Télé chambre', $appareils[0]['name']);
        $this->assertSame('10.0.0.7', $appareils[0]['host']);
    }

    /**
     * Une réponse mDNS embarque souvent d'autres annonces du réseau. Sans
     * filtrage sur le service, la box Internet se retrouvait listée comme
     * téléviseur — et l'envoi partait dessus.
     */
    public function testSeulLeServiceCastEstRetenu(): void
    {
        $paquet = pack('nnnnnn', 0, 0x8400, 0, 4, 0, 0)
            // Annonce parasite : un service HTTP quelconque sur la box.
            . self::rr('LIVEBOX._http._tcp.local', 33, pack('nnn', 0, 0, 80) . self::dnsName('livebox.local'))
            . self::rr('livebox.local', 1, inet_pton('192.168.1.1'))
            // La vraie annonce Cast.
            . self::rr('MiBox._googlecast._tcp.local', 33,
                pack('nnn', 0, 0, 8009) . self::dnsName('mibox.local'))
            . self::rr('mibox.local', 1, inet_pton('192.168.1.3'));

        $appareils = CastDiscovery::devicesFrom([$paquet]);
        $this->assertCount(1, $appareils, 'seul le service Cast doit ressortir');
        $this->assertSame('192.168.1.3', $appareils[0]['host']);
        $this->assertSame(8009, $appareils[0]['port']);
    }

    /** Sans adresse, il n'y a rien à joindre : l'annonce est écartée. */
    public function testAnnonceSansAdresseEstEcartee(): void
    {
        $instance = 'Orphelin._googlecast._tcp.local';
        $paquet = pack('nnnnnn', 0, 0x8400, 0, 1, 0, 0)
            . self::rr($instance, 33, pack('nnn', 0, 0, 8009) . self::dnsName('absent.local'));
        $this->assertSame([], CastDiscovery::devicesFrom([$paquet]));
    }

    /** @return array<string,array{0:string}> */
    public static function paquetsInvalidesProvider(): array
    {
        return [
            'vide'            => [''],
            'trop court'      => ["\x00\x01"],
            'en-tête seul'    => [pack('nnnnnn', 0, 0x8400, 0, 1, 0, 0)],
            'rdlength menteur' => [
                pack('nnnnnn', 0, 0x8400, 0, 1, 0, 0)
                . self::dnsName('x._googlecast._tcp.local') . pack('nnNn', 1, 1, 120, 9999),
            ],
            'pointeur bouclé' => [pack('nnnnnn', 0, 0x8400, 0, 1, 0, 0) . "\xC0\x0C"],
        ];
    }

    /**
     * Ces paquets viennent du réseau : n'importe qui sur le LAN peut en émettre.
     * Aucun ne doit faire boucler ni planter le service de découverte.
     */
    #[DataProvider('paquetsInvalidesProvider')]
    public function testPaquetsInvalidesNeCassentRien(string $paquet): void
    {
        $this->assertSame([], CastDiscovery::devicesFrom([$paquet]));
    }
}
