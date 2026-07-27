<?php

declare(strict_types=1);

/**
 * Lecture du format bencode, juste ce qu'il faut pour inspecter un .torrent.
 *
 * Sert à répondre à une question qu'on se pose avant de télécharger : est-ce un
 * fichier unique ou un pack de quarante épisodes ? Aujourd'hui il faut lancer le
 * téléchargement pour le savoir.
 *
 * L'entrée vient d'un tracker : elle est hostile par défaut. L'analyseur borne
 * donc la profondeur, le nombre d'éléments et la taille des chaînes, et refuse
 * plutôt que de deviner.
 */
final class Bencode
{
    private const MAX_DEPTH = 16;
    private const MAX_ITEMS = 20000;

    private int $pos = 0;
    private int $items = 0;

    private function __construct(private readonly string $data)
    {
    }

    /**
     * Décode une chaîne bencode. Renvoie null si elle est invalide ou trop
     * complexe — jamais d'exception à gérer chez l'appelant.
     *
     * @return array<string,mixed>|null
     */
    public static function decodeDict(string $data): ?array
    {
        $parser = new self($data);
        try {
            $value = $parser->parse(0);
        } catch (Throwable $e) {
            return null;
        }
        return is_array($value) ? $value : null;
    }

    /**
     * Résumé lisible d'un .torrent : nom, liste des fichiers, taille totale.
     *
     * @return array{name:string,files:array<int,array{path:string,size:int}>,size:int}|null
     */
    public static function summarize(string $torrent, int $maxFiles = 100): ?array
    {
        $dict = self::decodeDict($torrent);
        $info = $dict['info'] ?? null;
        if (!is_array($info)) {
            return null;
        }

        $name  = (string) ($info['name'] ?? '');
        $files = [];
        $total = 0;

        if (isset($info['files']) && is_array($info['files'])) {
            // Torrent multi-fichiers : chaque entrée porte son chemin découpé.
            foreach ($info['files'] as $f) {
                if (!is_array($f)) {
                    continue;
                }
                $size = (int) ($f['length'] ?? 0);
                $total += $size;
                if (count($files) >= $maxFiles) {
                    continue;
                }
                $parts = [];
                foreach ((array) ($f['path'] ?? []) as $part) {
                    $parts[] = (string) $part;
                }
                $files[] = ['path' => implode('/', $parts), 'size' => $size];
            }
        } else {
            $total = (int) ($info['length'] ?? 0);
            if ($name !== '') {
                $files[] = ['path' => $name, 'size' => $total];
            }
        }

        return ['name' => $name, 'files' => $files, 'size' => $total];
    }

    /** @return mixed */
    private function parse(int $depth)
    {
        if ($depth > self::MAX_DEPTH || ++$this->items > self::MAX_ITEMS) {
            throw new RuntimeException('structure trop profonde');
        }
        $c = $this->data[$this->pos] ?? '';

        if ($c === 'i') {
            return $this->parseInt();
        }
        if ($c === 'l') {
            return $this->parseList($depth);
        }
        if ($c === 'd') {
            return $this->parseDict($depth);
        }
        if ($c >= '0' && $c <= '9') {
            return $this->parseString();
        }
        throw new RuntimeException('octet inattendu');
    }

    private function parseInt(): int
    {
        $end = strpos($this->data, 'e', $this->pos);
        if ($end === false) {
            throw new RuntimeException('entier non terminé');
        }
        $raw = substr($this->data, $this->pos + 1, $end - $this->pos - 1);
        if (preg_match('/^-?\d+$/', $raw) !== 1) {
            throw new RuntimeException('entier invalide');
        }
        $this->pos = $end + 1;
        return (int) $raw;
    }

    private function parseString(): string
    {
        $colon = strpos($this->data, ':', $this->pos);
        if ($colon === false) {
            throw new RuntimeException('chaîne non terminée');
        }
        $lenRaw = substr($this->data, $this->pos, $colon - $this->pos);
        if (preg_match('/^\d{1,10}$/', $lenRaw) !== 1) {
            throw new RuntimeException('longueur invalide');
        }
        $len = (int) $lenRaw;
        if ($colon + 1 + $len > strlen($this->data)) {
            throw new RuntimeException('chaîne tronquée');
        }
        $this->pos = $colon + 1 + $len;
        return substr($this->data, $colon + 1, $len);
    }

    /** @return array<int,mixed> */
    private function parseList(int $depth): array
    {
        $this->pos++;
        $out = [];
        while (($this->data[$this->pos] ?? '') !== 'e') {
            if ($this->pos >= strlen($this->data)) {
                throw new RuntimeException('liste non terminée');
            }
            $out[] = $this->parse($depth + 1);
        }
        $this->pos++;
        return $out;
    }

    /** @return array<string,mixed> */
    private function parseDict(int $depth): array
    {
        $this->pos++;
        $out = [];
        while (($this->data[$this->pos] ?? '') !== 'e') {
            if ($this->pos >= strlen($this->data)) {
                throw new RuntimeException('dictionnaire non terminé');
            }
            $key = $this->parseString();
            $out[$key] = $this->parse($depth + 1);
        }
        $this->pos++;
        return $out;
    }
}
