<?php
// Vérifiez si l'URL du torrent est fournie en tant que paramètre GET
if (isset($_GET['url']) && !empty($_GET['url'])) {
    // Récupérer l'URL du torrent depuis l'API de Prowlarr
    $torrentUrl = $_GET['url'];

    // Télécharger le fichier torrent à partir de l'URL de l'API
    $torrentFile = file_get_contents($torrentUrl);

    // Vérifier si le téléchargement réussi
    if ($torrentFile !== false) {
        // Envoyer les en-têtes HTTP appropriés pour forcer le téléchargement
        header('Content-Type: application/x-bittorrent');
        header('Content-Disposition: attachment; filename="file.torrent"');

        // Envoyer le contenu du fichier torrent
        echo $torrentFile;

        // Arrêter l'exécution du script après l'envoi du fichier
        exit;
    } else {
        // Gérer les erreurs de téléchargement
        echo "Erreur lors du téléchargement du fichier .torrent depuis l'API de Prowlarr.";
    }
} else {
    // Gérer le cas où l'URL du torrent n'est pas fournie
    echo "L'URL du fichier .torrent n'est pas spécifiée.";
}
