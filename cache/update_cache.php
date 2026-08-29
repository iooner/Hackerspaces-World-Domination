<?php
/**
 * Cache Intelligent pour Hackerspaces Globe
 * 
 * Ce script parcourt le directory SpaceApi et met en cache les données
 * États possibles : 'open', 'limited', 'closed', 'unknown', 'static'
 */

// Augmenter la limite de temps d'exécution
set_time_limit(300);        // 5 minutes
ini_set('max_execution_time', '300');

// Sortie lisible quand le script est lancé depuis un navigateur
if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
    // Désactiver la mise en tampon pour voir la progression en direct
    @ini_set('output_buffering', 'off');
    @ini_set('zlib.output_compression', '0');
    while (ob_get_level() > 0) { ob_end_flush(); }
    ob_implicit_flush(true);
}

// Configuration
$directoryUrl = 'https://raw.githubusercontent.com/SpaceApi/directory/refs/heads/master/directory.json';
$cacheFile = __DIR__ . '/hackerspaces_cache.json';
$cacheDir = __DIR__;
$timeout = 5; // Timeout en secondes pour chaque requête
$maxConcurrent = 10; // Nombre maximum de requêtes simultanées
$expirationDays = 30; // Supprimer les spaces qui ne répondent plus depuis X jours

$runStart = microtime(true);
$runTimestamp = date('c');

// Créer le dossier cache s'il n'existe pas
if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0755, true);
}

echo "\n";
echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║          🌍 HACKERSPACES WORLD DOMINATION - CACHE             ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n";
echo "\n";

// Télécharger le directory
echo "📥 ÉTAPE 1/4 : Téléchargement du directory\n";
echo "────────────────────────────────────────────────────────────────\n";
$directoryJson = @file_get_contents($directoryUrl);

if ($directoryJson === false) {
    die("❌ Erreur: Impossible de télécharger le directory\n");
}

$directory = json_decode($directoryJson, true);
if (!$directory) {
    die("❌ Erreur: JSON du directory invalide\n");
}

echo "✅ Directory téléchargé: " . count($directory) . " hackerspaces\n\n";

// Charger la banlist
$banlistFile = __DIR__ . '/banlist.json';
$bannedSpaces = [];
$bannedDomains = [];
if (file_exists($banlistFile)) {
    $banlist = json_decode(file_get_contents($banlistFile), true);
    if ($banlist) {
        $bannedSpaces = array_column($banlist['spaces'] ?? [], 'reason', 'name');
        $bannedDomains = array_column($banlist['domains'] ?? [], 'reason', 'domain');
        echo "🚫 Banlist chargée: " . count($bannedSpaces) . " space(s), " . count($bannedDomains) . " domaine(s)\n\n";
    }
} else {
    echo "ℹ️  Pas de banlist trouvée (cache/banlist.json)\n\n";
}

echo "📂 ÉTAPE 2/4 : Chargement de l'ancien cache\n";
echo "────────────────────────────────────────────────────────────────\n";

// Charger l'ancien cache pour récupérer les données des spaces qui ne répondent plus
$oldCache = [];
$isFirstRun = !file_exists($cacheFile);

if (file_exists($cacheFile)) {
    $oldCacheData = json_decode(file_get_contents($cacheFile), true);
    if ($oldCacheData && isset($oldCacheData['spaces'])) {
        // Indexer par nom pour un accès rapide
        foreach ($oldCacheData['spaces'] as $space) {
            $oldCache[$space['name']] = $space;
        }
        echo "✅ Ancien cache chargé: " . count($oldCache) . " spaces\n\n";
    }
} else {
    echo "🆕 Premier lancement - création du cache initial\n\n";
}

// Préparer le cache
$cache = [
    'last_update' => date('c'),
    'total_spaces' => count($directory),
    'stats' => [
        'open' => 0,
        'limited' => 0,
        'closed' => 0,
        'unknown' => 0,
        'static' => 0,
        'down' => 0,
        'expired' => 0,
        'banned' => 0,
        'no_coords' => 0
    ],
    'spaces' => []
];

