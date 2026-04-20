<?php
/**
 * CORS Headers — untuk localhost Laragon
 * credentials: include membutuhkan Allow-Origin spesifik (bukan *)
 */

// Ambil origin dari request
$origin = $_SERVER['HTTP_ORIGIN'] ?? 'http://localhost';

// Whitelist origin yang diizinkan (localhost semua port)
$allowed = ['http://localhost', 'http://127.0.0.1'];
$isAllowed = false;
foreach ($allowed as $a) {
    if (strpos($origin, $a) === 0) { $isAllowed = true; break; }
}

if ($isAllowed) {
    header("Access-Control-Allow-Origin: $origin");
    header('Access-Control-Allow-Credentials: true');  // <-- penting untuk session cookie!
} else {
    header("Access-Control-Allow-Origin: http://localhost");
    header('Access-Control-Allow-Credentials: true');
}

header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Content-Type: application/json; charset=utf-8');

// Handle preflight OPTIONS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}
