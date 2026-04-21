<?php
/**
 * API: /api/reports.php
 * GET  → ambil semua laporan (publik, status != pending)
 * POST → kirim laporan baru dari warga
 */
require_once '../config/cors.php';
require_once '../config/database.php';

$method = $_SERVER['REQUEST_METHOD'];

// ── GET: Ambil laporan publik ──────────────────────────────
if ($method === 'GET') {
    try {
        $db = getDB();

        // Filter status (opsional: ?status=damaged)
        $status = $_GET['status'] ?? 'all';
        // Filter tanggal (opsional: ?from=2026-01-01&to=2026-12-31)
        $from = $_GET['from'] ?? null;
        $to   = $_GET['to']   ?? null;

        $params = [];
        $action = $_GET['action'] ?? '';

        // action=get_all atau stats=1 → admin, ambil semua termasuk pending & ditolak
        if ($action === 'get_all' || isset($_GET['stats'])) {
            $sql = "SELECT * FROM reports WHERE 1=1";
        } else {
            $sql = "SELECT * FROM reports WHERE status != 'pending' AND status != 'ditolak'";
        }

        if ($status !== 'all' && in_array($status, ['pending','damaged','in_progress','fixed','reported','ditolak'])) {
            $sql     .= " AND status = :status";
            $params[':status'] = $status;
        }
        // Filter pencarian nama jalan / deskripsi
        $search = trim($_GET['search'] ?? '');
        if ($search !== '') {
            $sql .= " AND (road_name LIKE :search OR description LIKE :search2)";
            $params[':search']  = '%' . $search . '%';
            $params[':search2'] = '%' . $search . '%';
        }
        if ($from) {
            $sql     .= " AND created_at >= :from";
            $params[':from'] = $from . ' 00:00:00';
        }
        if ($to) {
            $sql     .= " AND created_at <= :to";
            $params[':to'] = $to . ' 23:59:59';
        }

        // Hitung total data (tanpa LIMIT)
        $countSql  = "SELECT COUNT(*) FROM (" . $sql . ") AS sub";
        $countStmt = $db->prepare($countSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        // Server-side pagination
        $page    = max(1, intval($_GET['page']     ?? 1));
        $perPage = min(100, max(5, intval($_GET['per_page'] ?? 20)));
        $offset  = ($page - 1) * $perPage;

        $sql .= " ORDER BY created_at DESC LIMIT :limit OFFSET :offset";
        $stmt = $db->prepare($sql);
        foreach ($params as $k => $v) $stmt->bindValue($k, $v);
        $stmt->bindValue(':limit',  $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset,  PDO::PARAM_INT);
        $stmt->execute();
        $reports = $stmt->fetchAll();

        // Decode photo_urls dari JSON string ke array
        foreach ($reports as &$r) {
            $r['photo_urls']       = json_decode($r['photo_urls'] ?? '[]', true) ?: [];
            $r['rejection_reason'] = $r['rejection_reason'] ?? null;
        }

        echo json_encode([
            'success'     => true,
            'data'        => $reports,
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $perPage,
            'total_pages' => (int) ceil($total / $perPage),
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ── POST: Kirim laporan baru ───────────────────────────────
if ($method === 'POST') {
    try {
        // ── Rate Limiting (max 5 laporan per IP per 10 menit) ──
        $ip      = preg_replace('/[^0-9a-fA-F:.]/', '', $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        $rateDir = sys_get_temp_dir() . '/pantau_jalan_laporan_rl/';
        if (!is_dir($rateDir)) mkdir($rateDir, 0700, true);
        $rateFile = $rateDir . md5($ip) . '.json';
        $now      = time();
        $window   = 600; // 10 menit
        $maxReq   = 5;   // max 5 laporan per window

        $rl = file_exists($rateFile)
            ? json_decode(file_get_contents($rateFile), true)
            : ['hits' => [], 'blocked_until' => 0];

        if ($now < ($rl['blocked_until'] ?? 0)) {
            http_response_code(429);
            echo json_encode(['success' => false, 'message' => 'Terlalu banyak laporan dikirim. Coba lagi dalam 10 menit.']);
            exit;
        }
        $rl['hits'] = array_filter($rl['hits'] ?? [], fn($t) => ($now - $t) < $window);
        if (count($rl['hits']) >= $maxReq) {
            $rl['blocked_until'] = $now + $window;
            file_put_contents($rateFile, json_encode($rl));
            http_response_code(429);
            echo json_encode(['success' => false, 'message' => 'Terlalu banyak laporan dikirim. Coba lagi dalam 10 menit.']);
            exit;
        }
        $rl['hits'][] = $now;
        file_put_contents($rateFile, json_encode($rl));

        // Terima JSON body
        $body = json_decode(file_get_contents('php://input'), true);

        // Validasi input wajib
        $errors = [];
$roadName    = trim($body['roadName']    ?? '');
$description = trim($body['description'] ?? '');
$reporter    = trim($body['reporter']    ?? 'Anonim');
$lat         = floatval($body['lat']     ?? 0);
$lng         = floatval($body['lng']     ?? 0);
$photoUrls     = $body['photo_urls']     ?? [];

// ── Validasi Geofencing Kalimantan Tengah ─────────────────────
$KALTENG_BOUNDS = [
    'minLat' => -4.80, 'maxLat' => 0.10,
    'minLng' => 110.40, 'maxLng' => 116.70,
];
$latCheck = isset($body['lat']) ? floatval($body['lat']) : null;
$lngCheck = isset($body['lng']) ? floatval($body['lng']) : null;
if ($latCheck !== null && $lngCheck !== null) {
    if (
        $latCheck < $KALTENG_BOUNDS['minLat'] || $latCheck > $KALTENG_BOUNDS['maxLat'] ||
        $lngCheck < $KALTENG_BOUNDS['minLng'] || $lngCheck > $KALTENG_BOUNDS['maxLng']
    ) {
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'message' => 'Lokasi berada di luar wilayah Kalimantan Tengah. Sistem hanya menerima laporan dari wilayah Kalimantan Tengah.',
            'errors'  => ['lat' => 'Koordinat di luar wilayah Kalimantan Tengah.'],
        ]);
        exit;
    }
}
if (!is_array($photoUrls)) $photoUrls = [];
$photoUrlsJson = json_encode(array_values($photoUrls));

        if (strlen($roadName) < 5)    $errors[] = 'Nama jalan minimal 5 karakter.';
        if (strlen($roadName) > 120)  $errors[] = 'Nama jalan maksimal 120 karakter.';
        if (strlen($description) < 10) $errors[] = 'Deskripsi minimal 10 karakter.';
        if (strlen($description) > 500) $errors[] = 'Deskripsi maksimal 500 karakter.';
        if ($lat === 0.0 && $lng === 0.0) $errors[] = 'Koordinat GPS tidak valid.';
        if ($lat < -90 || $lat > 90)  $errors[] = 'Latitude tidak valid.';
        if ($lng < -180 || $lng > 180) $errors[] = 'Longitude tidak valid.';

        if (!empty($errors)) {
            http_response_code(422);
            echo json_encode(['success' => false, 'errors' => $errors]);
            exit;
        }

        // Sanitasi
        $roadName    = htmlspecialchars($roadName,    ENT_QUOTES, 'UTF-8');
        $description = htmlspecialchars($description, ENT_QUOTES, 'UTF-8');
        $reporter    = htmlspecialchars($reporter,    ENT_QUOTES, 'UTF-8');
        if (strlen($reporter) > 60) $reporter = substr($reporter, 0, 60);

        // Generate UUID v4
        $uuid = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );

        $now = date('Y-m-d H:i:s'); // Format MySQL DATETIME

        $db = getDB();

        // Insert laporan
        $stmt = $db->prepare("
            INSERT INTO reports
                (id, road_name, description, lat, lng, status, reporter, photo_urls, created_at, updated_at)
            VALUES
                (:id, :road_name, :description, :lat, :lng, 'pending', :reporter, :photo_urls, :created_at, :updated_at)
        ");
        $stmt->execute([
    ':id'          => $uuid,
    ':road_name'   => $roadName,
    ':description' => $description,
    ':lat'         => $lat,
    ':lng'         => $lng,
    ':reporter'    => $reporter,
    ':photo_urls'  => $photoUrlsJson,
    ':created_at'  => $now,
    ':updated_at'  => $now,
]);

        // Insert history awal
        $db->prepare("
            INSERT INTO report_history (report_id, status, actor, role, note, timestamp)
            VALUES (:report_id, 'pending', :actor, 'warga', 'Laporan dikirim oleh warga.', :ts)
        ")->execute([
            ':report_id' => $uuid,
            ':actor'     => $reporter,
            ':ts'        => $now,
        ]);

        http_response_code(201);
        echo json_encode([
            'success' => true,
            'message' => 'Laporan berhasil dikirim. Menunggu verifikasi petugas.',
            'id'      => $uuid,
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Method tidak didukung
http_response_code(405);
echo json_encode(['success' => false, 'message' => 'Method tidak didukung.']);