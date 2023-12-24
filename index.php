<?php
// Lire le fichier .env
$env = parse_ini_file('.env');

function searchProwlarr($query, $apiKey, $baseUrl) {
    $url = $baseUrl . '/api/v1/search?query=' . urlencode($query) . '&apikey=' . $apiKey;

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);

    return json_decode($response, true);
}

$results = [];
$searchTerm = '';

if ($_SERVER['REQUEST_METHOD'] == 'GET' && isset($_GET['search']) && !empty($_GET['search'])) {
    $searchTerm = $_GET['search'];

    // Effectuer la recherche et rediriger
    if (!isset($_GET['redirected'])) {
        $results = searchProwlarr($searchTerm, $env['PROWLARR_API_KEY'], $env['PROWLARR_BASE_URL']);

        if (!empty($results)) {
            usort($results, function($a, $b) {
                return $b['seeders'] - $a['seeders'];
            });
        }

        session_start();
        $_SESSION['results'] = $results; // Stocker les résultats dans la session
        header("Location: index.php?search=" . urlencode($searchTerm) . "&redirected=1");
        exit;
    }
    // Charger les résultats à partir de la session après la redirection
    else {
        session_start();
        if (isset($_SESSION['results'])) {
            $results = $_SESSION['results'];
        }
    }
        // Afficher les données pour le débogage
        #echo '<pre>'; var_dump($results); echo '</pre>';
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Recherche</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <h1>Recherche de fichiers</h1>
    <form action="index.php" method="get">
        <input type="text" name="search" placeholder="Nom du fichier" value="<?php echo htmlspecialchars($searchTerm); ?>">
        <button type="submit">Rechercher</button>
    </form>

    <?php if (!empty($results)): ?>
    <h2></h2>
    <div class="table-container">
        <table class="results">
            <thead>
                <tr>
                    <th>Source</th> <!-- Nouvelle colonne pour les initiales du tracker -->
                    <th>Titre</th>
                    <th>Seeders</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($results as $result): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($result['indexer'] ?? 'N/A'); ?></td>
                        <td><a href="<?php echo htmlspecialchars($result['infoUrl']); ?>" target="_blank"><?php echo htmlspecialchars($result['title']); ?></a></td>
                        <td><?php echo htmlspecialchars($result['seeders']); ?></td>
                        <td><a href="download.php?url=<?php echo urlencode($result['downloadUrl']); ?>">Télécharger</a></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
</body>
</html>
