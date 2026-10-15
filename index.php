<?php
session_start();

// Configuration - Replace with your OSM OAuth 2.0 Credentials
$clientId     = '__';
$clientSecret = '___;

// Determine base URL dynamically
$protocol    = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http";
$currentUrl  = $protocol . "://" . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
$baseUrl     = strtok($currentUrl, '?');
$redirectUri = 'https://bisikbekasi.rf.gd/o/index.php?action=callback';

// Ensure basic storage directories exist
if (!file_exists(__DIR__ . '/post')) { @mkdir(__DIR__ . '/post', 0777, true); }
if (!file_exists(__DIR__ . '/img'))  { @mkdir(__DIR__ . '/img',  0777, true); }

$dir = __DIR__ . '/post';
$userIndexDir = $dir . '/index_user';
$objIndexDir = $dir . '/index_object';

if (!file_exists($userIndexDir)) { @mkdir($userIndexDir, 0777, true); }
if (!file_exists($objIndexDir)) { @mkdir($objIndexDir, 0777, true); }

// --- AUTHENTICATION ROUTER ---
if (isset($_GET['action'])) {
    if ($_GET['action'] === 'login') {
        $_SESSION['oauth2state'] = bin2hex(random_bytes(16));
        $params = [
            'response_type' => 'code',
            'client_id'     => $clientId,
            'redirect_uri'  => $redirectUri,
            'scope'         => 'read_prefs',
            'state'         => $_SESSION['oauth2state']
        ];
        header('Location: https://www.openstreetmap.org/oauth2/authorize?' . http_build_query($params));
        exit;
    }
    
    if ($_GET['action'] === 'callback') {
        if (empty($_GET['state']) || $_GET['state'] !== ($_SESSION['oauth2state'] ?? '')) {
            unset($_SESSION['oauth2state']);
            exit('CSRF state verification failed.');
        }
        
        $code = $_GET['code'] ?? '';
        $ch = curl_init('https://www.openstreetmap.org/oauth2/token');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'grant_type'    => 'authorization_code',
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'code'          => $code,
            'redirect_uri'  => $redirectUri,
        ]));
        $tokenData = json_decode(curl_exec($ch), true);
        curl_close($ch);
        
        if (!isset($tokenData['access_token'])) {
            exit('Failed to obtain OSM access token.');
        }
        
        $ch = curl_init('https://api.openstreetmap.org/api/0.6/user/details.json');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $tokenData['access_token'],
            'User-Agent: OSM-Foursquare-Clone'
        ]);
        $userData = json_decode(curl_exec($ch), true);
        curl_close($ch);
        
        if (isset($userData['user'])) {
            $_SESSION['osm_user'] = [
                'id'           => (string)$userData['user']['id'],
                'display_name' => $userData['user']['display_name']
            ];
        }
        header('Location: ' . $baseUrl);
        exit;
    }
    
    if ($_GET['action'] === 'logout') {
        unset($_SESSION['osm_user']);
        header('Location: ' . $baseUrl);
        exit;
    }
}

