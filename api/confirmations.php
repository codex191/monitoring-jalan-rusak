<?php
/**
 * API: /api/confirmations.php
 * GET  ?report_id=xxx  → ambil jumlah + daftar konfirmasi
 * POST {report_id, name, note} → tambah konfirmasi
 */
require_once '../config/cors.php';
require_once '../config/database.php';

$method = $_SERVER['REQUEST_METHOD'];

// ── GET ──────────────────────────────────────────────────────────────────────
if ($method === 'GET') {
    $reportId = trim($_GET['report_id'] ?? '');
    if (!$reportId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'report_id wajib diisi.']);
        exit;
    }
    try {
        $db   = getDB();
        $stmt = $db->prepare("
            SELECT id, name, note, created_at
            FROM   report_confirmations
            WHERE  report_id = :rid
            ORDER  BY created_at DESC
            LIMIT  50
        ");
        $stmt->execute([':rid' => $reportId]);
        $rows = $stmt->fetchAll();

        $countStmt = $db->prepare("SELECT COUNT(*) FROM report_confirmations WHERE report_id = :rid");
        $countStmt->execute([':rid' => $reportId]);
        $total = (int)$countStmt->fetchColumn();

        echo json_encode(['success' => true, 'total' => $total, 'data' => $rows]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Terjadi kesalahan server.']);
    }
    exit;
}

// ── POST ─────────────────────────────────────────────────────────────────────
if ($method === 'POST') {
    $body     = json_decode(file_get_contents('php://input'), true) ?? [];
    $reportId = trim($body['report_id'] ?? '');
    $name     = trim($body['name']      ?? 'Anonim');
    $note     = trim($body['note']      ?? '');

    if (!$reportId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'report_id wajib diisi.']);
        exit;
    }

    // Sanitasi
    $name = htmlspecialchars(substr($name, 0, 60), ENT_QUOTES, 'UTF-8');
    $note = htmlspecialchars(substr($note, 0, 300), ENT_QUOTES, 'UTF-8');
    if (!$name) $name = 'Anonim';

    // Rate limit sederhana: 1 konfirmasi per IP per laporan per hari
    $ip  = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    $ip  = explode(',', $ip)[0];

    try {
        $db = getDB();

        // Pastikan laporan ada
        $chk = $db->prepare("SELECT id FROM reports WHERE id = :id LIMIT 1");
        $chk->execute([':id' => $reportId]);
        if (!$chk->fetchColumn()) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Laporan tidak ditemukan.']);
            exit;
        }

        // Cek duplikat hari ini dari IP yang sama
        $dup = $db->prepare("
            SELECT id FROM report_confirmations
            WHERE report_id = :rid AND ip_address = :ip
              AND DATE(created_at) = CURDATE()
            LIMIT 1
        ");
        $dup->execute([':rid' => $reportId, ':ip' => $ip]);
        if ($dup->fetchColumn()) {
            http_response_code(429);
            echo json_encode(['success' => false, 'message' => 'Kamu sudah mengkonfirmasi laporan ini hari ini.']);
            exit;
        }

        $uuid = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff),
            mt_rand(0,0x0fff)|0x4000, mt_rand(0,0x3fff)|0x8000,
            mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff)
        );

        $db->prepare("
            INSERT INTO report_confirmations (id, report_id, name, note, ip_address, created_at)
            VALUES (:id, :rid, :name, :note, :ip, NOW())
        ")->execute([':id' => $uuid, ':rid' => $reportId, ':name' => $name, ':note' => $note, ':ip' => $ip]);

        // Hitung total terbaru
        $total = (int)$db->prepare("SELECT COUNT(*) FROM report_confirmations WHERE report_id = :rid")
                         ->execute([':rid' => $reportId]) ? 0 : 0;
        $cntStmt = $db->prepare("SELECT COUNT(*) FROM report_confirmations WHERE report_id = :rid");
        $cntStmt->execute([':rid' => $reportId]);
        $total = (int)$cntStmt->fetchColumn();

        http_response_code(201);
        echo json_encode(['success' => true, 'total' => $total, 'message' => 'Konfirmasi berhasil dicatat. Terima kasih!']);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Terjadi kesalahan server.']);
    }
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'message' => 'Method tidak didukung.']);