// Garder la liste des noms "down" pour comparaison avec mapall
$downSpaceNames = [];
$expiredSpaces = [];
$unknownSpaces = [];
$bannedList = [];

/**
 * Geocode un hackerspace via Nominatim (OSM)
 * Retourne ['lat' => float, 'lon' => float] ou null
 */
function geocodeHackerspace($name) {
    // Nettoyer le nom pour la recherche
    $searchQuery = $name . ' hackerspace';
    $searchQuery = urlencode($searchQuery);

    // Nominatim API (gratuit, rate limit: 1 req/sec)
    $url = "https://nominatim.openstreetmap.org/search?q={$searchQuery}&format=json&limit=1";

    // Headers requis par Nominatim
    $context = stream_context_create([
        'http' => [
            'header' => "User-Agent: HSWD-Globe/1.0\r\n",
            'timeout' => 5,
            'ignore_errors' => true // pour pouvoir lire le corps même sur un statut non-200
        ]
    ]);

    $geocodeError = null;
    set_error_handler(function ($no, $str) use (&$geocodeError) { $geocodeError = $str; });
    $response = file_get_contents($url, false, $context);
    restore_error_handler();

    if ($response === false) {
        // Distingue "requête jamais partie/timeout" de "0 résultat trouvé" —
        // diagnostic temporaire pour le roadmap "Nominatim investigation"
        echo "    ↳ debug geocoding: échec réseau — " . ($geocodeError ?: 'raison inconnue') . "\n";
        return null;
    }

    // http_get_last_response_headers() (PHP 8.3+) remplace $http_response_header,
    // déprécié en 8.5 — les deux environnements (prod 8.3, dev 8.5) le supportent.
    $headers = function_exists('http_get_last_response_headers') ? http_get_last_response_headers() : ($http_response_header ?? []);
    $status = $headers[0] ?? '(pas de statut HTTP)';
    $data = json_decode($response, true);

    if (empty($data) || !isset($data[0]['lat']) || !isset($data[0]['lon'])) {
        echo "    ↳ debug geocoding: {$status} — 0 résultat pour \"" . urldecode($searchQuery) . "\" (réponse: " . substr($response, 0, 120) . ")\n";
        return null;
    }

    return [
        'lat' => floatval($data[0]['lat']),
        'lon' => floatval($data[0]['lon']),
        'display_name' => $data[0]['display_name'] ?? ''
    ];
}

// Fonction pour récupérer les données d'un hackerspace (version simple)
function fetchSpaceData($name, $apiUrl, $timeout) {
    $context = stream_context_create([
        'http' => [
            'timeout' => $timeout,
            'method' => 'GET',
            'header' => "User-Agent: HackerspacesGlobe/1.0\r\n"
        ]
    ]);
    
    $data = @file_get_contents($apiUrl, false, $context);
    
    if ($data === false) {
        return null;
    }
    
    return json_decode($data, true);
}

// Fonction pour récupérer plusieurs hackerspaces en parallèle avec curl_multi
function fetchSpacesParallel($spaces, $timeout, $maxConcurrent = 10) {
    $results = [];
    $chunks = array_chunk($spaces, $maxConcurrent, true);
    
    foreach ($chunks as $chunk) {
        $multiHandle = curl_multi_init();
        $handles = [];
        
        // Créer les handles curl pour ce chunk
        foreach ($chunk as $name => $apiUrl) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $apiUrl,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => $timeout,
                CURLOPT_USERAGENT => 'HackerspacesGlobe/1.0',
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_SSL_VERIFYPEER => false // Délibéré : certains spaces ont des certs expirés/auto-signés,
                                                   //            on préfère les afficher quand même
            ]);
            curl_multi_add_handle($multiHandle, $ch);
            $handles[$name] = ['ch' => $ch, 'url' => $apiUrl];
        }
        
        // Exécuter les requêtes en parallèle
        $running = null;
        do {
            curl_multi_exec($multiHandle, $running);
            curl_multi_select($multiHandle);
        } while ($running > 0);
        
        // Récupérer les résultats
        foreach ($handles as $name => $handle) {
            $content = curl_multi_getcontent($handle['ch']);
            $httpCode = curl_getinfo($handle['ch'], CURLINFO_HTTP_CODE);
            
            if ($content !== false && $httpCode == 200) {
                $results[$name] = json_decode($content, true);
            } else {
                $results[$name] = null;
            }
            
            curl_multi_remove_handle($multiHandle, $handle['ch']);
            curl_close($handle['ch']);
        }
        
        curl_multi_close($multiHandle);
        
        // Petite pause entre les chunks pour ne pas surcharger
        if (count($chunks) > 1) {
            usleep(100000); // 0.1 seconde
        }
    }
    
    return $results;
}


