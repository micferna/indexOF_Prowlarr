<?php
// Lire le fichier .env
$env = parse_ini_file('.env');

function searchProwlarr($query, $apiKey, $baseUrl, $days, $selectedTrackers) {
    $url = $baseUrl . '/api/v1/search?query=' . urlencode($query) . '&apikey=' . $apiKey . '&maxage=' . $days;

    // Si des trackers sont sélectionnés
    if (!empty($selectedTrackers)) {
        // Créer une liste des ID des trackers sélectionnés
        $selectedTrackerIds = [];

        // Parcourir les trackers sélectionnés
        foreach ($selectedTrackers as $tracker) {
            // Si le tracker est "Tous les trackers", ne pas ajouter d'ID spécifique
            if ($tracker === 'all') {
                // Quitter la boucle et retourner la recherche sur tous les trackers
                return json_decode(file_get_contents($url), true);
            } else {
                // Ajouter l'ID du tracker à la liste
                $selectedTrackerIds[] = $tracker;
            }
        }

        // Ajouter les ID des trackers à l'URL de la requête
        $url .= '&indexers=' . implode(',', $selectedTrackerIds);
    }

    // Effectuer la requête vers l'API Prowlarr
    $response = file_get_contents($url);

    // Retourner les résultats de la recherche
    return json_decode($response, true);
}

$results = [];
$searchTerm = '';
$tri = $_GET['tri'] ?? 'publishDate'; // Nouveau ou 'seeders' par défaut si vous voulez
$ordre = $_GET['ordre'] ?? 'desc'; // 'desc' par défaut
$days = $_GET['days'] ?? 1; // 1 jour par défaut
$selectedTrackers = $_GET['selected_trackers'] ?? [];


if ($_SERVER['REQUEST_METHOD'] == 'GET' && isset($_GET['search']) && !empty($_GET['search'])) {
    $searchTerm = $_GET['search'];

    if (!isset($_GET['redirected'])) {
        $results = searchProwlarr($searchTerm, $env['PROWLARR_API_KEY'], $env['PROWLARR_BASE_URL'], $days, $selectedTrackers);

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
        header("Location: index.php?search=" . urlencode($searchTerm) . "&tri=" . $tri . "&ordre=" . $ordre . "&days=" . $days . "&redirected=1");
        exit;
    } else {
        session_start();
        if (isset($_SESSION['results'])) {
            $results = $_SESSION['results'];
        }
    }
}


// Récupérer la liste des trackers configurés sur l'API Prowlarr
function getTrackers($apiKey, $baseUrl) {
    // URL de l'API pour obtenir la liste des trackers
    $url = $baseUrl . '/api/v1/indexer';

    // Initialiser cURL
    $ch = curl_init();

    // Configuration de la requête cURL
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'X-Api-Key: ' . $apiKey
    ]);

    // Exécution de la requête
    $response = curl_exec($ch);

    // Fermeture de la session cURL
    curl_close($ch);

    // Vérification de la réponse
    if ($response === false) {
        // En cas d'erreur, retourner un tableau vide
        return [];
    }

    // Conversion de la réponse JSON en tableau associatif
    $responseData = json_decode($response, true);

    // Initialisation du tableau des noms de trackers
    $trackers = [];

    // Ajouter une option pour "Tous les trackers"
    $trackers[] = 'all';

    // Parcours des données des trackers et récupération des noms
    foreach ($responseData as $tracker) {
        $trackers[] = $tracker['name'];
    }

    // Retour du tableau contenant les noms des trackers
    return $trackers;
}

// Appel de la fonction pour récupérer les trackers
$trackers = getTrackers($env['PROWLARR_API_KEY'], $env['PROWLARR_BASE_URL']);
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

        /* Style pour flouter les colonnes des sources et des noms des trackers */
        .hidden-column {
            filter: blur(4px); /* Flou */
        }
    </style>
</head>
<body class="bg-gray-900 text-white font-sans">

<div class="min-h-screen flex flex-col justify-center items-center py-12">
    <h1 class="text-3xl font-bold mb-4">Recherche de Fichiers</h1>
    <div class="max-w-lg mx-auto">
        <form action="" method="get" class="flex flex-col md:flex-row justify-center items-center">
            <input type="text" name="search" placeholder="Rechercher un fichier" value="<?php echo htmlspecialchars($searchTerm); ?>" class="px-4 py-2 border border-gray-700 rounded-md focus:outline-none focus:border-blue-500 bg-gray-800 text-gray-300 placeholder-gray-500 w-full md:w-auto mb-2 md:mb-0">
            <button type="submit" class="px-6 py-2 bg-blue-500 text-white rounded-md ml-2 hover:bg-blue-600 transition duration-300 ease-in-out">Rechercher</button>
        </form>
    </div>
    
    <div class="max-w-lg mx-auto mt-4">
        <form action="" method="get" class="flex flex-wrap justify-center">
        <?php foreach ($trackers as $tracker): ?>
            <label class="inline-flex items-center mr-4 mb-2">
                <input type="checkbox" name="selected_trackers[]" value="<?php echo htmlspecialchars($tracker); ?>" <?php if (in_array($tracker, $selectedTrackers)) echo 'checked'; ?> class="form-checkbox h-5 w-5 text-blue-600">
                <span class="ml-2 tracker-name"><?php echo htmlspecialchars($tracker); ?></span> <!-- Ajoutez la classe "tracker-name" ici -->
            </label>
        <?php endforeach; ?>

        </form>
    </div>

    <!-- Bouton "Masquer" pour flouter les colonnes -->
    <div class="max-w-full mx-auto px-4 sm:px-6 lg:px-8 mt-4">
    <button onclick="toggleHideColumns()" class="bg-gray-700 hover:bg-gray-600 text-white font-bold py-2 px-4 rounded focus:outline-none focus:shadow-outline transition duration-300 ease-in-out">
    Masquer/afficher
