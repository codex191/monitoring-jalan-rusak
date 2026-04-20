<?php
/**
 * API: /api/admin.php
 * POST action=login          → login petugas/admin
 * PUT  action=update_status  → ubah status laporan
 * DELETE action=delete       → hapus laporan (admin only)
 */
require_once '../config/cors.php';
require_once '../config/database.php';

// ── Session-based auth (simple) ───────────────────────────
session_start();

function requireAuth($role = null) {
    if (empty($_SESSION['user'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Belum login.']);
        exit;
    }
    if ($role && $_SESSION['user']['role'] !== $role) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Akses ditolak.']);
        exit;
    }
}

$method = $_SERVER['REQUEST_METHOD'];
$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $body['action'] ?? $_GET['action'] ?? '';

// ── LOGIN ─────────────────────────────────────────────────
if ($method === 'POST' && $action === 'login') {
    $username = trim($body['username'] ?? '');
    $password = trim($body['password'] ?? '');

    if (!$username || !$password) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Username dan password wajib diisi.']);
        exit;
    }

    try {
        $db   = getDB();
        $stmt = $db->prepare("SELECT * FROM users WHERE username = :username LIMIT 1");
        $stmt->execute([':username' => $username]);
        $user = $stmt->fetch();

        // Verifikasi password (bcrypt)
        if (!$user || !password_verify($password, $user['password'])) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Username atau password salah.']);
            exit;
        }

        // Simpan session
        $_SESSION['user'] = [
            'id'       => $user['id'],
            'username' => $user['username'],
            'name'     => $user['name'],
            'role'     => $user['role'],
        ];

        echo json_encode([
            'success' => true,
            'user'    => $_SESSION['user'],
            'message' => 'Login berhasil.',
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ── LOGOUT ────────────────────────────────────────────────
if ($method === 'POST' && $action === 'logout') {
    session_destroy();
    echo json_encode(['success' => true, 'message' => 'Logout berhasil.']);
    exit;
}

// ── CEK SESSION ───────────────────────────────────────────
if ($method === 'GET' && $action === 'check_session') {
    if (!empty($_SESSION['user'])) {
        echo json_encode(['success' => true, 'user' => $_SESSION['user']]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Belum login.']);
    }
    exit;
}

// ── GET: Ambil SEMUA laporan (termasuk pending) ───────────
if ($method === 'GET' && $action === 'get_all') {
    requireAuth();
    try {
        $db     = getDB();
        $status = $_GET['status'] ?? 'all';
        $from   = $_GET['from']   ?? null;
        $to     = $_GET['to']     ?? null;
        $search = $_GET['search'] ?? null;

        $sql    = "SELECT * FROM reports WHERE 1=1";
        $params = [];

        if ($status !== 'all' && in_array($status, ['pending','damaged','in_progress','fixed','reported'])) {
            $sql .= " AND status = :status";
            $params[':status'] = $status;
        }
        if ($from) { $sql .= " AND created_at >= :from"; $params[':from'] = $from.' 00:00:00'; }
        if ($to)   { $sql .= " AND created_at <= :to";   $params[':to']   = $to.' 23:59:59';   }
        if ($search) {
            $sql .= " AND (road_name LIKE :search OR description LIKE :search)";
            $params[':search'] = '%'.$search.'%';
        }

        $sql .= " ORDER BY created_at DESC";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $reports = $stmt->fetchAll();

        foreach ($reports as &$r) {
            $r['photo_urls'] = json_decode($r['photo_urls'] ?? '[]', true) ?: [];
            // Ambil history per laporan
            $h = $db->prepare("SELECT * FROM report_history WHERE report_id = :id ORDER BY timestamp ASC");
            $h->execute([':id' => $r['id']]);
            $r['history'] = $h->fetchAll();
        }

        echo json_encode(['success' => true, 'data' => $reports, 'total' => count($reports)]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ── PUT: Update status laporan ────────────────────────────
if ($method === 'PUT' && $action === 'update_status') {
    requireAuth();
    $id        = trim($body['id']     ?? '');
    $newStatus = trim($body['status'] ?? '');
    $note      = trim($body['note']   ?? '');

    $validStatuses = ['pending','damaged','in_progress','fixed','reported'];
    if (!$id || !in_array($newStatus, $validStatuses)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'ID atau status tidak valid.']);
        exit;
    }

    try {
        $db   = getDB();
        $user = $_SESSION['user'];
        $now  = date('Y-m-d H:i:s');

        // Update laporan
        $db->prepare("UPDATE reports SET status = :status, verified_by = :verified_by, updated_at = :now WHERE id = :id")
           ->execute([':status' => $newStatus, ':verified_by' => $user['name'], ':now' => $now, ':id' => $id]);

        // Tambah history
        $noteText = $note ?: 'Status diubah ke '.$newStatus.' oleh '.$user['name'].'.';
        $db->prepare("INSERT INTO report_history (report_id, status, actor, role, note, timestamp) VALUES (:rid, :status, :actor, :role, :note, :ts)")
           ->execute([':rid' => $id, ':status' => $newStatus, ':actor' => $user['name'], ':role' => $user['role'], ':note' => $noteText, ':ts' => $now]);

        echo json_encode(['success' => true, 'message' => 'Status berhasil diperbarui.']);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ── DELETE: Hapus laporan (admin only) ────────────────────
if ($method === 'DELETE' && $action === 'delete') {
    requireAuth('admin');
    $id = trim($body['id'] ?? '');

    if (!$id) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'ID laporan wajib diisi.']);
        exit;
    }

    try {
        $db = getDB();
        // history otomatis terhapus karena ON DELETE CASCADE
        $db->prepare("DELETE FROM reports WHERE id = :id")->execute([':id' => $id]);
        echo json_encode(['success' => true, 'message' => 'Laporan berhasil dihapus.']);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'message' => 'Action tidak dikenali.']);