// --- REST API ENDPOINTS ---
if (isset($_GET['api'])) {
    header('Content-Type: application/json');
    $api = $_GET['api'];

    $oldUserIndex = $dir . '/index_user.json';
    if (file_exists($oldUserIndex)) {
        $data = json_decode(file_get_contents($oldUserIndex), true) ?: [];
        foreach ($data as $uId => $posts) {
            file_put_contents($userIndexDir . '/' . $uId . '.json', json_encode($posts, JSON_PRETTY_PRINT));
        }
        unlink($oldUserIndex);
    }

    $oldObjIndex = $dir . '/index_object.json';
    if (file_exists($oldObjIndex)) {
        $data = json_decode(file_get_contents($oldObjIndex), true) ?: [];
        foreach ($data as $oKey => $posts) {
            $safeKey = str_replace(':', '_', $oKey);
            file_put_contents($objIndexDir . '/' . $safeKey . '.json', json_encode($posts, JSON_PRETTY_PRINT));
        }
        unlink($oldObjIndex);
    }
    
    if ($api === 'upload_image') {
        if (!isset($_SESSION['osm_user'])) {
            echo json_encode(['error' => 'Unauthorized']);
            exit;
        }
        if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            if (in_array($ext, $allowed)) {
                $filename = 'img_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                $target = __DIR__ . '/img/' . $filename;
                if (move_uploaded_file($_FILES['image']['tmp_name'], $target)) {
                    echo json_encode(['success' => true, 'url' => 'img/' . $filename]);
                    exit;
                }
            }
        }
        echo json_encode(['error' => 'Failed to upload image']);
        exit;
    }
    
    if ($api === 'create_post') {
        if (!isset($_SESSION['osm_user'])) {
            echo json_encode(['error' => 'Unauthorized']);
            exit;
        }
        $dataInput = json_decode(file_get_contents('php://input'), true);
        if (empty($dataInput['content']) || empty($dataInput['osm_id']) || empty($dataInput['osm_type'])) {
            echo json_encode(['error' => 'Missing required fields']);
            exit;
        }
        
        $user = $_SESSION['osm_user'];
        $metaFile = $dir . '/meta.json';
        $meta = ['current_chunk' => 1, 'total_posts' => 0];
        if (file_exists($metaFile)) {
            $meta = json_decode(file_get_contents($metaFile), true) ?: $meta;
        }
        
        $chunkNum = $meta['current_chunk'];
        $chunkFile = $dir . "/chunk_{$chunkNum}.json";
        $posts = file_exists($chunkFile) ? (json_decode(file_get_contents($chunkFile), true) ?: []) : [];
        
        if (count($posts) >= 100) {
            $chunkNum++;
            $meta['current_chunk'] = $chunkNum;
            $chunkFile = $dir . "/chunk_{$chunkNum}.json";
            $posts = [];
        }
        
        $postId = 'post_' . time() . '_' . bin2hex(random_bytes(3));
        $newPost = [
            'id'         => $postId,
            'user_id'    => $user['id'],
            'user_name'  => $user['display_name'],
            'osm_type'   => $dataInput['osm_type'],
            'osm_id'     => (string)$dataInput['osm_id'],
            'osm_name'   => $dataInput['osm_name'] ?? 'Unnamed Location',
            'lat'        => (float)($dataInput['lat'] ?? 0),
            'lng'        => (float)($dataInput['lng'] ?? 0),
            'content'    => trim($dataInput['content']),
            'created_at' => time()
        ];
        
        $posts[] = $newPost;
        file_put_contents($chunkFile, json_encode($posts, JSON_PRETTY_PRINT));
        
        $meta['total_posts']++;
        file_put_contents($metaFile, json_encode($meta, JSON_PRETTY_PRINT));
        
        $userFile = $userIndexDir . '/' . $user['id'] . '.json';
        $userPosts = file_exists($userFile) ? (json_decode(file_get_contents($userFile), true) ?: []) : [];
        array_unshift($userPosts, $newPost);
        file_put_contents($userFile, json_encode($userPosts, JSON_PRETTY_PRINT));
        
        $safeObjKey = $dataInput['osm_type'] . '_' . $dataInput['osm_id'];
        $objFile = $objIndexDir . '/' . $safeObjKey . '.json';
        $objPosts = file_exists($objFile) ? (json_decode(file_get_contents($objFile), true) ?: []) : [];
        array_unshift($objPosts, $newPost);
        file_put_contents($objFile, json_encode($objPosts, JSON_PRETTY_PRINT));
        
        echo json_encode(['success' => true, 'post' => $newPost]);
        exit;
    }

    if ($api === 'edit_post') {
        if (!isset($_SESSION['osm_user'])) {
            echo json_encode(['error' => 'Unauthorized']);
            exit;
        }
        $dataInput = json_decode(file_get_contents('php://input'), true);
        $postId = $dataInput['post_id'] ?? '';
        $content = trim($dataInput['content'] ?? '');
        
        if (empty($postId) || empty($content)) {
            echo json_encode(['error' => 'Missing post ID or content']);
            exit;
        }
        
        $userId = (string)$_SESSION['osm_user']['id'];
        $metaFile = $dir . '/meta.json';
        $found = false;
        $targetPost = null;

        if (file_exists($metaFile)) {
            $meta = json_decode(file_get_contents($metaFile), true);
            for ($i = $meta['current_chunk']; $i >= 1; $i--) {
                $cFile = $dir . "/chunk_{$i}.json";
                if (file_exists($cFile)) {
                    $posts = json_decode(file_get_contents($cFile), true) ?: [];
                    foreach ($posts as $idx => $p) {
                        if ($p['id'] === $postId) {
                            if ((string)$p['user_id'] !== $userId) {
                                echo json_encode(['error' => 'Forbidden: You can only edit your own posts']);
                                exit;
                            }
                            $posts[$idx]['content'] = $content;
                            $posts[$idx]['updated_at'] = time();
                            $targetPost = $posts[$idx];
                            file_put_contents($cFile, json_encode($posts, JSON_PRETTY_PRINT));
                            $found = true;
                            break 2;
                        }
                    }
                }
            }
        }

        if (!$found || !$targetPost) {
            echo json_encode(['error' => 'Post not found or unauthorized']);
            exit;
        }

        $userFile = $userIndexDir . '/' . $userId . '.json';
        if (file_exists($userFile)) {
            $userPosts = json_decode(file_get_contents($userFile), true) ?: [];
            foreach ($userPosts as $k => $p) {
                if ($p['id'] === $postId) {
                    $userPosts[$k]['content'] = $content;
                    $userPosts[$k]['updated_at'] = time();
                    break;
                }
            }
            file_put_contents($userFile, json_encode($userPosts, JSON_PRETTY_PRINT));
        }

        $safeObjKey = $targetPost['osm_type'] . '_' . $targetPost['osm_id'];
        $objFile = $objIndexDir . '/' . $safeObjKey . '.json';
        if (file_exists($objFile)) {
            $objPosts = json_decode(file_get_contents($objFile), true) ?: [];
            foreach ($objPosts as $k => $p) {
                if ($p['id'] === $postId) {
                    $objPosts[$k]['content'] = $content;
                    $objPosts[$k]['updated_at'] = time();
                    break;
                }
            }
            file_put_contents($objFile, json_encode($objPosts, JSON_PRETTY_PRINT));
        }

        echo json_encode(['success' => true, 'post' => $targetPost]);
        exit;
    }

    if ($api === 'delete_post') {
        if (!isset($_SESSION['osm_user'])) {
            echo json_encode(['error' => 'Unauthorized']);
            exit;
        }
        $dataInput = json_decode(file_get_contents('php://input'), true);
        $postId = $dataInput['post_id'] ?? '';
        
        if (empty($postId)) {
            echo json_encode(['error' => 'Missing post ID']);
            exit;
        }

        $userId = (string)$_SESSION['osm_user']['id'];
        $metaFile = $dir . '/meta.json';
        $found = false;
        $targetPost = null;

        if (file_exists($metaFile)) {
            $meta = json_decode(file_get_contents($metaFile), true);
            for ($i = $meta['current_chunk']; $i >= 1; $i--) {
                $cFile = $dir . "/chunk_{$i}.json";
                if (file_exists($cFile)) {
                    $posts = json_decode(file_get_contents($cFile), true) ?: [];
                    foreach ($posts as $idx => $p) {
                        if ($p['id'] === $postId) {
                            if ((string)$p['user_id'] !== $userId) {
                                echo json_encode(['error' => 'Forbidden: You can only delete your own posts']);
                                exit;
                            }
                            $targetPost = $p;
                            array_splice($posts, $idx, 1);
                            file_put_contents($cFile, json_encode($posts, JSON_PRETTY_PRINT));
                            $found = true;
                            if (isset($meta['total_posts']) && $meta['total_posts'] > 0) {
                                $meta['total_posts']--;
                                file_put_contents($metaFile, json_encode($meta, JSON_PRETTY_PRINT));
                            }
                            break 2;
                        }
                    }
                }
            }
        }

        if (!$found || !$targetPost) {
            echo json_encode(['error' => 'Post not found or unauthorized']);
            exit;
        }

        $userFile = $userIndexDir . '/' . $userId . '.json';
        if (file_exists($userFile)) {
            $userPosts = json_decode(file_get_contents($userFile), true) ?: [];
            $userPosts = array_values(array_filter($userPosts, function($p) use ($postId) {
                return $p['id'] !== $postId;
            }));
            if (empty($userPosts)) unlink($userFile);
            else file_put_contents($userFile, json_encode($userPosts, JSON_PRETTY_PRINT));
        }

        $safeObjKey = $targetPost['osm_type'] . '_' . $targetPost['osm_id'];
        $objFile = $objIndexDir . '/' . $safeObjKey . '.json';
        if (file_exists($objFile)) {
            $objPosts = json_decode(file_get_contents($objFile), true) ?: [];
            $objPosts = array_values(array_filter($objPosts, function($p) use ($postId) {
                return $p['id'] !== $postId;
            }));
            if (empty($objPosts)) unlink($objFile);
            else file_put_contents($objFile, json_encode($objPosts, JSON_PRETTY_PRINT));
        }

        echo json_encode(['success' => true]);
        exit;
    }
    
    if ($api === 'get_global_posts') {
        $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
        $limit  = isset($_GET['limit'])  ? (int)$_GET['limit']  : 10;
        
        $metaFile = $dir . '/meta.json';
        if (!file_exists($metaFile)) {
            echo json_encode(['posts' => [], 'has_more' => false]);
            exit;
        }
        
        $meta = json_decode(file_get_contents($metaFile), true);
        $allPosts = [];
        for ($i = $meta['current_chunk']; $i >= 1; $i--) {
            $cFile = $dir . "/chunk_{$i}.json";
            if (file_exists($cFile)) {
                $chunkData = json_decode(file_get_contents($cFile), true) ?: [];
                $allPosts = array_merge($allPosts, array_reverse($chunkData));
                if (count($allPosts) >= $offset + $limit + 1) break;
            }
        }
        
        $sliced = array_slice($allPosts, $offset, $limit);
        $hasMore = count($allPosts) > ($offset + $limit);
        echo json_encode(['posts' => $sliced, 'has_more' => $hasMore]);
        exit;
    }
    
    if ($api === 'get_user_posts') {
        $userId = $_GET['user_id'] ?? '';
        $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
        $limit  = isset($_GET['limit'])  ? (int)$_GET['limit']  : 10;
        
        $userFile = $userIndexDir . '/' . $userId . '.json';
        $userPosts = file_exists($userFile) ? (json_decode(file_get_contents($userFile), true) ?: []) : [];
        
        $sliced = array_slice($userPosts, $offset, $limit);
        $hasMore = count($userPosts) > ($offset + $limit);
        echo json_encode(['posts' => $sliced, 'has_more' => $hasMore]);
        exit;
    }
    
    if ($api === 'get_object_posts') {
        $osmType = $_GET['osm_type'] ?? '';
        $osmId   = $_GET['osm_id'] ?? '';
        $offset  = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
        $limit   = isset($_GET['limit'])  ? (int)$_GET['limit']  : 10;
        
        $safeObjKey = $osmType . '_' . $osmId;
        $objFile = $objIndexDir . '/' . $safeObjKey . '.json';
        $objectPosts = file_exists($objFile) ? (json_decode(file_get_contents($objFile), true) ?: []) : [];
        
        $sliced = array_slice($objectPosts, $offset, $limit);
        $hasMore = count($objectPosts) > ($offset + $limit);
        echo json_encode(['posts' => $sliced, 'has_more' => $hasMore]);
        exit;
    }
    
    if ($api === 'get_bbox_posts') {
        $minLat = (float)($_GET['min_lat'] ?? -90);
        $minLng = (float)($_GET['min_lng'] ?? -180);
        $maxLat = (float)($_GET['max_lat'] ?? 90);
        $maxLng = (float)($_GET['max_lng'] ?? 180);
        
        $results = [];
        if (file_exists($objIndexDir)) {
            $files = glob($objIndexDir . '/*.json');
            foreach ($files as $file) {
                $posts = json_decode(file_get_contents($file), true) ?: [];
                if (empty($posts)) continue;
                $latest = $posts[0];
                $lat = (float)$latest['lat'];
                $lng = (float)$latest['lng'];
                
                if ($lat >= $minLat && $lat <= $maxLat && $lng >= $minLng && $lng <= $maxLng) {
                    $osmKey = $latest['osm_type'] . ':' . $latest['osm_id'];
                    $results[] = [
                        'osm_key'     => $osmKey,
                        'osm_type'    => $latest['osm_type'],
                        'osm_id'      => $latest['osm_id'],
                        'osm_name'    => $latest['osm_name'],
                        'lat'         => $lat,
                        'lng'         => $lng,
                        'post_count'  => count($posts),
                        'latest_post' => $latest
                    ];
                }
            }
        }
        echo json_encode(['locations' => $results]);
        exit;
    }
    
    if ($api === 'get_single_post') {
        $postId = $_GET['post_id'] ?? '';
        $metaFile = $dir . '/meta.json';
        if (file_exists($metaFile)) {
            $meta = json_decode(file_get_contents($metaFile), true);
            for ($i = $meta['current_chunk']; $i >= 1; $i--) {
                $cFile = $dir . "/chunk_{$i}.json";
                if (file_exists($cFile)) {
                    $posts = json_decode(file_get_contents($cFile), true) ?: [];
                    foreach ($posts as $p) {
                        if ($p['id'] === $postId) {
                            echo json_encode(['post' => $p]);
                            exit;
                        }
                    }
                }
            }
        }
        echo json_encode(['error' => 'Post not found']);
        exit;
    }
}

// Server-side routing for full-interface standalone pages
$page = $_GET['page'] ?? '';
$userIdParam = $_GET['id'] ?? '';
$placeTypeParam = $_GET['type'] ?? '';
$placeIdParam = $_GET['id'] ?? '';

