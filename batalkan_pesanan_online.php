<?php
require_once __DIR__ . '/includes/config.php';
requireRole('kasir', 'owner');

header('Content-Type: application/json; charset=utf-8');

if (!verify_csrf()) {
    echo json_encode(['success' => false, 'message' => 'Sesi kedaluwarsa, silakan muat ulang halaman.']);
    exit;
}

$id = (int) ($_POST['id'] ?? 0);
if (!$id) {
    echo json_encode(['success' => false, 'message' => 'Data tidak valid.']);
    exit;
}

$stmt = $pdo->prepare("
    UPDATE pesanan_online
    SET status = 'dibatalkan', kasir_id = ?
    WHERE id_pesanan_online = ? AND status = 'menunggu'
");
$stmt->execute([$_SESSION['user']['id_user'], $id]);

if ($stmt->rowCount() > 0) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'message' => 'Pesanan tidak ditemukan atau sudah diproses sebelumnya.']);
}
