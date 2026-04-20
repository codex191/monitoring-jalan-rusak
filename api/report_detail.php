<?php
/**
 * API: /api/report_detail.php?id=UUID
 * GET → detail 1 laporan + history
 */
require_once '../config/cors.php';
require_once '../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method tidak didukung.']);
    exit;
}

$id = trim($_GET['id'] ?? '');
if (!$id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'ID laporan wajib diisi.']);
    exit;
}

try {
    $db = getDB();

    // Ambil laporan
    $stmt = $db->prepare("SELECT * FROM reports WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $id]);
    $report = $stmt->fetch();

    if (!$report) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Laporan tidak ditemukan.']);
        exit;
    }

    $report['photo_urls'] = json_decode($report['photo_urls'] ?? '[]', true) ?: [];

    // Ambil history
    $hStmt = $db->prepare("SELECT * FROM report_history WHERE report_id = :id ORDER BY timestamp ASC");
    $hStmt->execute([':id' => $id]);
    $report['history'] = $hStmt->fetchAll();

    echo json_encode(['success' => true, 'data' => $report]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}