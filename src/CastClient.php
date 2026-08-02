<?php

declare(strict_types=1);

require_once __DIR__ . '/CastProtocol.php';

final class CastError extends RuntimeException
{
}

/**
 * Envoi d'une vidéo vers un récepteur Cast (MiBox, Chromecast, Android TV).
 *
 * La séquence, telle que l'appareil l'attend :
 *
 *   1. TLS sur le port 8009 (certificat auto-signé : on ne le vérifie pas, il
 *      n'y a pas d'autorité à qui le confronter sur un réseau domestique) ;
 *   2. CONNECT vers `receiver-0` — sans ça, tout le reste est ignoré ;
 *   3. LAUNCH de l'application « Default Media Receiver », qui sait lire une URL ;
 *   4. attente du RECEIVER_STATUS qui donne le `transportId` de la session ;
 *   5. CONNECT vers ce transportId — c'est une seconde connexion logique ;
 *   6. LOAD avec l'URL de la vidéo.
 *
 * Chaque étape peut échouer pour une raison que l'utilisateur peut corriger
 * (appareil éteint, URL injoignable depuis la télévision, format refusé) : les
 * messages le disent plutôt que de renvoyer « échec ».
 */
final class CastClient
{
    private mixed $socket = null;
    private string $buffer = '';
    private string $sourceId = '';
    private int $requestId = 1;

    public function __construct(
        private readonly string $host,
        private readonly int $port = 8009,
        private readonly int $timeout = 10,
    ) {
        // Identifiant d'expéditeur : l'appareil s'en sert pour router ses
        // réponses. Deux sessions simultanées ne doivent pas le partager.
        $this->sourceId = 'sender-' . bin2hex(random_bytes(4));
    }

    /**
     * Lance une vidéo. Renvoie le nom de l'application lancée sur le téléviseur.
     *
     * @throws CastError
     */
    public function play(string $url, string $mime, string $titre = '', string $poster = ''): string
    {
        $this->connect();
        try {
            $this->send(CastProtocol::RECEIVER_ID, CastProtocol::NS_CONNECTION, ['type' => 'CONNECT']);

            $statut = $this->launch();
            $transport = (string) ($statut['transportId'] ?? '');
            if ($transport === '') {
                throw new CastError("Le téléviseur n'a pas ouvert de session de lecture.");
            }

            // Seconde connexion logique, vers l'application cette fois.
            $this->send($transport, CastProtocol::NS_CONNECTION, ['type' => 'CONNECT']);
            $this->load($transport, $url, $mime, $titre, $poster);

            return (string) ($statut['displayName'] ?? 'Lecteur');
        } finally {
            $this->close();
        }
    }

    /**
     * Commande de transport sur une lecture déjà en cours.
     *
     * @param 'PAUSE'|'PLAY'|'STOP' $commande
     * @throws CastError
     */
    public function control(string $commande): void
    {
        $this->connect();
        try {
            $this->send(CastProtocol::RECEIVER_ID, CastProtocol::NS_CONNECTION, ['type' => 'CONNECT']);
            $this->send(CastProtocol::RECEIVER_ID, CastProtocol::NS_RECEIVER, [
                'type' => 'GET_STATUS', 'requestId' => $this->requestId++,
            ]);
            $statut = $this->awaitReceiverStatus();
            $transport = (string) ($statut['transportId'] ?? '');
            if ($transport === '') {
                throw new CastError('Rien ne joue actuellement sur cet appareil.');
            }

            $this->send($transport, CastProtocol::NS_CONNECTION, ['type' => 'CONNECT']);
            $this->send($transport, CastProtocol::NS_MEDIA, [
                'type' => 'GET_STATUS', 'requestId' => $this->requestId++,
            ]);
            $media = (array) $this->awaitMessage(CastProtocol::NS_MEDIA, ['MEDIA_STATUS']);
            $sessionId = $media['status'][0]['mediaSessionId'] ?? null;
            if ($sessionId === null) {
                throw new CastError('Rien ne joue actuellement sur cet appareil.');
            }

            $this->send($transport, CastProtocol::NS_MEDIA, [
                'type' => $commande,
                'requestId' => $this->requestId++,
                'mediaSessionId' => $sessionId,
            ]);
        } finally {
            $this->close();
        }
    }

