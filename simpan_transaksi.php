<?php
require_once __DIR__ . '/includes/config.php';
requireRole('kasir', 'owner');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: dashboard_kasir.php');
    exit;
}

if (!verify_csrf()) {
    header('Location: dashboard_kasir.php?error=' . urlencode('Sesi kedaluwarsa, silakan coba lagi.'));
    exit;
}

$nama         = trim($_POST['nama_pelanggan'] ?? '');
$no_hp        = trim($_POST['no_hp_pelanggan'] ?? '');
$estimasi     = $_POST['tanggal_estimasi'] ?? null;
$metode       = $_POST['metode_bayar'] ?? 'tunai';
$nama_pengirim = trim($_POST['nama_pengirim'] ?? '');
$no_pengirim   = trim($_POST['no_pengirim'] ?? '');
$pesanan_online_id = (int) ($_POST['pesanan_online_id'] ?? 0);
$status_bayar = $_POST['status_bayar'] ?? 'belum_bayar';
if (!in_array($status_bayar, ['belum_bayar', 'dp', 'sudah_bayar'], true)) {
    $status_bayar = 'belum_bayar';
}
$total_harga  = (float)($_POST['total_harga'] ?? 0);
$jumlah_dp    = (float)($_POST['jumlah_dp'] ?? 0);
$item_ids     = $_POST['detail_barang_id'] ?? [];
$qtys         = $_POST['qty'] ?? [];
$hargas       = $_POST['harga_satuan'] ?? []; // harga aktual dipilih kasir (default dari katalog, bisa diubah manual)
$catatans     = $_POST['catatan_item'] ?? [];
$kasir_id     = $_SESSION['user']['id_user'];

if (!$nama || !$no_hp || empty($item_ids)) {
    header('Location: dashboard_kasir.php?error=Data+tidak+lengkap');
    exit;
}

// Validasi jumlah DP: wajib diisi, harus > 0, dan tidak boleh menutupi
// seluruh tagihan (kalau bayar penuh harusnya status "Sudah Lunas").
if ($status_bayar === 'dp') {
    if ($jumlah_dp <= 0) {
        header('Location: dashboard_kasir.php?error=' . urlencode('Jumlah DP harus diisi dan lebih dari 0.'));
        exit;
    }
    if ($jumlah_dp >= $total_harga) {
        header('Location: dashboard_kasir.php?error=' . urlencode('Jumlah DP tidak boleh sama/lebih dari total tagihan.'));
        exit;
    }
}

// Kalau metode bayarnya Transfer (dan ada pembayaran yang dicatat -- DP
// atau lunas), nama & no. pengirim WAJIB diisi supaya transfer bisa
// ditelusuri kalau ada selisih/komplain.
if ($metode === 'transfer' && ($status_bayar === 'dp' || $status_bayar === 'sudah_bayar')) {
    if ($nama_pengirim === '') {
        header('Location: dashboard_kasir.php?error=' . urlencode('Nama Pengirim wajib diisi untuk pembayaran Transfer.'));
        exit;
    }
}

