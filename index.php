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
$tri = $_GET['tri'] ?? null; // Nouveau ou 'seeders' par défaut si vous voulez
$ordre = $_GET['ordre'] ?? 'desc'; // 'desc' par défaut

if ($_SERVER['REQUEST_METHOD'] == 'GET' && isset($_GET['search']) && !empty($_GET['search'])) {
    $searchTerm = $_GET['search'];

    if (!isset($_GET['redirected'])) {
        $results = searchProwlarr($searchTerm, $env['PROWLARR_API_KEY'], $env['PROWLARR_BASE_URL']);

        if (!empty($results)) {
            usort($results, function($a, $b) use ($tri, $ordre) {
                $valA = $a[$tri] ?? 0; // Utiliser 0 comme valeur par défaut si l'indice n'existe pas
                $valB = $b[$tri] ?? 0;
                if ($ordre === 'asc') {
                    return $valA <=> $valB;
                } else { // 'desc' par défaut
                    return $valB <=> $valA;
                }
            });
        }

        session_start();
        $_SESSION['results'] = $results;
        header("Location: index.php?search=" . urlencode($searchTerm) . "&tri=" . $tri . "&ordre=" . $ordre . "&redirected=1");
        exit;
    } else {
        session_start();
        if (isset($_SESSION['results'])) {
            $results = $_SESSION['results'];
        }
    }
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
    <h1></h1>
    <form action="index.php" method="get">
        <input type="text" name="search" placeholder="Nom du fichier" value="<?php echo htmlspecialchars($searchTerm); ?>">
        <button type="submit">Rechercher</button>
    </form>

    <?php if (!empty($results)): ?>
    <div class="table-container">
        <table class="results">
            <thead>
                <tr>
                    <th>Source</th>
                    <th>Titre</th>
                    <th><a href="?search=<?php echo urlencode($searchTerm); ?>&tri=size&ordre=<?php echo $tri === 'size' && $ordre === 'desc' ? 'asc' : 'desc'; ?>">Taille</a></th>
                    <th><a href="?search=<?php echo urlencode($searchTerm); ?>&tri=seeders&ordre=<?php echo $tri === 'seeders' && $ordre === 'desc' ? 'asc' : 'desc'; ?>">Seeders</a></th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($results as $result): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($result['indexer'] ?? 'N/A'); ?></td>
                        <td><a href="<?php echo htmlspecialchars($result['infoUrl']); ?>" target="_blank"><?php echo htmlspecialchars($result['title']); ?></a></td>
                        <td><?php echo htmlspecialchars(number_format($result['size'] / (1024**3), 2)) . ' GB'; ?></td>
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
