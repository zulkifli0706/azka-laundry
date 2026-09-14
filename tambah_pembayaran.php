<?php
/**
 * AJAX: Tambah pembayaran (DP / cicilan / pelunasan) ke transaksi yang
 * sudah ada. Dipanggil dari tombol "Input DP" / "Tambah Bayar" di
 * dashboard_kasir.php dan antrian_kasir.php.
 *
 * Setiap pembayaran dicatat sebagai baris baru di tabel `pembayaran`,
 * lalu cache transaksi.total_dibayar & transaksi.status_bayar diperbarui
 * otomatis (lihat catatPembayaran() di includes/config.php):
 *   - total_dibayar == 0            -> status_bayar = belum_bayar
 *   - 0 < total_dibayar < total     -> status_bayar = dp
 *   - total_dibayar >= total_harga  -> status_bayar = sudah_bayar
 */
require_once __DIR__ . '/includes/config.php';
requireRole('kasir', 'owner');

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Metode request tidak valid.']);
    exit;
}

if (!verify_csrf()) {
    echo json_encode(['success' => false, 'message' => 'Sesi kedaluwarsa, silakan muat ulang halaman.']);
    exit;
}

$transaksiId = (int)($_POST['transaksi_id'] ?? 0);
$jumlahBayar = (float)($_POST['jumlah_bayar'] ?? 0);
$metode      = $_POST['metode_bayar'] ?? 'tunai';
$namaPengirim = trim($_POST['nama_pengirim'] ?? '');
$noPengirim   = trim($_POST['no_pengirim'] ?? '');
$kasirId     = (int)$_SESSION['user']['id_user'];

if ($transaksiId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Transaksi tidak valid.']);
    exit;
}
if ($jumlahBayar <= 0) {
    echo json_encode(['success' => false, 'message' => 'Jumlah bayar harus lebih dari 0.']);
    exit;
}

try {
    $pdo->beginTransaction();

    $hasil = catatPembayaran($pdo, $transaksiId, $jumlahBayar, $metode, $kasirId, $namaPengirim, $noPengirim);

    $stmt = $pdo->prepare("SELECT id_nota, nama_pelanggan, total_harga FROM transaksi WHERE id_transaksi = ?");
    $stmt->execute([$transaksiId]);
    $trx = $stmt->fetch();

    $pdo->commit();

    echo json_encode([
        'success'       => true,
        'nota'          => $trx['id_nota'] ?? '-',
        'nama'          => $trx['nama_pelanggan'] ?? '-',
        'total_harga'   => (float)($trx['total_harga'] ?? 0),
        'total_dibayar' => $hasil['total_dibayar'],
        'sisa'          => $hasil['sisa'],
        'status_bayar'  => $hasil['status_bayar'],
    ]);
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
