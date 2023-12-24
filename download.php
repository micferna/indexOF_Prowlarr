<?php
// Charger les variables d'environnement depuis le fichier .env
$env = parse_ini_file('.env');

// Assurez-vous que l'URL de téléchargement est fournie
if (!isset($_GET['url']) || empty($_GET['url'])) {
    die('URL de téléchargement manquante');
}

// URL du fichier à télécharger
$fileUrl = $_GET['url'];

// Utiliser les variables du fichier .env
$prowlarrApiKey = $env['PROWLARR_API_KEY'];
$prowlarrBaseUrl = $env['PROWLARR_BASE_URL'];

// Préparer la requête pour Prowlarr
$apiUrl = $prowlarrBaseUrl . '/command'; // ou une autre endpoint API appropriée
$data = [
    'name' => 'Download', // ou un autre nom de commande approprié
    'downloadUrl' => $fileUrl,
    // Autres paramètres nécessaires
];

// Utilisation de cURL pour envoyer la requête
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $apiUrl);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'X-Api-Key: ' . $prowlarrApiKey
]);
$response = curl_exec($ch);
$curlError = curl_error($ch);
curl_close($ch);

// Gérer les erreurs de cURL
if (!empty($curlError)) {
    die('Erreur lors de la communication avec Prowlarr : ' . $curlError);
}

// Traiter la réponse
$responseData = json_decode($response, true);
if (isset($responseData['success']) && $responseData['success']) {
    echo 'Téléchargement initié avec succès.';
} else {
    echo 'Échec de l\'initiation du téléchargement.';
}
