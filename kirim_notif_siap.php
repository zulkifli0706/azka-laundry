<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/meta_whatsapp.php';
require_once __DIR__ . '/includes/proses_helper.php';
requireRole('bagian_pencucian', 'owner', 'kasir');

header('Content-Type: application/json');

if (!verify_csrf()) {
    echo json_encode(['success' => false, 'message' => 'Sesi kedaluwarsa, silakan muat ulang halaman.']);
    exit;
}

$transaksi_id = (int) ($_POST['transaksi_id'] ?? 0);
if (!$transaksi_id) {
    echo json_encode(['success' => false, 'message' => 'transaksi_id tidak valid']);
    exit;
}

$waSent = kirimNotifSiapDiambil($pdo, $transaksi_id);

echo json_encode(['success' => true, 'wa_sent' => $waSent]);
