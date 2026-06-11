<?php

declare(strict_types=1);

require __DIR__ . '/../src/config.php';
require __DIR__ . '/../src/functions.php';
require __DIR__ . '/../src/ProwlarrClient.php';

$config = load_config();

// En-têtes de sécurité.
header("Content-Security-Policy: default-src 'self'; "
    . "style-src 'self' https://cdn.jsdelivr.net 'unsafe-inline'; "
    . "img-src 'self' data:; script-src 'self' 'unsafe-inline'; "
    . "form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');

$client = new ProwlarrClient(
    $config['base_url'],
    $config['api_key'],
    $config['timeout'],
    $config['cache_ttl'],
    $config['cache_dir'],
);

// ---- Entrées (validées / listes blanches) ----
$searchTerm = trim((string) ($_GET['search'] ?? ''));

$sortable = ['title', 'size', 'seeders', 'publishDate'];
$tri   = in_array($_GET['tri'] ?? '', $sortable, true) ? $_GET['tri'] : 'publishDate';
$ordre = (($_GET['ordre'] ?? '') === 'asc') ? 'asc' : 'desc';

$allowedDays = [0, 1, 7, 30, 90];
$days = in_array((int) ($_GET['days'] ?? 1), $allowedDays, true) ? (int) $_GET['days'] : 1;

$selectedTrackers = array_map('intval', (array) ($_GET['selected_trackers'] ?? []));

// ---- Données ----
$indexers = [];
$results  = [];
$error    = null;

try {
    $indexers = $client->indexers();
} catch (Throwable $exception) {
    $error = $exception->getMessage();
}

if ($searchTerm !== '' && $error === null) {
    try {
        $results = $client->search($searchTerm, $selectedTrackers, $days);

        usort($results, static function (array $a, array $b) use ($tri, $ordre): int {
            $valA = $a[$tri] ?? 0;
            $valB = $b[$tri] ?? 0;
            if ($tri === 'publishDate') {
                $valA = strtotime((string) $valA) ?: 0;
                $valB = strtotime((string) $valB) ?: 0;
            }
            $cmp = $valA <=> $valB;
            return $ordre === 'asc' ? $cmp : -$cmp;
        });
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

/**
 * Construit l'URL d'un en-tête de tri (inverse l'ordre si déjà actif).
 *
 * @param array<string,mixed> $base
 */
function sort_link(string $column, string $currentTri, string $currentOrdre, array $base): string
{
    $base['tri']   = $column;
    $base['ordre'] = ($currentTri === $column && $currentOrdre === 'desc') ? 'asc' : 'desc';
    return '?' . http_build_query($base);
}

$baseQuery = [
    'search'            => $searchTerm,
    'days'              => $days,
    'selected_trackers' => $selectedTrackers,
];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recherche Prowlarr</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet"
          integrity="sha256-tq2XQC7duQPnpdenPuR6Z5IE773aRSGjkcutnfUJuTI=" crossorigin="anonymous">
    <style>
        tbody tr:hover { background-color: rgba(79, 70, 229, 0.3); }
        tbody tr:hover .download-btn { background-color: rgba(79, 70, 229, 0.8); }
        .hidden-column { filter: blur(4px); }
    </style>
</head>
<body class="bg-gray-900 text-white font-sans">
<div class="min-h-screen flex flex-col items-center py-12 px-4">
    <h1 class="text-3xl font-bold mb-6">Recherche de Fichiers</h1>

    <form action="" method="get" class="w-full max-w-2xl flex flex-col md:flex-row gap-2 justify-center">
        <input type="text" name="search" placeholder="Rechercher un fichier"
               value="<?php echo e($searchTerm); ?>" autofocus
               class="px-4 py-2 border border-gray-700 rounded-md focus:outline-none focus:border-blue-500 bg-gray-800 text-gray-300 placeholder-gray-500 flex-1">
        <select name="days" class="px-3 py-2 rounded-md bg-gray-800 border border-gray-700 text-gray-300">
            <?php foreach ($allowedDays as $d): ?>
                <option value="<?php echo $d; ?>" <?php echo $d === $days ? 'selected' : ''; ?>>
                    <?php echo $d === 0 ? 'Tout' : $d . ' j'; ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="px-6 py-2 bg-blue-500 text-white rounded-md hover:bg-blue-600 transition">Rechercher</button>
        <?php foreach ($selectedTrackers as $t): ?>
            <input type="hidden" name="selected_trackers[]" value="<?php echo (int) $t; ?>">
        <?php endforeach; ?>
    </form>

    <?php if (!empty($indexers)): ?>
        <form action="" method="get" class="w-full max-w-3xl mt-4 flex flex-wrap justify-center gap-x-4 gap-y-2">
            <input type="hidden" name="search" value="<?php echo e($searchTerm); ?>">
            <input type="hidden" name="days" value="<?php echo $days; ?>">
            <?php foreach ($indexers as $indexer): ?>
                <label class="inline-flex items-center">
                    <input type="checkbox" name="selected_trackers[]" value="<?php echo (int) $indexer['id']; ?>"
                           onchange="this.form.submit()"
                           <?php echo in_array($indexer['id'], $selectedTrackers, true) ? 'checked' : ''; ?>
                           class="form-checkbox h-5 w-5 text-blue-600">
                    <span class="ml-2 tracker-name"><?php echo e($indexer['name']); ?></span>
                </label>
            <?php endforeach; ?>
        </form>
    <?php endif; ?>

    <div class="mt-4">
        <button type="button" onclick="toggleHideColumns()"
                class="bg-gray-700 hover:bg-gray-600 text-white font-bold py-2 px-4 rounded transition">
            Masquer/afficher
        </button>
    </div>

    <?php if ($error !== null): ?>
        <div class="mt-8 max-w-2xl bg-red-900/50 border border-red-700 text-red-200 px-4 py-3 rounded">
            <?php echo e($error); ?>
        </div>
    <?php endif; ?>

    <?php if ($searchTerm !== '' && $error === null && empty($results)): ?>
        <p class="mt-8 text-gray-400">Aucun résultat pour « <?php echo e($searchTerm); ?> ».</p>
    <?php endif; ?>

    <?php if (!empty($results)): ?>
        <div class="w-full max-w-6xl mt-8 overflow-x-auto">
            <table id="searchResultsTable" class="w-full table-auto bg-gray-800 rounded-md">
                <thead>
                    <tr>
                        <th class="px-4 py-2 text-left text-blue-500">Source</th>
                        <th class="px-4 py-2 text-left text-blue-500">Titre</th>
                        <th class="px-4 py-2 text-left text-blue-500">
                            <a href="<?php echo e(sort_link('size', $tri, $ordre, $baseQuery)); ?>" class="hover:underline">Taille</a>
                        </th>
                        <th class="px-4 py-2 text-left text-blue-500">
                            <a href="<?php echo e(sort_link('seeders', $tri, $ordre, $baseQuery)); ?>" class="hover:underline">Seeders</a>
                        </th>
                        <th class="px-4 py-2 text-left text-blue-500">
                            <a href="<?php echo e(sort_link('publishDate', $tri, $ordre, $baseQuery)); ?>" class="hover:underline">Date</a>
                        </th>
                        <th class="px-4 py-2 text-left text-blue-500">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($results as $result): ?>
                        <?php
                        $downloadUrl = (string) ($result['downloadUrl'] ?? '');
                        $magnetUrl   = (string) ($result['magnetUrl'] ?? '');
                        $infoUrl     = safe_url($result['infoUrl'] ?? null);
                        $days_old    = days_since($result['publishDate'] ?? null);

                        // Choix de l'action : magnet direct, sinon proxy signé.
                        $action = null;
                        if (str_starts_with($downloadUrl, 'magnet:') || $magnetUrl !== '') {
                            $action = ['type' => 'magnet', 'href' => safe_url($magnetUrl ?: $downloadUrl, ['magnet'])];
                        } elseif (safe_url($downloadUrl) !== '#') {
                            $sig = sign_url($downloadUrl, $config['secret']);
                            $action = ['type' => 'torrent', 'href' => 'download_torrent.php?'
                                . http_build_query(['url' => $downloadUrl, 'sig' => $sig])];
                        }
                        ?>
                        <tr>
                            <td class="px-4 py-2 source-column"><?php echo e($result['indexer'] ?? 'N/A'); ?></td>
                            <td class="px-4 py-2">
                                <a href="<?php echo e($infoUrl); ?>" target="_blank" rel="noopener noreferrer"
                                   class="text-blue-300 hover:underline"><?php echo e($result['title'] ?? 'N/A'); ?></a>
                            </td>
                            <td class="px-4 py-2 whitespace-nowrap"><?php echo e(format_size($result['size'] ?? 0)); ?></td>
                            <td class="px-4 py-2"><?php echo (int) ($result['seeders'] ?? 0); ?></td>
                            <td class="px-4 py-2 whitespace-nowrap">
                                <?php echo $days_old === null ? 'N/A' : $days_old . ' jours'; ?>
                            </td>
                            <td class="px-4 py-2">
                                <?php if ($action !== null && $action['href'] !== '#'): ?>
                                    <a href="<?php echo e($action['href']); ?>"
                                       class="px-4 py-2 bg-blue-500 text-white rounded-md hover:bg-blue-600 transition download-btn">
                                        <?php echo $action['type'] === 'magnet' ? 'Magnet' : 'Télécharger'; ?>
                                    </a>
                                <?php else: ?>
                                    <span class="text-gray-500">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<script>
    function toggleHideColumns() {
        document.querySelectorAll(".source-column, .tracker-name")
            .forEach(el => el.classList.toggle("hidden-column"));
        const hidden = document.querySelector(".source-column")?.classList.contains("hidden-column") ?? false;
        try { localStorage.setItem("columnsHidden", hidden ? "1" : "0"); } catch (e) {}
    }
    window.addEventListener("DOMContentLoaded", () => {
        try { if (localStorage.getItem("columnsHidden") === "1") toggleHideColumns(); } catch (e) {}
    });
</script>
</body>
</html>
