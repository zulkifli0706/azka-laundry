<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/meta_whatsapp.php';
require_once __DIR__ . '/includes/notif_helper.php';     // kirimNotifTransaksiBaru
require_once __DIR__ . '/includes/proses_helper.php';    // kirimNotifSiapDiambil
require_once __DIR__ . '/includes/estimasi_helper.php';  // kirimNotifKeterlambatan
requireRole('bagian_pencucian', 'owner', 'kasir');

header('Content-Type: application/json');

if (!verify_csrf()) {
    echo json_encode(['success' => false, 'message' => 'Sesi kedaluwarsa, silakan muat ulang halaman.']);
    exit;
}

$type         = $_POST['type'] ?? '';
$transaksi_id = (int) ($_POST['transaksi_id'] ?? 0);

if (!$transaksi_id || !in_array($type, ['baru', 'siap', 'terlambat', 'selesai'], true)) {
    echo json_encode(['success' => false, 'message' => 'Data tidak valid']);
    exit;
}

$waSent = match ($type) {
    'baru'      => kirimNotifTransaksiBaru($pdo, $transaksi_id),
    'siap'      => kirimNotifSiapDiambil($pdo, $transaksi_id),
    'terlambat' => kirimNotifKeterlambatan($pdo, $transaksi_id),
    'selesai'   => kirimNotifSelesai($pdo, $transaksi_id),
};

echo json_encode(['success' => true, 'wa_sent' => $waSent]);
