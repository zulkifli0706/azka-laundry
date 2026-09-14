<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/meta_whatsapp.php';
require_once __DIR__ . '/includes/proses_helper.php';
requireRole('bagian_pencucian', 'owner');

$namaPetugas = htmlspecialchars($_SESSION['user']['name']);
$inisial     = strtoupper(substr($_SESSION['user']['name'], 0, 2));

// Catatan: aksi "lanjut tahap" & "tandai siap diambil" sekarang dilakukan
// lewat AJAX ke update_status_cucian.php (lihat script di bawah), supaya
// tahapan proses (dicuci -> dikeringkan -> disetrika -> dikemas) bisa
// digerakkan sesuai jenis layanan tiap transaksi tanpa reload halaman.

// ── FILTER / SEARCH ──
$q      = trim($_GET['q'] ?? '');
$where  = "s.status = 'sedang_diproses'";
$params = [];
if ($q !== '') {
    $where .= " AND (t.id_nota LIKE ? OR t.nama_pelanggan LIKE ?)";
    $params[] = "%$q%";
    $params[] = "%$q%";
}

// ── DATA ──
$sql  = "
    SELECT t.id_transaksi, t.id_nota, t.nama_pelanggan, t.no_hp_pelanggan,
           t.tanggal_estimasi, t.pernah_terlambat, t.created_at, s.updated_at AS mulai_proses, s.tahap_proses,
           up.name AS nama_petugas
    FROM transaksi t
    JOIN status_cucian s ON t.id_transaksi = s.transaksi_id
    LEFT JOIN users up   ON up.id_user = s.updated_by
    WHERE $where
    ORDER BY t.tanggal_estimasi ASC, s.updated_at ASC
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$list = $stmt->fetchAll();

