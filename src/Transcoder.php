<?php

declare(strict_types=1);

/**
 * Lecture adaptative : direct si l'appareil sait lire, conversion sinon.
 *
 * Le principe, et il compte : **on ne transcode que ce qu'il faut**. Un fichier
 * s'analyse en trois questions, et la réponse détermine le coût.
 *
 *  - `direct`    — conteneur et codecs déjà compatibles. Aucun processus, nginx
 *                  sert le fichier, le déplacement dans la vidéo fonctionne.
 *  - `remux`     — les codecs conviennent, c'est le conteneur qui ne va pas
 *                  (un MKV en H.264/AAC). On recopie les flux dans du MP4 sans
 *                  les toucher : quasiment gratuit en processeur.
 *  - `audio`     — l'image convient, le son non (AC3, DTS, TrueHD — le cas le
 *                  plus fréquent sur les trackers). On ne réencode que l'audio,
 *                  l'image est recopiée telle quelle. Peu coûteux.
 *  - `full`      — l'image aussi doit être réencodée (HEVC, VC-1, AV1 sur un
 *                  vieux récepteur). C'est le seul cas réellement lourd.
 *
 * Sur une bibliothèque typique, la majorité des MKV tombe en `remux` ou `audio`.
 * Le transcodage complet reste l'exception, pas la règle.
 *
 * Ce que ça ne fait pas : pas de segmentation HLS, donc **pas de déplacement
 * dans une vidéo convertie** (le flux est produit au fil de l'eau, sa taille
 * n'est pas connue d'avance). La lecture directe, elle, reste navigable.
 */
final class Transcoder
{
    /**
     * Ce qu'un navigateur sait décoder nativement, et ce qu'un récepteur Cast
     * sait décoder. Le récepteur Cast par défaut est le plus limité des deux :
     * H.264 et AAC, rien d'autre côté son (ni AC3, ni DTS).
     *
     * @var array<string,array{video:array<int,string>,audio:array<int,string>,containers:array<int,string>}>
     */
    private const PROFILES = [
        'browser' => [
            'video'      => ['h264', 'vp8', 'vp9', 'av1'],
            'audio'      => ['aac', 'mp3', 'opus', 'vorbis', 'flac'],
            'containers' => ['mp4', 'm4v', 'webm', 'ogv'],
        ],
        'cast' => [
            'video'      => ['h264', 'vp8'],
            'audio'      => ['aac', 'mp3', 'vorbis', 'opus'],
            'containers' => ['mp4', 'm4v'],
        ],
    ];

    public function __construct(
        private readonly string $ffmpeg = 'ffmpeg',
        private readonly string $ffprobe = 'ffprobe',
        private readonly int $probeTimeout = 15,
    ) {
    }

    public static function isProfile(string $p): bool
    {
        return isset(self::PROFILES[$p]);
    }

    public function available(): bool
    {
        return $this->which($this->ffmpeg) && $this->which($this->ffprobe);
    }

