<?php
/**
 * API Endpoint — Hackerspaces World Domination v2
 *
 * GET api.php                  → JSON brut (compat v1)
 * GET api.php?format=geojson   → FeatureCollection (consommé par MapLibre)
 * GET api.php?state=open       → filtre par état (open|closed|limited|unknown|static)
 * GET api.php?format=history    → historique des runs (optionnel: &limit=N)
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

$cacheFile = __DIR__ . '/cache/hackerspaces_cache.json';

if (!file_exists($cacheFile)) {
    http_response_code(503);
    echo json_encode([
        'error' => 'Cache not found',
        'message' => 'Please run update_cache.php first'
    ]);
    exit;
}

$cacheAge = time() - filemtime($cacheFile);
$cacheAgeHours = round($cacheAge / 3600, 1);
$cacheAgeText = $cacheAgeHours < 1
    ? round($cacheAge / 60) . ' minutes ago'
    : $cacheAgeHours . ' hours ago';

$maxCacheSize = 10 * 1024 * 1024; // 10 Mo — un cache sain fait ~200 Ko
$cacheSize = filesize($cacheFile);
if ($cacheSize > $maxCacheSize) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Cache too large',
        'message' => 'Cache file exceeds 10 MB (' . round($cacheSize / 1024 / 1024, 1) . ' MB) — possible corruption'
    ]);
    exit;
}
$cacheData = json_decode(file_get_contents($cacheFile), true);

if (!$cacheData || !isset($cacheData['spaces'])) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Invalid cache',
        'message' => 'Cache file is corrupted'
    ]);
    exit;
}

$spaces = $cacheData['spaces'];

// Filtre par état si demandé
if (isset($_GET['state'])) {
    $stateFilter = $_GET['state'];
    $validStates = ['open', 'limited', 'closed', 'unknown', 'static'];
    if (in_array($stateFilter, $validStates, true)) {
        $spaces = array_values(array_filter($spaces, function ($space) use ($stateFilter) {
            return ($space['state'] ?? null) === $stateFilter;
        }));
    }
}

// ── Format GeoJSON ──────────────────────────────────────────────
if (isset($_GET['format']) && $_GET['format'] === 'geojson') {
    $features = [];
    foreach ($spaces as $space) {
        if (!isset($space['lat'], $space['lon'])) continue;

        $lat = (float) $space['lat'];
        $lon = (float) $space['lon'];

        // Garde-fou : certaines sources inversent lat/lon (ex: Gold Coast Techspace)
        if (abs($lat) > 90 && abs($lon) <= 90) {
            [$lat, $lon] = [$lon, $lat];
        }
        // Coordonnées toujours invalides → on écarte plutôt que d'afficher n'importe où
        if (abs($lat) > 90 || abs($lon) > 180) continue;

        $features[] = [
            'type' => 'Feature',
            'geometry' => [
                'type' => 'Point',
                // GeoJSON = [lon, lat], attention à l'ordre
                'coordinates' => [$lon, $lat],
            ],
            'properties' => [
                'name'       => $space['name'] ?? '',
                'state'      => $space['state'] ?? 'static',
                'city'       => $space['city'] ?? '',
                'country'    => $space['country'] ?? '',
                'address'    => $space['address'] ?? '',
                'message'    => $space['message'] ?? '',
                'url'        => $space['url'] ?? '',
                'logo'       => $space['logo'] ?? '',
                'lastchange' => $space['lastchange'] ?? null,
                'last_seen'  => $space['last_seen'] ?? null,
            ],
        ];
    }

    echo json_encode([
        'type' => 'FeatureCollection',
        'metadata' => [
            'last_update'     => $cacheData['last_update'] ?? null,
            'stats'           => $cacheData['stats'] ?? new stdClass(),
            'count'           => count($features),
            'cache_age_hours' => $cacheAgeHours,
            'cache_age_text'  => $cacheAgeText,
        ],
        'features' => $features,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ── Historique des runs ─────────────────────────────────────────
if (isset($_GET['format']) && $_GET['format'] === 'history') {
    $historyFile = __DIR__ . '/cache/run_history.json';
    if (!file_exists($historyFile)) {
        // Fallback : chercher au même niveau que api.php
        $historyFile2 = dirname(__FILE__) . '/cache/run_history.json';
        if (!file_exists($historyFile2)) {
            echo json_encode([
                'runs' => [],
                'message' => 'No history yet — run update_cache.php first.',
                'debug_path' => $historyFile,
                'debug_path2' => $historyFile2,
            ]);
            exit;
        }
        $historyFile = $historyFile2;
    }
    $history = json_decode(file_get_contents($historyFile), true);
    // Limiter la réponse aux N derniers runs si demandé
    $limit = isset($_GET['limit']) ? min((int)$_GET['limit'], 1008) : 1008;
    if (isset($history['runs'])) {
        $history['runs'] = array_slice($history['runs'], 0, $limit);
    }
    echo json_encode($history, JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Format brut (compat v1) ─────────────────────────────────────
$cacheData['spaces'] = $spaces;
$cacheData['cache_age_hours'] = $cacheAgeHours;
$cacheData['cache_age_text'] = $cacheAgeText;

echo json_encode($cacheData, JSON_UNESCAPED_UNICODE);