</button>

    </div>

    <?php if (!empty($results)): ?>
        <div class="max-w-full mx-auto px-4 sm:px-6 lg:px-8 mt-8">
            <div class="overflow-x-auto">
                <table id="searchResultsTable" class="w-full table-auto bg-gray-800 rounded-md">
                    <thead>
                        <tr>
                            <th class="px-4 py-2 text-left text-blue-500">Source</th>
                            <th class="px-4 py-2 text-left text-blue-500">Titre</th>
                            <th class="px-4 py-2 text-left text-blue-500"><a href="?search=<?php echo urlencode($searchTerm); ?>&tri=size&ordre=<?php echo $tri === 'size' && $ordre === 'desc' ? 'asc' : 'desc'; ?>" class="hover:underline">Taille</a></th>
                            <th class="px-4 py-2 text-left text-blue-500"><a href="?search=<?php echo urlencode($searchTerm); ?>&tri=seeders&ordre=<?php echo $tri === 'seeders' && $ordre === 'desc' ? 'asc' : 'desc'; ?>" class="hover:underline">Seeders</a></th>
                            <th class="px-4 py-2 text-left text-blue-500"><a href="?search=<?php echo urlencode($searchTerm); ?>&tri=publishDate&ordre=<?php echo $tri === 'publishDate' && $ordre === 'desc' ? 'asc' : 'desc'; ?>" class="hover:underline">Date</a></th>
                            <th class="px-4 py-2 text-left text-blue-500">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($results as $result): ?>
                            <tr>
                                <td class="px-4 py-2 <?php if(isset($_GET['hide'])) echo 'hidden-column'; ?>"><?php echo htmlspecialchars($result['indexer'] ?? 'N/A'); ?></td>
                                <td class="px-4 py-2"><a href="<?php echo htmlspecialchars($result['infoUrl']); ?>" target="_blank" class="text-blue-300 hover:underline"><?php echo htmlspecialchars($result['title']); ?></a></td>
                                <td class="px-4 py-2"><?php echo htmlspecialchars(number_format($result['size'] / (1024**3), 2)) . ' GB'; ?></td>
                                <td class="px-4 py-2"><?php echo htmlspecialchars($result['seeders']); ?></td>
                                <td class="px-4 py-2"><?php echo htmlspecialchars(floor((time() - strtotime($result['publishDate'])) / (60 * 60 * 24))); ?> jours</td>
                                <td class="px-4 py-2"><a href="download_torrent.php?url=<?php echo urlencode($result['downloadUrl']); ?>" class="px-4 py-2 bg-blue-500 text-white rounded-md hover:bg-blue-600 transition duration-300 ease-in-out download-btn">Télécharger</a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>


<!-- Script JavaScript pour masquer/afficher les colonnes -->
<script>
    function toggleHideColumns() {
        var cells = document.querySelectorAll("td:nth-child(1), .tracker-name");
        for (var i = 0; i < cells.length; i++) {
            cells[i].classList.toggle("hidden-column");
        }

        // Mise à jour du cookie pour enregistrer l'état de floutage
        var isHidden = cells[0].classList.contains("hidden-column"); // Vérifie si la première cellule est masquée
        document.cookie = "columnsHidden=" + isHidden;
    }

    // Fonction pour récupérer la valeur d'un cookie par son nom
    function getCookie(cookieName) {
        var name = cookieName + "=";
        var decodedCookie = decodeURIComponent(document.cookie);
        var cookieArray = decodedCookie.split(';');
        for(var i = 0; i <cookieArray.length; i++) {
            var cookie = cookieArray[i];
            while (cookie.charAt(0) === ' ') {
                cookie = cookie.substring(1);
            }
            if (cookie.indexOf(name) === 0) {
                return cookie.substring(name.length, cookie.length);
            }
        }
        return "";
    }

    // Vérifie l'état du floutage lors du chargement de la page
    window.onload = function() {
        var isHidden = getCookie("columnsHidden");
        if (isHidden === "true") {
            toggleHideColumns(); // Si les colonnes sont masquées, appliquez le floutage
        }
    };
</script>




</body>
</html>
