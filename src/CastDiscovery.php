<?php

declare(strict_types=1);

require_once __DIR__ . '/functions.php'; // ip_is_lan()

/**
 * Découverte des récepteurs Cast sur le réseau local (mDNS / DNS-SD).
 *
 * Les appareils Cast s'annoncent sur `_googlecast._tcp.local`, par multicast
 * UDP sur 224.0.0.251:5353. C'est ce qui impose un service à part : le réseau
 * bridge de Docker ne transporte pas le multicast, et le conteneur PHP n'entend
 * donc rien. Le service de découverte tourne en `network_mode: host` et dépose
 * ce qu'il trouve dans un fichier que l'application lit — elle n'a pas besoin
 * d'être sur le réseau de la maison, seulement de savoir qui s'y trouve.
 *
 * On n'écrit ici qu'un client minimal : une question, l'écoute des réponses,
 * l'assemblage des enregistrements A / SRV / TXT. Assez pour lister des noms et
 * des adresses, pas un résolveur mDNS complet.
 */
final class CastDiscovery
{
    private const SERVICE = '_googlecast._tcp.local';
    private const GROUP   = '224.0.0.251';
    private const PORT    = 5353;

    /**
     * Interroge le réseau et renvoie les appareils vus.
     *
     * @return array<int,array{id:string,name:string,host:string,port:int,model:string}>
     */
    public function discover(int $secondes = 4): array
    {
        $socket = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if ($socket === false) {
            return [];
        }

        @socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);
        if (defined('SO_REUSEPORT')) {
            @socket_set_option($socket, SOL_SOCKET, SO_REUSEPORT, 1);
        }
        @socket_set_option($socket, IPPROTO_IP, IP_MULTICAST_TTL, 2);

        // Écouter sur 5353, PAS sur un port éphémère : un répondeur mDNS diffuse
        // ses réponses sur 224.0.0.251:5353, il ne les renvoie pas à l'expéditeur.
        // Sur un port éphémère, on n'entend jamais rien — le service trouvait
        // zéro appareil alors que les annonces passaient sous son nez.
        $ecoute = @socket_bind($socket, '0.0.0.0', self::PORT);
        if (!$ecoute) {
            // 5353 déjà pris (avahi) : repli sur un port éphémère, en demandant
            // cette fois une réponse unicast. Moins fiable, mieux que rien.
            if (!@socket_bind($socket, '0.0.0.0', 0)) {
                socket_close($socket);
                return [];
            }
        }

        $question = $this->query(self::SERVICE, 12, !$ecoute);

        // Une requête PAR interface locale. La route multicast par défaut peut
        // partir ailleurs que sur le réseau de la maison — un tunnel VPN, par
        // exemple —, et la question n'atteint alors personne. On ne devine pas :
        // on demande sur chacune, les interfaces sans appareil restent muettes.
        $envoyee = false;
        foreach ($this->localInterfaces() as $index) {
            // L'extension sockets de PHP attend un INDEX d'interface, pas une
            // adresse : passer « 192.168.1.50 » échoue en silence, et la question
            // repart alors par la route par défaut — celle du VPN, le cas échéant.
            @socket_set_option($socket, IPPROTO_IP, MCAST_JOIN_GROUP, [
                'group' => self::GROUP, 'interface' => $index,
            ]);
            if (!@socket_set_option($socket, IPPROTO_IP, IP_MULTICAST_IF, $index)) {
                continue;
            }
            if (@socket_sendto($socket, $question, strlen($question), 0, self::GROUP, self::PORT) !== false) {
                $envoyee = true;
            }
        }
        if (!$envoyee) {
            socket_close($socket);
            return [];
        }

        $paquets = [];
        $fin = microtime(true) + max(1, $secondes);
        while (microtime(true) < $fin) {
            $lecture = [$socket];
            $ecriture = null;
            $exception = null;
            $reste = max(0, (int) ceil($fin - microtime(true)));
            if (@socket_select($lecture, $ecriture, $exception, $reste, 200000) < 1) {
                continue;
            }
            $data = '';
            $from = '';
            $port = 0;
            if (@socket_recvfrom($socket, $data, 9000, 0, $from, $port) === false) {
                continue;
            }
            $paquets[] = (string) $data;
        }
        socket_close($socket);

