<?php
/**
 * Pantau Jalan — Konfigurasi Database
 * Sesuaikan DB_NAME, DB_USER, DB_PASS dengan setting Laragon kamu
 */
define('DB_HOST', 'localhost');
define('DB_NAME', 'pantau_jalan');
define('DB_USER', 'root');       // default Laragon
define('DB_PASS', '');           // default Laragon (kosong)
define('DB_CHARSET', 'utf8mb4');

function getDB() {
    static $pdo = null;
    if ($pdo) return $pdo;

    try {
        $dsn = "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=".DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        return $pdo;
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Koneksi database gagal: ' . $e->getMessage()]);
        exit;
    }
}