// Traiter les hackerspaces en parallèle
$processed = 0;
$total = count($directory);

echo "🚀 ÉTAPE 3/4 : Récupération des données SpaceAPI\n";
echo "────────────────────────────────────────────────────────────────\n";
echo "Mode parallèle: $maxConcurrent requêtes simultanées\n\n";

// Récupérer toutes les données en parallèle
$allSpaceData = fetchSpacesParallel($directory, $timeout, $maxConcurrent);

// Traiter chaque résultat
foreach ($directory as $name => $apiUrl) {
    $processed++;
    $percent = round(($processed / $total) * 100);
    
    echo sprintf("[%3d%%] %s...\n", $percent, $name);

    // Check banlist : space banni → skip complet
    if (isset($bannedSpaces[$name])) {
        echo "  🚫 Banni: " . $bannedSpaces[$name] . "\n";
        $cache['stats']['banned']++;
        $bannedList[] = ['name' => $name, 'reason' => $bannedSpaces[$name], 'source' => 'spaceapi'];
        continue;
    }

    // Récupérer les données depuis le résultat parallèle
    $spaceData = $allSpaceData[$name] ?? null;
    
    if ($spaceData === null) {
        // API down - vérifier si on a des données en cache
        if (isset($oldCache[$name])) {
            $oldSpace = $oldCache[$name];
            
            // Vérifier si le space n'est pas expiré (> 30 jours)
            $lastSeen = isset($oldSpace['last_seen']) ? strtotime($oldSpace['last_seen']) : 0;
            $daysSinceLastSeen = ($lastSeen > 0) ? (time() - $lastSeen) / 86400 : null;
            
            if ($daysSinceLastSeen === null || $daysSinceLastSeen <= $expirationDays) {
                // Garder les anciennes données
                // Si c'était 'static', le garder static, sinon mettre 'unknown'
                if ($oldSpace['state'] !== 'static') {
                    $oldSpace['state'] = 'unknown';
                }
                $cache['spaces'][] = $oldSpace;
                
                if ($oldSpace['state'] === 'static') {
                    $cache['stats']['static']++;
                    echo "  🔵 Gardé en cache (source statique)\n";
                } else {
                    $cache['stats']['unknown']++;
                    $unknownSpaces[] = ['name' => $name, 'days' => $daysSinceLastSeen !== null ? round($daysSinceLastSeen) : null, 'last_seen' => $oldSpace['last_seen'] ?? null];
                    echo "  ⚪ Gardé en cache (API down, dernière réponse: " . ($daysSinceLastSeen !== null ? round($daysSinceLastSeen) . "j" : "inconnue") . ")\n";
                }
            } else {
                // Expiré
                $cache['stats']['expired']++;
                $expiredSpaces[] = ['name' => $name, 'days' => $daysSinceLastSeen !== null ? round($daysSinceLastSeen) : null];
                echo "  🗑️  Expiré (pas de réponse depuis " . ($daysSinceLastSeen !== null ? round($daysSinceLastSeen) . " jours" : "début inconnu, API jamais répondu") . ")\n";
            }
        } else {
            // Pas dans l'ancien cache et API down
            // Tentative de geocoding pour récupérer les coordonnées
            echo "  🔍 Tentative de géolocalisation...\n";
            
            $coords = geocodeHackerspace($name);

            if ($coords !== null) {
                // Geocoding réussi ! Ajouter en tant que space static
                $cache['spaces'][] = [
                    'name' => $name,
                    'state' => 'static',
                    'lat' => $coords['lat'],
                    'lon' => $coords['lon'],
                    'city' => '',
                    'country' => '',
                    'address' => $coords['display_name'] ?? '',
                    'url' => $directory[$name] ?? '',
                    'logo' => null,
                    'lastchange' => null,
                    'last_seen' => null
                ];
                $cache['stats']['static']++;
                echo "  🔵 Géolocalisé: {$coords['lat']}, {$coords['lon']}\n";
            } else {
                // Geocoding échoué
                $downSpaceNames[] = $name; // STOCKER le nom pour comparaison avec mapall
                $cache['stats']['down']++;
                if ($isFirstRun) {
                    echo "  ⚠️  API indisponible + géolocalisation échouée\n";
                } else {
                    echo "  ⚠️  API indisponible + géolocalisation échouée (nouveau)\n";
                }
            }

            // Respecter le rate limit de Nominatim (1 req/sec) — doit s'appliquer
            // après CHAQUE tentative, succès ou échec, sinon deux géolocalisations
            // ratées d'affilée dépassent la limite et font bannir temporairement
            // l'IP par Nominatim, ce qui fait échouer toutes les suivantes en cascade.
            sleep(1);
        }
        continue;
    }
    
    // Vérifier si on a les coordonnées
    if (!isset($spaceData['location']['lat']) || !isset($spaceData['location']['lon'])) {
        // Pas de coordonnées mais peut-être dans l'ancien cache ?
        if (isset($oldCache[$name]) && isset($oldCache[$name]['lat'])) {
            // Garder les anciennes coordonnées
            $oldSpace = $oldCache[$name];
            $oldSpace['state'] = 'unknown';
            $oldSpace['last_seen'] = date('c'); // On a une réponse mais sans coords
            $cache['spaces'][] = $oldSpace;
            $cache['stats']['unknown']++;
            echo "  📍 Pas de coordonnées mais gardé en cache\n";
        } else {
            $cache['stats']['no_coords']++;
            echo "  📍 Pas de coordonnées\n";
        }
        continue;
    }
    
    // Déterminer l'état (ouvert/fermé/limité)
    $state = 'closed'; // Par défaut
    
    if (isset($spaceData['state']['open'])) {
        if ($spaceData['state']['open']) {
            // Extension : open_to_visitors=false → ouvert aux membres uniquement
            $openToVisitors = $spaceData['state']['open_to_visitors']
                ?? $spaceData['open_to_visitors']
                ?? null;
            $state = ($openToVisitors === false) ? 'limited' : 'open';
        } else {
            $state = 'closed';
        }
    }
    
    $cache['stats'][$state]++;
    
    // Extraire les informations essentielles
    $spaceInfo = [
        'name' => $name,
        'state' => $state,
        'lat' => floatval($spaceData['location']['lat']),
        'lon' => floatval($spaceData['location']['lon']),
        'city' => $spaceData['location']['city'] ?? '',
        'country' => $spaceData['location']['country'] ?? '',
        'address' => $spaceData['location']['address'] ?? '',
        'message' => $spaceData['state']['message'] ?? '',
        'url' => (function() use ($spaceData, $apiUrl, $bannedDomains) {
            $url = $spaceData['url'] ?? $apiUrl;
            if ($url) {
                $host = parse_url($url, PHP_URL_HOST);
                $host = preg_replace('/^www\./', '', $host ?? '');
                if (isset($bannedDomains[$host])) {
                    echo "  ⚠️  URL bannie ($host): " . $bannedDomains[$host] . "\n";
                    return '';
                }
            }
            return $url;
        })(),
        'logo' => $spaceData['logo'] ?? null,
        'lastchange' => $spaceData['state']['lastchange'] ?? null,
        'last_seen' => date('c') // Timestamp de la dernière réponse réussie
    ];

    // Compléter city/country si absents en réutilisant l'ancien cache
    // Persistance city/country depuis l'ancien cache
    if ($spaceInfo['city'] === '') $spaceInfo['city'] = $oldCache[$name]['city'] ?? '';
    if ($spaceInfo['country'] === '') $spaceInfo['country'] = $oldCache[$name]['country'] ?? '';

    // Fallback : extraire la ville depuis l'adresse texte libre SpaceAPI
    // Format courant : "Rue X, Ville, Pays" ou "Ville, Pays" ou "Street, City"
    if ($spaceInfo['city'] === '' && $spaceInfo['address'] !== '') {
        $parts = array_map('trim', explode(',', $spaceInfo['address']));
        $parts = array_values(array_filter($parts)); // virer les vides
        if (count($parts) >= 2) {
            // Dernière partie souvent pays, avant-dernière souvent ville
            // Heuristique : on prend l'avant-dernière si elle ne ressemble pas à un code postal
            $candidate = $parts[count($parts) - 2];
            if (!preg_match('/^\d{4,}/', $candidate)) {
                $spaceInfo['city'] = $candidate;
            }
        }
    }

    $cache['spaces'][] = $spaceInfo;
    
    $stateEmoji = $state === 'open' ? '🟢' : ($state === 'limited' ? '🟡' : '🔴');
    echo "  $stateEmoji État: $state | Coords: {$spaceInfo['lat']}, {$spaceInfo['lon']}\n";
}