    /** @throws CastError */
    private function connect(): void
    {
        // Le certificat d'un Chromecast est auto-signé et lié à un identifiant
        // d'appareil : aucune autorité ne peut le valider. La confidentialité du
        // canal reste acquise, l'authentification ne l'est pas — on ne transmet
        // ici qu'une URL de lecture, jamais un secret.
        $contexte = stream_context_create(['ssl' => [
            'verify_peer'       => false,
            'verify_peer_name'  => false,
            'allow_self_signed' => true,
            'SNI_enabled'       => false,
        ]]);

        $erreur = 0;
        $message = '';
        $socket = @stream_socket_client(
            'tls://' . $this->host . ':' . $this->port,
            $erreur,
            $message,
            $this->timeout,
            STREAM_CLIENT_CONNECT,
            $contexte
        );
        if ($socket === false) {
            throw new CastError(
                "Téléviseur injoignable ({$this->host}) : vérifiez qu'il est allumé "
                . 'et sur le même réseau.'
            );
        }
        // Une seconde, pas le délai global : la lecture doit rendre la main
        // assez souvent pour que les relances périodiques aient lieu. Le délai
        // d'ensemble est tenu par les boucles, pas par la socket.
        stream_set_timeout($socket, 1);
        $this->socket = $socket;
        $this->buffer = '';
    }