// If page is place, type is passed via 'type' and id via 'id'
if ($page === 'place') {
    $placeTypeParam = $_GET['type'] ?? '';
    $placeIdParam = $_GET['id'] ?? '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
<title>whathappened.here</title>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
<style>
  :root {
    --primary: #2563eb;
    --primary-hover: #1d4ed8;
    --danger: #ef4444;
    --danger-hover: #dc2626;
    --bg-main: #0f172a;
    --panel-bg: rgba(255, 255, 255, 0.94);
    --border-color: #e2e8f0;
    --text-primary: #0f172a;
    --text-muted: #64748b;
  }

  * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
  body, html { margin: 0; padding: 0; height: 100%; width: 100%; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; background: #0f172a; overflow: hidden; }

  #app-header {
    position: absolute; top: 0; left: 0; right: 0; height: 56px; background: rgba(15, 23, 42, 0.95); backdrop-filter: blur(12px); color: #fff;
    display: flex; align-items: center; justify-content: space-between; padding: 0 16px; z-index: 2000; border-bottom: 1px solid rgba(255,255,255,0.1);
  }
  #app-header h1 { font-size: 1.05rem; margin: 0; font-weight: 700; letter-spacing: -0.02em; display: flex; align-items: center; gap: 8px; color: #f8fafc; }
  #app-header h1 a { color: inherit; text-decoration: none; }
  .user-badge { display: flex; align-items: center; gap: 10px; font-size: 0.85rem; font-weight: 500; }
  .user-name { color: #93c5fd; cursor: pointer; text-decoration: none; transition: color 0.2s; }
  .user-name:hover { color: #ffffff; text-decoration: underline; }
  
  .btn-auth { background: var(--primary); color: #fff; border: none; padding: 7px 14px; border-radius: 8px; font-size: 0.8rem; font-weight: 600; text-decoration: none; cursor: pointer; transition: all 0.2s; box-shadow: 0 2px 4px rgba(37,99,235,0.2); }
  .btn-auth:hover { background: var(--primary-hover); }
  .btn-auth.logout { background: rgba(239, 68, 68, 0.2); color: #fca5a5; border: 1px solid rgba(239, 68, 68, 0.4); }
  .btn-auth.logout:hover { background: var(--danger); color: #fff; }
  
  .btn-clear { background: rgba(100, 116, 139, 0.3); color: #f8fafc; border: 1px solid rgba(255,255,255,0.2); padding: 7px 14px; border-radius: 8px; font-size: 0.8rem; font-weight: 600; cursor: pointer; transition: all 0.2s; margin-right: 4px; }
  .btn-clear:hover { background: #64748b; }

  #main-container { position: absolute; top: 56px; bottom: 0; left: 0; right: 0; }
  #map { height: 100%; width: 100%; z-index: 1; }

  /* Google Maps Style Unified Side Panel */
  .main-panel {
    position: absolute; top: 16px; left: 16px; z-index: 1000; width: 380px; max-width: calc(100vw - 32px);
    max-height: calc(100vh - 88px); display: flex; flex-direction: column; background: var(--panel-bg);
    backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px); border-radius: 16px; border: 1px solid rgba(255, 255, 255, 0.6);
    box-shadow: 0 12px 32px rgba(15, 23, 42, 0.15); overflow: hidden; transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
  }

  .search-header { padding: 12px 14px; background: rgba(255, 255, 255, 0.85); border-bottom: 1px solid var(--border-color); flex-shrink: 0; }
  .search-input-wrapper { position: relative; display: flex; align-items: center; gap: 6px; }
  .search-icon { position: absolute; left: 12px; width: 18px; height: 18px; fill: #64748b; pointer-events: none; }
  input[type="text"].search-input { 
    width: 100%; padding: 10px 12px 10px 38px; border: 1px solid #cbd5e1; border-radius: 10px; font-size: 0.9rem; outline: none; background: #f8fafc; transition: all 0.2s; flex: 1; color: var(--text-primary);
  }
  input[type="text"].search-input:focus { border-color: var(--primary); background: #ffffff; box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15); }
  
  .clear-search-btn {
    background: none; border: none; font-size: 1.2rem; cursor: pointer; color: var(--text-muted); padding: 0 6px; line-height: 1; border-radius: 6px; transition: color 0.2s;
  }
  .clear-search-btn:hover { color: var(--danger); }

  .hint-text { font-size: 0.72rem; color: var(--text-muted); margin-top: 6px; padding: 0 4px; font-weight: 500; }
  
  #search-results-wrapper { display: none; flex-direction: column; flex: 1; min-height: 0; background: #fff; }
  #scroll-area { padding: 8px 12px 12px 12px; overflow-y: auto; flex: 1; }
  #scroll-area::-webkit-scrollbar { width: 5px; }
  #scroll-area::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
  #results-container { display: flex; flex-direction: column; gap: 8px; }
  
  .main-panel.searching #search-results-wrapper { display: flex !important; }
  .main-panel.searching #panel-content-wrapper { flex: none; }
  .main-panel.searching .panel-body { display: none !important; }

  .result-item { padding: 10px 12px; border: 1px solid var(--border-color); border-radius: 10px; cursor: pointer; background: white; transition: all 0.2s ease; }
  .result-item:hover, .result-item.active { border-color: #93c5fd; background-color: #eff6ff; }
  .result-title { font-weight: 600; color: var(--text-primary); font-size: 0.88rem; margin-bottom: 2px; }
  .result-context { font-size: 0.78rem; color: var(--text-muted); line-height: 1.3; }
  #more-btn { width: 100%; margin-top: 8px; padding: 10px; background: #f1f5f9; color: #334155; border: 1px solid #e2e8f0; border-radius: 10px; cursor: pointer; font-size: 0.82rem; font-weight: 600; display: none; transition: background-color 0.2s; }
  #more-btn:hover { background: #e2e8f0; }
  
  .toggle-btn { background: none; border: none; padding: 6px; cursor: pointer; display: flex; align-items: center; justify-content: center; color: var(--text-muted); border-radius: 8px; }
  .toggle-btn:hover { background-color: #f1f5f9; }
  .toggle-btn svg { width: 20px; height: 20px; fill: currentColor; transition: transform 0.3s ease; }
  
  .main-panel.collapsed #search-results-wrapper,
  .main-panel.collapsed #panel-content-wrapper,
  .main-panel.collapsed .hint-text { display: none !important; }
  .main-panel.collapsed #toggle-icon { transform: rotate(180deg); }

  /* Navigation Tabs & Main Sidebar Body */
  #panel-content-wrapper { flex: 1; display: flex; flex-direction: column; overflow: hidden; }
  .tab-nav { display: flex; border-bottom: 1px solid var(--border-color); background: rgba(248, 250, 252, 0.9); flex-shrink: 0; }
  .tab-btn { flex: 1; padding: 6px 1px; text-align: center; border: none; background: none; font-weight: 600; font-size: 0.82rem; color: var(--text-muted); cursor: pointer; transition: all 0.2s; }
  .tab-btn.active { color: var(--primary); border-bottom: 2px solid var(--primary); background: #fff; }

  .panel-body { flex: 1; overflow-y: auto; padding: 12px; -webkit-overflow-scrolling: touch; }
  .panel-body::-webkit-scrollbar { width: 5px; }
  .panel-body::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }

  .place-item { padding: 10px 12px; border: 1px solid var(--border-color); border-radius: 10px; margin-bottom: 8px; background: #fff; cursor: pointer; transition: all 0.2s; }
  .place-item:hover, .place-item.active { border-color: var(--primary); background: #eff6ff; transform: translateY(-1px); }
  .place-title { font-weight: 600; font-size: 0.88rem; color: var(--text-primary); }
  .place-meta { font-size: 0.72rem; color: var(--text-muted); margin-top: 2px; }

  .osm-history-link { color: var(--primary); text-decoration: none; font-weight: 600; transition: text-decoration 0.2s; }
  .osm-history-link:hover { text-decoration: underline; }

  .zoom-location-btn {
    position: absolute; bottom: 28px; left: 50%; transform: translateX(-50%); z-index: 1000;
    background: #0f172a; color: white; border: 1px solid rgba(255,255,255,0.2); padding: 11px 20px; border-radius: 30px;
    font-size: 0.82rem; font-weight: 600; box-shadow: 0 10px 25px rgba(0,0,0,0.25); cursor: pointer; transition: all 0.2s;
    display: flex; align-items: center; gap: 8px; white-space: nowrap; backdrop-filter: blur(8px);
  }
  .zoom-location-btn:hover:not(:disabled) { background: var(--primary); transform: translateX(-50%) scale(1.03); }
  .zoom-location-btn.disabled { opacity: 0.85; cursor: not-allowed; background: #334155; }

  .gps-control-btn {
    width: 38px; height: 38px; background: #ffffff; border: 1px solid #cbd5e1; border-radius: 10px;
    display: flex; align-items: center; justify-content: center; cursor: pointer; box-shadow: 0 4px 12px rgba(0,0,0,0.15); transition: background 0.2s;
  }
  .gps-control-btn:hover { background: #f8fafc; }
  .gps-control-btn svg { width: 20px; height: 20px; fill: #334155; }

  .post-card { background: #fff; border: 1px solid var(--border-color); border-radius: 12px; padding: 12px; margin-bottom: 10px; box-shadow: 0 2px 4px rgba(0,0,0,0.02); }
  .post-header { display: flex; justify-content: space-between; align-items: center; font-size: 0.8rem; margin-bottom: 8px; }
  .post-author { font-weight: 600; color: var(--primary); cursor: pointer; text-decoration: none; }
  .post-author:hover { text-decoration: underline; }
  .post-time { color: var(--text-muted); font-size: 0.72rem; }
  .post-body { font-size: 0.85rem; color: #334155; line-height: 1.5; overflow-wrap: break-word; }
  .post-body img { max-width: 100%; border-radius: 8px; margin-top: 8px; }
  .post-location-tag { display: inline-block; background: #f1f5f9; font-size: 0.72rem; font-weight: 500; padding: 3px 8px; border-radius: 6px; margin-bottom: 8px; color: #475569; text-decoration: none; cursor: pointer; }
  .post-location-tag:hover { background: #e2e8f0; color: var(--primary); }

  .post-footer { display: flex; justify-content: space-between; align-items: center; margin-top: 10px; padding-top: 8px; border-top: 1px solid #f1f5f9; }
  .post-actions { display: flex; gap: 8px; }
  .btn-action { background: none; border: none; font-size: 0.75rem; font-weight: 600; cursor: pointer; padding: 4px 8px; border-radius: 6px; transition: background 0.2s; }
  .btn-action.edit { color: #2563eb; background: #eff6ff; }
  .btn-action.edit:hover { background: #dbeafe; }
  .btn-action.delete { color: #ef4444; background: #fef2f2; }
  .btn-action.delete:hover { background: #fee2e2; }
  .permalink-link { font-size: 0.75rem; color: var(--text-muted); text-decoration: none; }
  .permalink-link:hover { text-decoration: underline; color: var(--primary); }

  .edit-textarea { width: 100%; height: 80px; border: 1px solid #cbd5e1; border-radius: 8px; padding: 8px; font-size: 0.85rem; outline: none; resize: vertical; font-family: inherit; }
  .btn-primary { background: var(--primary); color: white; border: none; padding: 6px 12px; border-radius: 6px; font-size: 0.78rem; font-weight: 600; cursor: pointer; }
  .btn-secondary { background: #e2e8f0; color: #334155; border: none; padding: 6px 12px; border-radius: 6px; font-size: 0.78rem; font-weight: 600; cursor: pointer; }

  .composer { background: #f8fafc; border: 1px solid var(--border-color); border-radius: 12px; padding: 10px; margin-bottom: 12px; }
  .composer textarea { width: 100%; height: 70px; border: 1px solid #cbd5e1; border-radius: 8px; padding: 8px; font-size: 0.85rem; outline: none; resize: vertical; font-family: inherit; }
  .composer textarea:focus { border-color: var(--primary); }
  .composer-toolbar { display: flex; justify-content: space-between; align-items: center; margin-top: 8px; }
  .composer-toolbar label { font-size: 0.75rem; font-weight: 500; background: #e2e8f0; color: #334155; padding: 5px 10px; border-radius: 6px; cursor: pointer; }
  .composer-toolbar input[type="file"] { display: none; }
  .btn-post { background: var(--primary); color: #fff; border: none; padding: 7px 16px; border-radius: 8px; font-size: 0.8rem; font-weight: 600; cursor: pointer; transition: background 0.2s; }
  .btn-post:hover { background: var(--primary-hover); }

  .load-more-btn { width: 100%; padding: 10px; background: #f1f5f9; border: 1px solid var(--border-color); border-radius: 8px; font-size: 0.8rem; cursor: pointer; font-weight: 600; color: #334155; margin-top: 8px; }
  .load-more-btn:hover { background: #e2e8f0; }

  .toast { position: fixed; bottom: 28px; left: 50%; transform: translateX(-50%); background: #0f172a; color: white; padding: 10px 20px; border-radius: 9999px; font-size: 0.82rem; font-weight: 500; display: none; box-shadow: 0 10px 25px rgba(0,0,0,0.3); z-index: 4000; border: 1px solid rgba(255,255,255,0.1); }
  .modal-overlay { position: fixed; inset: 0; background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(4px); z-index: 3000; display: none; align-items: center; justify-content: center; padding: 16px; }
  .modal-card { background: #fff; border-radius: 16px; width: 100%; max-width: 480px; padding: 18px; box-shadow: 0 20px 30px rgba(0,0,0,0.25); max-height: 85vh; overflow-y: auto; }

  /* Full Standalone Interface Pages */
  .standalone-page {
    position: absolute; top: 0px; left: 0; right: 0; bottom: 0; background: #f8fafc; z-index: 2500;
    overflow-y: auto; display: flex; flex-direction: column; align-items: center;
  }
  .standalone-container { width: 100%; max-width: 680px; padding: 24px 16px; }
  .standalone-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; width: 100%; }
  .standalone-title { font-size: 1.25rem; font-weight: 700; color: var(--text-primary); margin: 0; }
  .btn-back { background: #ffffff; color: var(--text-primary); border: 1px solid var(--border-color); padding: 6px 14px; border-radius: 8px; font-size: 0.8rem; font-weight: 600; text-decoration: none; transition: background 0.2s; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
  .btn-back:hover { background: #f1f5f9; }

  /* Map Marker Pin Styles */
  .custom-marker-wrapper { position: relative; }
  .marker-pin {
    width: 28px; height: 28px; border-radius: 50% 50% 50% 0; background: var(--primary);
    position: absolute; transform: rotate(-45deg); left: 50%; top: 50%; margin: -20px 0 0 -14px;
    box-shadow: 0 3px 8px rgba(0,0,0,0.3); display: flex; align-items: center; justify-content: center;
    border: 2px solid #ffffff; transition: all 0.25s ease;
  }
  .marker-pin::after {
    content: ''; width: 10px; height: 10px; background: #ffffff; border-radius: 50%;
  }
  .marker-pin.active {
    background: var(--danger); width: 36px; height: 36px; margin: -24px 0 0 -18px;
    border: 3px solid #fef08a; box-shadow: 0 4px 16px rgba(239, 68, 68, 0.6); z-index: 1000;
  }
  .marker-pin.active::after { background: #fef08a; width: 12px; height: 12px; }

  /* Map Marker Permanent Label Styles */
  .place-label-tooltip {
    background: rgba(15, 23, 42, 0.88) !important; color: #ffffff !important; border: none !important;
    border-radius: 6px !important; padding: 3px 8px !important; font-size: 0.72rem !important;
    font-weight: 600 !important; box-shadow: 0 4px 12px rgba(0,0,0,0.25) !important; backdrop-filter: blur(4px);
    white-space: nowrap; pointer-events: none;
  }
  .place-label-tooltip::before { display: none !important; }

  @media (max-width: 768px) {
    .main-panel { top: 8px; left: 8px; right: 8px; width: auto; max-width: none; max-height: 52vh; }
    .zoom-location-btn { bottom: 16px; font-size: 0.78rem; padding: 9px 16px; }
    .standalone-page { padding: 0; }
    .standalone-container { padding: 16px; }
  }
</style>
</head>
<body>

<div id="app-header">
  <h1><span>📍</span><a href="<?php echo $baseUrl; ?>">whathappened.here</a></h1>
  <div class="user-badge">
    <button id="clear-all-btn" class="btn-clear" style="display: none;">🧹 Clear Markers</button>
    <?php if (isset($_SESSION['osm_user'])): ?>
      <span class="user-name" onclick="location.href='?page=user&id=<?php echo htmlspecialchars($_SESSION['osm_user']['id']); ?>'">
        👤 <?php echo htmlspecialchars($_SESSION['osm_user']['display_name']); ?>
      </span>
      <a href="?action=logout" class="btn-auth logout">Logout</a>
    <?php else: ?>
      <a href="?action=login" class="btn-auth">Login with OSM</a>
    <?php endif; ?>
  </div>
</div>

<div id="main-container">
  <?php if ($page === 'user' && !empty($userIdParam)): ?>
    <!-- FULL INTERFACE PAGE: User Posts -->
    <div class="standalone-page" style="padding: 24px 16px;">
      <div class="standalone-container" style="background: #ffffff; border: 1px solid var(--border-color); border-radius: 16px; padding: 24px; box-shadow: 0 10px 30px rgba(15, 23, 42, 0.08);">
        <div class="standalone-header" style="margin-bottom: 20px; padding-bottom: 12px; border-bottom: 1px solid var(--border-color);">
          <div>
            <h2 class="standalone-title" id="user-page-title">User Posts</h2>
            <a id="osm-profile-link" href="#" target="_blank" rel="noopener" class="osm-history-link" style="font-size: 0.8rem; display: none;">View profile on OSM ↗</a>
          </div>
          <a href="<?php echo $baseUrl; ?>" class="btn-back">← Back to Map</a>
        </div>
        <div id="standalone-user-posts">
          <p style="color: var(--text-muted); font-size: 0.85rem;">Loading posts...</p>
        </div>
        <button id="standalone-user-more" class="load-more-btn" style="display: none;">Load More</button>
      </div>
    </div>
  <?php elseif ($page === 'place' && !empty($placeTypeParam) && !empty($placeIdParam)): ?>
    <!-- FULL INTERFACE PAGE: Specific Place Posts with Full-Screen Map on Top -->
    <div class="standalone-page">
      <!-- Full-screen map on top before rest of details -->
      <div id="place-fullscreen-map" style="width: 100%; height: 45vh; min-height: 280px; background: #e2e8f0; position: relative; z-index: 10;"></div>
      
      <div class="standalone-container" style="z-index: 20; margin-top: -20px; background: #f8fafc; border-top-left-radius: 20px; border-top-right-radius: 20px; box-shadow: 0 -10px 25px rgba(0,0,0,0.1); flex: 1;">
        <div class="standalone-header">
          <div>
            <h2 class="standalone-title" id="place-page-title">Place Reviews</h2>
            <div style="display: flex; gap: 12px; align-items: center; margin-top: 4px;">
              <span id="place-coords-badge" style="font-size: 0.75rem; color: var(--text-muted); background: #e2e8f0; padding: 2px 8px; border-radius: 4px; font-family: monospace;">Loading coordinates...</span>
              <a id="osm-place-link" href="#" target="_blank" rel="noopener" class="osm-history-link" style="font-size: 0.8rem;">View on OSM History ↗</a>
            </div>
          </div>
          <a href="<?php echo $baseUrl; ?>" class="btn-back">← Back to Map</a>
        </div>
        
        <div id="standalone-place-composer"></div>
        <div id="standalone-place-posts">
          <p style="color: var(--text-muted); font-size: 0.85rem;">Loading reviews...</p>
        </div>
        <button id="standalone-place-more" class="load-more-btn" style="display: none;">Load More</button>
      </div>
    </div>
  <?php else: ?>
    <!-- DEFAULT MAP INTERFACE -->
    <div id="map"></div>

    <!-- Google Maps Style Single Side Panel -->
    <div class="main-panel">
      <div class="search-header">
        <div class="search-input-wrapper">
          <svg class="search-icon" viewBox="0 0 24 24">
            <path d="M15.5 14h-.79l-.28-.27C15.41 12.59 16 11.11 16 9.5 16 5.91 13.09 3 9.5 3S3 5.91 3 9.5 5.91 16 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/>
          </svg>
          <input type="text" id="search-input" class="search-input" placeholder="Search places with Photon..." autocomplete="off">
          <button type="button" id="clear-search-btn" class="clear-search-btn" title="Clear search" style="display: none;">&times;</button>
          <button type="button" id="toggle-btn" class="toggle-btn" title="Toggle side panel">
            <svg id="toggle-icon" viewBox="0 0 24 24"><path d="M7.41 15.41L12 10.83l4.59 4.58L18 14l-6-6-6 6z"/></svg>
          </button>
        </div>
      </div>

      <!-- Search Results View -->
      <div id="search-results-wrapper">
        <div id="scroll-area">
          <div id="results-container"></div>
          <button id="more-btn">Load More Results</button>
        </div>
      </div>

      <!-- Panel Content Navigation & Tabs -->
      <div id="panel-content-wrapper">
        <div class="tab-nav">
          <button class="tab-btn active" onclick="switchTab('places')">Places</button>
          <button class="tab-btn" onclick="switchTab('active')">Active</button>
          <button class="tab-btn" onclick="switchTab('discover')">Discover</button>
        </div>

        <div id="tab-places" class="panel-body">
          <p style="color: var(--text-muted); font-size: 0.8rem; margin-top:4px;">Zoom in closer (level 16+) and click "Show all locations around here" to explore places on the map.</p>
          <div id="places-list"></div>
        </div>

        <div id="tab-active" class="panel-body" style="display: none;">
          <div id="active-location-info">
            <p style="color: var(--text-muted); font-size: 0.8rem; margin-top:4px;">Select a place on the map or from the list to view check-ins and write posts.</p>
          </div>
          <div id="active-composer-container"></div>
          <div id="active-posts-list"></div>
        </div>

        <div id="tab-discover" class="panel-body" style="display: none;">
          <button onclick="scanBboxReviews()" class="load-more-btn" style="margin-bottom: 10px; background: #eff6ff; color: #2563eb; border-color: #bfdbfe;">
            🔍 Scan Map Area for Reviewed Places
          </button>
          <div id="discover-posts-list"></div>
          <button id="discover-load-more" class="load-more-btn" style="display:none;" onclick="loadGlobalPosts(true)">Load More</button>
        </div>
      </div>
    </div>

    <button id="show-locations-btn" class="zoom-location-btn">📍 Show all locations around here (Zoom: 2)</button>
  <?php endif; ?>
</div>

<div id="toast" class="toast">Copied to clipboard!</div>

<div id="modal-overlay" class="modal-overlay">
  <div class="modal-card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; border-bottom: 1px solid var(--border-color); padding-bottom: 8px;">
      <strong id="modal-title" style="font-size: 0.95rem; color: var(--text-primary);">Permalink Post</strong>
      <button onclick="closeModal()" style="border:none; background:none; font-size: 1.3rem; cursor:pointer; color: var(--text-muted);">&times;</button>
    </div>
    <div id="modal-body"></div>
  </div>
</div>

<script>
  const isLoggedIn = <?php echo isset($_SESSION['osm_user']) ? 'true' : 'false'; ?>;
  const currentUserId = "<?php echo isset($_SESSION['osm_user']) ? $_SESSION['osm_user']['id'] : ''; ?>";
  const currentServerPage = "<?php echo $page; ?>";
  const serverUserId = "<?php echo $userIdParam; ?>";
  const serverPlaceType = "<?php echo $placeTypeParam; ?>";
  const serverPlaceId = "<?php echo $placeIdParam; ?>";

  // Marker Icon Definitions
  const defaultMarkerIcon = L.divIcon({
    className: 'custom-marker-wrapper',
    html: '<div class="marker-pin"></div>',
    iconSize: [28, 28],
    iconAnchor: [14, 28]
  });

  const activeMarkerIcon = L.divIcon({
    className: 'custom-marker-wrapper',
    html: '<div class="marker-pin active"></div>',
    iconSize: [36, 36],
    iconAnchor: [18, 36]
  });

  let map = null;
  if (currentServerPage === '' || currentServerPage === 'place') {
    const mapContainerId = currentServerPage === 'place' ? 'place-fullscreen-map' : 'map';
    if (document.getElementById(mapContainerId)) {
      map = L.map(mapContainerId, { zoomControl: false }).setView([20, 0], 2);
      L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19, attribution: '© OpenStreetMap contributors'
      }).addTo(map);

      if (currentServerPage === '') {
        L.control.zoom({ position: 'bottomright' }).addTo(map);

        const GpsControl = L.Control.extend({
          options: { position: 'bottomright' },
          onAdd: function() {
            const container = L.DomUtil.create('div', 'leaflet-bar gps-control-btn');
            container.title = 'Zoom to GPS';
            container.innerHTML = `<svg viewBox="0 0 24 24"><path d="M12 8c-2.21 0-4 1.79-4 4s1.79 4 4 4 4-1.79 4-4-1.79-4-4-4zm8.94 3A8.994 8.994 0 0 0 13 3.06V1h-2v2.06A8.994 8.994 0 0 0 3.06 11H1v2h2.06A8.994 8.994 0 0 0 11 20.94V23h2v-2.06A8.994 8.994 0 0 0 20.94 13H23v-2h-2.06zM12 19c-3.87 0-7-3.13-7-7s3.13-7 7-7 7 3.13 7 7-3.13 7-7 7 z"/></svg>`;
            container.onclick = function() {
              map.locate({ setView: true, maxZoom: 16 });
            };
            return container;
          }
        });
        map.addControl(new GpsControl());

        let userGpsMarker = null;
        map.on('locationfound', (e) => {
          if (userGpsMarker) map.removeLayer(userGpsMarker);
          userGpsMarker = L.circleMarker(e.latlng, { radius: 8, color: '#2563eb', fillColor: '#3b82f6', fillOpacity: 0.8 }).addTo(map);
        });

        const showLocationsBtn = document.getElementById('show-locations-btn');
        function updateZoomBtnState() {
          const zoom = map.getZoom();
          if (zoom >= 16) {
            showLocationsBtn.innerHTML = `📍 Show all locations around here (Zoom: ${zoom})`;
            showLocationsBtn.disabled = false;
            showLocationsBtn.classList.remove('disabled');
          } else {
            showLocationsBtn.innerHTML = `🔍 Zoom in closer to view places (Zoom: ${zoom}/16)`;
            showLocationsBtn.disabled = true;
            showLocationsBtn.classList.add('disabled');
          }
        }
        map.on('zoomend moveend load', updateZoomBtnState);
        updateZoomBtnState();
      }
    }
  }

  // --- GLOBAL MARKER VISIBILITY CHECKER ---
  const clearAllBtn = document.getElementById('clear-all-btn');
  
  function updateClearButtonVisibility() {
    if (!clearAllBtn) return;
    const hasPhoton = !!photonMarker;
    const hasActive = !!standaloneSelectedMarker;
    const hasOverpass = activeMarkersLayer && activeMarkersLayer.getLayers().length > 0;
    
    if (hasPhoton || hasActive || hasOverpass) {
      clearAllBtn.style.display = 'block';
    } else {
      clearAllBtn.style.display = 'none';
    }
  }

  function clearEverything() {
    clearSearchResults();
    if (activeMarkersLayer) activeMarkersLayer.clearLayers();
    nearbyMarkersMap = {};
    
    if (standaloneSelectedMarker) {
      map.removeLayer(standaloneSelectedMarker);
      standaloneSelectedMarker = null;
    }
    
    activeLocation = null;
    const pList = document.getElementById('places-list');
    if (pList) pList.innerHTML = '';
    switchTab('places');
    updateClearButtonVisibility();
  }

  if (clearAllBtn) clearAllBtn.addEventListener('click', clearEverything);

  // --- PHOTON SEARCH FEATURE ---
  let photonMarker = null;
  let allPhotonResults = [];
  let photonDisplayedCount = 0;
  const photonResultsPerLoad = 10;
  let typingTimer;

  const searchInput = document.getElementById('search-input');
  const clearSearchBtn = document.getElementById('clear-search-btn');
  const resultsContainer = document.getElementById('results-container');
  const searchResultsWrapper = document.getElementById('search-results-wrapper');
  const moreBtn = document.getElementById('more-btn');
  const mainPanel = document.querySelector('.main-panel');
  const toggleBtn = document.getElementById('toggle-btn');
  const toast = document.getElementById('toast');

  if (toggleBtn) toggleBtn.addEventListener('click', () => mainPanel.classList.toggle('collapsed'));
  
  if (searchInput) {
    searchInput.addEventListener('focus', () => {
      mainPanel.classList.remove('collapsed');
      if (searchInput.value.trim().length > 0 && allPhotonResults.length > 0) {
        mainPanel.classList.add('searching');
      }
    });

    searchInput.addEventListener('input', () => {
      if (searchInput.value.trim().length > 0) {
        clearSearchBtn.style.display = 'block';
      } else {
        clearSearchBtn.style.display = 'none';
        mainPanel.classList.remove('searching');
      }
      clearTimeout(typingTimer);
      typingTimer = setTimeout(performPhotonSearch, 400);
    });
  }

  if (clearSearchBtn) clearSearchBtn.addEventListener('click', clearSearchResults);

  function clearSearchResults() {
    if (!searchInput) return;
    searchInput.value = '';
    clearSearchBtn.style.display = 'none';
    resultsContainer.innerHTML = '';
    mainPanel.classList.remove('searching');
    moreBtn.style.display = 'none';
    
    if (photonMarker) {
      map.removeLayer(photonMarker);
      photonMarker = null;
    }
    allPhotonResults = [];
    photonDisplayedCount = 0;
    updateClearButtonVisibility();
  }

  if (moreBtn) moreBtn.addEventListener('click', renderMorePhotonResults);

  async function performPhotonSearch() {
    const query = searchInput.value.trim();
    if (!query) {
      clearSearchResults();
      return;
    }

    mainPanel.classList.remove('collapsed');
    mainPanel.classList.add('searching');
    resultsContainer.innerHTML = '<p style="color: var(--text-muted); font-size: 0.85rem; padding: 4px;">Searching...</p>';
    moreBtn.style.display = 'none';
    photonDisplayedCount = 0;
    allPhotonResults = [];

    try {
      const response = await fetch(`https://photon.komoot.io/api/?q=${encodeURIComponent(query)}&limit=50`);
      const data = await response.json();
      
      if (data.features && data.features.length > 0) {
        resultsContainer.innerHTML = '';
        allPhotonResults = data.features;
        renderMorePhotonResults();
      } else {
        resultsContainer.innerHTML = '<p style="color: var(--text-muted); font-size: 0.85rem; padding: 4px;">No results found.</p>';
      }
    } catch (error) {
      resultsContainer.innerHTML = '<p style="color: var(--danger); font-size: 0.85rem; padding: 4px;">Network error fetching data.</p>';
    }
  }

  function renderMorePhotonResults() {
    const slice = allPhotonResults.slice(photonDisplayedCount, photonDisplayedCount + photonResultsPerLoad);
    
    slice.forEach((feature) => {
      const props = feature.properties;
      const coords = feature.geometry.coordinates; 
      const lat = coords[1];
      const lon = coords[0];
      const coordsFormat = `${lat}, ${lon}`;

      const item = document.createElement('div');
      item.className = 'result-item';
      
      const titleText = props.name || 'Unnamed Location';
      const contextParts = [props.street, props.city, props.state, props.country].filter(Boolean).join(', ');

      item.innerHTML = `
        <div class="result-title">${titleText}</div>
        <div class="result-context">${contextParts || 'No location details'}</div>
      `;

      item.addEventListener('click', () => {
        document.querySelectorAll('.result-item').forEach(el => el.classList.remove('active'));
        item.classList.add('active');
        mainPanel.classList.remove('searching');
        
        map.flyTo([lat, lon], 16, { duration: 1.5 });
        
        if (photonMarker) map.removeLayer(photonMarker);
        photonMarker = L.marker([lat, lon], { icon: activeMarkerIcon }).addTo(map)
          .bindPopup(`<b>${titleText}</b><br>${contextParts}`)
          .openPopup();

        updateClearButtonVisibility();

        selectActiveLocation({
          type: props.osm_type || 'node',
          id: props.osm_id || Date.now(),
          name: titleText,
          lat: lat,
          lng: lon
        });
      });

      item.addEventListener('contextmenu', (e) => {
        e.preventDefault();
        navigator.clipboard.writeText(coordsFormat).then(() => showToast(`Copied: ${coordsFormat}`));
      });

      resultsContainer.appendChild(item);
    });

    photonDisplayedCount += slice.length;
    moreBtn.style.display = (photonDisplayedCount < allPhotonResults.length) ? 'block' : 'none';
  }

  function showToast(message) {
    if (!toast) return;
    toast.textContent = message;
    toast.style.display = 'block';
    setTimeout(() => toast.style.display = 'none', 2500);
  }

  // --- OVERPASS NEARBY LOCATIONS & CHECK-IN SYSTEM ---
  let activeLocation = null;
  let activeMarkersLayer = currentServerPage === '' ? L.layerGroup().addTo(map) : null;
  let nearbyMarkersMap = {};
  let standaloneSelectedMarker = null;

  const showLocationsBtn = document.getElementById('show-locations-btn');
  if (showLocationsBtn) showLocationsBtn.addEventListener('click', fetchNearbyPlaces);

  async function fetchNearbyPlaces() {
    if (map.getZoom() < 16) return;
    clearSearchResults();

    const bounds = map.getBounds();
    const bbox = `${bounds.getSouth()},${bounds.getWest()},${bounds.getNorth()},${bounds.getEast()}`;
    const query = `[out:json][timeout:15];(node["name"](${bbox});way["name"](${bbox});relation["name"](${bbox}););out center 50;`;

    const placesList = document.getElementById('places-list');
    placesList.innerHTML = '<p style="color:var(--text-muted); font-size:0.8rem;">Querying Overpass API...</p>';

    try {
      const response = await fetch('https://overpass-api.de/api/interpreter', { method: 'POST', body: query });
      const data = await response.json();
      activeMarkersLayer.clearLayers();
      nearbyMarkersMap = {};
      placesList.innerHTML = '';

      if (data.elements && data.elements.length > 0) {
        data.elements.forEach(elem => {
          const lat = elem.lat || (elem.center && elem.center.lat);
          const lng = elem.lon || (elem.center && elem.center.lon);
          const name = elem.tags.name || 'Unnamed Location';
          const type = elem.type;
          const id = elem.id;

          if (!lat || !lng) return;

          const key = `${type}:${id}`;
          const isSelected = activeLocation && activeLocation.type === type && String(activeLocation.id) === String(id);
          const icon = isSelected ? activeMarkerIcon : defaultMarkerIcon;

          const marker = L.marker([lat, lng], { icon: icon }).addTo(activeMarkersLayer);
          marker.bindTooltip(name, { permanent: true, direction: 'top', className: 'place-label-tooltip', offset: [0, -28] });
          if (isSelected) marker.setZIndexOffset(1000);

          const locObj = { type, id, name, lat, lng };
          marker.on('click', () => selectActiveLocation(locObj));

          nearbyMarkersMap[key] = { marker, loc: locObj };

          const typeMap = { N: 'node', W: 'way', R: 'relation' };
          const resolvedType = typeMap[type] || type;
          const historyUrl = `https://pewu.github.io/osm-history/#/${resolvedType}/${id}`;

          const item = document.createElement('div');
          item.className = `place-item ${isSelected ? 'active' : ''}`;
          item.id = `place-item-${key}`;
          item.innerHTML = `
            <div class="place-title">${name}</div>
            <div class="place-meta">
              <a href="${historyUrl}" target="_blank" rel="noopener" class="osm-history-link" onclick="event.stopPropagation();">
                ${type.toUpperCase()} #${id} ↗
              </a>
            </div>`;
          item.onclick = () => {
            map.flyTo([lat, lng], 17);
            selectActiveLocation(locObj);
          };
          placesList.appendChild(item);
        });
      } else {
        placesList.innerHTML = '<p style="color:var(--text-muted); font-size:0.8rem;">No named locations found in this view.</p>';
      }
    } catch (e) {
      placesList.innerHTML = '<p style="color:var(--danger); font-size:0.8rem;">Error fetching places from Overpass API.</p>';
    }
    
    updateClearButtonVisibility();
  }

  function updateMarkerStyles() {
    for (const key in nearbyMarkersMap) {
      const item = nearbyMarkersMap[key];
      const isSelected = activeLocation && item.loc.type === activeLocation.type && String(item.loc.id) === String(activeLocation.id);
      
      item.marker.setIcon(isSelected ? activeMarkerIcon : defaultMarkerIcon);
      if (isSelected) {
        item.marker.setZIndexOffset(1000);
      } else {
        item.marker.setZIndexOffset(0);
      }

      const listEl = document.getElementById(`place-item-${key}`);
      if (listEl) {
        if (isSelected) listEl.classList.add('active');
        else listEl.classList.remove('active');
      }
    }
  }

  function selectActiveLocation(loc) {
    activeLocation = loc;
    updateMarkerStyles();

    const key = `${loc.type}:${loc.id}`;
    if (!nearbyMarkersMap[key]) {
      if (standaloneSelectedMarker) {
        map.removeLayer(standaloneSelectedMarker);
      }
      standaloneSelectedMarker = L.marker([loc.lat, loc.lng], { icon: activeMarkerIcon, zIndexOffset: 1000 }).addTo(map);
      standaloneSelectedMarker.bindTooltip(loc.name, { permanent: true, direction: 'top', className: 'place-label-tooltip', offset: [0, -28] });
    } else if (standaloneSelectedMarker) {
      map.removeLayer(standaloneSelectedMarker);
      standaloneSelectedMarker = null;
    }

    updateClearButtonVisibility();
    switchTab('active');

    const typeMap = { N: 'node', W: 'way', R: 'relation' };
    const resolvedType = typeMap[loc.type] || loc.type;
    const historyUrl = `https://pewu.github.io/osm-history/#/${resolvedType}/${loc.id}`;

    const info = document.getElementById('active-location-info');
    const placePermalinkUrl = `${window.location.origin}${window.location.pathname}?page=place&type=${loc.type}&id=${loc.id}`;

    info.innerHTML = `<div style="background:#f1f5f9; padding:10px 12px; border-radius:10px; margin-bottom:12px; border:1px solid #e2e8f0;">
      <strong style="font-size:0.92rem; color:var(--text-primary);"><a href="${placePermalinkUrl}" style="color:inherit; text-decoration:none;" title="Open full place page">${escapeHtml(loc.name)} ↗</a></strong>
      <div style="font-size:0.75rem; margin-top:2px; display:flex; justify-content:space-between; align-items:center;">
        <a href="${historyUrl}" target="_blank" rel="noopener" class="osm-history-link">
          ${loc.type.toUpperCase()} #${loc.id} ↗
        </a>
        <a href="${placePermalinkUrl}" class="osm-history-link" style="font-weight:500;">Permalink Page</a>
      </div>
    </div>`;

    renderComposer();
    loadObjectPosts();
  }

  function renderComposer(containerId = 'active-composer-container') {
    const container = document.getElementById(containerId);
    if (!container) return;
    if (!isLoggedIn) {
      container.innerHTML = `<p style="font-size:0.8rem; color:var(--text-muted); text-align:center;"><a href="?action=login" style="color:var(--primary); font-weight:600;">Log in with OpenStreetMap</a> to check in and post.</p>`;
      return;
    }

    container.innerHTML = `
      <div class="composer">
        <textarea id="${containerId === 'standalone-place-composer' ? 'standalone-post-text' : 'post-text'}" placeholder="Write a check-in review (Markdown supported)..."></textarea>
        <div class="composer-toolbar">
          <label>📷 Upload Image <input type="file" id="${containerId === 'standalone-place-composer' ? 'standalone-image-input' : 'post-image-input'}" accept="image/*" onchange="uploadImage(this, '${containerId === 'standalone-place-composer' ? 'standalone-post-text' : 'post-text'}')"></label>
          <button class="btn-post" onclick="${containerId === 'standalone-place-composer' ? 'submitStandalonePost()' : 'submitPost()'}">Post</button>
        </div>
      </div>
    `;
  }

  async function uploadImage(input, targetTextareaId = 'post-text') {
    if (!input.files || !input.files[0]) return;
    const formData = new FormData();
    formData.append('image', input.files[0]);

    try {
      const res = await fetch('?api=upload_image', { method: 'POST', body: formData });
      const data = await res.json();
      if (data.success) {
        const textarea = document.getElementById(targetTextareaId);
        textarea.value += `\n![image](${data.url})\n`;
      } else {
        alert(data.error || 'Upload failed');
      }
    } catch(e) {
      alert('Error uploading image');
    }
  }

  async function submitPost() {
    const text = document.getElementById('post-text').value.trim();
    if (!text || !activeLocation) return;

    const payload = {
      osm_type: activeLocation.type,
      osm_id: activeLocation.id,
      osm_name: activeLocation.name,
      lat: activeLocation.lat,
      lng: activeLocation.lng,
      content: text
    };

    const res = await fetch('?api=create_post', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });
    const data = await res.json();

    if (data.success) {
      document.getElementById('post-text').value = '';
      loadObjectPosts();
    } else {
      alert(data.error || 'Failed to post.');
    }
  }

  async function submitStandalonePost() {
    const text = document.getElementById('standalone-post-text').value.trim();
    if (!text) return;

    const payload = {
      osm_type: serverPlaceType,
      osm_id: serverPlaceId,
      osm_name: document.getElementById('place-page-title').innerText,
      lat: window.currentPlaceLat || 0,
      lng: window.currentPlaceLng || 0,
      content: text
    };

    const res = await fetch('?api=create_post', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });
    const data = await res.json();

    if (data.success) {
      document.getElementById('standalone-post-text').value = '';
      loadStandalonePlacePosts();
    } else {
      alert(data.error || 'Failed to post.');
    }
  }

  async function loadObjectPosts() {
    if (!activeLocation) return;
    const list = document.getElementById('active-posts-list');
    list.innerHTML = '<p style="color:var(--text-muted); font-size:0.8rem;">Loading posts...</p>';

    const res = await fetch(`?api=get_object_posts&osm_type=${activeLocation.type}&osm_id=${activeLocation.id}`);
    const data = await res.json();

    list.innerHTML = '';
    if (data.posts && data.posts.length > 0) {
      data.posts.forEach(p => list.appendChild(createPostCard(p)));
    } else {
      list.innerHTML = '<p style="color:var(--text-muted); font-size:0.8rem;">No posts yet for this location.</p>';
    }
  }

  // --- EDIT AND DELETE POST FEATURES ---
  function toggleEditPost(postId) {
    const bodyEl = document.getElementById(`post-body-${postId}`);
    const editEl = document.getElementById(`post-edit-${postId}`);
    if (editEl.style.display === 'none') {
      editEl.style.display = 'block';
      bodyEl.style.display = 'none';
    } else {
      editEl.style.display = 'none';
      bodyEl.style.display = 'block';
    }
  }

  async function saveEditPost(postId) {
    const content = document.getElementById(`edit-text-${postId}`).value.trim();
    if (!content) return;

    try {
      const res = await fetch('?api=edit_post', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ post_id: postId, content: content })
      });
      const data = await res.json();
      if (data.success) {
        showToast('Post updated successfully');
        if (activeLocation) loadObjectPosts();
        if (currentServerPage === 'user') loadStandaloneUserPosts(serverUserId);
        if (currentServerPage === 'place') loadStandalonePlacePosts();
        if (document.getElementById('tab-discover') && document.getElementById('tab-discover').style.display !== 'none') loadGlobalPosts();
        closeModal();
      } else {
        alert(data.error || 'Failed to update post.');
      }
    } catch (e) {
      alert('Error updating post');
    }
  }

  async function deletePost(postId) {
    if (!confirm('Are you sure you want to delete this post?')) return;

    try {
      const res = await fetch('?api=delete_post', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ post_id: postId })
      });
      const data = await res.json();
      if (data.success) {
        showToast('Post deleted successfully');
        const card = document.getElementById(`post-card-${postId}`);
        if (card) card.remove();
        if (activeLocation) loadObjectPosts();
        if (currentServerPage === 'user') loadStandaloneUserPosts(serverUserId);
        if (currentServerPage === 'place') loadStandalonePlacePosts();
        if (document.getElementById('tab-discover') && document.getElementById('tab-discover').style.display !== 'none') loadGlobalPosts();
      } else {
        alert(data.error || 'Failed to delete post.');
      }
    } catch (e) {
      alert('Error deleting post');
    }
  }

  // --- DISCOVER FEED & BOUNDING BOX SCAN ---
  let globalOffset = 0;
  async function loadGlobalPosts(append = false) {
    if (!append) globalOffset = 0;
    const list = document.getElementById('discover-posts-list');
    const loadMoreBtn = document.getElementById('discover-load-more');
    if (!list) return;

    if (!append) list.innerHTML = '<p style="color:var(--text-muted); font-size:0.8rem;">Loading feed...</p>';

    const res = await fetch(`?api=get_global_posts&offset=${globalOffset}&limit=10`);
    const data = await res.json();

    if (!append) list.innerHTML = '';
    if (data.posts && data.posts.length > 0) {
      data.posts.forEach(p => list.appendChild(createPostCard(p, true)));
      globalOffset += data.posts.length;
      loadMoreBtn.style.display = data.has_more ? 'block' : 'none';
    } else if (!append) {
      list.innerHTML = '<p style="color:var(--text-muted); font-size:0.8rem;">No global posts available yet.</p>';
      loadMoreBtn.style.display = 'none';
    }
  }

  async function scanBboxReviews() {
    const bounds = map.getBounds();
    const url = `?api=get_bbox_posts&min_lat=${bounds.getSouth()}&min_lng=${bounds.getWest()}&max_lat=${bounds.getNorth()}&max_lng=${bounds.getEast()}`;
    const res = await fetch(url);
    const data = await res.json();

    activeMarkersLayer.clearLayers();
    nearbyMarkersMap = {};

    if (data.locations && data.locations.length > 0) {
      data.locations.forEach(loc => {
        const marker = L.marker([loc.lat, loc.lng], { icon: defaultMarkerIcon }).addTo(activeMarkersLayer);
        marker.bindTooltip(loc.osm_name, { permanent: true, direction: 'top', className: 'place-label-tooltip', offset: [0, -28] });
        
        const locObj = { type: loc.osm_type, id: loc.osm_id, name: loc.osm_name, lat: loc.lat, lng: loc.lng };
        marker.on('click', () => {
          selectActiveLocation(locObj);
        });

        const key = `${loc.osm_type}:${loc.osm_id}`;
        nearbyMarkersMap[key] = { marker, loc: locObj };
      });
      alert(`Found ${data.locations.length} reviewed place(s) in this map view. Click any marker to view its reviews.`);
    } else {
      alert('No reviewed places found in current view area.');
    }
    
    updateClearButtonVisibility();
  }

  function createPostCard(p, showLocationTag = false) {
    const card = document.createElement('div');
    card.className = 'post-card';
    card.id = `post-card-${p.id}`;

    const timeStr = new Date(p.created_at * 1000).toLocaleString();
    const editedTag = p.updated_at ? ' <span style="font-size:0.7rem; color:var(--text-muted);">(edited)</span>' : '';
    const htmlContent = marked.parse(p.content);

    let locationTagHtml = '';
    if (showLocationTag) {
      const placePageUrl = `?page=place&type=${p.osm_type}&id=${p.osm_id}`;
      locationTagHtml = `<a href="${placePageUrl}" class="post-location-tag">📍 ${escapeHtml(p.osm_name)}</a>`;
    }

    const isOwner = isLoggedIn && String(p.user_id) === String(currentUserId);
    const ownerActions = isOwner ? `
      <div class="post-actions">
        <button class="btn-action edit" onclick="toggleEditPost('${p.id}')">✏️ Edit</button>
        <button class="btn-action delete" onclick="deletePost('${p.id}')">🗑️ Delete</button>
      </div>
    ` : '<div></div>';

    const userProfileUrl = `?page=user&id=${p.user_id}`;

    card.innerHTML = `
      ${locationTagHtml}
      <div class="post-header">
        <a href="${userProfileUrl}" class="post-author">👤 ${escapeHtml(p.user_name)}</a>
        <span class="post-time">${timeStr}${editedTag}</span>
      </div>
      <div class="post-body" id="post-body-${p.id}">${htmlContent}</div>
      <div class="post-edit-container" id="post-edit-${p.id}" style="display:none; margin-top:8px;">
        <textarea id="edit-text-${p.id}" class="edit-textarea">${escapeHtml(p.content)}</textarea>
        <div style="display:flex; gap:8px; justify-content:flex-end; margin-top:6px;">
          <button class="btn-secondary" onclick="toggleEditPost('${p.id}')">Cancel</button>
          <button class="btn-primary" onclick="saveEditPost('${p.id}')">Save Changes</button>
        </div>
      </div>
      <div class="post-footer">
        ${ownerActions}
        <a href="javascript:void(0)" onclick="openPermalink('${p.id}')" class="permalink-link">Permalink</a>
      </div>
    `;
    return card;
  }

  function escapeQuotes(str) { return str.replace(/'/g, "\\'"); }
  function escapeHtml(str) { return String(str).replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;"); }

  // --- STANDALONE PAGE INITIALIZERS ---
  let standaloneUserOffset = 0;
  async function loadStandaloneUserPosts(userId) {
    const container = document.getElementById('standalone-user-posts');
    const loadMoreBtn = document.getElementById('standalone-user-more');
    if (!container) return;

    const res = await fetch(`?api=get_user_posts&user_id=${userId}&offset=${standaloneUserOffset}&limit=10`);
    const data = await res.json();

    if (standaloneUserOffset === 0) container.innerHTML = '';
    if (data.posts && data.posts.length > 0) {
      const userName = data.posts[0].user_name;
      document.getElementById('user-page-title').innerText = `${userName}'s Posts`;
      const profileLink = document.getElementById('osm-profile-link');
      if (profileLink && userName) {
        profileLink.href = `https://www.openstreetmap.org/user/${encodeURIComponent(userName)}`;
        profileLink.style.display = 'inline';
      }

      data.posts.forEach(p => container.appendChild(createPostCard(p, true)));
      standaloneUserOffset += data.posts.length;
      loadMoreBtn.style.display = data.has_more ? 'block' : 'none';
      loadMoreBtn.onclick = () => loadStandaloneUserPosts(userId);
    } else if (standaloneUserOffset === 0) {
      container.innerHTML = '<p style="color:var(--text-muted); font-size:0.85rem;">No posts found for this user.</p>';
      loadMoreBtn.style.display = 'none';
    }
  }

  let standalonePlaceOffset = 0;
  async function loadStandalonePlacePosts() {
    const container = document.getElementById('standalone-place-posts');
    const loadMoreBtn = document.getElementById('standalone-place-more');
    if (!container) return;

    const res = await fetch(`?api=get_object_posts&osm_type=${serverPlaceType}&osm_id=${serverPlaceId}&offset=${standalonePlaceOffset}&limit=10`);
    const data = await res.json();

    if (standalonePlaceOffset === 0) container.innerHTML = '';
    if (data.posts && data.posts.length > 0) {
      const placeName = data.posts[0].osm_name;
      const lat = data.posts[0].lat;
      const lng = data.posts[0].lng;
      window.currentPlaceLat = lat;
      window.currentPlaceLng = lng;

      document.getElementById('place-page-title').innerText = placeName;
      document.getElementById('place-coords-badge').innerText = `Coordinates: ${lat.toFixed(5)}, ${lng.toFixed(5)}`;
      
      if (map && lat && lng) {
        map.setView([lat, lng], 17);
        L.marker([lat, lng], { icon: activeMarkerIcon }).addTo(map)
          .bindTooltip(placeName, { permanent: true, direction: 'top', className: 'place-label-tooltip', offset: [0, -28] });
      }

      const typeMap = { N: 'node', W: 'way', R: 'relation' };
      const resolvedType = typeMap[serverPlaceType] || serverPlaceType;
      document.getElementById('osm-place-link').href = `https://pewu.github.io/osm-history/#/${resolvedType}/${serverPlaceId}`;

      data.posts.forEach(p => container.appendChild(createPostCard(p, false)));
      standalonePlaceOffset += data.posts.length;
      loadMoreBtn.style.display = data.has_more ? 'block' : 'none';
      loadMoreBtn.onclick = loadStandalonePlacePosts;
    } else if (standalonePlaceOffset === 0) {
      container.innerHTML = '<p style="color:var(--text-muted); font-size:0.85rem;">No reviews yet for this place. Be the first to write one!</p>';
      loadMoreBtn.style.display = 'none';
    }
  }

  async function openPermalink(postId) {
    document.getElementById('modal-title').innerText = 'Permalink Post';
    const modalBody = document.getElementById('modal-body');
    modalBody.innerHTML = '<p style="color:var(--text-muted); font-size:0.8rem;">Loading post...</p>';
    openModal();

    const res = await fetch(`?api=get_single_post&post_id=${postId}`);
    const data = await res.json();

    if (data.post) {
      modalBody.innerHTML = '';
      modalBody.appendChild(createPostCard(data.post, true));
      const shareUrl = window.location.origin + window.location.pathname + '?post=' + postId;
      modalBody.innerHTML += `<div style="margin-top:10px;"><input type="text" readonly value="${shareUrl}" style="width:100%; padding:8px; font-size:0.75rem; border:1px solid #cbd5e1; border-radius:6px; background:#f8fafc;" onclick="this.select()"></div>`;
    } else {
      modalBody.innerHTML = '<p style="color:var(--danger); font-size:0.8rem;">Post not found.</p>';
    }
  }

  function switchTab(tabName) {
    clearSearchResults();
    
    document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
    document.getElementById('tab-places').style.display = 'none';
    document.getElementById('tab-active').style.display = 'none';
    document.getElementById('tab-discover').style.display = 'none';

    if (tabName === 'places') {
      document.querySelectorAll('.tab-btn')[0].classList.add('active');
      document.getElementById('tab-places').style.display = 'block';
    } else if (tabName === 'active') {
      document.querySelectorAll('.tab-btn')[1].classList.add('active');
      document.getElementById('tab-active').style.display = 'block';
    } else if (tabName === 'discover') {
      document.querySelectorAll('.tab-btn')[2].classList.add('active');
      document.getElementById('tab-discover').style.display = 'block';
      loadGlobalPosts();
    }
  }

  function openModal() { document.getElementById('modal-overlay').style.display = 'flex'; }
  function closeModal() { document.getElementById('modal-overlay').style.display = 'none'; }

  window.addEventListener('DOMContentLoaded', () => {
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.has('post')) {
      openPermalink(urlParams.get('post'));
    }

    if (currentServerPage === 'user') {
      standaloneUserOffset = 0;
      loadStandaloneUserPosts(serverUserId);
    } else if (currentServerPage === 'place') {
      renderComposer('standalone-place-composer');
      loadStandalonePlacePosts();
    }
  });
</script>
</body>
</html>
