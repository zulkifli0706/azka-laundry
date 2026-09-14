<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/proses_helper.php';
requireRole('kasir', 'owner');

// ── STATISTIK ──
$trx_hari_ini    = $pdo->query("SELECT COUNT(*) FROM transaksi WHERE DATE(created_at) = CURDATE()")->fetchColumn();
$dalam_antrian   = $pdo->query("SELECT COUNT(*) FROM status_cucian WHERE status = 'dalam_antrian'")->fetchColumn();
$jml_pesanan_online = $pdo->query("SELECT COUNT(*) FROM pesanan_online WHERE status = 'menunggu'")->fetchColumn();
$sedang_diproses = $pdo->query("SELECT COUNT(*) FROM status_cucian WHERE status = 'sedang_diproses'")->fetchColumn();
$siap_diambil    = $pdo->query("SELECT COUNT(*) FROM status_cucian WHERE status = 'siap_diambil'")->fetchColumn();
$selesai_10hari  = $pdo->query("
    SELECT COUNT(*)
    FROM status_cucian s
    JOIN transaksi t ON t.id_transaksi = s.transaksi_id
    WHERE s.status = 'selesai' AND t.created_at >= NOW() - INTERVAL 10 DAY
")->fetchColumn();

// ── TRANSAKSI ──
// Catatan: transaksi yang belum selesai selalu tampil (biar kerjaan gak ilang dari
// pantauan kasir), sedangkan yang sudah "selesai" cuma ditampilkan kalau DIBUAT
// dalam 10 hari terakhir — supaya filter tab "10 Hari Terakhir" di bawah datanya lengkap.
$transaksi = $pdo->query("
    SELECT t.*, s.status AS status_cucian, s.tahap_proses, u.name AS nama_kasir
    FROM transaksi t
    LEFT JOIN status_cucian s ON t.id_transaksi = s.transaksi_id
    LEFT JOIN users u ON t.kasir_id = u.id_user
    WHERE
        s.status IS NULL
        OR s.status != 'selesai'
        OR (s.status = 'selesai' AND t.created_at >= NOW() - INTERVAL 10 DAY)
    ORDER BY t.created_at DESC
    LIMIT 100
")->fetchAll();

$transaksiIds = array_column($transaksi, 'id_transaksi');
$detailPerTrx = [];
if (!empty($transaksiIds)) {
    $inClause   = implode(',', array_map('intval', $transaksiIds));
    $detailRows = $pdo->query("
        SELECT dt.transaksi_id,
               COALESCE(il.nama_item, dt.keterangan, 'Barang') AS nama_layanan,
               COALESCE(il.satuan, 'pcs') AS satuan,
               dt.qty, dt.subtotal,
               COALESCE(il.tipe_proses, 'cuci_setrika') AS tipe_proses,
               kl.nama_kategori,
               ds.tahap_proses AS tahap_item
        FROM detail_transaksi dt
        LEFT JOIN detail_barang il    ON il.id_barang = dt.detail_barang_id
        LEFT JOIN kategori_barang kl ON kl.id_kategori = il.kategori_id
        LEFT JOIN detail_status ds   ON ds.detail_transaksi_id = dt.id_detail_transaksi
        WHERE dt.transaksi_id IN ($inClause)
        ORDER BY dt.id_detail_transaksi
    ")->fetchAll();
    foreach ($detailRows as $row) {
        $detailPerTrx[$row['transaksi_id']][] = $row;
    }
}

// ── DATA RINCIAN UNTUK MODAL DETAIL (JS) ──
// Dibangun terpisah dari WA agar rincian tetap bisa dilihat walau status sudah "selesai".
$rincianJson = [];
foreach ($transaksi as $t) {
    $items = $detailPerTrx[$t['id_transaksi']] ?? [];
    $rincianJson[$t['id_transaksi']] = [
        'nota'         => $t['id_nota'],
        'pelanggan'    => $t['nama_pelanggan'],
        'no_hp'        => $t['no_hp_pelanggan'],
        'tanggal'      => date('d/m/Y H:i', strtotime($t['created_at'])),
        'estimasi'     => !empty($t['tanggal_estimasi']) ? date('d/m/Y', strtotime($t['tanggal_estimasi'])) : '-',
        'status_bayar' => $t['status_bayar'],
        'total_dibayar'=> (float)($t['total_dibayar'] ?? 0),
        'sisa'         => sisaBayar($t['total_harga'], $t['total_dibayar'] ?? 0),
        'status_label' => match($t['status_cucian'] ?? 'dalam_antrian') {
            'dalam_antrian'   => 'Dalam Antrian',
            'sedang_diproses' => 'Sedang Diproses',
            'siap_diambil'    => 'Siap Diambil',
            'selesai'         => 'Selesai',
            default           => 'Dalam Antrian',
        },
        'total'        => (float)$t['total_harga'],
        'items'        => array_map(function ($i) use ($t) {
            $st = statusItemAsli(
                $i['tipe_proses'],
                $t['status_cucian'] ?? 'dalam_antrian',
                $i['tahap_item'],
                $i['nama_kategori'] ?? null
            );
            return [
                'nama'         => $i['nama_layanan'],
                'qty'          => rtrim(rtrim($i['qty'], '0'), '.'),
                'satuan'       => $i['satuan'],
                'subtotal'     => (float)$i['subtotal'],
                'status_label' => $st['label'],
                'status_state' => $st['state'],
                'status_icon'  => $st['icon'],
            ];
        }, $items),
    ];
}

$namaKasir = htmlspecialchars($_SESSION['user']['name']);
$inisial    = strtoupper(substr($_SESSION['user']['name'], 0, 2));

function generateWaManual($t, $items) {
    $noHp   = preg_replace('/^0/', '62', preg_replace('/\D/', '', $t['no_hp_pelanggan']));
    $tglEst = !empty($t['tanggal_estimasi']) ? date('d/m/Y', strtotime($t['tanggal_estimasi'])) : '-';
    $sc     = $t['status_cucian'] ?? 'dalam_antrian';

    $daftarBarang = '';
    foreach ($items as $item) {
        $qty      = rtrim(rtrim($item['qty'], '0'), '.');
        $subtotal = 'Rp ' . number_format($item['subtotal'], 0, ',', '.');
        $daftarBarang .= "  - " . $item['nama_layanan'] . " (" . $qty . " " . $item['satuan'] . ") = " . $subtotal . "\n";
    }

    if ($sc === 'siap_diambil') {
        $pesan =
            "Kepada Yth. " . $t['nama_pelanggan'] . ",\n\n" .
            "Cucian Anda sudah selesai dan siap diambil.\n\n" .
            "- No. Nota : `" . $t['id_nota'] . "`\n\n" .
            "Rincian Barang:\n" . $daftarBarang .
            "\nTotal: Rp " . number_format($t['total_harga'], 0, ',', '.') . "\n\n" .
            "Silakan datang ke Azka Laundry.\nJam operasional: 08.00 - 20.00 WIB.\n\nHormat kami,\nAzka Laundry";
    } else {
        $pesan =
            "Kepada Yth. " . $t['nama_pelanggan'] . ",\n\n" .
            "Pakaian Anda telah kami terima.\n\n" .
            "- No. Nota     : `" . $t['id_nota'] . "`\n" .
            "- Est. Selesai : " . $tglEst . "\n\n" .
            "Rincian Barang:\n" . $daftarBarang .
            "\nTotal: Rp " . number_format($t['total_harga'], 0, ',', '.') . "\n\nHormat kami,\nAzka Laundry";
    }

    return 'https://wa.me/' . $noHp . '?text=' . rawurlencode($pesan);
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>Dashboard Kasir — Azka Laundry</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root {
    --sky:        #4E90D9;
    --sky-deep:   #2E74C0;
    --sky-ink:    #164A85;
    --sky-soft:   #EAF2FB;
    --sky-pale:   #EAF2FB;
    --cloud:      #FFFFFF;
    --border:     #D6E7F7;
    --ink:        #164A85;
    --gray:       #2E74C0;
    --gray-soft:  #4E90D9;
}
* { font-family:'Plus Jakarta Sans',sans-serif; box-sizing:border-box; }
body { background:var(--sky-pale); color:#101418; }

.sidebar { width:230px; height:100vh; height:100dvh; background:var(--sky-ink); position:fixed; left:0; top:0; display:flex; flex-direction:column; z-index:100; overflow-y:auto; }
.sidebar-brand { padding:20px; border-bottom:1px solid rgba(255,255,255,0.14); display:flex; align-items:center; gap:10px; }
.sidebar-brand svg { width:28px; height:28px; flex-shrink:0; }
.sidebar-brand h4 { color:#FFFFFF; font-size:16px; font-weight:700; margin:0; }
.sidebar-brand small { color:rgba(255,255,255,0.55); font-size:11px; }
.sidebar-menu { padding:14px 10px; flex:1; }
.sidebar-menu a { display:flex; align-items:center; gap:10px; color:rgba(255,255,255,0.68); text-decoration:none; padding:10px 12px; border-radius:8px; margin-bottom:2px; font-size:13px; font-weight:500; transition:all 0.2s; }
.sidebar-menu a:hover, .sidebar-menu a.active { background:rgba(255,255,255,0.14); color:#FFFFFF; }
.sidebar-menu a.active { border-left:3px solid #FFFFFF; }
.sidebar-menu a i { width:16px; text-align:center; }
.sidebar-badge { background:var(--cloud); color:#101418; font-size:10px; font-weight:700; padding:2px 7px; border-radius:20px; margin-left:auto; }
.sidebar-badge.green { background:#1F5FA6; color:#FFFFFF; }
.sidebar-footer { padding:14px 10px calc(14px + env(safe-area-inset-bottom)); border-top:1px solid rgba(255,255,255,0.14); flex-shrink:0; }
.sidebar-footer a { display:flex; align-items:center; gap:10px; color:rgba(255,255,255,0.55); text-decoration:none; padding:9px 12px; border-radius:8px; font-size:13px; transition:all 0.2s; }
.sidebar-footer a:hover { background:rgba(255,255,255,0.14); color:#FFFFFF; }

.main { margin-left:230px; }

/* ===== MOBILE SIDEBAR DRAWER ===== */
.hamburger-btn {
    display:none; position:fixed; top:12px; left:14px; z-index:200;
    background:var(--sky-ink); color:#FFFFFF; border:none; width:42px; height:42px;
    border-radius:10px; font-size:18px; align-items:center; justify-content:center;
    cursor:pointer; box-shadow:0 3px 10px rgba(16,20,24,0.28);
}
.hamburger-btn:active { transform:scale(0.94); }
.sidebar-overlay { display:none; position:fixed; inset:0; background:rgba(22,74,133,0.5); z-index:100; }
.sidebar-overlay.show { display:block; }
.sidebar { transition:transform 0.28s ease; }
@media (max-width: 900px) {
    .hamburger-btn { display:flex; }
    .sidebar { transform:translateX(-100%); box-shadow:2px 0 20px rgba(16,20,24,0.28); }
    .sidebar.open { transform:translateX(0); }
    .main { margin-left:0 !important; }
    .topbar { padding:12px 16px 12px 62px !important; flex-wrap:wrap; row-gap:8px; }
    .topbar-title { font-size:15px !important; }
    .content { padding:16px !important; }
}
@media (max-width: 480px) {
    .topbar-right { gap:8px !important; }
    .stat-num { font-size:20px !important; }
    .content { padding:10px !important; }
    .trx-table-wrap { padding:0 !important; }
}
.topbar { background:var(--cloud); border-bottom:1px solid var(--border); padding:14px 26px; display:flex; justify-content:space-between; align-items:center; position:sticky; top:0; z-index:99; }
.topbar-title { font-size:17px; font-weight:700; color:#101418; }
.topbar-title small { display:block; font-family:'Plus Jakarta Sans',sans-serif; font-size:12px; color:#3A4450; font-weight:400; }
.topbar-right { display:flex; align-items:center; gap:12px; }
.avatar { width:36px; height:36px; border-radius:50%; background:var(--sky); display:flex; align-items:center; justify-content:center; color:#FFFFFF; font-weight:700; font-size:12px; }
.user-name { font-size:13px; font-weight:600; color:#101418; }
.user-role { font-size:11px; color:#4B5563; }
.content { padding:22px 26px; }

/* ── STAT CARDS ── */
.stat-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    margin-bottom: 20px;
}
@media (max-width: 900px) {
    .stat-grid { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 520px) {
    .stat-grid { grid-template-columns: repeat(4, 1fr); gap:6px; }
}
.stat-card {
    background: var(--cloud);
    border-radius: 12px;
    padding: 16px 20px;
    border: 1px solid var(--border);
    border-left: 4px solid var(--sky-deep);
    display: flex;
    align-items: center;
    gap: 14px;
    position: relative;
    overflow: hidden;
}
.stat-card.warn  { border-left-color: #3A4450; }
.stat-card.blue  { border-left-color: #4E90D9; }
.stat-card.ok    { border-left-color: #1F5FA6; }

.stat-icon-wrap {
    width: 44px; height: 44px; border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
    background: var(--sky-soft);
    color: var(--sky-deep);
    font-size: 17px;
}
.stat-card.warn .stat-icon-wrap { background:#EAF2FB; color:#3A4450; }
.stat-card.blue .stat-icon-wrap { background:#EAF2FB; color:#3A4450; }
.stat-card.ok   .stat-icon-wrap { background:#EAF2FB; color:#1B2126; }

.stat-info { position: relative; z-index: 1; }
.stat-label { font-size: 11px; color: #3A4450; margin-bottom: 3px; font-weight: 600; letter-spacing: 0.3px; text-transform: uppercase; }
.stat-num   { font-size:24px; font-weight:800; color:#101418; line-height:1; }

/* TOMBOL TAMBAH TRANSAKSI */
.btn-tambah-trx {
    display:inline-flex; align-items:center; gap:8px;
    background:var(--sky); color:#FFFFFF; text-decoration:none;
    padding:10px 18px; border-radius:12px; font-size:13px; font-weight:700;
    transition:background 0.2s;
}
.btn-tambah-trx:hover { background:var(--sky-deep); color:#FFFFFF; }

/* TABEL */
.trx-card { background:var(--cloud); border-radius:16px; border:1px solid var(--border); overflow:hidden; }
.trx-card-header { padding:16px 20px; background:var(--cloud); border-bottom:1px solid var(--border); display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; }
.trx-card-header h5 { font-size:15px; font-weight:700; color:#101418; margin:0; display:flex; align-items:center; gap:8px; }

/* FILTER TAB TANGGAL */
.filter-tabs { display:flex; align-items:center; gap:6px; padding:12px 20px 0; flex-wrap:nowrap; overflow-x:auto; -webkit-overflow-scrolling:touch; scrollbar-width:none; }
.filter-tabs::-webkit-scrollbar { display:none; }
.filter-tab { background:var(--sky-pale); color:#3A4450; border:1px solid var(--border); border-radius:20px; padding:6px 14px; font-size:12.5px; font-weight:600; cursor:pointer; font-family:inherit; transition:all 0.15s; white-space:nowrap; }
.filter-tab:hover { background:var(--sky-soft); color:var(--sky-deep); }
.filter-tab.active { background:var(--sky); color:#FFFFFF; border-color:var(--sky); }
.trx-table { width:100%; border-collapse:collapse; font-size:13px; }
.trx-table th { padding:10px 16px; text-align:left; font-size:11px; font-weight:700; color:#4B5563; letter-spacing:0.5px; border-bottom:1px solid var(--border); }
.trx-table td { padding:11px 16px; border-bottom:1px solid var(--sky-soft); color:#101418; vertical-align:middle; }
.trx-table tr:last-child td { border-bottom:none; }
.trx-table tr:hover td { background:var(--sky-pale); }
.trx-table tr.row-selesai td { opacity:0.55; }
.nota-code { font-family:monospace; font-weight:700; color:#101418; font-size:13px; }

/* BADGE STATUS */
.badge-status { padding:4px 10px; border-radius:20px; font-size:11px; font-weight:700; display:inline-flex; align-items:center; gap:5px; white-space:nowrap; }
.badge-status i { font-size:10px; }
.badge-antrian { background:#D6E7F7; color:#3A4450; }
.badge-proses  { background:var(--sky-soft); color:var(--sky-deep); }
.badge-siap    { background:#D6E7F7; color:#1B2126; }
.badge-selesai { background:#EAF2FB; color:#3A4450; }
.badge-lunas   { background:#D6E7F7; color:#1B2126; }
.badge-dp      { background:#D6E7F7; color:#3A4450; }
.badge-belum   { background:#D6E7F7; color:#3A4450; }

/* TOMBOL AKSI */
.btn-acts { display:flex; gap:6px; flex-wrap:nowrap; }
.btn-act { padding:7px 12px; border-radius:8px; font-size:11.5px; font-weight:700; border:1.5px solid #1B2126; cursor:pointer; text-decoration:none; display:inline-flex; align-items:center; justify-content:center; gap:6px; transition:all 0.15s; white-space:nowrap; font-family:inherit; background:#FFFFFF; color:#1B2126; }
.btn-act:hover { background:#1B2126; color:#FFFFFF; }

/* Baris aksi khusus HP: tombol berjejer horizontal di bawah data, bukan menumpuk di kolom sempit */
.trx-actions-row { display:none; }

/* Seragam: semua tombol aksi (Detail/WA/Bayar/Edit/Hapus) pakai warna & bentuk yang sama, lebar tetap biar berbaris rapi antar baris */
.btn-detail, .btn-wa, .btn-edit, .btn-bayar, .btn-hapus { background:#FFFFFF; color:#1B2126; border-color:#1B2126; padding:7px 12px; }
.btn-detail:hover, .btn-wa:hover, .btn-edit:hover, .btn-bayar:hover, .btn-hapus:hover { background:#1B2126; color:#FFFFFF; border-color:#1B2126; }
.btn-detail { min-width:68px; }
.btn-wa     { min-width:50px; }
.btn-bayar  { min-width:106px; }
.btn-edit   { min-width:56px; }
.btn-hapus  { min-width:64px; }

/* ALERT */
.alert-ok  { background:#D6E7F7; border:1px solid #AECBEF; color:#1B2126; border-radius:12px; padding:14px 18px; margin-bottom:16px; font-size:13px; display:flex; align-items:center; gap:10px; }
.alert-ok .wa-sent { background:#1F5FA6; color:#FFFFFF; font-size:11px; font-weight:700; padding:3px 10px; border-radius:20px; margin-left:auto; }
.alert-err { background:#EAF2FB; border:1px solid #AECBEF; color:#101418; border-radius:12px; padding:14px 18px; margin-bottom:16px; font-size:13px; }

.empty-state { text-align:center; padding:40px; color:#4B5563; font-size:14px; }

.search-wrap { position:relative; }
.search-wrap input { width:100%; padding:9px 14px 9px 36px; border:1.5px solid var(--border); border-radius:10px; font-size:13px; font-family:inherit; outline:none; background:var(--cloud); transition:border-color 0.2s; }
.search-wrap input:focus { border-color:var(--sky); }
.search-wrap i { position:absolute; left:12px; top:50%; transform:translateY(-50%); color:#4B5563; font-size:12px; }

/* MODAL DETAIL */
.modal-overlay { position:fixed; inset:0; background:rgba(22,74,133,0.45); z-index:1000; align-items:center; justify-content:center; padding:16px; }
.modal-box { background:#FFFFFF; border-radius:16px; width:420px; max-width:92vw; max-height:85vh; overflow-y:auto; }
.modal-box .form-card-header { border-radius:16px 16px 0 0; background:var(--sky-deep); padding:16px 20px; color:#FFFFFF; font-size:15px; font-weight:700; display:flex; align-items:center; gap:8px; }
.modal-close-btn { margin-left:auto; background:none; border:none; color:#FFFFFF; font-size:16px; cursor:pointer; }
.modal-body { padding:20px; }
.detail-section-title { font-size:12px; font-weight:700; color:#101418; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:8px; }
.item-row { display:flex; align-items:center; gap:8px; background:var(--sky-pale); border-radius:10px; padding:10px 12px; margin-bottom:8px; font-size:13px; flex-wrap:wrap; }
.item-row .item-name  { flex:1; min-width:60%; font-weight:600; color:#101418; }
.item-row .item-price { color:var(--sky-deep); font-weight:600; min-width:90px; text-align:right; }
.item-status { padding:3px 9px; border-radius:20px; font-size:10.5px; font-weight:700; white-space:nowrap; flex-shrink:0; margin-top:4px; display:inline-block; }
.item-status.active  { background:#EAF2FB; color:#3A4450; }
.item-status.done    { background:#D6E7F7; color:#1B2126; }
.item-status.waiting { background:#EAF2FB; color:#3A4450; }
.total-box { background:var(--sky-deep); border-radius:12px; padding:16px 18px; display:flex; justify-content:space-between; align-items:center; margin-top:12px; }
.total-box .lbl { color:rgba(255,255,255,0.75); font-size:13px; }
.total-box .val { color:#FFFFFF; font-size:22px; font-weight:800; }

/* ===== MODAL INPUT DP / PEMBAYARAN ===== */
.bayar-head { display:flex; align-items:center; gap:12px; margin-bottom:16px; padding-bottom:14px; border-bottom:1px dashed var(--sky-soft); }
.bayar-head-avatar { width:42px; height:42px; border-radius:11px; background:var(--sky-soft); color:var(--sky-deep); display:flex; align-items:center; justify-content:center; font-weight:800; font-size:14px; flex-shrink:0; }
.bayar-head-nama { font-size:13px; color:#3A4450; margin-top:2px; }

.bayar-summary-card { background:var(--sky-pale); border:1px solid var(--border); border-radius:12px; padding:6px 16px; margin-bottom:18px; }
.bayar-summary-row { display:flex; justify-content:space-between; align-items:center; padding:9px 0; font-size:13px; }
.bayar-summary-row:not(:last-child) { border-bottom:1px dashed var(--border); }
.bayar-summary-row span { color:#3A4450; }
.bayar-summary-row strong { color:#101418; font-weight:700; }
.bayar-summary-row.highlight strong { color:var(--sky-deep); font-size:16px; font-weight:800; }

.form-group { margin-bottom:18px; }
.form-group:last-of-type { margin-bottom:20px; }
.form-label { display:block; font-size:12.5px; font-weight:700; color:#101418; margin-bottom:8px; letter-spacing:0.2px; }

.input-rp { position:relative; }
.input-rp-prefix { position:absolute; left:14px; top:50%; transform:translateY(-50%); font-weight:700; color:var(--sky-deep); font-size:14.5px; pointer-events:none; }
.form-control { width:100%; border:1.5px solid var(--border); border-radius:10px; padding:12px 14px 12px 40px; font-size:16px; font-weight:700; font-family:inherit; color:#101418; background:var(--cloud); outline:none; transition:border-color .2s, box-shadow .2s; -moz-appearance:textfield; }
.form-control:focus { border-color:var(--sky); box-shadow:0 0 0 3px rgba(78,144,217,0.15); }
.bank-chip-row { display:flex; flex-wrap:wrap; gap:6px; }
.bank-chip { padding:6px 12px; border-radius:20px; border:1.5px solid var(--border); background:var(--cloud); color:#3A4450; font-size:11.5px; font-weight:600; cursor:pointer; font-family:inherit; }
.bank-chip:hover { border-color:var(--sky); color:var(--sky-deep); background:var(--sky-pale); }
.bank-chip.active { background:var(--sky); border-color:var(--sky); color:#FFFFFF; }
.form-control::placeholder { color:#9AAAB8; font-weight:600; }
.form-control::-webkit-outer-spin-button, .form-control::-webkit-inner-spin-button { -webkit-appearance:none; margin:0; }

.quick-amount-row { display:flex; gap:8px; margin-top:9px; }
.chip-amount { flex:1; background:var(--cloud); border:1.5px solid var(--border); color:var(--sky-deep); font-size:12px; font-weight:700; padding:9px 10px; border-radius:9px; cursor:pointer; font-family:inherit; transition:all .15s; }
.chip-amount:hover { background:var(--sky-soft); border-color:var(--sky); }
.chip-amount.lunas { color:#1F5FA6; }

.item-hint { font-size:11.5px; color:#4B5563; margin-top:8px; line-height:1.4; }

.method-toggle { display:flex; gap:8px; }
.method-btn { flex:1; display:flex; align-items:center; justify-content:center; gap:7px; padding:12px 10px; border-radius:10px; border:1.5px solid var(--border); background:var(--cloud); color:#3A4450; font-size:13px; font-weight:700; cursor:pointer; font-family:inherit; transition:all .15s; }
.method-btn:hover { border-color:var(--sky); color:var(--sky-deep); }
.method-btn.active { background:var(--sky); border-color:var(--sky); color:#FFFFFF; box-shadow:0 4px 12px rgba(78,144,217,0.35); }

.btn-simpan { width:100%; background:var(--sky-deep); color:#FFFFFF; border:none; border-radius:12px; padding:14px 18px; font-size:14px; font-weight:800; font-family:inherit; cursor:pointer; display:flex; align-items:center; justify-content:center; gap:8px; transition:background .2s, transform .1s; }
.btn-simpan:hover { background:var(--sky-ink); }
.btn-simpan:active { transform:scale(0.98); }

/* ===== TABEL -> KARTU DI HP ===== */
@media (max-width: 700px) {
    .trx-table-wrap { overflow-x:hidden; }
    .trx-table { table-layout:fixed; font-size:10.5px; }

    /* Nota, Pelanggan, Total, Status, Bayar */
    .trx-table th:nth-child(1), .trx-table td:nth-child(1) { width:27%; }
    .trx-table th:nth-child(2), .trx-table td:nth-child(2) { width:20%; }
    .trx-table th:nth-child(3), .trx-table td:nth-child(3) { width:15%; }
    .trx-table th:nth-child(4), .trx-table td:nth-child(4) { width:16%; }
    .trx-table th:nth-child(5), .trx-table td:nth-child(5) { width:22%; }
    /* Kolom Aksi asli disembunyikan di HP; digantikan baris .trx-actions-row di bawahnya */
    .trx-table th:nth-child(6) { display:none; }
    .trx-table td[data-label="Aksi"] { display:none; }

    .trx-table thead th { padding:6px 3px; font-size:7.5px; white-space:normal; word-break:break-word; }
    .trx-table tbody td { padding:6px 3px; font-size:9.5px; word-break:break-word; vertical-align:top; }

    .trx-table .nota-code { font-size:9px; white-space:nowrap; letter-spacing:-0.2px; }
    .trx-table td[data-label="Pelanggan"] { font-size:9.5px; }
    .trx-table td[data-label="Pelanggan"] small { font-size:8px; }
    .badge-status { font-size:8px; padding:2px 5px; white-space:normal; }

    /* Baris tombol aksi: tampil sebagai baris penuh di bawah data, tombol berjejer horizontal & bisa digeser */
    /* Baris data & baris aksi adalah 1 nota yang sama -> hilangkan garis di antara keduanya,
       garis pemisah cuma muncul setelah baris aksi (baru pindah ke nota berikutnya) */
    .trx-table tbody tr:not(.trx-actions-row) td { border-bottom:none; }
    .trx-actions-row { display:table-row; }
    .trx-actions-row td { border-bottom:1px solid var(--sky-soft); }
    .trx-actions-row td { padding:8px 3px 10px; }
    .trx-actions-row .btn-acts {
        flex-direction:row; flex-wrap:nowrap; gap:4px;
        overflow-x:auto; -webkit-overflow-scrolling:touch; padding-bottom:2px;
    }
    .trx-actions-row .btn-acts::-webkit-scrollbar { height:4px; }
    .trx-actions-row .btn-act {
        flex:0 0 auto; padding:6px 8px; font-size:9.5px; line-height:1.2; white-space:nowrap;
    }
    /* Lebar minimum tombol (dipakai biar rapi berbaris di desktop) dilonggarkan
       khusus di baris aksi HP, supaya kelima tombol (termasuk "Tambah Bayar")
       muat sebaris tanpa terpotong. */
    .trx-actions-row .btn-detail,
    .trx-actions-row .btn-wa,
    .trx-actions-row .btn-bayar,
    .trx-actions-row .btn-edit,
    .trx-actions-row .btn-hapus { min-width:0; }

    .trx-card-header { padding:14px 16px; }
    .search-wrap { width:100% !important; }
    .search-wrap input { font-size:16px; }

    .filter-tabs { padding:10px 12px 0; gap:5px; }
    .filter-tab { padding:6px 11px; font-size:11.5px; flex:0 0 auto; }
}

@media (max-width: 480px) {
    .stat-card { padding:14px 16px; gap:10px; }
    .stat-icon-wrap { width:38px; height:38px; font-size:15px; }
    .modal-box { width:100%; }
    .modal-body { padding:16px; }
    .method-toggle { flex-direction:column; }
    .trx-actions-row .btn-act { padding:6px 7px; font-size:9px; }
}

@media (max-width: 520px) {
    .stat-card {
        flex-direction:column; align-items:flex-start;
        padding:8px 6px; gap:2px; border-left-width:3px;
    }
    .stat-icon-wrap { display:none; }
    .stat-label { font-size:8px; margin-bottom:1px; line-height:1.2; white-space:normal; letter-spacing:0; }
    .stat-num { font-size:15px !important; }
}
</style>
</head>
<body>

<button type="button" class="hamburger-btn" id="hamburgerBtn" aria-label="Buka menu" onclick="toggleSidebar()"><span style="display:block;width:20px;height:2px;background:#fff;border-radius:2px;box-shadow:0 6px 0 #fff,0 -6px 0 #fff"></span></button>
<div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>

<div class="sidebar" id="sidebarEl">
  <div class="sidebar-brand">
    <svg viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg">
      <g transform="translate(32,34)">
        <circle cx="0" cy="2" r="22" fill="none" stroke="#FFFFFF" stroke-width="4"/>
        <circle cx="0" cy="4" r="12" fill="none" stroke="#FFFFFF" stroke-width="3.5"/>
        <rect x="-9" y="-26" width="18" height="7" rx="3.5" fill="#FFFFFF"/>
      </g>
    </svg>
    <div>
      <h4>Azka Laundry</h4>
      <small>Kasir Panel</small>
    </div>
  </div>
  <div class="sidebar-menu">
    <a href="dashboard_kasir.php" class="active">Dashboard</a>
    <a href="transaksi_baru.php">Tambah Transaksi</a>
    <a href="pesanan_online.php">
      Pesanan Online
      <?php if ($jml_pesanan_online > 0): ?>
      <span class="sidebar-badge green"><?= $jml_pesanan_online ?></span>
      <?php endif; ?>
    </a>
    <a href="antrian_kasir.php">
      Antrian
      <?php if ($dalam_antrian > 0): ?>
      <span class="sidebar-badge"><?= $dalam_antrian ?></span>
      <?php endif; ?>
    </a>
    <a href="sedang_diproses.php">
      Sedang Diproses
      <?php if ($sedang_diproses > 0): ?>
      <span class="sidebar-badge"><?= $sedang_diproses ?></span>
      <?php endif; ?>
    </a>
    <a href="siap_diambil.php">
      Siap Diambil
      <?php if ($siap_diambil > 0): ?>
      <span class="sidebar-badge green"><?= $siap_diambil ?></span>
      <?php endif; ?>
    </a>
  </div>
  <div class="sidebar-footer">
    <a href="logout.php">Logout</a>
  </div>
</div>

<div class="main">
  <div class="topbar">
    <div>
      <div class="topbar-title">
        Dashboard Kasir
        <small>Selamat datang, <?= $namaKasir ?> &middot; <?= date('d M Y') ?></small>
      </div>
    </div>
    <div class="topbar-right">
      <div style="text-align:right">
        <div class="user-name"><?= $namaKasir ?></div>
        <div class="user-role">Kasir</div>
      </div>
      <div class="avatar"><?= $inisial ?></div>
    </div>
  </div>

  <div class="content">

    <?php if (isset($_GET['success'])): ?>
    <div class="alert-ok">
            Transaksi berhasil disimpan. ID Nota: <strong><?= htmlspecialchars($_GET['nota']) ?></strong>
    </div>
    <?php elseif (isset($_GET['ok'])): ?>
    <div class="alert-ok"><?= htmlspecialchars($_GET['ok']) ?></div>
    <?php elseif (isset($_GET['error'])): ?>
    <div class="alert-err"><?= htmlspecialchars($_GET['error']) ?></div>
    <?php endif; ?>

    <!-- PANEL STATISTIK -->
    <div class="stat-grid">
      <div class="stat-card blue">
        <div class="stat-info">
          <div class="stat-label">Transaksi Hari Ini</div>
          <div class="stat-num"><?= $trx_hari_ini ?></div>
        </div>
      </div>
      <div class="stat-card warn">
        <div class="stat-info">
          <div class="stat-label">Sedang Diproses</div>
          <div class="stat-num"><?= $sedang_diproses ?></div>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-info">
          <div class="stat-label">Siap Diambil</div>
          <div class="stat-num"><?= $siap_diambil ?></div>
        </div>
      </div>
      <div class="stat-card ok">
        <div class="stat-info">
          <div class="stat-label">Selesai 10 Hari Terakhir</div>
          <div class="stat-num"><?= $selesai_10hari ?></div>
        </div>
      </div>
    </div>

    <!-- TABEL TRANSAKSI (FULL WIDTH, TANPA FORM) -->
    <div class="trx-card">
      <div class="trx-card-header">
        <h5>Daftar Transaksi</h5>
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
          <small style="color:#4B5563;font-size:12px" id="countTrx"><?= count($transaksi) ?> transaksi</small>
          <div class="search-wrap" style="width:200px">
                        <input type="text" id="inputCariTrx" placeholder="Cari nota / nama..." oninput="filterTabel(this.value)">
          </div>
          <a href="transaksi_baru.php" class="btn-tambah-trx">
            Tambah Transaksi
          </a>
        </div>
      </div>
      <?php if (!empty($transaksi)): ?>
      <div class="filter-tabs">
        <button type="button" class="filter-tab active" data-tab="semua" onclick="setDateFilter('semua', this)">Semua</button>
        <button type="button" class="filter-tab" data-tab="hari_ini" onclick="setDateFilter('hari_ini', this)">Hari Ini</button>
        <button type="button" class="filter-tab" data-tab="kemarin" onclick="setDateFilter('kemarin', this)">Kemarin</button>
        <button type="button" class="filter-tab" data-tab="10hari" onclick="setDateFilter('10hari', this)">10 Hari Terakhir</button>
      </div>
      <?php endif; ?>
      <?php if (empty($transaksi)): ?>
      <div class="empty-state">
        Belum ada transaksi.
        <div style="margin-top:16px"><a href="transaksi_baru.php" class="btn-tambah-trx">Buat Transaksi Baru</a></div>
      </div>
      <?php else: ?>
      <div style="overflow-x:auto;max-height:650px;overflow-y:auto">
      <table class="trx-table" id="tabelTrx">
        <thead>
          <tr>
            <th>NOTA</th>
            <th>PELANGGAN</th>
            <th>TOTAL</th>
            <th>STATUS</th>
            <th>BAYAR</th>
            <th>AKSI</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($transaksi as $t):
            $sc = $t['status_cucian'] ?? 'dalam_antrian';
            $scClass = match($sc) {
                'dalam_antrian'   => 'badge-antrian',
                'sedang_diproses' => 'badge-proses',
                'siap_diambil'    => 'badge-siap',
                'selesai'         => 'badge-selesai',
                default           => 'badge-antrian',
            };
            $scIcon = match($sc) {
                'dalam_antrian'   => 'fa-clock',
                'sedang_diproses' => 'fa-rotate',
                'siap_diambil'    => 'fa-circle-check',
                'selesai'         => 'fa-box-archive',
                default           => 'fa-clock',
            };
            $scLabel = match($sc) {
                'dalam_antrian'   => 'Antrian',
                'sedang_diproses' => 'Diproses',
                'siap_diambil'    => 'Siap Diambil',
                'selesai'         => 'Selesai',
                default           => $sc,
            };
            $rowClass = $sc === 'selesai' ? 'row-selesai' : '';
            $itemsTrx = $detailPerTrx[$t['id_transaksi']] ?? [];
            $waUrl    = generateWaManual($t, $itemsTrx);
          ?>
          <tr class="<?= $rowClass ?>" data-search="<?= strtolower(htmlspecialchars($t['id_nota'].' '.$t['nama_pelanggan'])) ?>" data-tanggal="<?= date('Y-m-d', strtotime($t['created_at'])) ?>">
            <td data-label="Nota">
              <span class="nota-code"><?= htmlspecialchars($t['id_nota']) ?></span><br>
              <small style="color:#4B5563"><?= date('d/m H:i', strtotime($t['created_at'])) ?></small>
            </td>
            <td data-label="Pelanggan">
              <span style="font-weight:600"><?= htmlspecialchars($t['nama_pelanggan']) ?></span><br>
              <small style="color:#4B5563"><?= htmlspecialchars($t['no_hp_pelanggan']) ?></small>
              <?php if (!empty($t['nama_kasir'])): ?>
              <br><small style="color:#8A94A3">Kasir: <?= htmlspecialchars($t['nama_kasir']) ?></small>
              <?php endif; ?>
            </td>
            <td data-label="Total" style="font-weight:700;color:var(--sky-deep)">Rp<?= number_format($t['total_harga'],0,',','.') ?></td>
            <td data-label="Status">
              <span class="badge-status <?= $scClass ?>">
                <?= $scLabel ?>
              </span>
            </td>
            <td data-label="Bayar">
              <?php if ($t['status_bayar'] === 'sudah_bayar'): ?>
              <span class="badge-status badge-lunas">Lunas</span>
              <?php elseif ($t['status_bayar'] === 'dp'): ?>
              <span class="badge-status badge-dp" title="Sudah bayar <?= number_format($t['total_dibayar'],0,',','.') ?>, sisa Rp<?= number_format(sisaBayar($t['total_harga'],$t['total_dibayar']),0,',','.') ?>">
                DP &middot; Kurang Rp<?= number_format(sisaBayar($t['total_harga'],$t['total_dibayar']),0,',','.') ?>
              </span>
              <?php else: ?>
              <span class="badge-status badge-belum">Belum</span>
              <?php endif; ?>
            </td>
            <td data-label="Aksi">
              <div class="btn-acts">
                <button type="button" class="btn-act btn-detail" onclick="showDetail(<?= $t['id_transaksi'] ?>)" title="Lihat rincian pesanan">
                  Detail
                </button>
                <a href="<?= $waUrl ?>" target="_blank" class="btn-act btn-wa" title="Kirim notifikasi WhatsApp">
                  WA
                </a>
                <?php if ($t['status_bayar'] !== 'sudah_bayar'): ?>
                <button type="button" class="btn-act btn-bayar"
                        data-id="<?= $t['id_transaksi'] ?>"
                        data-nota="<?= htmlspecialchars($t['id_nota'],ENT_QUOTES) ?>"
                        data-nama="<?= htmlspecialchars($t['nama_pelanggan'],ENT_QUOTES) ?>"
                        data-total="<?= (float)$t['total_harga'] ?>"
                        data-dibayar="<?= (float)$t['total_dibayar'] ?>"
                        onclick="bukaModalBayar(this)" title="Input DP / catat pembayaran">
                  <?= $t['status_bayar'] === 'dp' ? 'Tambah Bayar' : 'Input DP' ?>
                </button>
                <?php endif; ?>
                <?php if ($sc !== 'selesai'): ?>
                <a href="edit_transaksi.php?id=<?= $t['id_transaksi'] ?>" class="btn-act btn-edit" onclick="simpanStateHalaman()">
                  Edit
                </a>
                <button type="button" class="btn-act btn-hapus" data-id="<?= $t['id_transaksi'] ?>"
                        onclick="hapusTransaksi(<?= $t['id_transaksi'] ?>, '<?= htmlspecialchars(addslashes($t['id_nota'])) ?>', this)">
                  Hapus
                </button>
                <?php endif; ?>
              </div>
            </td>
          </tr>
          <tr class="trx-actions-row">
            <td colspan="6">
              <div class="btn-acts">
                <button type="button" class="btn-act btn-detail" onclick="showDetail(<?= $t['id_transaksi'] ?>)" title="Lihat rincian pesanan">
                  Detail
                </button>
                <a href="<?= $waUrl ?>" target="_blank" class="btn-act btn-wa" title="Kirim notifikasi WhatsApp">
                  WA
                </a>
                <?php if ($t['status_bayar'] !== 'sudah_bayar'): ?>
                <button type="button" class="btn-act btn-bayar"
                        data-id="<?= $t['id_transaksi'] ?>"
                        data-nota="<?= htmlspecialchars($t['id_nota'],ENT_QUOTES) ?>"
                        data-nama="<?= htmlspecialchars($t['nama_pelanggan'],ENT_QUOTES) ?>"
                        data-total="<?= (float)$t['total_harga'] ?>"
                        data-dibayar="<?= (float)$t['total_dibayar'] ?>"
                        onclick="bukaModalBayar(this)" title="Input DP / catat pembayaran">
                  <?= $t['status_bayar'] === 'dp' ? 'Tambah Bayar' : 'Input DP' ?>
                </button>
                <?php endif; ?>
                <?php if ($sc !== 'selesai'): ?>
                <a href="edit_transaksi.php?id=<?= $t['id_transaksi'] ?>" class="btn-act btn-edit" onclick="simpanStateHalaman()">
                  Edit
                </a>
                <button type="button" class="btn-act btn-hapus" data-id="<?= $t['id_transaksi'] ?>"
                        onclick="hapusTransaksi(<?= $t['id_transaksi'] ?>, '<?= htmlspecialchars(addslashes($t['id_nota'])) ?>', this)">
                  Hapus
                </button>
                <?php endif; ?>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
          <tr id="rowFilterEmpty" style="display:none">
            <td colspan="6">
              <div class="empty-state">
                                Tidak ada transaksi untuk filter ini.
              </div>
            </td>
          </tr>
        </tbody>
      </table>
      </div>
      <?php endif; ?>
    </div>

  </div>
</div>

<!-- MODAL DETAIL RINCIAN (tersedia untuk semua status, termasuk "Selesai") -->
<div class="modal-overlay" id="modalDetail" style="display:none">
  <div class="modal-box">
    <div class="form-card-header">
      Rincian Pesanan
      <button type="button" class="modal-close-btn" onclick="closeDetail()">&times;</button>
    </div>
    <div class="modal-body" id="detailBody"></div>
  </div>
</div>

<!-- MODAL INPUT DP / TAMBAH PEMBAYARAN -->
<div class="modal-overlay" id="modalBayar" style="display:none">
  <div class="modal-box" style="width:400px">
    <div class="form-card-header">
      Input DP / Pembayaran
      <button type="button" class="modal-close-btn" onclick="closeModalBayar()">&times;</button>
    </div>
    <div class="modal-body">
      <div class="bayar-head">
        <div class="bayar-head-avatar" id="bayarInisial">--</div>
        <div>
          <div class="nota-code" id="bayarNota"></div>
          <div class="bayar-head-nama" id="bayarNama"></div>
        </div>
      </div>

      <div class="bayar-summary-card">
        <div class="bayar-summary-row"><span>Total Tagihan</span><strong id="bayarTotal">Rp0</strong></div>
        <div class="bayar-summary-row"><span>Sudah Dibayar</span><strong id="bayarSudahDibayar">Rp0</strong></div>
        <div class="bayar-summary-row highlight"><span>Sisa yang Harus Dibayar</span><strong id="bayarSisa">Rp0</strong></div>
      </div>

      <div class="form-group">
        <label class="form-label">Jumlah Dibayar Sekarang</label>
        <div class="input-rp">
          <span class="input-rp-prefix">Rp</span>
          <input type="number" id="inputJumlahBayar" class="form-control" min="1" step="1" placeholder="0">
        </div>
        <div class="quick-amount-row">
          <button type="button" class="chip-amount" onclick="setJumlahPercent(50)">Setengah (DP)</button>
          <button type="button" class="chip-amount lunas" onclick="setJumlahPercent(100)">Bayar Lunas</button>
        </div>
        <div class="item-hint" id="bayarHint"></div>
      </div>

      <div class="form-group">
        <label class="form-label">Metode Pembayaran</label>
        <input type="hidden" id="inputMetodeBayarDp" value="tunai">
        <div class="method-toggle">
          <button type="button" class="method-btn active" data-metode="tunai" onclick="pilihMetodeBayar(this)">Tunai (Cash)</button>
          <button type="button" class="method-btn" data-metode="transfer" onclick="pilihMetodeBayar(this)">Transfer</button>
        </div>
      </div>

      <div class="form-group" id="wrapTransferFieldsDp" style="display:none">
        <label class="form-label">Nama Pengirim</label>
        <input type="text" id="inputNamaPengirimDp" class="form-control" placeholder="Nama sesuai rekening/e-wallet pengirim">
        <label class="form-label" style="margin-top:8px">Bank / E-wallet & No. Rekening</label>
        <div class="bank-chip-row">
          <button type="button" class="bank-chip" onclick="pilihBankCepat('inputNoPengirimDp','BCA')">BCA</button>
          <button type="button" class="bank-chip" onclick="pilihBankCepat('inputNoPengirimDp','Mandiri')">Mandiri</button>
          <button type="button" class="bank-chip" onclick="pilihBankCepat('inputNoPengirimDp','BRI')">BRI</button>
          <button type="button" class="bank-chip" onclick="pilihBankCepat('inputNoPengirimDp','BNI')">BNI</button>
          <button type="button" class="bank-chip" onclick="pilihBankCepat('inputNoPengirimDp','ShopeePay')">ShopeePay</button>
          <button type="button" class="bank-chip" onclick="pilihBankCepat('inputNoPengirimDp','DANA')">DANA</button>
          <button type="button" class="bank-chip" onclick="pilihBankCepat('inputNoPengirimDp','OVO')">OVO</button>
          <button type="button" class="bank-chip" onclick="pilihBankCepat('inputNoPengirimDp','GoPay')">GoPay</button>
        </div>
        <input type="text" id="inputNoPengirimDp" class="form-control" style="margin-top:8px" placeholder="Contoh: BCA 1234567890, atau ShopeePay saja">
      </div>

      <button type="button" class="btn-simpan" id="btnSimpanBayar" onclick="submitBayar()">
        Simpan Pembayaran
      </button>
    </div>
  </div>
</div>

<div class="toast-wrap" id="toastWrap" style="position:fixed;bottom:20px;right:20px;z-index:2000;display:flex;flex-direction:column;gap:8px"></div>

<script>
// Data rincian transaksi (termasuk yang sudah "selesai") untuk modal Detail
const rincianData = <?= json_encode($rincianJson, JSON_UNESCAPED_UNICODE) ?>;

function formatRp(n) {
    return 'Rp ' + Math.round(n).toLocaleString('id-ID');
}

// ── MODAL INPUT DP / TAMBAH PEMBAYARAN ──
const CSRF_TOKEN = <?= json_encode(csrf_token()) ?>;
let _bayarId = null, _bayarSisa = 0;

function bukaModalBayar(btn) {
    _bayarId = btn.dataset.id;
    const total    = parseFloat(btn.dataset.total) || 0;
    const dibayar  = parseFloat(btn.dataset.dibayar) || 0;
    const sisa     = Math.max(total - dibayar, 0);
    _bayarSisa     = sisa;

    document.getElementById('bayarNota').textContent = btn.dataset.nota;
    document.getElementById('bayarNama').textContent = btn.dataset.nama;
    document.getElementById('bayarInisial').textContent = (btn.dataset.nama || '--').trim().substring(0, 2).toUpperCase();
    document.getElementById('bayarTotal').textContent = formatRp(total);
    document.getElementById('bayarSudahDibayar').textContent = formatRp(dibayar);
    document.getElementById('bayarSisa').textContent = formatRp(sisa);

    const input = document.getElementById('inputJumlahBayar');
    input.value = '';
    input.max   = sisa;
    document.getElementById('bayarHint').textContent =
        'Maksimal ' + formatRp(sisa) + '. Isi sebagian untuk DP, atau isi penuh untuk langsung lunas.';

    document.getElementById('inputMetodeBayarDp').value = 'tunai';
    document.querySelectorAll('.method-btn').forEach(b => b.classList.toggle('active', b.dataset.metode === 'tunai'));
    document.getElementById('inputNamaPengirimDp').value = '';
    document.getElementById('inputNoPengirimDp').value = '';
    document.getElementById('wrapTransferFieldsDp').style.display = 'none';

    document.getElementById('modalBayar').style.display = 'flex';
}

function setJumlahPercent(persen) {
    const nominal = Math.round(_bayarSisa * persen / 100);
    document.getElementById('inputJumlahBayar').value = nominal;
}

function pilihMetodeBayar(btn) {
    document.querySelectorAll('.method-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById('inputMetodeBayarDp').value = btn.dataset.metode;
    // Field nama/no pengirim cuma relevan buat Transfer -- supaya kalau ada
    // selisih/komplain transfer, kasir gampang menelusuri dari data ini.
    const tampil = btn.dataset.metode === 'transfer';
    document.getElementById('wrapTransferFieldsDp').style.display = tampil ? 'block' : 'none';
    if (!tampil) {
        document.getElementById('inputNamaPengirimDp').value = '';
        document.getElementById('inputNoPengirimDp').value = '';
    }
}

// Tombol pilihan cepat Bank/E-wallet -- cuma bantu isi cepat field
// "no_pengirim" yang sudah ada (teks bebas), jadi TIDAK perlu kolom baru
// di database. Kasir tinggal lanjut ketik nomor rekening/HP setelahnya
// kalau perlu, atau dibiarkan nama e-wallet-nya saja (mis. "ShopeePay").
function pilihBankCepat(inputId, nama) {
    const input = document.getElementById(inputId);
    if (!input) return;
    input.value = nama + ' ';
    input.focus();
    input.setSelectionRange(input.value.length, input.value.length);
    const row = input.closest('.form-group')?.querySelector('.bank-chip-row');
    if (row) {
        row.querySelectorAll('.bank-chip').forEach(c => c.classList.toggle('active', c.textContent.trim() === nama));
    }
}

function closeModalBayar() {
    document.getElementById('modalBayar').style.display = 'none';
}
document.getElementById('modalBayar').addEventListener('click', function(e) {
    if (e.target === this) closeModalBayar();
});

async function submitBayar() {
    const jumlah = parseFloat(document.getElementById('inputJumlahBayar').value) || 0;
    if (jumlah <= 0) {
        showToast('fa-circle-exclamation', 'Jumlah bayar harus diisi dan lebih dari 0.', 'err');
        return;
    }
    if (jumlah > _bayarSisa + 0.01) {
        showToast('fa-circle-exclamation', 'Jumlah melebihi sisa tagihan (' + formatRp(_bayarSisa) + ').', 'err');
        return;
    }
    const metode = document.getElementById('inputMetodeBayarDp').value;
    const namaPengirim = document.getElementById('inputNamaPengirimDp').value.trim();
    const noPengirim   = document.getElementById('inputNoPengirimDp').value.trim();
    if (metode === 'transfer' && !namaPengirim) {
        showToast('fa-circle-exclamation', 'Nama Pengirim wajib diisi untuk pembayaran Transfer.', 'err');
        return;
    }
    const fd = new FormData();
    fd.append('transaksi_id', _bayarId);
    fd.append('jumlah_bayar', jumlah);
    fd.append('metode_bayar', metode);
    fd.append('nama_pengirim', namaPengirim);
    fd.append('no_pengirim', noPengirim);
    fd.append('csrf_token', CSRF_TOKEN);

    try {
        const res  = await fetch('tambah_pembayaran.php', { method: 'POST', body: fd, credentials: 'same-origin' });
        const data = await res.json();
        if (data.success) {
            closeModalBayar();
            const label = data.status_bayar === 'sudah_bayar' ? 'Lunas' : 'DP tercatat, sisa ' + formatRp(data.sisa);
            showToast('fa-check-circle', data.nota + ' — Pembayaran tersimpan (' + label + ').', 'ok');
            perbaruiBarisBayar(_bayarId, data);
        } else {
            showToast('fa-circle-exclamation', data.message || 'Gagal menyimpan pembayaran.', 'err');
        }
    } catch (e) {
        showToast('fa-circle-exclamation', 'Gagal terhubung ke server.', 'err');
    }
}

// Perbarui tampilan langsung di baris tabel (tanpa reload halaman) setelah
// pembayaran tersimpan, supaya tab filter/scroll/pencarian yang aktif tidak
// ikut ter-reset.
function perbaruiBarisBayar(transaksiId, data) {
    let badgeHtml;
    if (data.status_bayar === 'sudah_bayar') {
        badgeHtml = '<span class="badge-status badge-lunas">Lunas</span>';
    } else if (data.status_bayar === 'dp') {
        badgeHtml = '<span class="badge-status badge-dp" title="Sudah bayar ' + formatRp(data.total_dibayar) + ', sisa ' + formatRp(data.sisa) + '">DP &middot; Kurang ' + formatRp(data.sisa) + '</span>';
    } else {
        badgeHtml = '<span class="badge-status badge-belum">Belum</span>';
    }

    document.querySelectorAll('.btn-bayar[data-id="' + transaksiId + '"]').forEach(btn => {
        const row = btn.closest('tr');
        // Baris data (desktop) punya td Bayar sendiri; baris .trx-actions-row (HP)
        // tidak, jadi ambil dari baris data di atasnya.
        const td = row?.querySelector('td[data-label="Bayar"]') || row?.previousElementSibling?.querySelector('td[data-label="Bayar"]');
        if (td) td.innerHTML = badgeHtml;
        btn.dataset.dibayar = data.total_dibayar;
        if (data.status_bayar === 'sudah_bayar') {
            btn.remove(); // sudah lunas, tombol bayar tidak diperlukan lagi
        } else {
            btn.textContent = 'Tambah Bayar';
        }
    });

    // Sinkronkan data rincian dipakai modal Detail juga
    if (rincianData[transaksiId]) {
        rincianData[transaksiId].status_bayar  = data.status_bayar;
        rincianData[transaksiId].total_dibayar = data.total_dibayar;
        rincianData[transaksiId].sisa          = data.sisa;
    }
}

function showToast(iconClass, msg, type) {
    const wrap = document.getElementById('toastWrap');
    const el   = document.createElement('div');
    const bg   = type === 'ok' ? '#D6E7F7' : '#EAF2FB';
    const fg   = type === 'ok' ? '#1F5FA6' : '#101418';
    el.style.cssText = 'background:' + bg + ';color:' + fg + ';padding:10px 16px;border-radius:8px;font-size:13px;font-weight:600;box-shadow:0 6px 18px rgba(16,20,24,0.12);display:flex;align-items:center;gap:8px';
    el.innerHTML = msg;
    wrap.appendChild(el);
    setTimeout(() => el.remove(), 3500);
}

// Tanggal server (bukan tanggal browser pengguna) dipakai sebagai acuan "hari ini",
// biar konsisten dengan waktu created_at yang disimpan di database.
const SERVER_TODAY = <?= json_encode(date('Y-m-d')) ?>;

let activeKeyword    = '';
let activeDateFilter = 'semua';

function selisihHari(tanggal) {
    const a = new Date(tanggal + 'T00:00:00');
    const b = new Date(SERVER_TODAY + 'T00:00:00');
    return Math.round((b - a) / 86400000); // 0 = hari ini, 1 = kemarin, dst
}

function cocokFilterTanggal(tanggal) {
    if (activeDateFilter === 'semua') return true;
    const selisih = selisihHari(tanggal);
    if (activeDateFilter === 'hari_ini') return selisih === 0;
    if (activeDateFilter === 'kemarin')  return selisih === 1;
    if (activeDateFilter === '10hari')   return selisih >= 0 && selisih <= 9;
    return true;
}

function terapkanFilter() {
    const rows = document.querySelectorAll('#tabelTrx tbody tr[data-search]');
    let jumlahTampil = 0;
    rows.forEach(row => {
        const cocokKw  = row.dataset.search.includes(activeKeyword);
        const cocokTgl = cocokFilterTanggal(row.dataset.tanggal);
        const tampil   = cocokKw && cocokTgl;
        row.style.display = tampil ? '' : 'none';
        // baris tombol aksi (khusus HP) mengikuti tampil/sembunyinya baris data di atasnya
        const rowAksi = row.nextElementSibling;
        if (rowAksi && rowAksi.classList.contains('trx-actions-row')) {
            rowAksi.style.display = tampil ? '' : 'none';
        }
        if (tampil) jumlahTampil++;
    });

    const rowEmpty = document.getElementById('rowFilterEmpty');
    if (rowEmpty) rowEmpty.style.display = (jumlahTampil === 0 && rows.length > 0) ? '' : 'none';

    const countEl = document.getElementById('countTrx');
    if (countEl) countEl.textContent = jumlahTampil + ' transaksi';
}

function filterTabel(kw) {
    activeKeyword = kw.toLowerCase().trim();
    terapkanFilter();
    simpanStateHalaman();
}

function setDateFilter(tab, btn) {
    activeDateFilter = tab;
    document.querySelectorAll('.filter-tab').forEach(b => b.classList.remove('active'));
    if (btn) btn.classList.add('active');
    terapkanFilter();
    simpanStateHalaman();
}

// ── SUPAYA HALAMAN TIDAK "RESET" (tab filter / pencarian / posisi scroll)
// SETELAH HAPUS, EDIT, ATAU AKSI LAIN ──
// Disimpan di sessionStorage (bukan reload penuh) supaya begitu kita kembali
// ke halaman ini, tab & posisi yang lagi dilihat tetap sama persis.
const STATE_KEY = 'dashboardKasirState';

function simpanStateHalaman() {
    try {
        sessionStorage.setItem(STATE_KEY, JSON.stringify({
            tab:      activeDateFilter,
            keyword:  document.getElementById('inputCariTrx')?.value || '',
            scrollY:  window.scrollY,
        }));
    } catch (e) { /* abaikan kalau sessionStorage tidak tersedia */ }
}

function pulihkanStateHalaman() {
    let state;
    try {
        state = JSON.parse(sessionStorage.getItem(STATE_KEY) || 'null');
    } catch (e) { state = null; }
    if (!state) return;

    if (state.tab) {
        const btn = document.querySelector('.filter-tab[data-tab="' + state.tab + '"]');
        activeDateFilter = state.tab;
        document.querySelectorAll('.filter-tab').forEach(b => b.classList.remove('active'));
        if (btn) btn.classList.add('active');
    }
    if (state.keyword) {
        const input = document.getElementById('inputCariTrx');
        if (input) input.value = state.keyword;
        activeKeyword = state.keyword.toLowerCase().trim();
    }
    terapkanFilter();

    if (typeof state.scrollY === 'number') {
        window.scrollTo(0, state.scrollY);
    }
    sessionStorage.removeItem(STATE_KEY);
}
pulihkanStateHalaman();

// ── HAPUS TRANSAKSI VIA AJAX (tanpa navigasi/refresh halaman) ──
async function hapusTransaksi(id, nota, btn) {
    if (!confirm('Yakin hapus transaksi ' + nota + '?')) return;

    simpanStateHalaman();

    const fd = new FormData();
    fd.append('id', id);
    fd.append('csrf_token', CSRF_TOKEN);
    fd.append('ajax', '1');

    btn.disabled = true;
    try {
        const res  = await fetch('hapus_transaksi.php', { method: 'POST', body: fd, credentials: 'same-origin' });
        const data = await res.json();
        if (data.success) {
            showToast('fa-check-circle', 'Transaksi ' + nota + ' berhasil dihapus.', 'ok');
            document.querySelectorAll('.btn-hapus[data-id="' + id + '"]').forEach(delBtn => {
                const row = delBtn.closest('tr');
                if (!row) return;
                const rowAksi = row.classList.contains('trx-actions-row') ? null : row.nextElementSibling;
                if (rowAksi && rowAksi.classList.contains('trx-actions-row')) rowAksi.remove();
                row.remove();
            });
            terapkanFilter();
        } else {
            showToast('fa-circle-exclamation', data.message || 'Gagal menghapus transaksi.', 'err');
            btn.disabled = false;
        }
    } catch (e) {
        showToast('fa-circle-exclamation', 'Gagal terhubung ke server.', 'err');
        btn.disabled = false;
    }
}

// ── MODAL DETAIL RINCIAN ──
function showDetail(id) {
    const d = rincianData[id];
    if (!d) return;

    let itemsHtml = '';
    if (d.items.length === 0) {
        itemsHtml = '<div style="color:#4B5563;font-size:13px">Tidak ada rincian barang.</div>';
    } else {
        d.items.forEach(function(item) {
            itemsHtml +=
                '<div class="item-row" style="cursor:default">' +
                  '<div class="item-name">' + item.nama + '<br>' +
                    '<small style="color:#4B5563;font-weight:400">' + item.qty + ' ' + item.satuan + '</small><br>' +
                    '<span class="item-status ' + item.status_state + '">' + item.status_label + '</span>' +
                  '</div>' +
                  '<div class="item-price">' + formatRp(item.subtotal) + '</div>' +
                '</div>';
        });
    }

    let bayarBadge;
    if (d.status_bayar === 'sudah_bayar') {
        bayarBadge = '<span class="badge-status badge-lunas">Lunas</span>';
    } else if (d.status_bayar === 'dp') {
        bayarBadge = '<span class="badge-status badge-dp">DP &middot; Kurang ' + formatRp(d.sisa) + '</span>';
    } else {
        bayarBadge = '<span class="badge-status badge-belum">Belum Lunas</span>';
    }

    document.getElementById('detailBody').innerHTML =
        '<div style="margin-bottom:14px">' +
          '<div class="nota-code" style="font-size:15px">' + d.nota + '</div>' +
          '<div style="font-size:13px;color:#3A4450;margin-top:2px">' + d.pelanggan + ' &middot; ' + d.no_hp + '</div>' +
          '<div style="font-size:12px;color:#4B5563;margin-top:4px">Dibuat: ' + d.tanggal + ' &middot; Estimasi: ' + d.estimasi + '</div>' +
          '<div style="margin-top:8px;display:flex;gap:6px">' + bayarBadge + '<span class="badge-status badge-selesai">' + d.status_label + '</span></div>' +
        '</div>' +
        '<div class="detail-section-title">Rincian Barang</div>' +
        itemsHtml +
        '<div class="total-box" style="margin-top:14px">' +
          '<div class="lbl">Total</div>' +
          '<div class="val">' + formatRp(d.total) + '</div>' +
        '</div>';

    document.getElementById('modalDetail').style.display = 'flex';
}

function closeDetail() {
    document.getElementById('modalDetail').style.display = 'none';
}

// Tutup modal saat klik area luar box
document.getElementById('modalDetail').addEventListener('click', function(e) {
    if (e.target === this) closeDetail();
});
</script>

<script>
function toggleSidebar() {
    document.getElementById('sidebarEl').classList.toggle('open');
    document.getElementById('sidebarOverlay').classList.toggle('show');
}
function closeSidebar() {
    document.getElementById('sidebarEl').classList.remove('open');
    document.getElementById('sidebarOverlay').classList.remove('show');
}
// Tutup sidebar otomatis saat pindah halaman/menu di mobile
document.querySelectorAll('.sidebar-menu a, .sidebar-footer a').forEach(function(a){
    a.addEventListener('click', closeSidebar);
});
</script>

<?php
$csrfTokenJs = csrf_token();
$antrianNotifWaAwal = isset($_GET['notif_id'])
    ? [['type' => 'baru', 'transaksi_id' => (int) $_GET['notif_id']]]
    : [];
require __DIR__ . '/includes/notif_wa_widget.php';
?>

<script>
// ── HEARTBEAT: ping server tiap 30 menit selama tab dashboard ini terbuka ──
// Supaya pengecekan otomatis "estimasi diperpanjang / terlambat" tetap
// kepicu tepat waktu walau tidak ada request halaman lain yang masuk ke
// server (lihat heartbeat.php & includes/config.php untuk detail).
(function () {
    var INTERVAL_MS = 30 * 60 * 1000; // 30 menit
    function kirimHeartbeat() {
        fetch('heartbeat.php', { method: 'GET', credentials: 'same-origin' }).catch(function () {});
    }
    setInterval(kirimHeartbeat, INTERVAL_MS);
})();
</script>
</body>
</html>