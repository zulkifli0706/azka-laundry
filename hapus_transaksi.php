<?php
require_once __DIR__ . '/includes/config.php';
requireLogin();

// Dipanggil lewat AJAX (fetch) dari dashboard_kasir.php supaya baris transaksi
// bisa dihapus dari halaman tanpa navigasi/refresh — tab filter, kata kunci
// pencarian, dan posisi scroll yang lagi aktif jadi tidak ikut ter-reset.
// Kalau dipanggil tanpa 'ajax' (mis. akses langsung), tetap redirect seperti biasa.
$isAjax = isset($_POST['ajax']) || (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest');

function balasHapus(bool $isAjax, string $redirectDashboard, bool $success, string $pesan) {
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => $success, 'message' => $pesan]);
        exit;
    }
    $param = $success ? 'ok' : 'error';
    header('Location: ' . $redirectDashboard . '?' . $param . '=' . urlencode($pesan));
    exit;
}

if (!in_array($_SESSION['user']['role'], ['kasir', 'owner'])) {
    if ($isAjax) balasHapus(true, '', false, 'Tidak diizinkan.');
    header('Location: login.php?error=unauthorized');
    exit;
}

$redirectDashboard = $_SESSION['user']['role'] === 'owner' ? 'dashboard_owner.php' : 'dashboard_kasir.php';

// Hanya boleh dihapus lewat POST (bukan link GET) supaya tidak rawan CSRF
// dan tidak bisa terpicu tanpa sengaja (mis. prefetch link oleh browser/bot).
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    balasHapus($isAjax, $redirectDashboard, false, 'Metode tidak diizinkan.');
}

if (!verify_csrf()) {
    balasHapus($isAjax, $redirectDashboard, false, 'Sesi kedaluwarsa, silakan coba lagi.');
}

$id = intval($_POST['id'] ?? 0);

if (!$id) {
    balasHapus($isAjax, $redirectDashboard, false, 'ID tidak valid');
}

$stmt = $pdo->prepare("SELECT id_nota FROM transaksi WHERE id_transaksi = ?");
$stmt->execute([$id]);
$trx = $stmt->fetch();

if (!$trx) {
    balasHapus($isAjax, $redirectDashboard, false, 'Transaksi tidak ditemukan');
}

try {
    $pdo->beginTransaction();

    $pdo->prepare("DELETE FROM pembayaran WHERE transaksi_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM detail_transaksi WHERE transaksi_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM transaksi WHERE id_transaksi = ?")->execute([$id]);

    $pdo->commit();

    balasHapus($isAjax, $redirectDashboard, true, 'Transaksi ' . $trx['id_nota'] . ' berhasil dihapus');

} catch (PDOException $e) {
    $pdo->rollBack();
    error_log('Gagal hapus transaksi: ' . $e->getMessage());
    balasHapus($isAjax, $redirectDashboard, false, 'Gagal menghapus transaksi. Coba lagi.');
}
