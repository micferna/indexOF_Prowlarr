<?php

declare(strict_types=1);

/**
 * Codage des messages du protocole Google Cast (CASTV2).
 *
 * Un récepteur Cast — Chromecast, Android TV, MiBox — parle un protocole binaire
 * sur TLS : une trame = 4 octets de longueur (gros-boutiste) suivis d'un message
 * protobuf `CastMessage` :
 *
 *   1 protocol_version  varint   (0 = CASTV2_1_0)
 *   2 source_id         string
 *   3 destination_id    string
 *   4 namespace         string
 *   5 payload_type      varint   (0 = STRING, 1 = BINARY)
 *   6 payload_utf8      string
 *
 * Six champs, dont deux entiers toujours nuls : embarquer une bibliothèque
 * protobuf entière pour ça coûterait plus cher que de les écrire à la main. Et
 * les écrire à la main les rend testables sans récepteur en face — ce qui compte,
 * puisqu'on ne peut pas brancher une télévision dans une suite de tests.
 */
final class CastProtocol
{
    /** Espaces de noms du protocole. Chacun a son vocabulaire. */
    public const NS_CONNECTION = 'urn:x-cast:com.google.cast.tp.connection';
    public const NS_HEARTBEAT  = 'urn:x-cast:com.google.cast.tp.heartbeat';
    public const NS_RECEIVER   = 'urn:x-cast:com.google.cast.receiver';
    public const NS_MEDIA      = 'urn:x-cast:com.google.cast.media';

    /** Application « Default Media Receiver » : lit une URL, sans rien installer. */
    public const APP_MEDIA = 'CC1AD845';

    /** Interlocuteur initial, avant qu'une application ne soit lancée. */
    public const RECEIVER_ID = 'receiver-0';

    /** Une trame plus grosse que ça n'est pas un message Cast. */
    private const MAX_FRAME = 512 * 1024;

    /** Entier en encodage varint (base 128, petit-boutiste, bit 7 = continuation). */
    public static function varint(int $n): string
    {
        $out = '';
        do {
            $octet = $n & 0x7F;
            $n >>= 7;
            $out .= chr($n > 0 ? ($octet | 0x80) : $octet);
        } while ($n > 0);
        return $out;
    }

    /**
     * Lit un varint. `$pos` avance jusqu'après l'entier lu.
     *
     * Renvoie null sur une donnée tronquée ou aberrante : la trame vient du
     * réseau, elle n'est pas de confiance.
     */
    public static function readVarint(string $buf, int &$pos): ?int
    {
        $val = 0;
        $shift = 0;
        $len = strlen($buf);
        while ($pos < $len) {
            $octet = ord($buf[$pos++]);
            $val |= ($octet & 0x7F) << $shift;
            if (($octet & 0x80) === 0) {
                return $val;
            }
            $shift += 7;
            if ($shift > 63) {
                return null; // varint aberrant
            }
        }
        return null; // tronqué
    }

    /** Champ protobuf de type chaîne (longueur préfixée). */
    private static function stringField(int $field, string $value): string
    {
        return chr(($field << 3) | 2) . self::varint(strlen($value)) . $value;
    }

    /** Champ protobuf de type entier. */
    private static function varintField(int $field, int $value): string
    {
        return chr(($field << 3) | 0) . self::varint($value);
    }

    /**
     * Construit une trame complète, prête à écrire sur la socket.
     */
    public static function frame(string $source, string $destination, string $namespace, string $payload): string
    {
        $msg = self::varintField(1, 0)                       // protocol_version
             . self::stringField(2, $source)
             . self::stringField(3, $destination)
             . self::stringField(4, $namespace)
             . self::varintField(5, 0)                       // payload_type = STRING
             . self::stringField(6, $payload);

        return pack('N', strlen($msg)) . $msg;
    }

    /**
     * Décode un message CastMessage (sans le préfixe de longueur).
     *
     * @return array{source:string,destination:string,namespace:string,payload:string}|null
     */
    public static function decode(string $msg): ?array
    {
        $out = ['source' => '', 'destination' => '', 'namespace' => '', 'payload' => ''];
        $pos = 0;
        $len = strlen($msg);

        while ($pos < $len) {
            $key = self::readVarint($msg, $pos);
            if ($key === null) {
                return null;
            }
            $field = $key >> 3;
            $type  = $key & 7;

            if ($type === 0) {
                if (self::readVarint($msg, $pos) === null) {
                    return null;
                }
                continue;
            }
            if ($type !== 2) {
                return null; // aucun autre type n'existe dans CastMessage
            }

            $taille = self::readVarint($msg, $pos);
            if ($taille === null || $taille < 0 || $pos + $taille > $len) {
                return null;
            }
            $valeur = substr($msg, $pos, $taille);
            $pos += $taille;

            match ($field) {
                2 => $out['source'] = $valeur,
                3 => $out['destination'] = $valeur,
                4 => $out['namespace'] = $valeur,
                6 => $out['payload'] = $valeur,
                default => null,
            };
        }
        return $out;
    }

    /**
     * Extrait la première trame complète de `$buffer` et la retire.
     *
     * Renvoie null tant que la trame n'est pas entièrement arrivée : TCP découpe
     * où il veut, un message peut arriver en trois morceaux.
     *
     * @return array{source:string,destination:string,namespace:string,payload:string}|null
     */
    public static function shift(string &$buffer): ?array
    {
        if (strlen($buffer) < 4) {
            return null;
        }
        /** @var array{1:int} $entete */
        $entete = unpack('N', substr($buffer, 0, 4));
        $taille = $entete[1];

        if ($taille <= 0 || $taille > self::MAX_FRAME) {
            // Flux désynchronisé ou hostile : on jette plutôt que d'allouer.
            $buffer = '';
            return null;
        }
        if (strlen($buffer) < 4 + $taille) {
            return null;
        }

        $msg = substr($buffer, 4, $taille);
        $buffer = substr($buffer, 4 + $taille);
        return self::decode($msg);
    }
}
