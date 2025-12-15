<?php
//Cache "Intelligent" pour Hackerspaces World Domination

// Gestion de la limite d'execution
set_time_limit(300);        // 5 minutes
ini_set('max_execution_time', '300');

// Configuration
$directoryUrl = 'https://raw.githubusercontent.com/SpaceApi/directory/refs/heads/master/directory.json';
$cacheFile = __DIR__ . '/hackerspaces_cache.json';
$cacheDir = __DIR__;
$timeout = 5; // Timeout en secondes pour chaque requête
$maxConcurrent = 10; // Nombre maximum de requêtes simultanées
$expirationDays = 30; // Supprimer les spaces qui ne répondent plus depuis X jours

// Créer le dossier cache s'il n'existe pas
if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0755, true);
}

echo "\n";
echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║          🌍 HACKERSPACES WORLD DOMINATION - CACHE              ║\n";
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
        'closed' => 0,
        'unknown' => 0,
        'static' => 0,
        'down' => 0,
        'expired' => 0,
        'no_coords' => 0
    ],
    'spaces' => []
];

// Garder la liste des noms "down" pour comparaison avec mapall
$downSpaceNames = [];

/**
 * Geocode un hackerspace via Nominatim (OSM)
 * Retourne ['lat' => float, 'lon' => float] ou null
 */
