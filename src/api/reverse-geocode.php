<?php
require_once __DIR__ . '/../includes/helpers.php';

$lat = isset($_GET['lat']) ? (float)$_GET['lat'] : null;
$lng = isset($_GET['lng']) ? (float)$_GET['lng'] : null;

if ($lat === null || $lng === null || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
    http_response_code(400);
    respond(['error' => 'Invalid coordinates.']);
}

respond(['label' => reverseGeocode($lat, $lng)]);