    private function which(string $bin): bool
    {
        if (str_contains($bin, '/')) {
            return is_executable($bin);
        }
        foreach (explode(':', (string) (getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin')) as $dir) {
            if ($dir !== '' && is_executable($dir . '/' . $bin)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Analyse les flux d'un fichier.
     *
     * On lit les codecs réels, jamais l'extension : un « .mkv » peut contenir du
     * H.264/AAC parfaitement lisible, et un « .mp4 » du HEVC que rien ne lit.
     *
     * @return array{video:string,audio:string,pix:string,duration:float,width:int,height:int}|null
     */
    public function probe(string $path): ?array
    {
        /** @var list<string> $cmd */
        $cmd = [
            $this->ffprobe, '-v', 'error',
            '-print_format', 'json',
            '-show_streams', '-show_format',
            '-i', $path,
        ];

        $sortie = $this->run($cmd, $this->probeTimeout);
        if ($sortie === null) {
            return null;
        }
        $data = json_decode($sortie, true);
        if (!is_array($data)) {
            return null;
        }

        $video = '';
        $audio = '';
        $pix = '';
        $largeur = 0;
        $hauteur = 0;
        foreach ((array) ($data['streams'] ?? []) as $flux) {
            if (!is_array($flux)) {
                continue;
            }
            $type = (string) ($flux['codec_type'] ?? '');
            if ($type === 'video' && $video === '') {
                // Les pochettes intégrées sont déclarées comme des flux vidéo :
                // les prendre pour l'image du film ferait transcoder une image fixe.
                if ((int) ($flux['disposition']['attached_pic'] ?? 0) === 1) {
                    continue;
                }
                $video = strtolower((string) ($flux['codec_name'] ?? ''));
                $pix = strtolower((string) ($flux['pix_fmt'] ?? ''));
                $largeur = (int) ($flux['width'] ?? 0);
                $hauteur = (int) ($flux['height'] ?? 0);
            } elseif ($type === 'audio' && $audio === '') {
                $audio = strtolower((string) ($flux['codec_name'] ?? ''));
            }
        }

        return [
            'video'    => $video,
            'audio'    => $audio,
            'pix'      => $pix,
            'duration' => (float) ($data['format']['duration'] ?? 0),
            'width'    => $largeur,
            'height'   => $hauteur,
        ];
    }

    /**
     * Décide du mode de lecture.
     *
     * @param array{video:string,audio:string,pix?:string,duration:float,width:int,height:int}|null $probe
     * @return 'direct'|'remux'|'audio'|'full'
     */
    public function decide(?array $probe, string $ext, string $profile): string
    {
        $p = self::PROFILES[$profile] ?? self::PROFILES['browser'];
        $conteneurOk = in_array(strtolower($ext), $p['containers'], true);

        // Sans analyse (ffprobe absent ou fichier illisible), on se rabat sur le
        // conteneur : c'est le seul indice disponible, et il vaut mieux tenter
        // une conversion inutile qu'afficher une vidéo noire.
        if ($probe === null) {
            return $conteneurOk ? 'direct' : 'full';
        }

        $videoOk = $probe['video'] !== '' && in_array($probe['video'], $p['video'], true)
            && self::pixelFormatOk((string) ($probe['pix'] ?? ''));
        $audioOk = $probe['audio'] === '' || in_array($probe['audio'], $p['audio'], true);

        if ($videoOk && $audioOk) {
            return $conteneurOk ? 'direct' : 'remux';
        }
        if ($videoOk) {
            return 'audio';
        }
        return 'full';
    }

    /**
     * Le format de pixels est-il décodable par du matériel grand public ?
     *
     * Le nom du codec ne suffit pas : un récepteur Cast — et un navigateur —
     * refusent le H.264 en 4:2:2, 4:4:4 ou 10 bits (le « Hi10P » des fansubs),
     * alors que ffprobe les annonce tous comme « h264 ». Sans cette vérification,
     * ces fichiers étaient classés en simple changement de conteneur, puis
     * rejetés par le téléviseur.
     *
     * Un format inconnu passe : mieux vaut tenter la lecture directe que
     * réencoder inutilement sur une hypothèse.
     */
    private static function pixelFormatOk(string $pix): bool
    {
        if ($pix === '') {
            return true;
        }
        return in_array($pix, ['yuv420p', 'yuvj420p', 'nv12', 'nv21'], true);
    }

    /**
     * Construit la commande de conversion, sortie sur la sortie standard.
     *
     * `$mode` vaut « remux », « audio » ou « full ». Tout autre valeur est
     * traitée comme « full » : mieux vaut une conversion inutile qu'une
     * commande construite sur une hypothèse fausse.
     *
     * @return list<string>
     */
    public function command(string $path, string $mode, string $profile, int $start = 0): array
    {
        if (!in_array($mode, ['remux', 'audio', 'full'], true)) {
            $mode = 'full';
        }
        $cmd = [$this->ffmpeg, '-nostdin', '-hide_banner', '-loglevel', 'error'];

        // Recherche rapide AVANT l'entrée : ffmpeg saute directement au bon
        // endroit du fichier au lieu de tout décoder depuis le début.
        if ($start > 0) {
            $cmd[] = '-ss';
            $cmd[] = (string) $start;
        }
        array_push($cmd, '-i', $path);

        // Première piste vidéo et première piste audio ; le « ? » évite l'échec
        // sur un fichier sans son. Les sous-titres image (PGS, VOBSUB) n'ont pas
        // leur place dans un MP4 : les inclure fait échouer la conversion.
        array_push($cmd, '-map', '0:v:0', '-map', '0:a:0?', '-sn', '-dn');

        if ($mode === 'full') {
            array_push(
                $cmd,
                '-c:v', 'libx264',
                // veryfast : sur un NAS, la vitesse prime sur la taille — le flux
                // doit sortir plus vite qu'il n'est consommé, sinon ça saccade.
                '-preset', 'veryfast',
                '-crf', '21',
                // Profil et format de pixels imposés : un récepteur Cast refuse
                // du 4:2:2 ou du 10 bits, même en H.264.
                '-profile:v', 'high',
                '-level', '4.1',
                '-pix_fmt', 'yuv420p',
            );
        } else {
            array_push($cmd, '-c:v', 'copy');
        }

        if ($mode === 'remux') {
            array_push($cmd, '-c:a', 'copy');
        } else {
            // Stéréo : le récepteur Cast n'a pas de sortie multicanal, et un
            // 5.1 mal replié donne des dialogues inaudibles.
            array_push($cmd, '-c:a', 'aac', '-b:a', '192k', '-ac', '2');
        }

        // MP4 fragmenté : lisible au fil de l'eau, sans avoir à écrire l'index
        // à la fin du fichier — impossible sur un flux.
        //
        // Le découpage se fait à la DURÉE, pas aux images-clés. Un encodage
        // BluRay place les siennes toutes les dix secondes : avec
        // `frag_keyframe`, le flux sortait en fragments de 10 s, et le récepteur
        // Cast lisait le premier puis restait bloqué en mémoire tampon. Une
        // seconde par fragment, et la lecture s'enchaîne.
        array_push(
            $cmd,
            '-movflags', '+empty_moov+default_base_moof',
            '-frag_duration', '1000000',
            '-f', 'mp4',
            'pipe:1'
        );
        return $cmd;
    }

    /**
     * Commande de conversion en HLS : une liste de lecture et des segments.
     *
     * C'est le seul format qu'un récepteur Cast sait réellement enchaîner. Un
     * MP4 fragmenté servi au fil de l'eau n'a ni taille annoncée ni requêtes
     * Range : la Mi Box en lit une quinzaine de secondes, referme la connexion,
     * et reste bloquée. Mesuré, journal nginx à l'appui — 18 Mo puis plus rien.
     *
     * `event` plutôt que `vod` : la liste grandit au fur et à mesure, la lecture
     * démarre sans attendre la fin de la conversion, et le déplacement reste
     * possible dans ce qui est déjà produit.
     *
     * @return list<string>
     */
    public function hlsCommand(string $path, string $mode, string $profile, string $dir): array
    {
        if (!in_array($mode, ['remux', 'audio', 'full'], true)) {
            $mode = 'full';
        }
        $cmd = [$this->ffmpeg, '-nostdin', '-hide_banner', '-loglevel', 'error', '-i', $path];
        array_push($cmd, '-map', '0:v:0', '-map', '0:a:0?', '-sn', '-dn');

        if ($mode === 'full') {
            array_push(
                $cmd,
                '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '21',
                '-profile:v', 'high', '-level', '4.1', '-pix_fmt', 'yuv420p',
                // Une image-clé toutes les deux secondes : les segments se
                // découpent dessus, et des segments courts démarrent plus vite.
                '-g', '48', '-keyint_min', '48', '-sc_threshold', '0',
            );
        } else {
            array_push($cmd, '-c:v', 'copy');
        }
        array_push($cmd, '-c:a', 'aac', '-b:a', '192k', '-ac', '2');

        array_push(
            $cmd,
            '-f', 'hls',
            '-hls_time', '4',
            '-hls_playlist_type', 'event',
            // 0 = on garde toute la liste : sans ça, le début disparaît et le
            // lecteur ne peut plus revenir en arrière.
            '-hls_list_size', '0',
            '-hls_segment_filename', $dir . '/seg%05d.ts',
            $dir . '/index.m3u8'
        );
        return $cmd;
    }

    /**
     * Exécute une commande courte et renvoie sa sortie standard.
     *
     * @param list<string> $cmd
     */
    private function run(array $cmd, int $timeout): ?string
    {
        $descripteurs = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $pipes = [];
        $process = @proc_open($cmd, $descripteurs, $pipes);
        if (!is_resource($process)) {
            return null;
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $sortie = '';
        $limite = microtime(true) + $timeout;
        while (microtime(true) < $limite) {
            $sortie .= (string) stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]); // vidé pour ne pas bloquer ffprobe
            $statut = proc_get_status($process);
            if (!$statut['running']) {
                $sortie .= (string) stream_get_contents($pipes[1]);
                break;
            }
            usleep(20_000);
        }

        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        $code = proc_close($process);

        return $code === 0 ? $sortie : null;
    }
}
