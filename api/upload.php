<?php
/**
 * API: /api/upload.php
 * POST → upload foto laporan
 * Mengembalikan URL foto yang disimpan di /uploads/photos/
 */
require_once '../config/cors.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method tidak didukung.']);
    exit;
}

// Konfigurasi upload
$uploadDir  = __DIR__ . '/../uploads/photos/';
$maxSize    = 5 * 1024 * 1024; // 5MB
$allowedExt = ['jpg', 'jpeg', 'png', 'webp'];
$allowedMime= ['image/jpeg', 'image/png', 'image/webp'];

if (empty($_FILES['photo'])) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'File foto tidak ditemukan.']);
    exit;
}

$file = $_FILES['photo'];

// Validasi ukuran
if ($file['size'] > $maxSize) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Ukuran foto maksimal 5MB.']);
    exit;
}

// Validasi ekstensi
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if (!in_array($ext, $allowedExt)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Format foto tidak didukung. Gunakan JPG, PNG, atau WebP.']);
    exit;
}

// Validasi MIME type (lebih aman dari ekstensi saja)
$finfo    = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

if (!in_array($mimeType, $allowedMime)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Tipe file tidak valid.']);
    exit;
}

// Generate nama file unik
$newFilename = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
$destination = $uploadDir . $newFilename;

// Pastikan folder ada
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

// Pindahkan file
if (!move_uploaded_file($file['tmp_name'], $destination)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Gagal menyimpan foto.']);
    exit;
}

// Kembalikan URL foto
$baseUrl  = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
$photoUrl = $baseUrl . '/pantau-jalan/uploads/photos/' . $newFilename;

echo json_encode([
    'success'  => true,
    'url'      => $photoUrl,
    'filename' => $newFilename,
    'message'  => 'Foto berhasil diupload.',
]);