    private function close(): void
    {
        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }
        $this->socket = null;
    }

    /**
     * @param array<string,mixed> $payload
     * @throws CastError
     */
    private function send(string $destination, string $namespace, array $payload): void
    {
        if (!is_resource($this->socket)) {
            throw new CastError('Connexion perdue.');
        }
        $trame = CastProtocol::frame(
            $this->sourceId,
            $destination,
            $namespace,
            (string) json_encode($payload)
        );
        if (@fwrite($this->socket, $trame) !== strlen($trame)) {
            throw new CastError("Le téléviseur a coupé la connexion.");
        }
    }

    /**
     * Lit jusqu'au message attendu.
     *
     * Le récepteur intercale ses propres messages — PING, statuts non sollicités.
     * On y répond (un PING sans PONG fait fermer la connexion) et on continue.
     *
     * `$fenetre` borne l'attente à une durée plus courte que le délai global ;
     * l'appelant reprend alors la main pour relancer sa question au lieu de
     * rester suspendu jusqu'au bout.
     *
     * @param array<int,string> $types
     * @return array<string,mixed>|null null si la fenêtre expire (sans erreur)
     * @throws CastError
     */
    private function awaitMessage(string $namespace, array $types, ?float $fenetre = null): ?array
    {
        $limite = microtime(true) + ($fenetre ?? (float) $this->timeout);

        while (microtime(true) < $limite) {
            $msg = CastProtocol::shift($this->buffer);
            if ($msg === null) {
                if (!is_resource($this->socket) || feof($this->socket)) {
                    throw new CastError("Le téléviseur a fermé la connexion.");
                }
                // Silence d'une seconde : ce n'est pas une erreur, l'appareil
                // prend son temps. C'est l'échéance de la boucle qui tranche.
                $morceau = @fread($this->socket, 8192);
                if ($morceau === false || $morceau === '') {
                    usleep(50_000);
                    continue;
                }
                $this->buffer .= $morceau;
                continue;
            }

            $charge = json_decode($msg['payload'], true);
            $charge = is_array($charge) ? $charge : [];
            $type = (string) ($charge['type'] ?? '');

            // Un PING sans réponse et l'appareil raccroche au bout de quelques
            // secondes, au milieu de la séquence de lancement.
            if ($msg['namespace'] === CastProtocol::NS_HEARTBEAT && $type === 'PING') {
                $this->send($msg['source'], CastProtocol::NS_HEARTBEAT, ['type' => 'PONG']);
                continue;
            }
            // Refus explicite : le dire tel quel vaut mieux qu'un délai dépassé.
            if ($type === 'LAUNCH_ERROR' || $type === 'INVALID_REQUEST' || $type === 'LOAD_FAILED') {
                throw new CastError($this->explain($type, $charge));
            }
            if ($msg['namespace'] === $namespace && in_array($type, $types, true)) {
                return $charge;
            }
        }
        if ($fenetre !== null) {
            return null; // l'appelant décide quoi faire de ce silence
        }
        throw new CastError("Le téléviseur n'a pas répondu à temps.");
    }

    /**
     * Lance l'application de lecture et renvoie sa session.
     *
     * @return array<string,mixed>
     * @throws CastError
     */
    private function launch(): array
    {
        $this->send(CastProtocol::RECEIVER_ID, CastProtocol::NS_RECEIVER, [
            'type'      => 'LAUNCH',
            'requestId' => $this->requestId++,
            'appId'     => CastProtocol::APP_MEDIA,
        ]);
        return $this->awaitReceiverStatus();
    }

    /**
     * Attend un RECEIVER_STATUS décrivant l'application de lecture.
     *
     * L'appareil en émet plusieurs, dont un avant que la session ne soit prête :
     * on ne s'arrête que sur celui qui porte un `transportId`.
     *
     * @return array<string,mixed>
     * @throws CastError
     */
    private function awaitReceiverStatus(): array
    {
        $limite = microtime(true) + $this->timeout;
        $prochainSondage = 0.0;

        while (microtime(true) < $limite) {
            // Une application met deux à cinq secondes à démarrer sur le
            // téléviseur, et le premier RECEIVER_STATUS arrive AVANT qu'elle ne
            // soit prête. Le récepteur n'annonce pas toujours spontanément
            // qu'elle l'est devenue : il faut redemander, sinon on attend un
            // message qui ne viendra jamais.
            if (microtime(true) >= $prochainSondage) {
                $this->send(CastProtocol::RECEIVER_ID, CastProtocol::NS_RECEIVER, [
                    'type' => 'GET_STATUS', 'requestId' => $this->requestId++,
                ]);
                $prochainSondage = microtime(true) + 1.5;
            }

            $statut = $this->awaitMessage(CastProtocol::NS_RECEIVER, ['RECEIVER_STATUS'], 1.5);
            foreach ((array) ($statut['status']['applications'] ?? []) as $app) {
                if (!is_array($app) || ($app['transportId'] ?? '') === '') {
                    continue;
                }
                if (($app['appId'] ?? '') === CastProtocol::APP_MEDIA) {
                    return $app;
                }
            }
        }
        throw new CastError("Le téléviseur n'a pas ouvert de session de lecture.");
    }

    /** @throws CastError */
    private function load(string $transport, string $url, string $mime, string $titre, string $poster): void
    {
        $metadata = ['metadataType' => 0, 'title' => $titre !== '' ? $titre : 'indexOF'];
        if ($poster !== '') {
            $metadata['images'] = [['url' => $poster]];
        }

        $this->send($transport, CastProtocol::NS_MEDIA, [
            'type'      => 'LOAD',
            'requestId' => $this->requestId++,
            'autoplay'  => true,
            'currentTime' => 0,
            'media'     => [
                'contentId'   => $url,
                'contentType' => $mime,
                'streamType'  => 'BUFFERED',
                'metadata'    => $metadata,
            ],
        ]);

        $reponse = (array) $this->awaitMessage(CastProtocol::NS_MEDIA, ['MEDIA_STATUS']);
        if (($reponse['status'] ?? []) === []) {
            throw new CastError(
                "Le téléviseur a refusé le fichier. C'est presque toujours le format : "
                . 'le récepteur Cast ne lit que du H.264/AAC en MP4. Pour un MKV, passez par VLC.'
            );
        }
    }

    /** @param array<string,mixed> $charge */
    private function explain(string $type, array $charge): string
    {
        $raison = (string) ($charge['reason'] ?? '');
        return match ($type) {
            'LAUNCH_ERROR' => "Le téléviseur n'a pas pu lancer le lecteur"
                . ($raison !== '' ? " ({$raison})" : '') . '.',
            'LOAD_FAILED' => "Le téléviseur a refusé le fichier. C'est presque toujours le format : "
                . 'le récepteur Cast ne lit que du H.264/AAC en MP4. Pour un MKV, passez par VLC.',
            default => 'Requête refusée par le téléviseur'
                . ($raison !== '' ? " ({$raison})" : '') . '.',
        };
    }
}