        return self::devicesFrom($paquets);
    }

    /**
     * Index des interfaces portant une adresse de réseau domestique.
     *
     * `CAST_INTERFACE` désigne l'interface à utiliser (son nom, « enp8s0 »)
     * quand la détection ne convient pas — plusieurs réseaux, VLAN.
     *
     * @return array<int,int>
     */
    private function localInterfaces(): array
    {
        $force = trim((string) (getenv('CAST_INTERFACE') ?: ''));
        if ($force !== '') {
            $index = self::interfaceIndex($force);
            return $index > 0 ? [$index] : [];
        }

        $index = [];
        $interfaces = function_exists('net_get_interfaces') ? net_get_interfaces() : false;
        foreach (is_array($interfaces) ? $interfaces : [] as $nom => $infos) {
            foreach ((array) ($infos['unicast'] ?? []) as $unicast) {
                $ip = (string) ($unicast['address'] ?? '');
                if ($ip === '' || !ip_is_lan($ip)) {
                    continue;
                }
                $i = self::interfaceIndex((string) $nom);
                if ($i > 0) {
                    $index[$i] = true;
                }
                break;
            }
        }
        return array_keys($index);
    }

    /**
     * Index noyau d'une interface.
     *
     * PHP n'expose pas `if_nametoindex()` ; le noyau le publie dans /sys, et
     * c'est la seule source disponible sans extension supplémentaire.
     */
    private static function interfaceIndex(string $nom): int
    {
        if (preg_match('/^[A-Za-z0-9_.@-]{1,32}$/', $nom) !== 1) {
            return 0;
        }
        $lu = @file_get_contents('/sys/class/net/' . $nom . '/ifindex');
        return $lu === false ? 0 : (int) trim($lu);
    }

    /**
     * Assemble une liste d'appareils à partir de réponses mDNS brutes.
     *
     * Séparé de la socket exprès : c'est la partie qui peut se tromper, et la
     * seule qu'on puisse éprouver sans réseau ni téléviseur. Une annonce arrive
     * souvent éclatée en plusieurs paquets — SRV ici, TXT là, adresse ailleurs —
     * d'où l'assemblage par instance plutôt que paquet par paquet.
     *
     * @param array<int,string> $paquets
     * @return array<int,array{id:string,name:string,host:string,port:int,model:string}>
     */
    public static function devicesFrom(array $paquets): array
    {
        /** @var array<string,array<string,mixed>> $srv */
        $srv = [];      // instance => {target, port}
        /** @var array<string,array<string,string>> $txt */
        $txt = [];      // instance => clés TXT
        /** @var array<string,string> $adresses */
        $adresses = []; // hôte => IP

        $lecteur = new self();
        foreach ($paquets as $paquet) {
            $lecteur->parse($paquet, $srv, $txt, $adresses);
        }

        $appareils = [];
        foreach ($srv as $instance => $info) {
            $cible = (string) ($info['target'] ?? '');
            $ip = $adresses[$cible] ?? '';
            if ($ip === '') {
                continue; // annonce incomplète : sans adresse, rien à joindre
            }
            $t = $txt[$instance] ?? [];
            // `fn` = friendly name, le nom que l'utilisateur a donné à l'appareil.
            $nom = $t['fn'] ?? explode('.', $instance)[0];
            $appareils[] = [
                'id'    => $t['id'] ?? $cible,
                'name'  => $nom !== '' ? $nom : $ip,
                'host'  => $ip,
                'port'  => (int) ($info['port'] ?? 8009),
                'model' => $t['md'] ?? '',
            ];
        }

        usort($appareils, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));
        return $appareils;
    }

    /**
     * Question DNS : en-tête + QNAME encodé en labels.
     *
     * `$unicast` positionne le bit QU, qui demande une réponse en direct plutôt
     * qu'en diffusion — le seul moyen d'entendre quoi que ce soit depuis un port
     * éphémère.
     */
    private function query(string $name, int $type, bool $unicast = false): string
    {
        $qname = '';
        foreach (explode('.', $name) as $label) {
            if ($label !== '') {
                $qname .= chr(strlen($label)) . $label;
            }
        }
        $qname .= "\0";
        return pack('nnnnnn', 0, 0, 1, 0, 0, 0) . $qname . pack('nn', $type, $unicast ? 0x8001 : 1);
    }

    /**
     * Extrait les enregistrements SRV, TXT et A d'une réponse.
     *
     * @param array<string,array<string,mixed>> $srv
     * @param array<string,array<string,string>> $txt
     * @param array<string,string> $adresses
     */
    private function parse(string $msg, array &$srv, array &$txt, array &$adresses): void
    {
        if (strlen($msg) < 12) {
            return;
        }
        /** @var array{qd:int,an:int,ns:int,ar:int} $entete */
        $entete = unpack('nid/nflags/nqd/nan/nns/nar', $msg);
        $pos = 12;

        // Les questions se relisent pour être sautées, pas pour être gardées.
        for ($i = 0; $i < $entete['qd']; $i++) {
            if ($this->name($msg, $pos) === null) {
                return;
            }
            $pos += 4;
        }

        $total = $entete['an'] + $entete['ns'] + $entete['ar'];
        for ($i = 0; $i < $total; $i++) {
            $nom = $this->name($msg, $pos);
            if ($nom === null || $pos + 10 > strlen($msg)) {
                return;
            }
            /** @var array{type:int,rdlength:int} $rr */
            $rr = unpack('ntype/nclass/Nttl/nrdlength', substr($msg, $pos, 10));
            $pos += 10;
            $fin = $pos + $rr['rdlength'];
            if ($fin > strlen($msg)) {
                return;
            }

            switch ($rr['type']) {
                case 1: // A
                    if ($rr['rdlength'] === 4) {
                        $adresses[$nom] = inet_ntop(substr($msg, $pos, 4)) ?: '';
                    }
                    break;

                case 33: // SRV
                    // Uniquement le service Cast : une réponse mDNS embarque
                    // souvent d'autres annonces du réseau, et sans ce filtre la
                    // box Internet se retrouvait listée comme téléviseur.
                    if ($rr['rdlength'] >= 7 && str_ends_with(strtolower($nom), '.' . self::SERVICE)) {
                        /** @var array{port:int} $s */
                        $s = unpack('npriority/nweight/nport', substr($msg, $pos, 6));
                        $p = $pos + 6;
                        $cible = $this->name($msg, $p);
                        if ($cible !== null) {
                            $srv[$nom] = ['target' => $cible, 'port' => $s['port']];
                        }
                    }
                    break;

                case 16: // TXT : suite de chaînes « clé=valeur », longueur préfixée
                    if (!str_ends_with(strtolower($nom), '.' . self::SERVICE)) {
                        break;
                    }
                    $p = $pos;
                    $paires = [];
                    while ($p < $fin) {
                        $n = ord($msg[$p]);
                        $p++;
                        if ($n === 0 || $p + $n > $fin) {
                            break;
                        }
                        $entree = substr($msg, $p, $n);
                        $p += $n;
                        $eq = strpos($entree, '=');
                        if ($eq !== false) {
                            $paires[substr($entree, 0, $eq)] = substr($entree, $eq + 1);
                        }
                    }
                    if ($paires !== []) {
                        $txt[$nom] = $paires + ($txt[$nom] ?? []);
                    }
                    break;
            }
            $pos = $fin;
        }
    }

    /**
     * Lit un nom DNS, en suivant la compression par pointeur (0xC0).
     *
     * `$pos` avance jusqu'après le nom. Renvoie null sur un message malformé —
     * il vient du réseau, n'importe qui peut en émettre.
     */
    private function name(string $msg, int &$pos): ?string
    {
        $labels = [];
        $sauts = 0;
        $curseur = $pos;
        $suivi = false;

        while (true) {
            if ($curseur >= strlen($msg)) {
                return null;
            }
            $n = ord($msg[$curseur]);

            if ($n === 0) {
                $curseur++;
                break;
            }
            if (($n & 0xC0) === 0xC0) { // pointeur de compression
                if ($curseur + 1 >= strlen($msg) || ++$sauts > 16) {
                    return null; // boucle de pointeurs
                }
                $cible = (($n & 0x3F) << 8) | ord($msg[$curseur + 1]);
                if (!$suivi) {
                    $pos = $curseur + 2;
                    $suivi = true;
                }
                $curseur = $cible;
                continue;
            }
            $curseur++;
            if ($curseur + $n > strlen($msg)) {
                return null;
            }
            $labels[] = substr($msg, $curseur, $n);
            $curseur += $n;
        }

        if (!$suivi) {
            $pos = $curseur;
        }
        return implode('.', $labels);
    }
}
