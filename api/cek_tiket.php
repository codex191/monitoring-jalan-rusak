<?php
/**
 * API: /api/cek_tiket.php?kode=KJR-202604-0001
 * GET → cek status laporan berdasarkan kode tiket (publik, tanpa login)
 */
require_once '../config/cors.php';
require_once '../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method tidak didukung.']);
    exit;
}

$kode = strtoupper(trim($_GET['kode'] ?? ''));

if (!$kode) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Kode tiket wajib diisi.']);
    exit;
}

// Validasi format kode tiket
if (!preg_match('/^KJR-\d{6}-\d{4}$/', $kode)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Format kode tiket tidak valid. Contoh: KJR-202604-0001']);
    exit;
}

try {
    $db   = getDB();
    $stmt = $db->prepare("SELECT id, road_name, description, status, reporter, rejection_reason, ticket_code, created_at, updated_at FROM reports WHERE ticket_code = :kode LIMIT 1");
    $stmt->execute([':kode' => $kode]);
    $report = $stmt->fetch();

    if (!$report) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Kode tiket tidak ditemukan. Pastikan kode yang kamu masukkan sudah benar.']);
        exit;
    }

    // Ambil history
    $hStmt = $db->prepare("SELECT status, actor, role, note, timestamp FROM report_history WHERE report_id = :id ORDER BY timestamp ASC");
    $hStmt->execute([':id' => $report['id']]);
    $history = $hStmt->fetchAll();

    echo json_encode([
        'success' => true,
        'data'    => [
            'ticket_code'      => $report['ticket_code'],
            'road_name'        => $report['road_name'],
            'description'      => $report['description'],
            'status'           => $report['status'],
            'reporter'         => $report['reporter'],
            'rejection_reason' => $report['rejection_reason'],
            'detail_url'       => 'detail.html?id=' . $report['id'],
            'created_at'       => $report['created_at'],
            'updated_at'       => $report['updated_at'],
            'history'          => $history,
        ],
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Terjadi kesalahan server.']);
}