// ── DETAIL ITEM + KATEGORI PER TRANSAKSI ──
$detailPerTrx   = [];
$kategoriPerTrx = [];
if (!empty($list)) {
    $ids = implode(',', array_map(fn($r) => (int)$r['id_transaksi'], $list));
    $rows = $pdo->query("
        SELECT dt.id_detail_transaksi AS detail_id, dt.transaksi_id, COALESCE(il.nama_item, dt.keterangan, 'Item') AS nama_item,
               COALESCE(il.satuan, 'pcs') AS satuan, dt.qty, dt.subtotal,
               kl.nama_kategori, COALESCE(il.tipe_proses, 'cuci_setrika') AS tipe_proses,
               il.custom_tahap, ds.tahap_proses AS tahap_item
        FROM detail_transaksi dt
        LEFT JOIN detail_barang il      ON il.id_barang = dt.detail_barang_id
        LEFT JOIN kategori_barang kl  ON kl.id_kategori = il.kategori_id
        LEFT JOIN detail_status ds     ON ds.detail_transaksi_id = dt.id_detail_transaksi
        WHERE dt.transaksi_id IN ($ids)
        ORDER BY dt.id_detail_transaksi
    ")->fetchAll();
    foreach ($rows as $r) {
        $detailPerTrx[$r['transaksi_id']][] = $r;
        if (!empty($r['nama_kategori'])) {
            $kategoriPerTrx[$r['transaksi_id']][$r['nama_kategori']] = true;
        }
    }
}

// ── JSON UNTUK MODAL DETAIL ──
$today    = strtotime('today');
$tomorrow = strtotime('+1 day');
$rincianJson = [];
foreach ($list as $row) {
    $items     = $detailPerTrx[$row['id_transaksi']] ?? [];
    $terlambat = !empty($row['pernah_terlambat']) || (!empty($row['tanggal_estimasi']) && strtotime($row['tanggal_estimasi']) < $today);
    $rincianJson[$row['id_transaksi']] = [
        'nota'        => $row['id_nota'],
        'nama'        => $row['nama_pelanggan'],
        'no_hp'       => $row['no_hp_pelanggan'],
        'estimasi'    => !empty($row['tanggal_estimasi']) ? date('d/m/Y', strtotime($row['tanggal_estimasi'])) : '-',
        'mulai'       => !empty($row['mulai_proses']) ? date('d/m/Y, H:i', strtotime($row['mulai_proses'])) : '-',
        'terlambat'   => $terlambat,
        'petugas'     => $row['nama_petugas'] ?: null,
        'tahap'       => $row['tahap_proses'],
        'tahap_label' => $row['tahap_proses'] ? labelTahap($row['tahap_proses'], tipeDominanTransaksi($pdo, $row['id_transaksi']), kategoriDominanTransaksi($pdo, $row['id_transaksi'])) : null,
        'items'       => array_map(function($i) {
            $itemSt        = statusItemAsli($i['tipe_proses'], 'sedang_diproses', $i['tahap_item'], $i['nama_kategori'] ?? null);
            $tahapBerikut  = tahapBerikutnyaItem($i['tipe_proses'], $i['tahap_item'], $i['custom_tahap'] ?? null);
            $tahapSebelum  = tahapSebelumnyaItem($i['tipe_proses'], $i['tahap_item'], $i['custom_tahap'] ?? null);
            return [
                'detail_id'     => (int) $i['detail_id'],
                'nama'          => $i['nama_item'],
                'kategori'      => $i['nama_kategori'] ?: '-',
                'qty'           => rtrim(rtrim($i['qty'], '0'), '.'),
                'satuan'        => $i['satuan'],
                'subtotal'      => (float) $i['subtotal'],
                'status_label'  => $itemSt['label'],
                'status_state'  => $itemSt['state'],
                'status_icon'   => $itemSt['icon'],
                'next_label'    => $tahapBerikut ? labelTahap($tahapBerikut, $i['tipe_proses'], $i['nama_kategori'] ?? null) : null,
                'is_terakhir'   => $tahapBerikut === 'kemas',
                'prev_label'    => $tahapSebelum ? labelTahap($tahapSebelum, $i['tipe_proses'], $i['nama_kategori'] ?? null) : null,
            ];
        }, $items),
    ];
}
$csrf_token_out    = json_encode(csrf_token());
$rincian_json_out = json_encode($rincianJson, JSON_HEX_TAG | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
$total_proses = count($list);
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>Sedang Diproses — Azka Laundry</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root {
    --blue:      #2E74C0;
    --blue-deep: #1F5FA6;
    --blue-pale: #EAF2FB;
    --line:      #D6E7F7;
    --ink:       #164A85;
    --gray:      #2E74C0;
    --gray-soft: #4E90D9;
    --white:     #FFFFFF;
}
* { font-family:'Plus Jakarta Sans',sans-serif; box-sizing:border-box; }
body { margin:0; background:#EAF2FB; color:#101418; }

/* SIDEBAR */
.sidebar { width:230px; height:100vh; height:100dvh; background:var(--blue-deep); position:fixed; left:0; top:0; display:flex; flex-direction:column; z-index:100; overflow-y:auto; }
.sidebar-brand { padding:20px; border-bottom:1px solid rgba(255,255,255,0.14); display:flex; align-items:center; gap:10px; }
.sidebar-brand svg { width:28px; height:28px; flex-shrink:0; }
.sidebar-brand h4 { color:#FFFFFF; font-size:16px; font-weight:700; margin:0; }
.sidebar-brand small { color:rgba(255,255,255,0.55); font-size:11px; }
.sidebar-menu { padding:14px 10px; flex:1; }
.sidebar-menu a { display:flex; align-items:center; gap:10px; color:rgba(255,255,255,0.68); text-decoration:none; padding:10px 12px; border-radius:8px; margin-bottom:2px; font-size:13px; font-weight:500; }
.sidebar-menu a:hover, .sidebar-menu a.active { background:rgba(255,255,255,0.14); color:#FFFFFF; }
.sidebar-menu a i { width:16px; text-align:center; }
.sidebar-footer { padding:14px 10px calc(14px + env(safe-area-inset-bottom)); border-top:1px solid rgba(255,255,255,0.14); flex-shrink:0; }
.sidebar-footer a { display:flex; align-items:center; gap:10px; color:rgba(255,255,255,0.55); text-decoration:none; padding:9px 12px; border-radius:8px; font-size:13px; }
.sidebar-footer a:hover { background:rgba(255,255,255,0.14); color:#FFFFFF; }

/* MAIN */
.main { margin-left:230px; }

/* ===== MOBILE SIDEBAR DRAWER ===== */
.hamburger-btn {
    display:none; position:fixed; top:12px; left:14px; z-index:200;
    background:var(--blue-deep); color:#FFFFFF; border:none; width:42px; height:42px;
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
.topbar { background:var(--white); border-bottom:1px solid var(--line); padding:14px 26px; display:flex; justify-content:space-between; align-items:center; position:sticky; top:0; z-index:99; }
.topbar-title { font-size:17px; font-weight:700; color:#101418; }
.topbar-title small { display:block; font-size:12px; color:#3A4450; font-weight:400; margin-top:2px; }
.avatar { width:36px; height:36px; border-radius:50%; background:var(--blue); display:flex; align-items:center; justify-content:center; color:#FFFFFF; font-weight:700; font-size:12px; }
.topbar-right { display:flex; align-items:center; gap:12px; }
.user-name { font-size:13px; font-weight:600; color:#101418; }
.user-role { font-size:11px; color:#4B5563; }
.content { padding:22px 26px; }

/* ALERT */
.alert-ok  { background:var(--white); border:1px solid var(--ink); border-left:4px solid var(--blue); color:#101418; border-radius:10px; padding:13px 18px; margin-bottom:16px; font-size:13px; display:flex; align-items:center; gap:10px; }
.alert-ok .wa-sent { background:var(--blue); color:#FFFFFF; font-size:11px; font-weight:700; padding:3px 10px; border-radius:20px; margin-left:auto; }
.alert-err { background:var(--white); border:1px solid var(--ink); border-left:4px solid var(--ink); color:#101418; border-radius:10px; padding:13px 18px; margin-bottom:16px; font-size:13px; }

/* SEARCH BAR */
.search-bar { background:var(--white); border:1px solid var(--line); border-radius:12px; padding:14px 18px; margin-bottom:18px; display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
.search-wrap { position:relative; flex:1; min-width:200px; }
.search-wrap input { width:100%; padding:9px 14px 9px 34px; border:1px solid var(--line); border-radius:9px; font-size:13px; font-family:inherit; outline:none; background:#EAF2FB; }
.search-wrap input:focus { border-color:var(--blue); background:var(--white); }
.search-wrap i { position:absolute; left:12px; top:50%; transform:translateY(-50%); color:#4B5563; font-size:12px; }

.filter-tabs { display:flex; background:var(--blue-pale); border-radius:9px; padding:3px; gap:2px; border:1px solid var(--line); }
.ftab { padding:7px 13px; border:none; background:transparent; border-radius:7px; font-size:12px; font-weight:600; color:#3A4450; cursor:pointer; font-family:inherit; white-space:nowrap; display:inline-flex; align-items:center; gap:6px; }
.ftab:hover { color:var(--blue-deep); }
.ftab.active { background:var(--white); color:var(--blue-deep); border:1px solid var(--line); }
.ftab-count { display:inline-block; background:rgba(46,116,192,0.14); border-radius:20px; padding:1px 7px; font-size:10px; font-weight:700; }
.ftab.active .ftab-count { background:rgba(31,95,166,0.14); color:var(--blue-deep); }
.btn-cari { padding:9px 16px; background:var(--blue); color:#FFFFFF; border:none; border-radius:9px; font-size:13px; font-weight:600; cursor:pointer; font-family:inherit; }
.btn-cari:hover { background:var(--blue-deep); }
.btn-reset { font-size:12px; color:#3A4450; text-decoration:none; font-weight:500; padding:6px 10px; border-radius:8px; }
.btn-reset:hover { background:var(--blue-pale); color:var(--blue-deep); }
.count-label { font-size:12px; color:#4B5563; font-weight:500; white-space:nowrap; margin-left:auto; }
.count-label strong { color:#101418; }

/* AKSI MASSAL (checkbox + tombol garis 3) */
.bulk-wrap { position:relative; }
.btn-bulk { display:inline-flex; align-items:center; gap:8px; padding:9px 14px; border-radius:9px; border:1.5px solid var(--line); background:var(--white); color:#3A4450; font-size:12.5px; font-weight:700; cursor:pointer; font-family:inherit; white-space:nowrap; }
.btn-bulk:hover:not(:disabled) { border-color:var(--blue); color:var(--blue-deep); }
.btn-bulk:disabled { opacity:0.45; cursor:not-allowed; }
.bulk-icon { font-size:14px; line-height:1; letter-spacing:1px; }
.bulk-count { background:var(--blue-deep); color:#FFFFFF; font-size:10.5px; font-weight:700; padding:1px 7px; border-radius:20px; }
.bulk-menu { display:none; position:absolute; top:calc(100% + 6px); left:0; background:var(--white); border:1px solid var(--line); border-radius:10px; box-shadow:0 8px 24px rgba(16,20,24,0.16); min-width:220px; z-index:80; overflow:hidden; }
.bulk-menu.open { display:block; }
.kebab-item { display:flex; align-items:center; gap:6px; width:100%; text-align:left; padding:11px 15px; border:none; background:none; font-size:12.5px; font-weight:600; color:#4E90D9; cursor:pointer; font-family:inherit; white-space:nowrap; }
.kebab-item:hover { background:var(--blue-pale); color:var(--blue-deep); }

/* CHECKBOX PILIH BARIS */
.trx-table input[type="checkbox"] { width:16px; height:16px; cursor:pointer; accent-color:var(--blue-deep); }

/* TOMBOL TITIK TIGA PER BARIS */
.btn-kebab { width:32px; height:32px; border-radius:8px; border:1.5px solid var(--line); background:var(--white); color:#3A4450; font-size:16px; font-weight:700; cursor:pointer; display:inline-flex; align-items:center; justify-content:center; font-family:inherit; line-height:1; flex-shrink:0; }
.btn-kebab:hover { border-color:var(--blue); color:var(--blue-deep); background:var(--blue-pale); }
/* Popover kebab dirender fixed & posisinya diatur lewat JS, supaya tidak
   kepotong overflow:hidden/auto punya .trx-table-wrap (penting terutama di HP). */
.kebab-popover { display:none; position:fixed; background:var(--white); border:1px solid var(--line); border-radius:10px; box-shadow:0 10px 30px rgba(16,20,24,0.2); min-width:210px; z-index:1000; overflow:hidden; }
.kebab-popover.open { display:block; }

/* PANEL / TABLE */
.panel { background:var(--white); border-radius:14px; border:1px solid var(--line); overflow:hidden; }
.trx-table-wrap { overflow-x:auto; }
.trx-table { width:100%; border-collapse:collapse; font-size:13px; }
.trx-table thead th { background:#EAF2FB; color:#3A4450; font-size:11px; font-weight:700; letter-spacing:0.4px; padding:10px 16px; border-top:1px solid var(--line); border-bottom:1px solid var(--line); white-space:nowrap; text-align:left; }
.trx-table tbody td { padding:12px 16px; border-bottom:1px solid var(--line); vertical-align:middle; }
.trx-table tbody tr:hover td { background:#EAF2FB; }
.trx-table tbody tr:last-child td { border-bottom:none; }

.nota-code { font-family:monospace; font-weight:700; color:var(--blue-deep); font-size:13px; }
.late-tag { display:inline-block; background:var(--ink); color:#FFFFFF; font-size:9px; font-weight:700; padding:2px 7px; border-radius:5px; margin-left:6px; letter-spacing:0.3px; }
.est-late { color:#101418; font-weight:700; font-size:12px; }
.est-warn { color:#3A4450; font-weight:700; font-size:12px; }
.est-ok   { color:#3A4450; font-size:12px; }

/* KATEGORI CHIPS */
.kat-chip { display:inline-block; background:var(--blue-pale); color:var(--blue-deep); border:1px solid var(--line); font-size:11px; font-weight:600; padding:2px 9px; border-radius:20px; margin:1px 3px 1px 0; }
.kat-more { color:#4B5563; font-size:11px; }

/* BADGE (bayar / status) */
.badge-s { padding:4px 10px; border-radius:20px; font-size:11px; font-weight:700; display:inline-flex; align-items:center; gap:5px; white-space:nowrap; }
.bs-lunas { background:var(--blue); color:#FFFFFF; }
.bs-dp      { background:#D6E7F7; color:#3A4450; }
.bs-belum { background:var(--white); color:#101418; border:1px solid var(--line); }
.badge-tahap { padding:4px 10px; border-radius:20px; font-size:11px; font-weight:700; display:inline-flex; align-items:center; gap:5px; white-space:nowrap; background:#D6E7F7; color:#3A4450; border:1px solid #D6E7F7; }
.toast-wrap { position:fixed; bottom:24px; right:24px; z-index:9999; display:flex; flex-direction:column; gap:8px; }
.toast { padding:12px 18px; border-radius:10px; font-size:13px; font-weight:600; color:#FFFFFF; display:flex; align-items:center; gap:8px; }
.toast.ok  { background:var(--blue-deep); }
.toast.err { background:var(--ink); }
.bs-proses { background:var(--blue-pale); color:var(--blue-deep); }

/* AKSI BUTTONS */
.btn-acts { display:flex; gap:6px; flex-wrap:wrap; }
.btn-act { padding:7px 12px; border-radius:8px; font-size:11.5px; font-weight:700; border:1.5px solid #1B2126; cursor:pointer; text-decoration:none; display:inline-flex; align-items:center; gap:6px; font-family:inherit; white-space:nowrap; background:#FFFFFF; color:#1B2126; }
.btn-act:hover { background:#1B2126; color:#FFFFFF; }
.btn-detail  { background:#FFFFFF; color:#1B2126; border-color:#1B2126; }
.btn-detail:hover { background:#1B2126; color:#FFFFFF; }
.btn-selesai { background:var(--blue); color:#FFFFFF; border-color:var(--blue); }
.btn-selesai:hover { background:var(--blue-deep); }
.btn-mundur { background:#FFFFFF; color:#4E90D9; border-color:#B9D4EF; }
.btn-mundur:hover { background:#EAF2FB; color:var(--blue-deep); }
.item-tahap-btns { display:flex; gap:6px; flex-wrap:wrap; margin-top:6px; width:100%; }

/* Baris aksi khusus HP: tombol berjejer horizontal di bawah data, bukan menumpuk di kolom sempit */
.trx-actions-row { display:none; }

/* EMPTY STATE */
.empty-state { text-align:center; padding:48px 20px; color:#4B5563; }
.empty-state .ei { width:50px; height:50px; border-radius:50%; background:var(--blue-pale); color:var(--blue-deep); display:flex; align-items:center; justify-content:center; font-size:19px; margin:0 auto 12px; }
.empty-state p { font-size:13px; color:#3A4450; margin:0; }

/* MODAL DETAIL */
.modal-overlay { display:none; position:fixed; inset:0; background:rgba(22,74,133,0.45); z-index:999; align-items:center; justify-content:center; padding:16px; }
.modal-overlay.active { display:flex; }
.modal-box { background:var(--white); border-radius:14px; width:640px; max-width:94vw; max-height:88vh; overflow-y:auto; text-align:left; }
.modal-head { background:var(--blue-deep); padding:20px 26px; color:#FFFFFF; font-size:19px; font-weight:700; display:flex; align-items:center; gap:8px; }
.modal-head-close { margin-left:auto; background:none; border:none; color:#FFFFFF; font-size:16px; cursor:pointer; }
.modal-body { padding:26px; }
.modal-sub-title { font-size:13.5px; font-weight:700; color:#3A4450; text-transform:uppercase; letter-spacing:0.5px; margin:18px 0 10px; }
.modal-sub-title:first-child { margin-top:0; }
.item-row { display:flex; align-items:center; gap:10px; background:#EAF2FB; border:1px solid var(--line); border-radius:10px; padding:14px 17px; margin-bottom:10px; font-size:16.5px; }
.item-row .item-name { flex:1; font-weight:600; color:#101418; }
.item-row .item-name small { display:block; font-weight:400; color:#3A4450; font-size:14px; margin-top:3px; }
.item-row .item-price { color:var(--blue-deep); font-weight:700; min-width:100px; text-align:right; font-size:16.5px; }

.item-status { padding:5px 13px; border-radius:20px; font-size:13px; font-weight:700; white-space:nowrap; flex-shrink:0; margin-top:5px; display:inline-block; }
.item-status.active  { background:#EAF2FB; color:#3A4450; }
.item-status.done    { background:#D6E7F7; color:#1B2126; }
.item-status.waiting { background:#EAF2FB; color:#3A4450; }
.total-box { background:var(--blue-deep); border-radius:10px; padding:14px 16px; display:flex; justify-content:space-between; align-items:center; margin-top:12px; }
.total-box .lbl { color:rgba(255,255,255,0.8); font-size:13px; }
.total-box .val { color:#FFFFFF; font-size:19px; font-weight:700; }

/* MODAL KONFIRMASI SELESAI */
.modal-confirm-box { background:var(--white); border-radius:14px; padding:26px; max-width:420px; width:90%; text-align:center; }
.modal-icon-wrap { width:58px; height:58px; border-radius:50%; background:var(--blue-pale); color:var(--blue-deep); display:flex; align-items:center; justify-content:center; font-size:23px; margin:0 auto 14px; }
.modal-title { font-size:19px; font-weight:700; color:#101418; margin-bottom:8px; }
.modal-sub   { font-size:15.5px; color:#3A4450; margin-bottom:14px; line-height:1.6; }
.modal-note  { background:#EAF2FB; border-radius:10px; border:1px solid var(--line); padding:11px 14px; margin-bottom:16px; font-size:13.5px; color:#3A4450; display:flex; align-items:center; gap:8px; text-align:left; }
.confirm-btns { display:flex; gap:10px; justify-content:center; }
.btn-ok     { padding:12px 26px; background:var(--blue); color:#FFFFFF; border:none; border-radius:9px; font-size:14.5px; font-weight:700; cursor:pointer; font-family:inherit; }
.btn-ok:hover { background:var(--blue-deep); }
.btn-cancel { padding:12px 26px; background:var(--white); color:#3A4450; border:1px solid var(--line); border-radius:9px; font-size:14.5px; font-weight:600; cursor:pointer; font-family:inherit; }
.btn-cancel:hover { background:#EAF2FB; }

/* ===== TABEL TETAP TABEL DI HP, TAPI DIKECILIN SUPAYA MUAT (GAK SCROLL SAMPING) ===== */
@media (max-width: 700px) {
    .warning-bar, .readonly-info { align-items:flex-start; }
    .trx-table-wrap { overflow-x:hidden; padding:6px; }
    .trx-table { table-layout:fixed; font-size:10.5px; }

    /* Checkbox, Nota, Pelanggan, Kategori, Tahap, Estimasi */
    .trx-table th:nth-child(1), .trx-table td:nth-child(1) { width:8%; }
    .trx-table th:nth-child(2), .trx-table td:nth-child(2) { width:22%; }
    .trx-table th:nth-child(3), .trx-table td:nth-child(3) { width:18%; }
    .trx-table th:nth-child(4), .trx-table td:nth-child(4) { width:14%; }
    .trx-table th:nth-child(5), .trx-table td:nth-child(5) { width:16%; }
    .trx-table th:nth-child(6), .trx-table td:nth-child(6) { width:22%; }
    .trx-table th:nth-child(7) { display:none; }
    .trx-table td[data-label="Aksi"] { display:none; }

    .trx-table thead th { padding:6px 4px; font-size:8px; white-space:normal; word-break:break-word; }
    .trx-table tbody td { padding:6px 4px; font-size:10px; word-break:break-word; vertical-align:top; }

    .trx-table .nota-code { font-size:9px; display:inline-block; white-space:nowrap; letter-spacing:-0.2px; }
    .trx-table .late-tag { font-size:7px; padding:1px 4px; margin-left:2px; }
    .trx-table td[data-label="Pelanggan"] div:first-child { font-size:9.5px; }
    .trx-table td[data-label="Pelanggan"] div:last-child  { font-size:8px; }
    .kat-chip { font-size:8px; padding:1px 5px; margin:0 2px 2px 0; }
    .kat-more { font-size:8px; }
    .badge-tahap { font-size:8px; padding:2px 5px; white-space:normal; }
    .trx-table td.est-late, .trx-table td.est-warn, .trx-table td.est-ok { font-size:9.5px; }

    /* Baris tombol aksi: tampil sebagai baris penuh di bawah data, tombol berjejer horizontal & bisa digeser */
    /* Baris data & baris aksi adalah 1 nota yang sama -> hilangkan garis di antara keduanya,
       garis pemisah cuma muncul setelah baris aksi (baru pindah ke nota berikutnya) */
    .trx-table tbody tr:not(.trx-actions-row) td { border-bottom:none; }
    .trx-actions-row { display:table-row; }
    .trx-actions-row td { border-bottom:1px solid var(--sky-soft); }
    .trx-actions-row td { padding:8px 3px 10px; }
    .trx-actions-row .btn-acts {
        flex-direction:row; flex-wrap:wrap; gap:6px;
        row-gap:8px; overflow-x:visible; padding-bottom:2px;
    }
    .trx-actions-row .btn-act {
        flex:0 0 auto; padding:7px 12px; font-size:10.5px; line-height:1.2; white-space:nowrap;
    }
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
      <small>Panel Pencucian</small>
    </div>
  </div>
  <div class="sidebar-menu">
    <a href="dashboard_pencucian.php">Dashboard</a>
    <a href="antrian.php">Antrian</a>
    <a href="proses.php" class="active">Sedang Diproses</a>
  </div>
  <div class="sidebar-footer">
    <a href="logout.php">Logout</a>
  </div>
</div>

<div class="main">
  <div class="topbar">
    <div>
      <div class="topbar-title">
        Sedang Diproses
        <small><?= date('l, d M Y') ?> &middot; Panel Pencucian</small>
      </div>
    </div>
    <div class="topbar-right">
      <div style="text-align:right">
        <div class="user-name"><?= $namaPetugas ?></div>
        <div class="user-role">Bagian Pencucian</div>
      </div>
      <div class="avatar"><?= $inisial ?></div>
    </div>
  </div>

  <div class="content">

    <?php if (!empty($_GET['sukses'])): ?>
    <div class="alert-ok">
            Cucian berhasil ditandai <strong>Siap Diambil</strong>!
      <span class="wa-sent">✓ Notifikasi WA terkirim</span>
    </div>
    <?php endif; ?>
    <?php if (!empty($_GET['error'])): ?>
    <div class="alert-err"><?= htmlspecialchars($_GET['error']) ?></div>
    <?php endif; ?>

    <!-- SEARCH BAR + FILTER + URUTKAN -->
    <div class="search-bar" style="flex-wrap:wrap">
      <div class="bulk-wrap" id="bulkWrapP">
        <button type="button" class="btn-bulk" id="btnBulkMenuP" onclick="toggleBulkMenuP()" disabled title="Aksi massal untuk nota terpilih">
          <span class="bulk-icon">&#9776;</span> Aksi Massal <span class="bulk-count" id="bulkCountP" style="display:none">0</span>
        </button>
        <div class="bulk-menu" id="bulkMenuP">
          <button type="button" class="kebab-item" onclick="handleBulkKembalikanAntrian()">
            &larr; Kembalikan ke Antrian
          </button>
        </div>
      </div>
      <div class="search-wrap">
        <input type="text" id="searchInputP" placeholder="Cari nota atau nama pelanggan…" oninput="applyFilterP()">
      </div>
      <div class="filter-tabs" id="filterGroupStatus">
        <button class="ftab active" id="ftab-semua" onclick="setFilterP('semua')">
          Semua <span class="ftab-count" id="fc-semua"><?= count($list) ?></span>
        </button>
        <?php $jmlTerlambatP = count(array_filter($list, fn($r) => !empty($r['pernah_terlambat']) || (!empty($r['tanggal_estimasi']) && strtotime($r['tanggal_estimasi']) < $today))); ?>
        <?php if ($jmlTerlambatP > 0): ?>
        <button class="ftab" id="ftab-terlambat" onclick="setFilterP('terlambat')">
          Terlambat <span class="ftab-count" id="fc-terlambat"><?= $jmlTerlambatP ?></span>
        </button>
        <?php endif; ?>
      </div>
      <div class="filter-tabs" id="filterGroupSort">
        <button class="ftab" id="sort-terbaru" onclick="setSortP('terbaru')">Terbaru</button>
        <button class="ftab" id="sort-terlama" onclick="setSortP('terlama')">Terlama</button>
      </div>
      <div class="count-label" id="countLabelP">Menampilkan <strong><?= $total_proses ?></strong> cucian diproses</div>
    </div>

    <?php if (empty($list)): ?>
    <div class="empty-state">
      <div class="ei"></div>
      <p><?= $q !== '' ? 'Tidak ada hasil untuk pencarian "<strong>' . htmlspecialchars($q) . '</strong>"' : 'Belum ada cucian yang sedang diproses.' ?></p>
    </div>
    <?php else: ?>
    <div class="panel">
      <div class="trx-table-wrap">
        <table class="trx-table">
          <thead>
            <tr>
              <th style="width:36px"><input type="checkbox" id="checkAllP" onchange="toggleCheckAllP(this)" title="Pilih semua yang tampil"></th>
              <th>NOTA</th>
              <th>PELANGGAN</th>
              <th>KATEGORI</th>
              <th>TAHAP</th>
              <th>ESTIMASI</th>
              <th>AKSI</th>
            </tr>
          </thead>
          <tbody id="tabelProsesTbody">
          <?php foreach ($list as $row):
            $terlambat = !empty($row['pernah_terlambat']) || (!empty($row['tanggal_estimasi']) && strtotime($row['tanggal_estimasi']) < $today);
            $segera    = !$terlambat && !empty($row['tanggal_estimasi']) && strtotime($row['tanggal_estimasi']) <= $tomorrow;
            $estFmt    = !empty($row['tanggal_estimasi']) ? date('d/m/Y', strtotime($row['tanggal_estimasi'])) : '-';
            $estClass  = $terlambat ? 'est-late' : ($segera ? 'est-warn' : 'est-ok');
            $katList   = array_keys($kategoriPerTrx[$row['id_transaksi']] ?? []);
            $tipeDominan   = tipeDominanTransaksi($pdo, $row['id_transaksi']);
            $tahapSekarang = $row['tahap_proses'];
            $labelSekarang = $tahapSekarang ? labelTahap($tahapSekarang, $tipeDominan, $katList[0] ?? null) : null;
            $dataFilterP = 'semua' . ($terlambat ? ' terlambat' : '');
          ?>
          <tr id="rowP-<?= $row['id_transaksi'] ?>" data-filter="<?= $dataFilterP ?>" data-created="<?= strtotime($row['created_at']) ?>" data-search="<?= strtolower(htmlspecialchars($row['id_nota'].' '.$row['nama_pelanggan'], ENT_QUOTES)) ?>">
            <td data-label="Pilih">
              <input type="checkbox" class="row-check" data-id="<?= $row['id_transaksi'] ?>" onchange="onRowCheckChange(this)">
            </td>
            <td data-label="Nota">
              <span class="nota-code"><?= htmlspecialchars($row['id_nota']) ?></span>
              <?php if ($terlambat): ?><span class="late-tag">TERLAMBAT</span><?php endif; ?>
            </td>
            <td data-label="Pelanggan">
              <div style="font-weight:600;color:#101418"><?= htmlspecialchars($row['nama_pelanggan']) ?></div>
              <div style="font-size:11px;color:#4B5563"><?= htmlspecialchars($row['no_hp_pelanggan']) ?></div>
              <?php if (!empty($row['nama_petugas'])): ?>
              <div style="font-size:10.5px;color:#8A94A3">Diproses oleh: <?= htmlspecialchars($row['nama_petugas']) ?></div>
              <?php endif; ?>
            </td>
            <td data-label="Kategori">
              <?php if (!empty($katList)): ?>
                <span class="kat-chip"><?= htmlspecialchars($katList[0]) ?></span>
                <?php if (count($katList) > 1): ?><span class="kat-more">+<?= count($katList) - 1 ?> lainnya</span><?php endif; ?>
              <?php else: ?>
                <span style="color:#4B5563;font-size:12px">-</span>
              <?php endif; ?>
            </td>
            <td data-label="Tahap">
              <?php if ($labelSekarang): ?>
              <span class="badge-tahap" id="tahapBadge-<?= $row['id_transaksi'] ?>"><?= htmlspecialchars($labelSekarang) ?></span>
              <?php else: ?>
              <span style="color:#4B5563;font-size:12px">-</span>
              <?php endif; ?>
            </td>
            <td data-label="Estimasi" class="<?= $estClass ?>"><?= $estFmt ?></td>
            <td data-label="Aksi">
              <div class="btn-acts" id="btnActs-<?= $row['id_transaksi'] ?>">
                <button type="button" class="btn-kebab" onclick="toggleKebabMenu(event, <?= $row['id_transaksi'] ?>, '<?= htmlspecialchars($row['id_nota'], ENT_QUOTES) ?>')" title="Aksi lain">
                  &#8942;
                </button>
                <button type="button" class="btn-act btn-detail" onclick="showDetail(<?= $row['id_transaksi'] ?>)" title="Lihat rincian pesanan &amp; lanjutkan tahap per item">
                  Detail &amp; Lanjut Tahap
                </button>
              </div>
            </td>
          </tr>
          <tr class="trx-actions-row">
            <td colspan="7">
              <div class="btn-acts" id="btnActs-<?= $row['id_transaksi'] ?>-m">
                <button type="button" class="btn-kebab" onclick="toggleKebabMenu(event, <?= $row['id_transaksi'] ?>, '<?= htmlspecialchars($row['id_nota'], ENT_QUOTES) ?>')" title="Aksi lain">
                  &#8942;
                </button>
                <button type="button" class="btn-act btn-detail" onclick="showDetail(<?= $row['id_transaksi'] ?>)" title="Lihat rincian pesanan &amp; lanjutkan tahap per item">
                  Detail &amp; Lanjut Tahap
                </button>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>

  </div>
</div>

<!-- MODAL DETAIL RINCIAN -->
<div class="modal-overlay" id="modalDetail">
  <div class="modal-box">
    <div class="modal-head">
      Rincian Pesanan
      <button type="button" class="modal-head-close" onclick="tutupModal('modalDetail')">Tutup</button>
    </div>
    <div class="modal-body" id="detailBody"></div>
  </div>
</div>

<!-- MODAL KONFIRMASI LANJUT TAHAP / SIAP DIAMBIL -->
<div class="modal-overlay" id="modalKonfirmasi">
  <div class="modal-confirm-box">
    <div class="modal-icon-wrap"></div>
    <div class="modal-title" id="modalTitle">Konfirmasi</div>
    <div class="modal-sub" id="modalSub"></div>
    <div class="modal-note" id="modalNoteWA" style="display:none">
            Notifikasi WhatsApp akan otomatis dikirim ke pelanggan.
    </div>
    <div class="confirm-btns">
      <button type="button" class="btn-cancel" onclick="tutupModal('modalKonfirmasi')">Batal</button>
      <button type="button" class="btn-ok" id="btnKonfirmasiOk" onclick="jalankanKonfirmasi()">Ya, Lanjutkan</button>
    </div>
  </div>
</div>

<div class="toast-wrap" id="toastWrap"></div>

<!-- POPOVER TITIK TIGA (per-baris) -->
<div class="kebab-popover" id="kebabPopover">
  <button type="button" class="kebab-item" onclick="onKebabKembalikan()">
    &larr; Kembalikan ke Antrian
  </button>
</div>

<script>
const rincianData = <?= $rincian_json_out ?>;
const CSRF_TOKEN   = <?= $csrf_token_out ?>;
let _detailIdAktif = null;
let _transaksiIdAktif = null;
const PROSES_STATE_KEY = 'prosesState';
// Antrian notifikasi WA "siap diambil" ditangani oleh window.antrekanNotifWa()
// dari includes/notif_wa_widget.php (di-include di bawah, dekat </body>).

// ── FILTER (Semua/Terlambat) + URUTKAN (Terbaru/Terlama) + PENCARIAN ──
// Filter (visibilitas baris) dan urutan (posisi baris) SENGAJA dipisah jadi
// 2 state independen -- supaya kasir/petugas bisa gabungkan mis. "Terlambat"
// + "Terlama" sekaligus, bukan cuma satu-satu.
let activeFilterP = 'semua';
let activeSortP   = null; // null = urutan asli dari server (estimasi terdekat dulu)
let activeSearchP = '';

function setFilterP(key) {
    activeFilterP = key;
    document.querySelectorAll('#filterGroupStatus .ftab').forEach(b => b.classList.remove('active'));
    document.getElementById('ftab-' + key)?.classList.add('active');
    applyFilterP();
}

function setSortP(key) {
    // Toggle: klik lagi tombol yang sama = matikan urutan custom, balik ke
    // urutan asli server (estimasi paling dekat duluan).
    activeSortP = (activeSortP === key) ? null : key;
    document.getElementById('sort-terbaru')?.classList.toggle('active', activeSortP === 'terbaru');
    document.getElementById('sort-terlama')?.classList.toggle('active', activeSortP === 'terlama');
    applyFilterP();
}

function applyFilterP() {
    activeSearchP = (document.getElementById('searchInputP')?.value || '').toLowerCase().trim();
    const tbody = document.getElementById('tabelProsesTbody');
    if (!tbody) return;

    let rows = Array.from(tbody.querySelectorAll('tr[data-filter]'));

    // Urutkan dulu (kalau ada urutan custom aktif) -- pindahkan baris +
    // sibling "trx-actions-row"-nya (baris tombol aksi versi mobile)
    // bersama-sama supaya tetap nempel setelah induknya.
    if (activeSortP) {
        rows.sort((a, b) => {
            const ta = parseInt(a.dataset.created) || 0;
            const tb = parseInt(b.dataset.created) || 0;
            return activeSortP === 'terbaru' ? tb - ta : ta - tb;
        });
        rows.forEach(row => {
            const aksiRow = row.nextElementSibling;
            tbody.appendChild(row);
            if (aksiRow && aksiRow.classList.contains('trx-actions-row')) tbody.appendChild(aksiRow);
        });
    }

    let visible = 0;
    rows.forEach(row => {
        const matchFilter = activeFilterP === 'semua' || row.dataset.filter.includes(activeFilterP);
        const matchSearch = activeSearchP === '' || row.dataset.search.includes(activeSearchP);
        const show = matchFilter && matchSearch;
        row.style.display = show ? '' : 'none';
        const aksiRow = row.nextElementSibling;
        if (aksiRow && aksiRow.classList.contains('trx-actions-row')) {
            aksiRow.style.display = show ? '' : 'none';
        }
        if (show) visible++;
    });

    const countLabel = document.getElementById('countLabelP');
    if (countLabel) countLabel.innerHTML = 'Menampilkan <strong>' + visible + '</strong> cucian diproses';

    // Filter/search bisa menyembunyikan baris yang tadinya dicentang lewat
    // "pilih semua" -- sinkronkan lagi supaya checkbox header tidak nyangkut aktif.
    syncCheckAllStateP();

    let emptyRow = document.getElementById('filterEmptyRowP');
    if (visible === 0) {
        if (!emptyRow) {
            emptyRow = document.createElement('tr');
            emptyRow.id = 'filterEmptyRowP';
            emptyRow.innerHTML = '<td colspan="7" style="text-align:center;padding:28px;color:#4B5563">Tidak ditemukan nota yang cocok.</td>';
            tbody.appendChild(emptyRow);
        }
    } else {
        emptyRow?.remove();
    }
}

// Setelah "Lanjut Tahap" tersimpan, halaman perlu dimuat ulang supaya data
// tahap terbaru (dari server) akurat — tapi biar tidak terasa seperti "reset",
// posisi scroll dipulihkan dan modal detail nota yang tadi dibuka otomatis
// dibuka lagi menampilkan tahap terbaru.
(function pulihkanStateProses() {
    let state;
    try { state = JSON.parse(sessionStorage.getItem(PROSES_STATE_KEY) || 'null'); } catch (e) { state = null; }
    if (!state) return;
    sessionStorage.removeItem(PROSES_STATE_KEY);
    if (typeof state.scrollY === 'number') window.scrollTo(0, state.scrollY);
    if (state.transaksiId && rincianData[state.transaksiId]) {
        showDetail(state.transaksiId);
    }
})();

function formatRp(n) { return 'Rp' + Math.round(n).toLocaleString('id-ID'); }

function tutupModal(id) { document.getElementById(id)?.classList.remove('active'); }
document.querySelectorAll('.modal-overlay').forEach(el =>
    el.addEventListener('click', e => { if (e.target === el) el.classList.remove('active'); })
);

// ── MODAL DETAIL (tiap item punya tombol "Lanjut Tahap" sendiri) ──
function showDetail(id) {
    const d = rincianData[id];
    if (!d) return;
    _transaksiIdAktif = id;

    let itemsHtml = '';
    if (d.items.length === 0) {
        itemsHtml = '<div style="color:#4B5563;font-size:13px">Tidak ada rincian item.</div>';
    } else {
        d.items.forEach(item => {
            const namaAman = item.nama.replace(/'/g, "\\'");
            const tombolMundur = item.prev_label
                ? '<button type="button" class="btn-act btn-mundur" ' +
                    'onclick="handleMundurItem(' + item.detail_id + ', \'' + namaAman + '\', \'' + item.prev_label + '\')">' +
                    '&larr; Mundur: ' + item.prev_label +
                  '</button>'
                : '';
            const tombolLanjut = item.next_label
                ? '<button type="button" class="btn-act btn-selesai" ' +
                    'onclick="handleLanjutItem(' + item.detail_id + ', \'' + namaAman + '\', \'' + item.next_label + '\', ' + item.is_terakhir + ')">' +
                    'Lanjut: ' + item.next_label + ' &rarr;' +
                  '</button>'
                : '';
            const tombolTahap = (tombolMundur || tombolLanjut)
                ? '<div class="item-tahap-btns">' + tombolMundur + tombolLanjut + '</div>'
                : '';
            itemsHtml +=
                '<div class="item-row" style="align-items:flex-start;flex-wrap:wrap">' +
                  '<div class="item-name">' + item.nama + '<small>' + item.kategori + ' &middot; ' + item.qty + ' ' + item.satuan + '</small>' +
                    '<span class="item-status ' + item.status_state + '">' + item.status_label + '</span>' +
                    tombolTahap +
                  '</div>' +
                  '<div class="item-price">' + formatRp(item.subtotal) + '</div>' +
                '</div>';
        });
    }

    document.getElementById('detailBody').innerHTML =
        '<div style="margin-bottom:6px">' +
          '<div class="nota-code" style="font-size:18px">' + d.nota + (d.terlambat ? '<span class="late-tag">TERLAMBAT</span>' : '') + '</div>' +
          '<div style="font-size:15px;color:#3A4450;margin-top:4px">' + d.nama + ' &middot; ' + d.no_hp + '</div>' +
          '<div style="font-size:13.5px;color:#4B5563;margin-top:5px">Estimasi selesai: ' + d.estimasi + ' &middot; Mulai diproses: ' + d.mulai + '</div>' +
          (d.petugas ? '<div style="font-size:13.5px;color:#4B5563;margin-top:3px">Terakhir diubah oleh: <strong style="color:#101418">' + d.petugas + '</strong></div>' : '') +
        '</div>' +
        '<div class="modal-sub-title">Rincian Item &amp; Lanjut Tahap per Item</div>' +
        itemsHtml +
        '<div style="font-size:13.5px;color:#3A4450;background:#EAF2FB;border:1px solid var(--line);border-radius:9px;padding:11px 14px;margin:10px 0 4px;line-height:1.65">' +
          'Tiap item punya alur &amp; tombol lanjut tahap sendiri-sendiri. Begitu semua item sudah "Sudah Dikemas", nota otomatis ditandai Siap Diambil.' +
        '</div>';

    document.getElementById('modalDetail').classList.add('active');
}

// ── LANJUT / MUNDUR TAHAP ITEM ──
// _aksiAktif menandai aksi apa yang sedang dikonfirmasi lewat modalKonfirmasi
// ('lanjut' atau 'mundur'), supaya tombol "Ya, Lanjutkan" di modal itu tahu
// fungsi eksekusi mana yang harus dipanggil (lihat jalankanKonfirmasi()).
let _aksiAktif = null;
let _kembalikanIdAktif = null;

// ── PILIH BARIS (CHECKBOX) & AKSI MASSAL ──
let selectedIdsP = new Set();

function toggleCheckAllP(cb) {
    const checked = cb.checked;
    document.querySelectorAll('#tabelProsesTbody .row-check').forEach(chk => {
        const row = chk.closest('tr');
        if (row.style.display === 'none') return; // cuma yang lagi tampil (kena filter/search)
        chk.checked = checked;
        const id = parseInt(chk.dataset.id, 10);
        if (checked) selectedIdsP.add(id); else selectedIdsP.delete(id);
    });
    updateBulkButtonP();
}

function onRowCheckChange(chk) {
    const id = parseInt(chk.dataset.id, 10);
    if (chk.checked) selectedIdsP.add(id); else selectedIdsP.delete(id);
    syncCheckAllStateP();
    updateBulkButtonP();
}

function syncCheckAllStateP() {
    const checkAll = document.getElementById('checkAllP');
    if (!checkAll) return;
    const visible = Array.from(document.querySelectorAll('#tabelProsesTbody .row-check'))
        .filter(c => c.closest('tr').style.display !== 'none');
    checkAll.checked = visible.length > 0 && visible.every(c => c.checked);
}

function updateBulkButtonP() {
    const btn     = document.getElementById('btnBulkMenuP');
    const countEl = document.getElementById('bulkCountP');
    const n = selectedIdsP.size;
    if (btn) btn.disabled = n === 0;
    if (countEl) {
        if (n > 0) { countEl.textContent = n; countEl.style.display = 'inline-block'; }
        else { countEl.style.display = 'none'; }
    }
    if (n === 0) document.getElementById('bulkMenuP')?.classList.remove('open');
}

function toggleBulkMenuP() {
    if (selectedIdsP.size === 0) return;
    document.getElementById('bulkMenuP')?.classList.toggle('open');
}

function handleBulkKembalikanAntrian() {
    document.getElementById('bulkMenuP')?.classList.remove('open');
    if (selectedIdsP.size === 0) return;
    _aksiAktif = 'bulk_kembalikan_antrian';

    document.getElementById('modalTitle').textContent = 'Konfirmasi Kembalikan ke Antrian';
    document.getElementById('modalSub').innerHTML =
        'Kembalikan <strong style="color:var(--blue-deep)">' + selectedIdsP.size + ' nota terpilih</strong> ke status <strong>Antrian</strong>? Progres tahap yang sudah tercatat akan direset.';
    document.getElementById('modalNoteWA').style.display = 'none';
    document.getElementById('btnKonfirmasiOk').textContent = 'Ya, Kembalikan';
    document.getElementById('modalKonfirmasi').classList.add('active');
}

async function eksekusiBulkKembalikanAntrian() {
    const ids = Array.from(selectedIdsP);
    if (ids.length === 0) return;

    let gagal = 0;
    for (const id of ids) {
        const fd = new FormData();
        fd.append('transaksi_id', id);
        fd.append('status', 'dalam_antrian');
        fd.append('csrf_token', CSRF_TOKEN);
        try {
            const res  = await fetch('update_status_cucian.php', { method: 'POST', body: fd, credentials: 'same-origin' });
            const data = await res.json();
            if (!data.success) gagal++;
        } catch (e) {
            gagal++;
        }
    }

    if (gagal === 0) {
        showToast('fa-circle-check', ids.length + ' nota dikembalikan ke Antrian.', 'ok');
    } else {
        showToast('fa-circle-exclamation', (ids.length - gagal) + ' berhasil, ' + gagal + ' gagal dikembalikan.', 'err');
    }
    selectedIdsP.clear();
    sessionStorage.setItem(PROSES_STATE_KEY, JSON.stringify({ scrollY: window.scrollY }));
    setTimeout(() => location.reload(), 900);
}

// ── TITIK TIGA PER BARIS (popover, posisi diatur via JS supaya gak
// kepotong overflow punya .trx-table-wrap, terutama di tampilan HP) ──
let _kebabRowId   = null;
let _kebabRowNota = null;

function toggleKebabMenu(evt, id, nota) {
    evt.stopPropagation();
    const popover = document.getElementById('kebabPopover');
    if (popover.classList.contains('open') && _kebabRowId === id) {
        closeKebabMenu();
        return;
    }
    _kebabRowId   = id;
    _kebabRowNota = nota;

    const rect = evt.currentTarget.getBoundingClientRect();
    popover.style.top  = (rect.bottom + 6) + 'px';
    let left = rect.left;
    const maxLeft = window.innerWidth - 220;
    if (left > maxLeft) left = Math.max(8, maxLeft);
    popover.style.left = left + 'px';
    popover.classList.add('open');
}

function closeKebabMenu() {
    document.getElementById('kebabPopover')?.classList.remove('open');
    _kebabRowId   = null;
    _kebabRowNota = null;
}

function onKebabKembalikan() {
    if (!_kebabRowId) return;
    const id = _kebabRowId, nota = _kebabRowNota;
    closeKebabMenu();
    handleKembalikanAntrian(id, nota);
}

document.addEventListener('click', function (e) {
    const popover = document.getElementById('kebabPopover');
    if (popover && popover.classList.contains('open') && !popover.contains(e.target) && !e.target.closest('.btn-kebab')) {
        closeKebabMenu();
    }
    const bulkMenu = document.getElementById('bulkMenuP');
    if (bulkMenu && bulkMenu.classList.contains('open') && !document.getElementById('bulkWrapP')?.contains(e.target)) {
        bulkMenu.classList.remove('open');
    }
});
window.addEventListener('scroll', closeKebabMenu, true);
window.addEventListener('resize', closeKebabMenu);

// ── KEMBALIKAN KE ANTRIAN (batal proses, nota balik ke tahap Antrian) ──
// Dipakai kalau petugas salah pencet "mulai proses" atau ternyata nota
// ini harus menunggu dulu (mis. mesin cuci penuh / barang belum lengkap).
function handleKembalikanAntrian(transaksiId, nota) {
    _kembalikanIdAktif = transaksiId;
    _aksiAktif          = 'kembalikan_antrian';

    document.getElementById('modalTitle').textContent = 'Konfirmasi Kembalikan ke Antrian';
    document.getElementById('modalSub').innerHTML =
        'Kembalikan nota <strong style="color:var(--blue-deep)">' + nota + '</strong> ke status <strong>Antrian</strong>? Progres tahap yang sudah tercatat akan direset.';
    document.getElementById('modalNoteWA').style.display = 'none';
    document.getElementById('btnKonfirmasiOk').textContent = 'Ya, Kembalikan';
    document.getElementById('modalKonfirmasi').classList.add('active');
}

async function eksekusiKembalikanAntrian(transaksiId) {
    if (!transaksiId) return;

    const fd = new FormData();
    fd.append('transaksi_id', transaksiId);
    fd.append('status', 'dalam_antrian');
    fd.append('csrf_token', CSRF_TOKEN);

    try {
        const res  = await fetch('update_status_cucian.php', { method: 'POST', body: fd, credentials: 'same-origin' });
        const data = await res.json();

        if (!data.success) {
            showToast('fa-circle-exclamation', data.message || 'Gagal memproses, silakan coba lagi.', 'err');
            return;
        }

        showToast('fa-circle-check', 'Nota dikembalikan ke Antrian.', 'ok');
        sessionStorage.setItem(PROSES_STATE_KEY, JSON.stringify({ scrollY: window.scrollY }));
        setTimeout(() => location.reload(), 800);
    } catch (e) {
        showToast('fa-circle-exclamation', 'Terjadi kesalahan koneksi.', 'err');
    }
}

// Kalau ini BUKAN klik terakhir (belum sampai "kemas"), langsung eksekusi
// tanpa popup konfirmasi supaya cepat. Popup konfirmasi hanya muncul saat
// item BENERAN akan ditandai selesai dikemas (tahap berikutnya = kemas).
function handleLanjutItem(detailId, namaItem, nextLabel, isTerakhir) {
    if (isTerakhir) {
        konfirmasiLanjutItem(detailId, namaItem, nextLabel);
    } else {
        eksekusiLanjutItem(detailId);
    }
}

// ── MUNDUR TAHAP: kembalikan item ke tahap sebelumnya (mis. salah pencet
// atau perlu dikerjakan ulang). Selalu tampilkan konfirmasi dulu karena
// aksi ini "membatalkan" progres yang sudah tercatat.
function handleMundurItem(detailId, namaItem, prevLabel) {
    konfirmasiMundurItem(detailId, namaItem, prevLabel);
}

// ── MODAL KONFIRMASI (hanya untuk tahap terakhir: selesai dikemas) ──
function konfirmasiLanjutItem(detailId, namaItem, nextLabel) {
    _detailIdAktif = detailId;
    _aksiAktif     = 'lanjut';

    document.getElementById('modalTitle').textContent = 'Konfirmasi Item Selesai Dikemas';
    document.getElementById('modalSub').innerHTML =
        'Tandai item <strong style="color:var(--blue-deep)">' + namaItem + '</strong> sudah <strong>' + nextLabel + '</strong>?';
    document.getElementById('modalNoteWA').style.display = 'none';
    document.getElementById('btnKonfirmasiOk').textContent = 'Ya, Lanjutkan';
    document.getElementById('modalKonfirmasi').classList.add('active');
}

// ── MODAL KONFIRMASI MUNDUR TAHAP ──
function konfirmasiMundurItem(detailId, namaItem, prevLabel) {
    _detailIdAktif = detailId;
    _aksiAktif     = 'mundur';

    document.getElementById('modalTitle').textContent = 'Konfirmasi Mundur Tahap';
    document.getElementById('modalSub').innerHTML =
        'Kembalikan item <strong style="color:var(--blue-deep)">' + namaItem + '</strong> ke tahap <strong>' + prevLabel + '</strong>?';
    document.getElementById('modalNoteWA').style.display = 'none';
    document.getElementById('btnKonfirmasiOk').textContent = 'Ya, Mundurkan';
    document.getElementById('modalKonfirmasi').classList.add('active');
}

// Dipanggil dari tombol "Ya, ..." di modalKonfirmasi — meneruskan ke fungsi
// eksekusi yang sesuai berdasarkan _aksiAktif yang diset saat modal dibuka.
function jalankanKonfirmasi() {
    const detailId = _detailIdAktif;
    const aksi     = _aksiAktif;
    tutupModal('modalKonfirmasi');

    if (aksi === 'bulk_kembalikan_antrian') {
        eksekusiBulkKembalikanAntrian();
        return;
    }
    if (aksi === 'kembalikan_antrian') {
        eksekusiKembalikanAntrian(_kembalikanIdAktif);
        return;
    }
    if (!detailId) return;
    if (aksi === 'mundur') {
        eksekusiMundurItem(detailId);
    } else {
        eksekusiLanjutItem(detailId);
    }
}

async function eksekusiLanjutItem(detailId) {
    if (!detailId) return;

    const fd = new FormData();
    fd.append('detail_id', detailId);
    fd.append('aksi', 'lanjut_tahap_item');
    fd.append('csrf_token', CSRF_TOKEN);

    try {
        const res  = await fetch('update_status_cucian.php', { method: 'POST', body: fd, credentials: 'same-origin' });
        const data = await res.json();

        if (!data.success) {
            showToast('fa-circle-exclamation', data.message || 'Gagal memproses, silakan coba lagi.', 'err');
            return;
        }

        if (data.selesai_semua) {
            if (data.butuh_notif_siap && data.transaksi_id) {
                antrekanNotifWa('siap', data.transaksi_id);
            }
            showToast('fa-circle-check', 'Semua item selesai — nota otomatis ditandai Siap Diambil. Notifikasi WA akan menyusul terkirim (antrean bertahap).', 'ok');
            // Nota ini keluar dari daftar "Sedang Diproses", jadi cukup pulihkan
            // posisi scroll saja (modal tidak dibuka lagi karena notanya sudah pindah halaman).
            sessionStorage.setItem(PROSES_STATE_KEY, JSON.stringify({ scrollY: window.scrollY }));
        } else {
            showToast('fa-circle-check', 'Item dilanjutkan ke ' + data.tahap_label + '.', 'ok');
            // Nota masih ada di daftar ini — simpan supaya modal detailnya otomatis
            // terbuka lagi menampilkan tahap terbaru, tanpa terasa seperti reset halaman.
            sessionStorage.setItem(PROSES_STATE_KEY, JSON.stringify({ scrollY: window.scrollY, transaksiId: _transaksiIdAktif }));
        }
        setTimeout(() => location.reload(), 800);
    } catch (e) {
        showToast('fa-circle-exclamation', 'Terjadi kesalahan koneksi.', 'err');
    }
}

// ── EKSEKUSI MUNDUR TAHAP ──
async function eksekusiMundurItem(detailId) {
    if (!detailId) return;

    const fd = new FormData();
    fd.append('detail_id', detailId);
    fd.append('aksi', 'mundur_tahap_item');
    fd.append('csrf_token', CSRF_TOKEN);

    try {
        const res  = await fetch('update_status_cucian.php', { method: 'POST', body: fd, credentials: 'same-origin' });
        const data = await res.json();

        if (!data.success) {
            showToast('fa-circle-exclamation', data.message || 'Gagal memproses, silakan coba lagi.', 'err');
            return;
        }

        showToast('fa-circle-check', 'Item dikembalikan ke tahap ' + data.tahap_label + '.', 'ok');
        // Nota masih ada di daftar ini — simpan supaya modal detailnya otomatis
        // terbuka lagi menampilkan tahap terbaru, tanpa terasa seperti reset halaman.
        sessionStorage.setItem(PROSES_STATE_KEY, JSON.stringify({ scrollY: window.scrollY, transaksiId: _transaksiIdAktif }));
        setTimeout(() => location.reload(), 800);
    } catch (e) {
        showToast('fa-circle-exclamation', 'Terjadi kesalahan koneksi.', 'err');
    }
}

function showToast(iconClass, msg, type) {
    const wrap = document.getElementById('toastWrap');
    const el   = document.createElement('div');
    el.className = 'toast ' + type;
    el.innerHTML = msg;
    wrap.appendChild(el);
    setTimeout(() => el.remove(), 3000);
}
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

<?php $csrfTokenJs = csrf_token(); $antrianNotifWaAwal = []; require __DIR__ . '/includes/notif_wa_widget.php'; ?>
</body>
</html>