// DEBUG : Afficher la liste des spaces 'down'
if (!empty($downSpaceNames)) {
    echo "\n┌─────────────────────────────────────────────────────────────┐\n";
    echo "│ 🔍 DEBUG : Liste des " . count($downSpaceNames) . " spaces 'down' (API indisponible)      │\n";
    echo "├─────────────────────────────────────────────────────────────┤\n";
    foreach ($downSpaceNames as $downName) {
        echo sprintf("│ • %-58s│\n", $downName);
    }
    echo "└─────────────────────────────────────────────────────────────┘\n";
}

echo "\n╔════════════════════════════════════════════════════════════════╗\n";
echo "║  📥 ÉTAPE 4/4 : Source statique mapall.space                  ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n";
echo "\n";

// Fetch source statique directement (pas besoin de proxy CORS en PHP)
$staticUrl = 'https://mapall.space/wiki.json';
$staticData = file_get_contents($staticUrl); // Enlever @ pour voir les erreurs

if ($staticData !== false) {
    echo "✅ Données brutes récupérées: " . strlen($staticData) . " bytes\n";
    
    $staticJson = json_decode($staticData, true);
    
    if ($staticJson === null) {
        echo "❌ ERREUR JSON decode: " . json_last_error_msg() . "\n";
    } else {
        // C'est un GeoJSON FeatureCollection
        if (isset($staticJson['type']) && $staticJson['type'] === 'FeatureCollection' && isset($staticJson['features'])) {
            $staticSpaces = $staticJson['features'];
            echo "✅ GeoJSON parsé: " . count($staticSpaces) . " features\n\n";
        } else {
            echo "⚠️  Format JSON non reconnu\n";
            $staticSpaces = [];
        }
    }
    
    if (!empty($staticSpaces)) {
        echo "✅ Source statique chargée: " . count($staticSpaces) . " spaces\n\n";
        
        $addedFromStatic = 0;
        $duplicatesFound = 0;
        
        foreach ($staticSpaces as $feature) {
            // Vérifier qu'on a les données minimales (GeoJSON format)
            if (!isset($feature['geometry']['coordinates']) || !isset($feature['properties']['name'])) {
                continue;
            }
            
            // GeoJSON : coordinates = [longitude, latitude]
            $coords = $feature['geometry']['coordinates'];
            $staticLon = floatval($coords[0]);
            $staticLat = floatval($coords[1]);
            
            // Ignorer les coordonnées invalides [0, 0]
            if ($staticLon == 0 && $staticLat == 0) {
                continue;
            }
            
            $properties = $feature['properties'];
            $staticName = $properties['name'];

            // Check banlist avant tout traitement mapall
            if (isset($bannedSpaces[$staticName])) {
                echo "  🚫 Banni (mapall): " . $bannedSpaces[$staticName] . "\n";
                $cache['stats']['banned']++;
                $bannedList[] = ['name' => $staticName, 'reason' => $bannedSpaces[$staticName], 'source' => 'mapall'];
                continue;
            }
            
            // PRIORITÉ 1 : Si c'est un space "down" de SpaceAPI, l'ajouter en bleu directement
            // Comparaison fuzzy (pas exacte) car les noms peuvent varier légèrement
            $isDownSpace = false;
            foreach ($downSpaceNames as $downName) {
                $nameSimilar = (
                    strtolower($downName) === strtolower($staticName) ||
                    stripos($downName, $staticName) !== false ||
                    stripos($staticName, $downName) !== false
                );
                
                if ($nameSimilar) {
                    $isDownSpace = true;
                    break;
                }
            }
            
            if ($isDownSpace) {
                $cache['spaces'][] = [
                    'name' => $staticName,
                    'state' => 'static',
                    'lat' => $staticLat,
                    'lon' => $staticLon,
                    'city' => $properties['city'] ?? '',
                    'country' => $properties['country'] ?? '',
                    'address' => '',
                    'url' => $properties['url'] ?? 'https://mapall.space',
                    'logo' => null,
                    'lastchange' => null,
                    'last_seen' => null
                ];
                $addedFromStatic++;
                echo "  🔵 Récupéré depuis mapall (était down): $staticName\n";
                continue; // Passer au suivant
            }
            
            // PRIORITÉ 2 : Dédoublonnage normal pour les autres
            $isDuplicate = false;
            
            foreach ($cache['spaces'] as $existingSpace) {
                // Distance géographique (formule simplifiée)
                $latDiff = abs($existingSpace['lat'] - $staticLat);
                $lonDiff = abs($existingSpace['lon'] - $staticLon);
                $distance = sqrt(pow($latDiff, 2) + pow($lonDiff, 2));
                
                // Similarité du nom (simple comparaison)
                $nameSimilar = (
                    strtolower($existingSpace['name']) === strtolower($staticName) ||
                    stripos($existingSpace['name'], $staticName) !== false ||
                    stripos($staticName, $existingSpace['name']) !== false
                );
                
                // Si distance < 0.01° (~1km) OU nom similaire → doublon
                if ($distance < 0.01 || $nameSimilar) {
                    $isDuplicate = true;
                    $duplicatesFound++;
                    break;
                }
            }
            
            if (!$isDuplicate) {
                // Ajouter ce space statique
                $cache['spaces'][] = [
                    'name' => $staticName,
                    'state' => 'static', // Nouvel état pour source statique
                    'lat' => $staticLat,
                    'lon' => $staticLon,
                    'city' => $properties['city'] ?? '',
                    'country' => $properties['country'] ?? '',
                    'address' => '',
                    'url' => $properties['url'] ?? 'https://mapall.space',
                    'logo' => null,
                    'lastchange' => null,
                    'last_seen' => null
                ];
                $addedFromStatic++;
                echo "  🔵 Unique à mapall (pas dans SpaceAPI): $staticName\n";
            }
        }
        
        echo "\n┌─────────────────────────────────────────────────────────────┐\n";
        echo "│ 📊 Résumé intégration mapall.space                          │\n";
        echo "├─────────────────────────────────────────────────────────────┤\n";
        echo sprintf("│ • Récupérés de mapall (🔵): %-32s│\n", $addedFromStatic);
        echo sprintf("│ • Doublons évités: %-40s│\n", $duplicatesFound);
        echo sprintf("│ • Restent perdus: %-39s│\n", count($downSpaceNames));
        echo "└─────────────────────────────────────────────────────────────┘\n";
        echo "\n";
        
        // Ajouter stat pour mapall (additionner avec les geocoded)
        $cache['stats']['static'] += $addedFromStatic;
    }
} else {
    echo "⚠️  Impossible de charger la source statique\n";
}



