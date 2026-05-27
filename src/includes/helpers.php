<?php
// Demy's — Shared Helpers
require_once dirname(__DIR__, 2) . '/config/database.php';
define('BASE_URL', str_replace($_SERVER['DOCUMENT_ROOT'], '', __DIR__ . '/../..'));

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Auth
function isLoggedIn(): bool {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (empty($_SESSION['userID']) || !is_numeric($_SESSION['userID']) || (int) $_SESSION['userID'] <= 0) {
        return false;
    }

    $db = getDB();
    $id = (int) $_SESSION['userID'];
    $stmt = $db->prepare('SELECT userID FROM Users WHERE userID = ? LIMIT 1');
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $result->fetch_assoc()) {
        return true;
    }

    session_unset();
    session_destroy();
    return false;
}

function requireLogin(): void {
    if (!isLoggedIn()) {
        header('Location: ../pages/login.php');
        exit;
    }
}

function currentUser(): ?array {
    if (!isLoggedIn()) return null;
    $db  = getDB();
    $id  = (int) $_SESSION['userID'];
    $stmt = $db->prepare('SELECT userID, email, username, role, contact_number, date_joined, coordinates FROM Users WHERE userID = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

// Flash messages
function setFlash(string $type, string $msg): void {
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
}

function getFlash(): ?array {
    if (!isset($_SESSION['flash'])) return null;
    $f = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $f;
}

// CSRF
function csrfToken(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function verifyCsrf(): void {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf'] ?? '', $token)) {
        http_response_code(403);
        die('Invalid CSRF token.');
    }
}

// Sanitize
function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function formatPrice(float $p): string {
    return '₱' . number_format($p, 2);
}

function timeAgo(string $datetime): string {
    $diff = time() - strtotime($datetime);
    if ($diff < 60)        return 'just now';
    if ($diff < 3600)      return floor($diff/60) . 'm ago';
    if ($diff < 86400)     return floor($diff/3600) . 'h ago';
    if ($diff < 604800)    return floor($diff/86400) . 'd ago';
    return date('M j, Y', strtotime($datetime));
}

function reverseGeocode(float $lat, float $lng): string
{
    $url = sprintf(
        'https://nominatim.openstreetmap.org/reverse?lat=%f&lon=%f&format=json&zoom=16&addressdetails=1',
        $lat, $lng  // FIX: was reverseGeocode($userLat, $userLat) at call site — both args are now used correctly
    );

    $ctx = stream_context_create(['http' => [
        'timeout' => 3,
        'header'  => "User-Agent: Demy's Marketplace/1.0\r\n",
    ]]);

    $json = @file_get_contents($url, false, $ctx);
    if (!$json) return '';

    $data = json_decode($json, true);

    $a = $data['address'] ?? [];
    $parts = array_filter([
        $a['suburb']       ?? $a['village']    ?? $a['quarter'] ?? '',
        $a['city']         ?? $a['town']        ?? $a['municipality'] ?? '',
    ]);

    return implode(', ', $parts) ?: ($data['display_name'] ?? '');
}

function reverseGeocodeMany(array $items): array
{
    $results         = [];
    $latLngMap       = [];
    $uncachedIndices = [];

    foreach ($items as $i => $item) {
        if (empty($item['seller_lat']) || empty($item['seller_lng'])) {
            $results[$i] = '';
            continue;
        }

        $latLngMap[$i] = sprintf('%.5f,%.5f', (float)$item['seller_lat'], (float)$item['seller_lng']);
    }

    if (empty($latLngMap)) {
        return $results;
    }

    $uniqueLatLngs = array_values(array_unique($latLngMap));
    $placeholders  = implode(',', array_fill(0, count($uniqueLatLngs), '?'));

    $db   = getDB();
    $stmt = $db->prepare("SELECT lat_lng, label FROM geocode_cache WHERE lat_lng IN ($placeholders)");

    $types = str_repeat('s', count($uniqueLatLngs));
    $stmt->bind_param($types, ...$uniqueLatLngs);
    $stmt->execute();

    $result    = $stmt->get_result();
    $cacheHits = [];

    while ($row = $result->fetch_assoc()) {
        $cacheHits[$row['lat_lng']] = $row['label'];
    }
    $stmt->close();

    $mh      = curl_multi_init();
    $handles = [];

    foreach ($latLngMap as $i => $latLng) {
        if (isset($cacheHits[$latLng])) {
            $results[$i] = $cacheHits[$latLng];
        } else {
            $uncachedIndices[$latLng][] = $i;

            if (!isset($handles[$latLng])) {
                // Parse lat and lng from the key string so we never rely on
                // $item still being in scope (avoids the swapped-axes bug)
                [$kLat, $kLng] = explode(',', $latLng);

                $url = sprintf(
                    'https://nominatim.openstreetmap.org/reverse?lat=%s&lon=%s&format=json&zoom=16&addressdetails=1',
                    $kLat, $kLng
                );

                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => 4,
                    CURLOPT_HTTPHEADER     => ["User-Agent: Demy's Marketplace/1.0"],
                ]);
                curl_multi_add_handle($mh, $ch);
                $handles[$latLng] = $ch;
            }
        }
    }

    if (!empty($handles)) {
        do {
            curl_multi_exec($mh, $active);
            curl_multi_select($mh);
        } while ($active);

        $newCacheEntries = [];

        foreach ($handles as $latLng => $ch) {
            $json = curl_multi_getcontent($ch);
            $data = $json ? json_decode($json, true) : [];
            $a    = $data['address'] ?? [];

            $parts = array_filter([
                $a['suburb']   ?? $a['village'] ?? $a['quarter']      ?? '',
                $a['city']     ?? $a['town']    ?? $a['municipality'] ?? '',
            ]);

            $label = implode(', ', $parts) ?: ($data['display_name'] ?? '');

            foreach ($uncachedIndices[$latLng] as $idx) {
                $results[$idx] = $label;
            }

            if ($label !== '') {
                $newCacheEntries[$latLng] = $label;
            }

            curl_multi_remove_handle($mh, $ch);
        }

        if (!empty($newCacheEntries)) {
            $insertValues = [];
            $insertParams = [];

            foreach ($newCacheEntries as $latLng => $label) {
                $insertValues[] = '(?, ?)';
                $insertParams[] = $latLng;
                $insertParams[] = $label;
            }

            $insertSql  = "INSERT IGNORE INTO geocode_cache (lat_lng, label) VALUES " . implode(', ', $insertValues);
            $insertStmt = $db->prepare($insertSql);

            $insertTypes = str_repeat('ss', count($newCacheEntries));
            $insertStmt->bind_param($insertTypes, ...$insertParams);
            $insertStmt->execute();
            $insertStmt->close();
        }
    }

    curl_multi_close($mh);
    ksort($results);

    return $results;
}