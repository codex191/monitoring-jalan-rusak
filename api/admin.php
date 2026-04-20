<?php
/**
 * API: /api/admin.php
 * Menangani: login, logout, check_session, update_status, reject, delete
 */
require_once '../config/cors.php';
require_once '../config/database.php';

session_start();
$now    = date('Y-m-d H:i:s');
$method = $_SERVER['REQUEST_METHOD'];
$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = trim($body['action'] ?? ($_GET['action'] ?? ''));

// ── Helper response ──────────────────────────────────────────
function ok($data = [])  { echo json_encode(array_merge(['success'=>true], $data)); exit; }
function fail($msg, $code = 422) { http_response_code($code); echo json_encode(['success'=>false,'message'=>$msg]); exit; }

// ── GET: check_session ───────────────────────────────────────
if ($method === 'GET' && $action === 'check_session') {
    if (!empty($_SESSION['user'])) ok(['user' => $_SESSION['user']]);
    else fail('Tidak ada sesi aktif.', 401);
}

// ── Semua POST ───────────────────────────────────────────────
if ($method !== 'POST') fail('Method tidak didukung.', 405);

// ── LOGIN (tidak perlu sesi) ─────────────────────────────────
if ($action === 'login') {
    $username = trim($body['username'] ?? '');
    $password = trim($body['password'] ?? '');
    if (!$username || !$password) fail('Username dan password wajib diisi.');
    $db   = getDB();
    $stmt = $db->prepare("SELECT * FROM users WHERE username = :u LIMIT 1");
    $stmt->execute([':u' => $username]);
    $user = $stmt->fetch();
    if (!$user || !password_verify($password, $user['password'])) {
        fail('Username atau password salah.', 401);
    }
    $userData = [
        'id'       => $user['id'],
        'username' => $user['username'],
        'name'     => $user['name'],
        'role'     => $user['role'],
    ];
    $_SESSION['user'] = $userData;
    ok(['user' => $userData]);
}

// ── LOGOUT ───────────────────────────────────────────────────
if ($action === 'logout') {
    $_SESSION = [];
    session_destroy();
    ok(['message' => 'Logout berhasil.']);
}

// ── Aksi berikut perlu autentikasi ───────────────────────────
if (empty($_SESSION['user'])) fail('Tidak terautentikasi. Silakan login.', 401);
$user  = $_SESSION['user'];
$role  = $user['role'];
$actor = $user['name'];

// ── UPDATE STATUS ────────────────────────────────────────────
if ($action === 'update_status') {
    $id        = trim($body['id']     ?? '');
    $newStatus = trim($body['status'] ?? '');
    $note      = trim($body['note']   ?? 'Status diperbarui.');
    $allowed   = ['damaged','in_progress','fixed','reported'];
    if (!$id) fail('ID laporan wajib diisi.');
    if (!in_array($newStatus, $allowed)) fail('Status tidak valid.');
    $db = getDB();
    $db->prepare("UPDATE reports SET status=:s, verified_by=:a, updated_at=:n WHERE id=:id")
       ->execute([':s'=>$newStatus,':a'=>$actor,':n'=>$now,':id'=>$id]);
    $db->prepare("INSERT INTO report_history (report_id,status,actor,role,note,timestamp) VALUES (:r,:s,:a,:ro,:no,:t)")
       ->execute([':r'=>$id,':s'=>$newStatus,':a'=>$actor,':ro'=>$role,':no'=>$note,':t'=>$now]);
    ok(['message'=>'Status berhasil diperbarui.']);
}

// ── TOLAK LAPORAN ────────────────────────────────────────────
if ($action === 'reject') {
    $id     = trim($body['id']     ?? '');
    $reason = trim($body['reason'] ?? '');
    if (!$id) fail('ID laporan wajib diisi.');
    if (strlen($reason) < 5) fail('Alasan penolakan minimal 5 karakter.');
    $db = getDB();
    $db->prepare("UPDATE reports SET status='ditolak', rejection_reason=:r, verified_by=:a, updated_at=:n WHERE id=:id")
       ->execute([':r'=>$reason,':a'=>$actor,':n'=>$now,':id'=>$id]);
    $db->prepare("INSERT INTO report_history (report_id,status,actor,role,note,timestamp) VALUES (:r,'ditolak',:a,:ro,:no,:t)")
       ->execute([':r'=>$id,':a'=>$actor,':ro'=>$role,':no'=>'Laporan ditolak: '.$reason,':t'=>$now]);
    ok(['message'=>'Laporan berhasil ditolak.']);
}

// ── HAPUS PERMANEN (admin only) ──────────────────────────────
if ($action === 'delete') {
    $id = trim($body['id'] ?? '');
    if (!$id) fail('ID laporan wajib diisi.');
    if ($role !== 'admin') fail('Hanya admin yang dapat menghapus laporan.', 403);
    $db = getDB();
    $db->prepare("DELETE FROM reports WHERE id=:id")->execute([':id'=>$id]);
    ok(['message'=>'Laporan berhasil dihapus.']);
}

fail('Aksi tidak dikenal: '.$action);