echo "\n╔════════════════════════════════════════════════════════════════╗\n";
echo "║                    ✅ CACHE GÉNÉRÉ AVEC SUCCÈS                 ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n";
echo "\n";
echo "┌─────────────────────────────────────────────────────────────┐\n";
echo "│ 📊 STATISTIQUES FINALES                                      │\n";
echo "├─────────────────────────────────────────────────────────────┤\n";
echo sprintf("│ Total directory SpaceAPI: %-34s│\n", $cache['total_spaces']);
echo "├─────────────────────────────────────────────────────────────┤\n";
echo sprintf("│ 🟢 Ouverts: %-48s│\n", $cache['stats']['open']);
echo sprintf("│ 🟡 Ouverts aux membres (limited): %-26s│\n", $cache['stats']['limited']);
echo sprintf("│ 🔴 Fermés: %-49s│\n", $cache['stats']['closed']);
echo sprintf("│ ⚪ État inconnu (API down < 30j): %-27s│\n", $cache['stats']['unknown']);
echo sprintf("│ 🔵 Géolocalisés + mapall: %-35s│\n", $cache['stats']['static']);
echo sprintf("│ ⚠️  Perdus (API down + géoloc échouée): %-23s│\n", $cache['stats']['down']);
echo sprintf("│ 🗑️  Expirés (> 30j): %-40s│\n", $cache['stats']['expired']);
echo sprintf("│ 🚫 Bannis: %-51s│\n", $cache['stats']['banned']);
echo sprintf("│ 📍 Sans coordonnées: %-40s│\n", $cache['stats']['no_coords']);
echo "├─────────────────────────────────────────────────────────────┤\n";
echo sprintf("│ 🗺️  TOTAL AFFICHABLES SUR LA CARTE: %-26s│\n", count($cache['spaces']));
echo "└─────────────────────────────────────────────────────────────┘\n";
echo "\n";

