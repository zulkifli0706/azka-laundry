<?php
/**
 * Endpoint PUBLIK (tanpa login) untuk menyimpan pesanan online dari
 * kalkulator estimasi.php. Dipanggil via fetch() AJAX, bukan form submit
 * biasa, supaya halaman tidak reload dan bisa langsung tampilkan kode
 * pesanan + instruksi lanjutan ke pelanggan.
 *
 * PENTING: harga & qty yang dikirim dari sini HANYA dipakai sebagai
 * ESTIMASI AWAL (disimpan apa adanya ke pesanan_online_detail). Saat kasir
 * mengkonversi pesanan ini jadi transaksi asli (lihat pesanan_online.php +
 * transaksi_baru.php), qty/berat WAJIB diisi ulang oleh kasir -- endpoint
 * ini TIDAK membuat baris di tabel `transaksi` sama sekali.
 */
require_once __DIR__ . '/includes/config.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Metode tidak diizinkan.']);
    exit;
}

$nama  = trim($_POST['nama_pelanggan'] ?? '');
$noHp  = trim($_POST['no_hp_pelanggan'] ?? '');
$itemsRaw = $_POST['items'] ?? '[]';
$items = json_decode($itemsRaw, true);

if ($nama === '' || $noHp === '') {
    echo json_encode(['success' => false, 'message' => 'Nama dan No. HP wajib diisi.']);
    exit;
}
if (strlen($nama) > 100) {
    echo json_encode(['success' => false, 'message' => 'Nama terlalu panjang.']);
    exit;
}
// Validasi No. HP longgar (angka, spasi, +, -, min 8 digit) -- konsisten
// dengan validasi di transaksi_baru.php, supaya nomor yang lolos di sini
// juga pasti valid dipakai kirim WA nanti.
$noHpDigit = preg_replace('/[^0-9]/', '', $noHp);
if (strlen($noHpDigit) < 8 || strlen($noHpDigit) > 15) {
    echo json_encode(['success' => false, 'message' => 'Nomor HP tidak valid.']);
    exit;
}
if (!is_array($items) || empty($items)) {
    echo json_encode(['success' => false, 'message' => 'Keranjang kosong, pilih minimal 1 barang dulu.']);
    exit;
}
if (count($items) > 50) {
    echo json_encode(['success' => false, 'message' => 'Terlalu banyak barang dalam satu pesanan.']);
    exit;
}

// Validasi tiap baris item terhadap katalog ASLI di database (jangan
// percaya harga/nama kiriman dari browser mentah-mentah) -- qty tetap
// dipercaya dari input pelanggan karena itu memang cuma estimasi.
$detailBarangIds = array_map(fn($i) => (int)($i['detail_barang_id'] ?? 0), $items);
$detailBarangIds = array_filter($detailBarangIds);
if (empty($detailBarangIds)) {
    echo json_encode(['success' => false, 'message' => 'Data barang tidak valid.']);
    exit;
}
$in = implode(',', array_map('intval', $detailBarangIds));
$stmtKatalog = $pdo->query("SELECT id_barang, nama_item, satuan, harga FROM detail_barang WHERE id_barang IN ($in) AND is_aktif = 1");
$katalog = [];
foreach ($stmtKatalog->fetchAll() as $k) {
    $katalog[$k['id_barang']] = $k;
}

$rows = [];
foreach ($items as $it) {
    $dbId = (int)($it['detail_barang_id'] ?? 0);
    $qty  = (float)($it['qty'] ?? 0);
    if (!isset($katalog[$dbId]) || $qty <= 0) {
        continue; // lewati baris yang barangnya tidak valid/sudah nonaktif
    }
    $k = $katalog[$dbId];
    $rows[] = [
        'detail_barang_id'  => $dbId,
        'nama_item'         => $k['nama_item'],
        'satuan'            => $k['satuan'],
        'qty_estimasi'      => $qty,
        'harga_satuan'      => (float)$k['harga'],
        'subtotal_estimasi' => $qty * (float)$k['harga'],
    ];
}
if (empty($rows)) {
    echo json_encode(['success' => false, 'message' => 'Semua barang di keranjang sudah tidak tersedia, silakan pilih ulang.']);
    exit;
}

try {
    $pdo->beginTransaction();

    // Kode pesanan sengaja dibuat PENDEK (5 karakter) supaya gampang
    // diucapkan/diingat pelanggan saat datang ke laundry -- dicek unik
    // dengan retry loop kecil. Karakter ambigu (0/O, 1/I) sengaja
    // dikeluarkan dari charset supaya tidak salah baca/ketik.
    $charset = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    do {
        $kode = '';
        for ($i = 0; $i < 5; $i++) {
            $kode .= $charset[random_int(0, strlen($charset) - 1)];
        }
        $cek = $pdo->prepare("SELECT 1 FROM pesanan_online WHERE kode = ?");
        $cek->execute([$kode]);
    } while ($cek->fetch());

    $pdo->prepare("
        INSERT INTO pesanan_online (kode, nama_pelanggan, no_hp_pelanggan, status)
        VALUES (?, ?, ?, 'menunggu')
    ")->execute([$kode, $nama, $noHp]);
    $pesananId = (int) $pdo->lastInsertId();

    $stmtDetail = $pdo->prepare("
        INSERT INTO pesanan_online_detail
            (pesanan_online_id, detail_barang_id, nama_item, satuan, qty_estimasi, harga_satuan, subtotal_estimasi)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $totalEstimasi = 0;
    foreach ($rows as $r) {
        $stmtDetail->execute([
            $pesananId, $r['detail_barang_id'], $r['nama_item'], $r['satuan'],
            $r['qty_estimasi'], $r['harga_satuan'], $r['subtotal_estimasi'],
        ]);
        $totalEstimasi += $r['subtotal_estimasi'];
    }

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'kode'    => $kode,
        'total'   => $totalEstimasi,
    ]);
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('Gagal simpan pesanan online: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Terjadi kesalahan server, coba lagi.']);
}