function geocodeHackerspace($name) {
    // Nettoyer le nom pour la recherche
    $searchQuery = $name . ' hackerspace';
    $searchQuery = urlencode($searchQuery);
    
    // Nominatim API (gratuit, rate limit: 1 req/sec !!!)
    $url = "https://nominatim.openstreetmap.org/search?q={$searchQuery}&format=json&limit=1";
    
    // Headers requis par Nominatim
    $context = stream_context_create([
        'http' => [
            'header' => "User-Agent: HSWD-Globe/1.0\r\n",
            'timeout' => 5
        ]
    ]);
    
    $response = @file_get_contents($url, false, $context);
    
    if ($response === false) {
        return null;
    }
    
    $data = json_decode($response, true);
    
    if (empty($data) || !isset($data[0]['lat']) || !isset($data[0]['lon'])) {
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
            'header' => "User-Agent: HSWD hswd.iooner.io/1.1\r\n"
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
                CURLOPT_SSL_VERIFYPEER => false // Pour éviter les problèmes SSL
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

// Fonction pour décaler automatiquement les points en collision
function autoOffsetCollisions(&$spaces, $threshold = 0.001, $radius = 0.003) {
    $clusters = [];
    $processed = [];
    
    echo "\n🔍 Détection des collisions géographiques...\n";
    
    // Détecter les clusters de points proches
    for ($i = 0; $i < count($spaces); $i++) {
        if (isset($processed[$i])) continue;
        
        $cluster = [$i];
        for ($j = $i + 1; $j < count($spaces); $j++) {
            if (isset($processed[$j])) continue;
            
            $dist = sqrt(
                pow($spaces[$i]['lat'] - $spaces[$j]['lat'], 2) +
                pow($spaces[$i]['lon'] - $spaces[$j]['lon'], 2)
            );
            
            if ($dist < $threshold) {
                $cluster[] = $j;
                $processed[$j] = true;
            }
        }
        
        // Si on a trouvé un cluster (2+ points proches)
        if (count($cluster) > 1) {
            // Calculer le centre du cluster
            $centerLat = array_sum(array_map(fn($idx) => $spaces[$idx]['lat'], $cluster)) / count($cluster);
            $centerLon = array_sum(array_map(fn($idx) => $spaces[$idx]['lon'], $cluster)) / count($cluster);
            
            // Disposer les points en cercle autour du centre
            foreach ($cluster as $n => $idx) {
                $angle = (2 * M_PI * $n) / count($cluster);
                $spaces[$idx]['lat'] = $centerLat + $radius * cos($angle);
                $spaces[$idx]['lon'] = $centerLon + $radius * sin($angle);
            }
            
            $names = implode(', ', array_map(fn($idx) => $spaces[$idx]['name'], $cluster));
            echo "  🔧 Cluster de " . count($cluster) . " points décalés: $names\n";
            
            $clusters[] = [
                'count' => count($cluster),
                'center' => [$centerLat, $centerLon],
                'names' => array_map(fn($idx) => $spaces[$idx]['name'], $cluster)
            ];
        }
        
        $processed[$i] = true;
    }
    
    if (count($clusters) > 0) {
        echo "✅ " . count($clusters) . " cluster(s) traité(s)\n";
    } else {
        echo "✅ Aucune collision détectée\n";
    }
    
    return $clusters;
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
    
    // Récupérer les données depuis le résultat parallèle
    $spaceData = $allSpaceData[$name] ?? null;
    
    if ($spaceData === null) {
        // API down - vérifier si on a des données en cache
        if (isset($oldCache[$name])) {
            $oldSpace = $oldCache[$name];
            
            // Vérifier si le space n'est pas expiré (> 30 jours)
            $lastSeen = isset($oldSpace['last_seen']) ? strtotime($oldSpace['last_seen']) : 0;
            $daysSinceLastSeen = ($lastSeen > 0) ? (time() - $lastSeen) / 86400 : 999;
            
            if ($daysSinceLastSeen <= $expirationDays) {
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
                    echo "  ⚪ Gardé en cache (API down, dernière réponse: " . round($daysSinceLastSeen) . "j)\n";
                }
            } else {
                // Expiré
                $cache['stats']['expired']++;
                echo "  🗑️  Expiré (pas de réponse depuis " . round($daysSinceLastSeen) . " jours)\n";
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
                    'url' => $directory[$name] ?? '',
                    'logo' => null,
                    'lastchange' => null,
                    'last_seen' => null
                ];
                $cache['stats']['static']++;
                echo "  🔵 Géolocalisé: {$coords['lat']}, {$coords['lon']}\n";
                
                // Respecter le rate limit de Nominatim (1 req/sec)
                sleep(1);
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
    
    // Déterminer l'état (ouvert/fermé)
    $state = 'closed'; // Par défaut
    
    if (isset($spaceData['state']['open'])) {
        $state = $spaceData['state']['open'] ? 'open' : 'closed';
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
        'url' => $spaceData['url'] ?? $apiUrl,
        'logo' => $spaceData['logo'] ?? null,
        'lastchange' => $spaceData['state']['lastchange'] ?? null,
        'last_seen' => date('c') // Timestamp de la dernière réponse réussie
    ];
    
    $cache['spaces'][] = $spaceInfo;
    
    $stateEmoji = $state === 'open' ? '🟢' : '🔴';
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
            echo "✅ GeoJSON parsé: " . count($staticSpaces) . " features\n";
            
            // Debug: afficher la structure du premier élément
            if (count($staticSpaces) > 0) {
                echo "📋 Structure du premier feature:\n";
                $first = $staticSpaces[0];
                if (isset($first['properties']['name'])) echo "  - name: " . $first['properties']['name'] . "\n";
                if (isset($first['geometry']['coordinates'])) {
                    $coords = $first['geometry']['coordinates'];
                    echo "  - coordinates: [" . $coords[0] . ", " . $coords[1] . "] (lon, lat)\n";
                }
            }
            echo "\n";
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

// Traiter les collisions géographiques
$clusters = autoOffsetCollisions($cache['spaces'], 0.05, 0.01);

// Sauvegarder les informations de clusters si présents
if (count($clusters) > 0) {
    $clustersFile = __DIR__ . '/collision_clusters.json';
    file_put_contents($clustersFile, json_encode($clusters, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    echo "💾 Informations de clusters sauvegardées: $clustersFile\n";
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
echo sprintf("│ 🔴 Fermés: %-49s│\n", $cache['stats']['closed']);
echo sprintf("│ ⚪ État inconnu (API down < 30j): %-27s│\n", $cache['stats']['unknown']);
echo sprintf("│ 🔵 Géolocalisés + mapall: %-35s│\n", $cache['stats']['static']);
echo sprintf("│ ⚠️  Perdus (API down + géoloc échouée): %-23s│\n", $cache['stats']['down']);
echo sprintf("│ 🗑️  Expirés (> 30j): %-40s│\n", $cache['stats']['expired']);
echo sprintf("│ 📍 Sans coordonnées: %-40s│\n", $cache['stats']['no_coords']);
echo "├─────────────────────────────────────────────────────────────┤\n";
echo sprintf("│ 🗺️  TOTAL AFFICHABLES SUR LA CARTE: %-26s│\n", count($cache['spaces']));
echo "└─────────────────────────────────────────────────────────────┘\n";
echo "\n";

// Sauvegarder le cache
$jsonCache = json_encode($cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
file_put_contents($cacheFile, $jsonCache);

echo "💾 Cache sauvegardé: $cacheFile\n";
echo "📦 Taille: " . round(strlen($jsonCache) / 1024, 2) . " KB\n";
echo "\n";
echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║                         🎉 TERMINÉ !                           ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n";
echo "\n";
