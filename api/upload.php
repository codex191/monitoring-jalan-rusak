<?php
/**
 * API: /api/upload.php
 * POST → upload foto laporan
 * Mengembalikan URL foto yang disimpan di /uploads/photos/
 *
 * Keamanan:
 *  1. Validasi ukuran, ekstensi, MIME type
 *  2. Verifikasi magic bytes (header biner file)
 *  3. Strip metadata EXIF (privasi GPS)
 *  4. Rename acak + blokir eksekusi PHP di folder uploads via .htaccess
 *  5. Rate limiting sederhana (max 10 upload per IP per menit)
 */
require_once '../config/cors.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method tidak didukung.']);
    exit;
}

// ── Konfigurasi ───────────────────────────────────────────────
$uploadDir   = __DIR__ . '/../uploads/photos/';
$maxSize     = 5 * 1024 * 1024; // 5MB
$allowedExt  = ['jpg', 'jpeg', 'png', 'webp'];
$allowedMime = ['image/jpeg', 'image/png', 'image/webp'];

// Magic bytes tiap format
$magicBytes = [
    'jpg'  => ["\xFF\xD8\xFF"],
    'jpeg' => ["\xFF\xD8\xFF"],
    'png'  => ["\x89PNG\r\n\x1A\n"],
    'webp' => ["RIFF"],          // RIFF....WEBP
];

// ── Helper ────────────────────────────────────────────────────
function fail($msg, $code = 422) {
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $msg]);
    exit;
}

// ── Rate Limiting (file-based, max 10 req/IP/menit) ──────────
$ip        = preg_replace('/[^0-9a-fA-F:.]/', '', $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
$rateDir   = sys_get_temp_dir() . '/pantau_jalan_rl/';
if (!is_dir($rateDir)) mkdir($rateDir, 0700, true);
$rateFile  = $rateDir . md5($ip) . '.json';
$now       = time();
$window    = 60;   // detik
$maxReq    = 10;

$rl = file_exists($rateFile) ? json_decode(file_get_contents($rateFile), true) : ['hits' => [], 'blocked_until' => 0];
if ($now < ($rl['blocked_until'] ?? 0)) {
    fail('Terlalu banyak upload. Coba lagi dalam 1 menit.', 429);
}
// Hapus hits di luar window
$rl['hits'] = array_filter($rl['hits'] ?? [], fn($t) => ($now - $t) < $window);
if (count($rl['hits']) >= $maxReq) {
    $rl['blocked_until'] = $now + $window;
    file_put_contents($rateFile, json_encode($rl));
    fail('Terlalu banyak upload. Coba lagi dalam 1 menit.', 429);
}
$rl['hits'][] = $now;
file_put_contents($rateFile, json_encode($rl));

// ── Cek file ada ──────────────────────────────────────────────
if (empty($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
    $errMsg = [
        UPLOAD_ERR_INI_SIZE   => 'File melebihi batas upload server.',
        UPLOAD_ERR_FORM_SIZE  => 'File melebihi batas form.',
        UPLOAD_ERR_PARTIAL    => 'Upload tidak lengkap.',
        UPLOAD_ERR_NO_FILE    => 'File foto tidak ditemukan.',
        UPLOAD_ERR_NO_TMP_DIR => 'Folder sementara tidak tersedia.',
        UPLOAD_ERR_CANT_WRITE => 'Gagal menulis file.',
        UPLOAD_ERR_EXTENSION  => 'Upload diblokir oleh ekstensi server.',
    ];
    $code = $_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE;
    fail($errMsg[$code] ?? 'Error upload tidak diketahui.', 422);
}

$file = $_FILES['photo'];

// ── 1. Validasi ukuran ────────────────────────────────────────
if ($file['size'] > $maxSize) fail('Ukuran foto maksimal 5MB.');
if ($file['size'] < 512)      fail('File terlalu kecil, kemungkinan korup.');

// ── 2. Validasi ekstensi ──────────────────────────────────────
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if (!in_array($ext, $allowedExt)) fail('Format tidak didukung. Gunakan JPG, PNG, atau WebP.');

// ── 3. Validasi MIME type via finfo ──────────────────────────
$finfo    = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);
if (!in_array($mimeType, $allowedMime)) fail('Tipe file tidak valid.');

// ── 4. Validasi Magic Bytes (cegah file tersamar) ────────────
$handle  = fopen($file['tmp_name'], 'rb');
$header  = fread($handle, 12);
fclose($handle);
$validMagic = false;
foreach ($magicBytes[$ext] as $magic) {
    if (strncmp($header, $magic, strlen($magic)) === 0) {
        $validMagic = true;
        break;
    }
}
// WebP: RIFF????WEBP
if ($ext === 'webp' && !$validMagic) {
    $validMagic = (strncmp($header, 'RIFF', 4) === 0 && substr($header, 8, 4) === 'WEBP');
}
if (!$validMagic) fail('File tidak valid atau telah dimanipulasi.');

// ── 5. Pastikan folder & .htaccess ada ───────────────────────
if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

$htaccess = $uploadDir . '.htaccess';
if (!file_exists($htaccess)) {
    file_put_contents($htaccess,
        "Options -Indexes\n" .
        "<FilesMatch \"\\.php$\">\n  Require all denied\n</FilesMatch>\n"
    );
}

// ── 6. Generate nama file acak ────────────────────────────────
$newFilename = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
$destination = $uploadDir . $newFilename;

// ── 7. Pindahkan file ─────────────────────────────────────────
if (!move_uploaded_file($file['tmp_name'], $destination)) {
    fail('Gagal menyimpan foto. Periksa izin folder uploads.', 500);
}

// ── 8. Strip metadata EXIF (privasi — hapus koordinat GPS) ───
if (function_exists('imagecreatefromjpeg') && in_array($ext, ['jpg', 'jpeg'])) {
    $img = @imagecreatefromjpeg($destination);
    if ($img) { imagejpeg($img, $destination, 90); imagedestroy($img); }
} elseif (function_exists('imagecreatefrompng') && $ext === 'png') {
    $img = @imagecreatefrompng($destination);
    if ($img) { imagepng($img, $destination, 8); imagedestroy($img); }
}

// ── Kembalikan URL ────────────────────────────────────────────
$baseUrl   = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
$scriptDir = str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'])));
$photoUrl  = $baseUrl . $scriptDir . '/uploads/photos/' . $newFilename;

echo json_encode([
    'success'  => true,
    'url'      => $photoUrl,
    'filename' => $newFilename,
    'message'  => 'Foto berhasil diupload.',
]);
