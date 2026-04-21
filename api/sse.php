<?php
/**
 * API: /api/sse.php
 * GET → Server-Sent Events stream untuk notifikasi real-time
 * Petugas/admin subscribe ke endpoint ini — server push saat ada laporan pending baru
 *
 * Cara kerja:
 *  - Client buka koneksi: EventSource('/api/sse.php')
 *  - Server poll DB setiap 5 detik
 *  - Kalau ada laporan pending baru (created_at > last_check), kirim event
 *  - Koneksi otomatis reconnect jika terputus
 */
require_once '../config/cors.php';
require_once '../config/database.php';

// SSE harus pakai text/event-stream
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no'); // penting untuk Nginx

// Matikan output buffering
if (ob_get_level()) ob_end_clean();
ini_set('output_buffering', 'off');
ini_set('zlib.output_compression', false);

// Cegah timeout terlalu cepat (max 2 menit stream)
set_time_limit(120);
ignore_user_abort(false);

// ── Helper kirim SSE event ────────────────────────────────────
function sendEvent($event, $data, $id = null) {
    if ($id !== null) echo "id: $id\n";
    echo "event: $event\n";
    echo "data: " . json_encode($data) . "\n\n";
    flush();
}

// ── Kirim heartbeat awal ──────────────────────────────────────
sendEvent('connected', ['message' => 'Terhubung ke server notifikasi.', 'ts' => time()]);

// ── Loop polling ──────────────────────────────────────────────
$lastCheck   = time() - 10; // cek 10 detik ke belakang saat pertama connect
$pollInterval = 5;           // cek setiap 5 detik
$maxLoops    = 24;           // 24 × 5 detik = 2 menit, lalu client reconnect
$loop        = 0;

while ($loop < $maxLoops) {
    // Cek apakah client masih terhubung
    if (connection_aborted()) break;

    try {
        $db   = getDB();
        $now  = date('Y-m-d H:i:s');
        $from = date('Y-m-d H:i:s', $lastCheck);

        // Cek laporan pending baru
        $stmt = $db->prepare("
            SELECT id, road_name, reporter, created_at
            FROM reports
            WHERE status = 'pending'
              AND created_at > :from
            ORDER BY created_at DESC
            LIMIT 10
        ");
        $stmt->execute([':from' => $from]);
        $newReports = $stmt->fetchAll();

        if (!empty($newReports)) {
            sendEvent('new_report', [
                'count'   => count($newReports),
                'reports' => array_map(fn($r) => [
                    'id'         => $r['id'],
                    'road_name'  => $r['road_name'],
                    'reporter'   => $r['reporter'],
                    'created_at' => $r['created_at'],
                ], $newReports),
                'message' => count($newReports) . ' laporan baru menunggu verifikasi.',
            ], time());
        }

        // Hitung total pending untuk badge update
        $totalStmt = $db->prepare("SELECT COUNT(*) FROM reports WHERE status = 'pending'");
        $totalStmt->execute();
        $totalPending = (int) $totalStmt->fetchColumn();

        // Kirim stats update setiap 3 loop (~15 detik)
        if ($loop % 3 === 0) {
            sendEvent('stats_update', [
                'pending' => $totalPending,
                'ts'      => time(),
            ]);
        }

        $lastCheck = time();
    } catch (Exception $e) {
        sendEvent('error', ['message' => 'Gagal cek data: ' . $e->getMessage()]);
    }

    $loop++;
    sleep($pollInterval);
}

// Beritahu client untuk reconnect
sendEvent('reconnect', ['message' => 'Stream selesai, reconnecting...']);