try {
    $pdo->beginTransaction();

    // Generate ID Nota: AZK + tanggal + 4 huruf random
    $id_nota = 'AZK' . date('ymd') . strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));

    // total_dibayar awal: penuh kalau langsung lunas, sebesar DP kalau status "dp", 0 kalau belum bayar
    $total_dibayar_awal = $status_bayar === 'sudah_bayar' ? $total_harga : ($status_bayar === 'dp' ? $jumlah_dp : 0);

    // Insert transaksi
    $stmt = $pdo->prepare("
        INSERT INTO transaksi (id_nota, kasir_id, nama_pelanggan, no_hp_pelanggan, total_harga, status_bayar, total_dibayar, tanggal_estimasi)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$id_nota, $kasir_id, $nama, $no_hp, $total_harga, $status_bayar, $total_dibayar_awal, $estimasi ?: null]);
    $transaksi_id = $pdo->lastInsertId();

    // Insert detail transaksi berbasis katalog detail_barang (kategori + item)
    $stmtDetail = $pdo->prepare("
        INSERT INTO detail_transaksi (transaksi_id, detail_barang_id, qty, harga_satuan, subtotal, keterangan)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmtItem = $pdo->prepare("
        SELECT il.nama_item, il.satuan, il.harga
        FROM detail_barang il WHERE il.id_barang = ?
    ");

    $daftarLayanan = [];
    foreach ($item_ids as $i => $iid) {
        $iid = (int)$iid;
        $qty = (float)($qtys[$i] ?? 1);

        $stmtItem->execute([$iid]);
        $item = $stmtItem->fetch();
        if (!$item) continue; // item tidak ditemukan / sudah dihapus, lewati

        // Harga aktual: pakai input kasir jika valid (misal ada diskon manual),
        // fallback ke harga katalog kalau kosong/tidak valid.
        $harga = isset($hargas[$i]) ? (float)$hargas[$i] : (float)$item['harga'];
        if ($harga < 0) $harga = (float)$item['harga'];

        $subtotal = $harga * $qty;
        $catatan  = trim($catatans[$i] ?? '') ?: null;

        $stmtDetail->execute([$transaksi_id, $iid, $qty, $harga, $subtotal, $catatan]);

        $qtyFmt = rtrim(rtrim((string)$qty, '0'), '.');
        $daftarLayanan[] = "  - {$item['nama_item']} ({$qtyFmt} {$item['satuan']}) = Rp " . number_format($subtotal, 0, ',', '.');
    }

    if (empty($daftarLayanan)) {
        throw new Exception('Tidak ada barang valid yang dipilih.');
    }

    // Insert pembayaran jika sudah bayar (lunas penuh) atau DP (bayar sebagian)
    if ($status_bayar === 'sudah_bayar' || $status_bayar === 'dp') {
        $jumlahBayarAwal = $status_bayar === 'sudah_bayar' ? $total_harga : $jumlah_dp;
        $stmtPay = $pdo->prepare("
            INSERT INTO pembayaran (transaksi_id, jumlah_bayar, metode, nama_pengirim, no_pengirim, kasir_id)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmtPay->execute([
            $transaksi_id, $jumlahBayarAwal, $metode,
            $metode === 'transfer' ? $nama_pengirim : null,
            $metode === 'transfer' ? $no_pengirim : null,
            $kasir_id,
        ]);
    }

    // Fallback insert status_cucian jika trigger belum jalan
    $cekStatus = $pdo->prepare("SELECT COUNT(*) FROM status_cucian WHERE transaksi_id = ?");
    $cekStatus->execute([$transaksi_id]);
    if ($cekStatus->fetchColumn() == 0) {
        $pdo->prepare("INSERT INTO status_cucian (transaksi_id, status) VALUES (?, 'dalam_antrian')")
            ->execute([$transaksi_id]);
    }

    // Kalau transaksi ini berasal dari Pesanan Online (lihat transaksi_baru.php
    // ?dari_online=<id>), tandai pesanan itu 'dikonversi' & kaitkan ke
    // transaksi_id yang baru dibuat -- supaya tidak muncul lagi di daftar
    // pesanan_online.php yang masih menunggu, dan ketahuan kasir mana yang
    // mengonversinya & kapan. Divalidasi ulang status='menunggu' di WHERE
    // supaya tidak dobel-konversi kalau ada race condition (2 kasir buka
    // link yang sama nyaris bersamaan).
    if ($pesanan_online_id > 0) {
        $pdo->prepare("
            UPDATE pesanan_online
            SET status = 'dikonversi', transaksi_id = ?, kasir_id = ?, dikonversi_at = NOW()
            WHERE id_pesanan_online = ? AND status = 'menunggu'
        ")->execute([$transaksi_id, $kasir_id, $pesanan_online_id]);
    }

    $pdo->commit();

    // Notifikasi WA "transaksi diterima" TIDAK dikirim langsung di sini --
    // cukup ditandai lewat notif_id di URL, lalu browser (dashboard_kasir.php)
    // yang antrekan pengiriman-nya (dengan jeda 20-30 detik, lewat
    // includes/notif_wa_widget.php). Pesannya sendiri dibangun ulang dari
    // database saat benar-benar dikirim, lihat includes/notif_helper.php.

    header('Location: dashboard_kasir.php?success=1&nota=' . urlencode($id_nota) . '&notif_id=' . $transaksi_id);
    exit;

} catch (Exception $e) {
    $pdo->rollBack();
    header('Location: dashboard_kasir.php?error=' . urlencode('Gagal simpan: ' . $e->getMessage()));
    exit;
}