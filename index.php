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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recherche</title>
    <!-- Intégration de Tailwind CSS -->
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <style>
        /* Style pour illuminer toute la ligne au survol */
        tbody tr:hover {
            background-color: rgba(79, 70, 229, 0.3); /* Couleur de fond au survol */
        }

        /* Style pour illuminer le bouton Télécharger au survol */
        tbody tr:hover .download-btn {
            background-color: rgba(79, 70, 229, 0.8); /* Couleur de fond au survol */
        }
    </style>
</head>
<body class="bg-gray-900 text-white font-sans">
    <div class="min-h-screen flex flex-col justify-center items-center py-12">
        <h1 class="text-3xl font-bold mb-4">Recherche de Fichiers</h1>
        <form action="" method="get" class="flex flex-wrap justify-center items-center w-full px-4">
            <input type="text" name="search" placeholder="Rechercher un fichier" value="<?php echo htmlspecialchars($searchTerm); ?>" class="px-4 py-2 border border-gray-700 rounded-md focus:outline-none focus:border-blue-500 bg-gray-800 text-gray-300 placeholder-gray-500 w-full md:w-auto mb-2 md:mb-0">
            <button type="submit" class="px-6 py-2 bg-blue-500 text-white rounded-md ml-2 hover:bg-blue-600 transition duration-300 ease-in-out">Rechercher</button>
        </form>
        
        <?php if (!empty($results)): ?>
        <div class="max-w-full mx-auto px-4 sm:px-6 lg:px-8 mt-8">
            <div class="overflow-x-auto">
                <table class="w-full table-auto bg-gray-800 rounded-md">
                    <thead>
                        <tr>
                            <th class="px-4 py-2 text-left text-blue-500">Source</th>
                            <th class="px-4 py-2 text-left text-blue-500">Titre</th>
                            <th class="px-4 py-2 text-left text-blue-500"><a href="?search=<?php echo urlencode($searchTerm); ?>&tri=size&ordre=<?php echo $tri === 'size' && $ordre === 'desc' ? 'asc' : 'desc'; ?>" class="hover:underline">Taille</a></th>
                            <th class="px-4 py-2 text-left text-blue-500"><a href="?search=<?php echo urlencode($searchTerm); ?>&tri=seeders&ordre=<?php echo $tri === 'seeders' && $ordre === 'desc' ? 'asc' : 'desc'; ?>" class="hover:underline">Seeders</a></th>
                            <th class="px-4 py-2 text-left text-blue-500">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($results as $result): ?>
                            <tr>
                                <td class="px-4 py-2"><?php echo htmlspecialchars($result['indexer'] ?? 'N/A'); ?></td>
                                <td class="px-4 py-2"><a href="<?php echo htmlspecialchars($result['infoUrl']); ?>" target="_blank" class="text-blue-300 hover:underline"><?php echo htmlspecialchars($result['title']); ?></a></td>
                                <td class="px-4 py-2 whitespace-nowrap"><?php echo htmlspecialchars(number_format($result['size'] / (1024**3), 2)) . ' GB'; ?></td>
                                <td class="px-4 py-2"><?php echo htmlspecialchars($result['seeders']); ?></td>
                                <td class="px-4 py-2"><a href="download_torrent.php?url=<?php echo urlencode($result['downloadUrl']); ?>" class="px-4 py-2 bg-blue-500 text-white rounded-md hover:bg-blue-600 transition duration-300 ease-in-out download-btn">Télécharger</a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>
