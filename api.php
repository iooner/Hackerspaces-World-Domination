<?php
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

$cacheData = json_decode(file_get_contents($cacheFile), true);

if (!$cacheData) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Invalid cache',
        'message' => 'Cache file is corrupted'
    ]);
    exit;
}

$cacheData['cache_age_hours'] = $cacheAgeHours;
$cacheData['cache_age_text'] = $cacheAgeHours < 1 
    ? round($cacheAge / 60) . ' minutes ago' 
    : $cacheAgeHours . ' hours ago';

if (isset($_GET['state'])) {
    $stateFilter = $_GET['state'];
    $validStates = ['open', 'closed', 'down'];
    
    if (in_array($stateFilter, $validStates)) {
        $cacheData['spaces'] = array_filter($cacheData['spaces'], function($space) use ($stateFilter) {
            return $space['state'] === $stateFilter;
        });
        $cacheData['spaces'] = array_values($cacheData['spaces']);
        $cacheData['filtered_by'] = $stateFilter;
    }
}

echo json_encode($cacheData, JSON_UNESCAPED_UNICODE);