// Sauvegarder le cache
$jsonCache = json_encode($cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
file_put_contents($cacheFile, $jsonCache);

echo "💾 Cache sauvegardé: $cacheFile\n";
$finalSize = filesize($cacheFile);
echo "📦 Taille: " . round($finalSize / 1024, 2) . " KB";
if ($finalSize > 5 * 1024 * 1024) {
    echo " ⚠️  ATTENTION: cache > 5 Mo, vérifier une possible corruption";
}
echo "\n";
echo "\n";
// ── Sauvegarder l'historique des runs ─────────────────────────────
$historyFile = __DIR__ . '/run_history.json';
$maxRuns = 1008; // 7 jours × 144 runs/jour (cron toutes les 10 min)

$history = [];
if (file_exists($historyFile)) {
    $existing = json_decode(file_get_contents($historyFile), true);
    if ($existing && isset($existing['runs'])) {
        $history = $existing['runs'];
    }
}

$runEntry = [
    'ts'       => $runTimestamp,
    'dur'      => round(microtime(true) - $runStart),
    'stats'    => $cache['stats'],
    'down'     => $downSpaceNames,
    'expired'  => $expiredSpaces,
    'unknown'  => array_map(fn($s) => ['name' => $s['name'], 'days' => $s['days'], 'last_seen' => $s['last_seen']], $unknownSpaces),
    'banned'   => $bannedList,
    'total'    => count($cache['spaces']),
];

array_unshift($history, $runEntry); // plus récent en premier
$history = array_slice($history, 0, $maxRuns);

file_put_contents($historyFile, json_encode(['runs' => $history], JSON_UNESCAPED_UNICODE));
echo "📈 Historique mis à jour: " . count($history) . " run(s) conservés\n\n";

echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║                         🎉 TERMINÉ !                           ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n";
echo "\n";
