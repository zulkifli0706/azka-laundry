<?php
require_once __DIR__ . '/includes/config.php';
requireRole('owner');

header('Content-Type: application/json; charset=utf-8');

$id_nota = trim($_GET['id_nota'] ?? '');

if ($id_nota === '') {
    echo json_encode(['success' => false, 'message' => 'Parameter id_nota tidak ditemukan.']);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT
            t.id_transaksi,
            t.id_nota,
            t.nama_pelanggan,
            t.no_hp_pelanggan                AS no_hp,
            t.total_harga,
            t.status_bayar,
            t.total_dibayar,
            t.tanggal_estimasi,
            t.created_at,
            t.updated_at,
            COALESCE(s.status, 'dalam_antrian') AS status,
            CASE WHEN s.status = 'selesai' THEN s.updated_at ELSE NULL END AS tanggal_diambil,
            u.name                           AS nama_kasir
        FROM transaksi t
        LEFT JOIN status_cucian s ON t.id_transaksi = s.transaksi_id
        LEFT JOIN users u         ON t.kasir_id = u.id_user
        WHERE t.id_nota = ?
        LIMIT 1
    ");
    $stmt->execute([$id_nota]);
    $transaksi = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$transaksi) {
        echo json_encode(['success' => false, 'message' => 'Transaksi tidak ditemukan.']);
        exit;
    }

    // Ambil detail item layanan (kategori_barang + detail_barang)
    $stmtItems = $pdo->prepare("
        SELECT
            dt.id_detail_transaksi,
            COALESCE(il.nama_item, dt.keterangan, 'Barang') AS nama_layanan,
            il.nama_item        AS nama_item_detail,
            il.harga,
            dt.qty              AS jumlah,
            COALESCE(il.satuan, 'pcs') AS satuan,
            dt.harga_satuan,
            dt.subtotal,
            dt.keterangan
        FROM detail_transaksi dt
        LEFT JOIN detail_barang il ON dt.detail_barang_id = il.id_barang
        WHERE dt.transaksi_id = ?
        ORDER BY dt.id_detail_transaksi ASC
    ");
    $stmtItems->execute([$transaksi['id_transaksi']]);
    $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

    // Rincian pembayaran (bisa lebih dari 1 baris kalau DP lalu pelunasan
    // terpisah) -- termasuk nama & no. pengirim transfer, supaya owner bisa
    // menelusuri transfer tanpa perlu buka database langsung.
    $stmtBayar = $pdo->prepare("
        SELECT jumlah_bayar, metode, nama_pengirim, no_pengirim, created_at
        FROM pembayaran WHERE transaksi_id = ? ORDER BY created_at ASC, id_pembayaran ASC
    ");
    $stmtBayar->execute([$transaksi['id_transaksi']]);
    $pembayaran = $stmtBayar->fetchAll(PDO::FETCH_ASSOC);
    foreach ($pembayaran as &$p) {
        $p['jumlah_bayar'] = (int) $p['jumlah_bayar'];
    }
    unset($p);

    $transaksi['total_harga']   = (int) $transaksi['total_harga'];
    $transaksi['total_dibayar'] = (int) ($transaksi['total_dibayar'] ?? 0);
    $transaksi['sisa_bayar']    = (int) sisaBayar($transaksi['total_harga'], $transaksi['total_dibayar']);

    foreach ($items as &$item) {
        $item['jumlah']       = (float) $item['jumlah'];
        $item['harga_satuan'] = (int)   $item['harga_satuan'];
        $item['subtotal']     = (int)   $item['subtotal'];
        $item['harga']        = (int)   ($item['harga'] ?? 0);
    }
    unset($item);

    echo json_encode([
        'success'    => true,
        'transaksi'  => $transaksi,
        'items'      => $items,
        'pembayaran' => $pembayaran,
    ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_QUOT);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}