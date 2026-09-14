<?php
require_once __DIR__ . '/includes/config.php';
requireRole('kasir', 'owner');

// ── AJAX: Konfirmasi Diambil ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['transaksi_id'])) {
    header('Content-Type: application/json; charset=utf-8');
    if (!verify_csrf()) {
        echo json_encode(['success' => false, 'message' => 'Sesi kedaluwarsa, silakan muat ulang halaman.']);
        exit;
    }
    $id          = (int)$_POST['transaksi_id'];
    $tandaiLunas = ($_POST['tandai_lunas'] ?? '0') === '1';
    $metodeBayar = in_array($_POST['metode_bayar'] ?? '', ['tunai', 'transfer'], true)
        ? $_POST['metode_bayar'] : 'tunai';
    $jumlahBayarInput = isset($_POST['jumlah_bayar']) && $_POST['jumlah_bayar'] !== ''
        ? (float)$_POST['jumlah_bayar'] : null;
    $namaPengirim = trim($_POST['nama_pengirim'] ?? '');
    $noPengirim   = trim($_POST['no_pengirim'] ?? '');
    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Parameter tidak valid.']);
        exit;
    }
    try {
        $pdo->beginTransaction();
        if ($tandaiLunas) {
            // Hanya tagih SISA yang belum dibayar (total_harga - total_dibayar).
            // Kalau pelanggan sudah DP sebagian, jangan tagih ulang jumlah penuh —
            // supaya total_dibayar tidak melebihi total_harga.
            $stmtTrx = $pdo->prepare("SELECT total_harga, total_dibayar FROM transaksi WHERE id_transaksi = ?");
            $stmtTrx->execute([$id]);
            $trxRow = $stmtTrx->fetch();
            if (!$trxRow) {
                throw new Exception('Transaksi tidak ditemukan.');
            }
            $sisaTagihan = sisaBayar($trxRow['total_harga'], $trxRow['total_dibayar']);

            // Nominal pelunasan diambil dari form kasir (bisa kurang dari sisa penuh);
            // fallback ke sisa penuh kalau form tidak mengirim nilai (kompatibilitas lama).
            $jumlahBayar = $jumlahBayarInput !== null ? $jumlahBayarInput : $sisaTagihan;

            if ($sisaTagihan > 0) {
                if ($jumlahBayar <= 0) {
                    throw new Exception('Jumlah pembayaran harus diisi dan lebih dari 0.');
                }
                catatPembayaran($pdo, $id, $jumlahBayar, $metodeBayar, (int)$_SESSION['user']['id_user'], $namaPengirim, $noPengirim);
            } else {
                // Sisa sudah 0 tapi status belum sudah_bayar (kasus jarang) — sinkronkan saja.
                $pdo->prepare("UPDATE transaksi SET status_bayar='sudah_bayar', updated_at=NOW() WHERE id_transaksi=?")
                    ->execute([$id]);
            }
        }
        $exists = $pdo->prepare("SELECT id_status_cucian FROM status_cucian WHERE transaksi_id = ?");
        $exists->execute([$id]);
        if ($exists->fetch()) {
            $pdo->prepare("UPDATE status_cucian SET status='selesai', updated_at=NOW() WHERE transaksi_id=?")
                ->execute([$id]);
        } else {
            $pdo->prepare("INSERT INTO status_cucian (transaksi_id, status) VALUES (?, 'selesai')")
                ->execute([$id]);
        }
        $stmt = $pdo->prepare("SELECT id_nota, nama_pelanggan FROM transaksi WHERE id_transaksi = ?");
        $stmt->execute([$id]);
        $trx  = $stmt->fetch();
        $pdo->commit();
        echo json_encode(['success' => true, 'nota' => $trx['id_nota'] ?? '-', 'nama' => $trx['nama_pelanggan'] ?? '-']);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

$namaKasir = htmlspecialchars($_SESSION['user']['name']);
$inisial    = strtoupper(substr($_SESSION['user']['name'], 0, 2));

// ── SIDEBAR COUNTERS ──
$dalam_antrian      = $pdo->query("SELECT COUNT(*) FROM status_cucian WHERE status = 'dalam_antrian'")->fetchColumn();
$jml_pesanan_online = $pdo->query("SELECT COUNT(*) FROM pesanan_online WHERE status = 'menunggu'")->fetchColumn();
$sedang_diproses    = $pdo->query("SELECT COUNT(*) FROM status_cucian WHERE status = 'sedang_diproses'")->fetchColumn();
$siap_diambil_count = $pdo->query("SELECT COUNT(*) FROM status_cucian WHERE status = 'siap_diambil'")->fetchColumn();

// ── DATA SIAP DIAMBIL ──
$list = $pdo->query("
    SELECT t.id_transaksi, t.id_nota, t.nama_pelanggan, t.no_hp_pelanggan,
           t.total_harga, t.status_bayar, t.total_dibayar, t.tanggal_estimasi, t.pernah_terlambat, t.created_at,
           up.name AS nama_petugas, s.updated_at AS status_updated_at,
           (SELECT p.metode FROM pembayaran p
            WHERE p.transaksi_id = t.id_transaksi
            ORDER BY p.created_at DESC, p.id_pembayaran DESC LIMIT 1) AS metode_bayar
    FROM transaksi t
    JOIN status_cucian s ON t.id_transaksi = s.transaksi_id
    LEFT JOIN users up   ON up.id_user = s.updated_by
    WHERE s.status = 'siap_diambil'
    ORDER BY t.tanggal_estimasi ASC, t.created_at ASC
")->fetchAll();

// ── DETAIL ITEM + KATEGORI ──
$ids = array_column($list, 'id_transaksi');
$detailPerTrx   = [];
$kategoriPerTrx = [];
if (!empty($ids)) {
    $in   = implode(',', array_map('intval', $ids));
    $rows = $pdo->query("
        SELECT dt.transaksi_id, COALESCE(il.nama_item, dt.keterangan, 'Item') AS nama_item,
               COALESCE(il.satuan, 'pcs') AS satuan, dt.qty, dt.subtotal,
               kl.nama_kategori
        FROM detail_transaksi dt
        LEFT JOIN detail_barang il      ON il.id_barang = dt.detail_barang_id
        LEFT JOIN kategori_barang kl  ON kl.id_kategori = il.kategori_id
        WHERE dt.transaksi_id IN ($in)
        ORDER BY dt.id_detail_transaksi
    ")->fetchAll();
    foreach ($rows as $r) {
        $detailPerTrx[$r['transaksi_id']][] = $r;
        if (!empty($r['nama_kategori'])) {
            $kategoriPerTrx[$r['transaksi_id']][$r['nama_kategori']] = true;
        }
    }
}

function waUrlSiap($t, $items) {
    $noHp   = preg_replace('/^0/', '62', preg_replace('/\D/', '', $t['no_hp_pelanggan']));
    $daftar = '';
    foreach ($items as $item) {
        $qty     = rtrim(rtrim($item['qty'], '0'), '.');
        $daftar .= "  - " . $item['nama_item'] . " (" . $qty . " " . $item['satuan'] . ") = Rp " . number_format($item['subtotal'], 0, ',', '.') . "\n";
    }
    $pesan =
        "Kepada Yth. " . $t['nama_pelanggan'] . ",\n\n" .
        "Kami informasikan bahwa laundry Anda telah selesai diproses dan siap untuk diambil.\n\n" .
        "- No. Nota     : `" . $t['id_nota'] . "`\n\n" .
        "Rincian Barang:\n" . $daftar .
        "\nTotal Biaya   : Rp " . number_format($t['total_harga'], 0, ',', '.') . "\n\n" .
        "Silakan datang ke Azka Laundry.\nJam: 08.00–20.00 WIB.\n\nHormat kami,\nAzka Laundry";
    return 'https://wa.me/' . $noHp . '?text=' . rawurlencode($pesan);
}

// ── JSON UNTUK MODAL DETAIL ──
$today = strtotime('today');
$rincianJson = [];
foreach ($list as $t) {
    $items     = $detailPerTrx[$t['id_transaksi']] ?? [];
    $terlambat = !empty($t['pernah_terlambat']) || (!empty($t['tanggal_estimasi']) && strtotime($t['tanggal_estimasi']) < $today);
    $rincianJson[$t['id_transaksi']] = [
        'nota'      => $t['id_nota'],
        'nama'      => $t['nama_pelanggan'],
        'no_hp'     => $t['no_hp_pelanggan'],
        'estimasi'  => !empty($t['tanggal_estimasi']) ? date('d/m/Y', strtotime($t['tanggal_estimasi'])) : '-',
        'masuk'     => date('d/m/Y, H:i', strtotime($t['created_at'])),
        'terlambat' => $terlambat,
        'bayar'     => $t['status_bayar'],
        'metode'    => $t['metode_bayar'] ?: null,
        'total'     => (float) $t['total_harga'],
        'dibayar'   => (float) $t['total_dibayar'],
        'sisa'      => sisaBayar($t['total_harga'], $t['total_dibayar']),
        'petugas'    => $t['nama_petugas'] ?: null,
        'diubah_pada' => !empty($t['status_updated_at']) ? date('d/m/Y H:i', strtotime($t['status_updated_at'])) : null,
        'items'     => array_map(fn($i) => [
            'nama'     => $i['nama_item'],
            'kategori' => $i['nama_kategori'] ?: '-',
            'qty'      => rtrim(rtrim($i['qty'], '0'), '.'),
            'satuan'   => $i['satuan'],
            'subtotal' => (float) $i['subtotal'],
        ], $items),
    ];
}
$rincian_json_out = json_encode($rincianJson, JSON_HEX_TAG | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
$csrf_token_out    = json_encode(csrf_token());
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>Siap Diambil — Azka Laundry</title>
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
.sidebar-badge { background:var(--white); color:var(--blue-deep); font-size:10px; font-weight:700; padding:2px 7px; border-radius:20px; margin-left:auto; }
.sidebar-badge.green { background:var(--ink); color:#FFFFFF; }
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

/* STAT CHIP */
.stat-chip { background:var(--white); border-radius:12px; padding:16px 20px; border:1px solid var(--line); border-left:4px solid var(--blue-deep); display:flex; align-items:center; gap:14px; margin-bottom:18px; }
.stat-chip .stat-icon-wrap { width:44px; height:44px; border-radius:10px; background:var(--blue-pale); color:var(--blue-deep); display:flex; align-items:center; justify-content:center; font-size:17px; flex-shrink:0; }
.stat-chip .num { font-size:24px; font-weight:800; color:#101418; line-height:1; transition:color 0.2s, transform 0.2s; }
.stat-chip .lbl { font-size:11px; color:#3A4450; margin-top:3px; font-weight:600; letter-spacing:0.3px; text-transform:uppercase; }

/* PANEL / TABLE */
.panel { background:var(--white); border-radius:14px; border:1px solid var(--line); overflow:hidden; }
.panel-header { padding:16px 20px; border-bottom:1px solid var(--line); display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; }
.panel-header h5 { font-size:15px; font-weight:700; color:#101418; margin:0; display:flex; align-items:center; gap:8px; }
.search-wrap { position:relative; width:230px; max-width:100%; }
.search-wrap input { width:100%; padding:9px 14px 9px 34px; border:1px solid var(--line); border-radius:9px; font-size:13px; font-family:inherit; outline:none; background:#EAF2FB; }
.search-wrap input:focus { border-color:var(--blue); background:var(--white); }
.search-wrap i { position:absolute; left:12px; top:50%; transform:translateY(-50%); color:#4B5563; font-size:12px; }

.trx-table-wrap { overflow-x:auto; }
.trx-table { width:100%; border-collapse:collapse; font-size:13px; }
.trx-table thead th { background:#EAF2FB; color:#3A4450; font-size:11px; font-weight:700; letter-spacing:0.4px; padding:10px 16px; border-top:1px solid var(--line); border-bottom:1px solid var(--line); white-space:nowrap; text-align:left; }
.trx-table tbody td { padding:12px 16px; border-bottom:1px solid var(--line); vertical-align:middle; }
.trx-table tbody tr:hover td { background:#EAF2FB; }
.trx-table tbody tr:last-child td { border-bottom:none; }
.trx-table tbody tr.removing { opacity:0; transition:opacity 0.3s; }

.nota-code { font-family:monospace; font-weight:700; color:var(--blue-deep); font-size:13px; }
.late-tag { display:inline-block; background:var(--ink); color:#FFFFFF; font-size:9px; font-weight:700; padding:2px 7px; border-radius:5px; margin-left:6px; letter-spacing:0.3px; }
.est-late { color:#101418; font-weight:700; font-size:12px; }
.est-ok   { color:#3A4450; font-size:12px; }

/* KATEGORI CHIPS */
.kat-chip { display:inline-block; background:var(--blue-pale); color:var(--blue-deep); border:1px solid var(--line); font-size:11px; font-weight:600; padding:2px 9px; border-radius:20px; margin:1px 3px 1px 0; }
.kat-more { color:#4B5563; font-size:11px; }

/* BADGE — teks polos hitam, tanpa latar belakang/pil warna */
.badge-s { padding:0; border-radius:0; font-size:12px; font-weight:700; display:inline-flex; align-items:center; gap:5px; white-space:nowrap; background:none; color:#101418; border:none; }
.bs-lunas { background:none; color:#101418; }
.bs-dp    { background:none; color:#101418; }
.bs-belum { background:none; color:#101418; border:none; }
.bs-siap  { background:none; color:#101418; }

/* AKSI BUTTONS — seragam: Detail/WA = outline biru netral; Konfirm = solid biru (aksi utama) */
.btn-acts { display:flex; gap:6px; flex-wrap:wrap; }
.btn-act { padding:7px 12px; border-radius:8px; font-size:11.5px; font-weight:700; border:1.5px solid #1B2126; cursor:pointer; text-decoration:none; display:inline-flex; align-items:center; gap:6px; font-family:inherit; white-space:nowrap; background:#FFFFFF; color:#1B2126; }
.btn-act:hover { background:#1B2126; color:#FFFFFF; }
.btn-detail, .btn-wa { background:#FFFFFF; color:#1B2126; border-color:#1B2126; }
.btn-detail:hover, .btn-wa:hover { background:#1B2126; color:#FFFFFF; }
.btn-konfirm { background:var(--blue); color:#FFFFFF; border-color:var(--blue); }
.btn-konfirm:hover { background:var(--blue-deep); }

/* Baris aksi khusus HP: tombol berjejer horizontal di bawah data, bukan menumpuk di kolom sempit */
.trx-actions-row { display:none; }

/* EMPTY */
.empty-state { text-align:center; padding:48px 20px; color:#4B5563; }
.empty-state .ei { width:50px; height:50px; border-radius:50%; background:var(--blue-pale); color:var(--blue-deep); display:flex; align-items:center; justify-content:center; font-size:19px; margin:0 auto 12px; }
.empty-state p { font-size:13px; color:#3A4450; margin:0; }

/* MODAL DETAIL */
.modal-overlay { display:none; position:fixed; inset:0; background:rgba(22,74,133,0.45); z-index:999; align-items:center; justify-content:center; padding:16px; }
.modal-overlay.active { display:flex; }
.modal-box { background:var(--white); border-radius:14px; width:440px; max-width:92vw; max-height:85vh; overflow-y:auto; text-align:left; }
.modal-head { background:var(--blue-deep); padding:16px 20px; color:#FFFFFF; font-size:15px; font-weight:700; display:flex; align-items:center; gap:8px; }
.modal-head-close { margin-left:auto; background:none; border:none; color:#FFFFFF; font-size:15px; cursor:pointer; }
.modal-body { padding:20px; }
.modal-sub-title { font-size:11px; font-weight:700; color:#3A4450; text-transform:uppercase; letter-spacing:0.5px; margin:14px 0 8px; }
.modal-sub-title:first-child { margin-top:0; }
.item-row { display:flex; align-items:center; gap:8px; background:#EAF2FB; border:1px solid var(--line); border-radius:9px; padding:9px 12px; margin-bottom:7px; font-size:13px; }
.item-row .item-name { flex:1; font-weight:600; color:#101418; }
.item-row .item-name small { display:block; font-weight:400; color:#3A4450; }
.item-row .item-price { color:var(--blue-deep); font-weight:700; min-width:88px; text-align:right; }
.total-box { background:var(--blue-deep); border-radius:10px; padding:14px 16px; display:flex; justify-content:space-between; align-items:center; margin-top:12px; }
.total-box .lbl { color:rgba(255,255,255,0.8); font-size:13px; }
.total-box .val { color:#FFFFFF; font-size:19px; font-weight:700; }
.modal-detail-actions { display:flex; gap:8px; margin-top:16px; }
.modal-detail-actions .btn-act { flex:1; justify-content:center; padding:10px; }

/* MODAL KONFIRMASI */
.modal-confirm-box { background:var(--white); border-radius:14px; padding:26px; max-width:400px; width:90%; text-align:center; }

/* MODAL KECIL "Data Pengirim Transfer" -- muncul di ATAS modal konfirmasi
   utama (z-index lebih tinggi), supaya nama/no. pengirim tidak numpuk
   jadi satu form panjang bareng ringkasan pembayaran. */
.modal-overlay-small { display:none; position:fixed; inset:0; background:rgba(16,20,24,0.4); z-index:1100; align-items:center; justify-content:center; padding:16px; }
.modal-overlay-small.active { display:flex; }
.modal-small-box { background:var(--white); border-radius:14px; padding:22px; max-width:320px; width:90%; text-align:left; }
.modal-small-box .modal-small-title { font-size:15px; font-weight:700; color:#101418; margin-bottom:4px; }
.modal-small-box .modal-small-sub { font-size:12px; color:#4B5563; margin-bottom:16px; }
.pengirim-summary { display:none; align-items:center; justify-content:space-between; gap:10px; background:var(--blue-pale); border:1px solid var(--line); border-radius:10px; padding:10px 12px; margin-top:4px; }
.pengirim-summary.show { display:flex; }
.pengirim-summary-text { font-size:12.5px; color:#101418; }
.pengirim-summary-text strong { display:block; font-size:13px; }
.pengirim-summary button { border:none; background:none; color:var(--blue-deep); font-size:12px; font-weight:700; cursor:pointer; padding:4px; }
.btn-isi-pengirim { width:100%; padding:11px; border:1.5px dashed var(--line); background:var(--blue-pale); color:var(--blue-deep); border-radius:10px; font-size:13px; font-weight:700; cursor:pointer; font-family:inherit; }
.btn-isi-pengirim:hover { background:#DCEBFA; }
.modal-icon-wrap { width:52px; height:52px; border-radius:50%; background:var(--blue-pale); color:var(--blue-deep); display:flex; align-items:center; justify-content:center; font-size:20px; margin:0 auto 14px; }
.modal-title { font-size:16px; font-weight:700; color:#101418; margin-bottom:12px; }
.modal-nota { background:#EAF2FB; border:1px solid var(--line); border-radius:10px; padding:10px 16px; font-family:monospace; font-size:15px; font-weight:700; color:var(--blue-deep); margin-bottom:6px; }
.modal-nama { font-size:13px; color:#3A4450; margin-bottom:20px; }
.confirm-btns { display:flex; gap:10px; justify-content:center; }
.btn-ok { padding:11px 26px; background:var(--blue); color:#FFFFFF; border:none; border-radius:9px; font-size:13px; font-weight:700; cursor:pointer; font-family:inherit; }
.btn-ok:hover { background:var(--blue-deep); }
.btn-cancel { padding:10px 22px; background:var(--white); color:#3A4450; border:1px solid var(--line); border-radius:9px; font-size:13px; font-weight:600; cursor:pointer; font-family:inherit; }
.btn-cancel:hover { background:#EAF2FB; }
.metode-bayar-wrap { text-align:left; margin-bottom:20px; }
.metode-bayar-wrap label { display:block; font-size:12px; font-weight:700; color:#3A4450; margin-bottom:6px; }
.metode-bayar-wrap select { width:100%; border:1.5px solid var(--line); border-radius:9px; font-size:13px; padding:10px 14px; font-family:inherit; outline:none; background:#FFFFFF; color:#101418; }
.metode-bayar-wrap select:focus { border-color:var(--blue); box-shadow:0 0 0 3px rgba(46,116,192,0.1); }

/* RINCIAN TAGIHAN & FORM PELUNASAN DI MODAL KONFIRMASI */
.bayar-summary-card { text-align:left; background:var(--blue-pale); border:1px solid var(--line); border-radius:10px; padding:4px 14px; margin-bottom:16px; }
.bayar-summary-row { display:flex; justify-content:space-between; align-items:center; padding:8px 0; font-size:13px; }
.bayar-summary-row:not(:last-child) { border-bottom:1px dashed var(--line); }
.bayar-summary-row span { color:#3A4450; }
.bayar-summary-row strong { color:#101418; font-weight:700; }
.bayar-summary-row.highlight strong { color:var(--blue-deep); font-size:15px; font-weight:800; }

.form-group { text-align:left; margin-bottom:18px; }
.form-label { display:block; font-size:12px; font-weight:700; color:#3A4450; margin-bottom:7px; }
.input-rp { position:relative; }
.input-rp-prefix { position:absolute; left:14px; top:50%; transform:translateY(-50%); font-weight:700; color:var(--blue-deep); font-size:14px; pointer-events:none; }
.form-control { width:100%; border:1.5px solid var(--line); border-radius:9px; padding:11px 14px 11px 38px; font-size:15px; font-weight:700; font-family:inherit; color:#101418; background:#FFFFFF; outline:none; transition:border-color .2s, box-shadow .2s; }
.form-control:focus { border-color:var(--blue); box-shadow:0 0 0 3px rgba(46,116,192,0.1); }
.bank-chip-row { display:flex; flex-wrap:wrap; gap:6px; }
.bank-chip { padding:6px 12px; border-radius:20px; border:1.5px solid var(--line); background:var(--white); color:#3A4450; font-size:11.5px; font-weight:600; cursor:pointer; font-family:inherit; }
.bank-chip:hover { border-color:var(--blue); color:var(--blue-deep); background:var(--blue-pale); }
.bank-chip.active { background:var(--blue); border-color:var(--blue); color:#FFFFFF; }
.item-hint { font-size:11.5px; color:#4B5563; margin-top:7px; line-height:1.4; }

.method-toggle { display:flex; gap:8px; }
.method-btn { flex:1; padding:11px 10px; border-radius:9px; border:1.5px solid var(--line); background:#FFFFFF; color:#3A4450; font-size:13px; font-weight:700; cursor:pointer; font-family:inherit; transition:all .15s; }
.method-btn:hover { border-color:var(--blue); color:var(--blue-deep); }
.method-btn.active { background:var(--blue); border-color:var(--blue); color:#FFFFFF; box-shadow:0 4px 12px rgba(46,116,192,0.3); }

/* TOAST */
.toast-wrap { position:fixed; bottom:24px; right:24px; z-index:9999; display:flex; flex-direction:column; gap:8px; }
.toast { padding:12px 18px; border-radius:10px; font-size:13px; font-weight:600; color:#FFFFFF; display:flex; align-items:center; gap:8px; }
.toast.ok  { background:var(--blue-deep); }
.toast.err { background:var(--ink); }

/* ===== TABEL TETAP TABEL DI HP, DIKECILIN SUPAYA MUAT (GAK SCROLL SAMPING) ===== */
@media (max-width: 700px) {
    .warning-bar, .readonly-info { align-items:flex-start; }
    .trx-table-wrap { overflow-x:hidden; padding:6px; }
    .trx-table { table-layout:fixed; font-size:10.5px; }

    /* Nota, Pelanggan, Kategori, Total, Bayar, Estimasi */
    .trx-table th:nth-child(1), .trx-table td:nth-child(1) { width:22%; }
    .trx-table th:nth-child(2), .trx-table td:nth-child(2) { width:17%; }
    .trx-table th:nth-child(3), .trx-table td:nth-child(3) { width:14%; }
    .trx-table th:nth-child(4), .trx-table td:nth-child(4) { width:13%; }
    .trx-table th:nth-child(5), .trx-table td:nth-child(5) { width:13%; }
    .trx-table th:nth-child(6), .trx-table td:nth-child(6) { width:13%; }
    .trx-table th:nth-child(7) { display:none; }
    .trx-table td[data-label="Aksi"] { display:none; }

    .trx-table thead th { padding:6px 3px; font-size:7.5px; white-space:normal; word-break:break-word; }
    .trx-table tbody td { padding:6px 3px; font-size:9.5px; word-break:break-word; vertical-align:top; }

    .trx-table .nota-code { font-size:9px; white-space:nowrap; letter-spacing:-0.2px; }
    .trx-table .late-tag { font-size:7px; padding:1px 3px; }
    .trx-table td[data-label="Pelanggan"] div:first-child { font-size:9.5px; }
    .trx-table td[data-label="Pelanggan"] div:last-child  { font-size:8px; }
    .kat-chip { font-size:7.5px; padding:1px 4px; margin:0 1px 1px 0; }
    .kat-more { font-size:7.5px; }
    .badge-s  { font-size:8px; padding:2px 5px; white-space:normal; }
    .trx-table td.est-late, .trx-table td.est-ok { font-size:9px; }

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
      <small>Kasir Panel</small>
    </div>
  </div>
  <div class="sidebar-menu">
    <a href="dashboard_kasir.php">Dashboard</a>
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
    <a href="siap_diambil.php" class="active">
      Siap Diambil
      <?php if ($siap_diambil_count > 0): ?>
      <span class="sidebar-badge green"><?= $siap_diambil_count ?></span>
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
        Cucian Siap Diambil
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

    <?php if (empty($list)): ?>
    <div class="empty-state" id="emptyDefault">
      <div class="ei">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
          <path d="M5 13l4 4L19 7" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
      </div>
      <p>Tidak ada cucian yang menunggu diambil saat ini.</p>
    </div>
    <?php else: ?>
    <div class="panel">
      <div class="panel-header">
        <h5>Daftar Siap Diambil <span class="kat-chip" id="jumlahSiap" style="margin:0"><?= count($list) ?> nota</span></h5>
        <div class="search-wrap">
                    <input type="text" id="searchInput" placeholder="Cari nota / nama..." oninput="filterTabel(this.value)">
        </div>
      </div>
      <div class="trx-table-wrap">
        <table class="trx-table" id="tabelSiap">
          <thead>
            <tr>
              <th>NOTA</th>
              <th>PELANGGAN</th>
              <th>KATEGORI</th>
              <th>TOTAL</th>
              <th>BAYAR</th>
              <th>ESTIMASI</th>
              <th>AKSI</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($list as $t):
            $terlambat  = !empty($t['pernah_terlambat']) || (!empty($t['tanggal_estimasi']) && strtotime($t['tanggal_estimasi']) < $today);
            $estFmt     = !empty($t['tanggal_estimasi']) ? date('d/m/Y', strtotime($t['tanggal_estimasi'])) : '-';
            $sudahLunas = $t['status_bayar'] === 'sudah_bayar';
            $sisaBayarTrx = sisaBayar($t['total_harga'], $t['total_dibayar']);
            $waUrl      = waUrlSiap($t, $detailPerTrx[$t['id_transaksi']] ?? []);
            $katList    = array_keys($kategoriPerTrx[$t['id_transaksi']] ?? []);
          ?>
          <tr id="row-<?= $t['id_transaksi'] ?>" data-search="<?= strtolower(htmlspecialchars($t['id_nota'].' '.$t['nama_pelanggan'])) ?>">
            <td data-label="Nota">
              <span class="nota-code"><?= htmlspecialchars($t['id_nota']) ?></span>
              <?php if ($terlambat): ?><span class="late-tag">TERLAMBAT</span><?php endif; ?>
            </td>
            <td data-label="Pelanggan">
              <div style="font-weight:600;color:#101418"><?= htmlspecialchars($t['nama_pelanggan']) ?></div>
              <div style="font-size:11px;color:#4B5563"><?= htmlspecialchars($t['no_hp_pelanggan']) ?></div>
              <?php if (!empty($t['nama_petugas'])): ?>
              <div style="font-size:10.5px;color:#8A94A3">Diproses oleh: <?= htmlspecialchars($t['nama_petugas']) ?></div>
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
            <td data-label="Total" style="font-weight:700;color:var(--blue-deep)">Rp<?= number_format($t['total_harga'],0,',','.') ?></td>
            <td data-label="Bayar">
              <?php if ($sudahLunas): ?>
              <span class="badge-s bs-lunas">Lunas
                <?php if (!empty($t['metode_bayar'])): ?>
                  &middot; <?= $t['metode_bayar'] === 'transfer' ? 'Transfer' : 'Tunai' ?>
                <?php endif; ?>
              </span>
              <?php elseif ($t['status_bayar'] === 'dp'): ?>
              <span class="badge-s bs-dp">DP Rp<?= number_format($t['total_dibayar'],0,',','.') ?>
                <?php if (!empty($t['metode_bayar'])): ?>
                  &middot; <?= $t['metode_bayar'] === 'transfer' ? 'Transfer' : 'Tunai' ?>
                <?php endif; ?>
                &middot; Kurang Rp<?= number_format($sisaBayarTrx,0,',','.') ?>
              </span>
              <?php else: ?>
              <span class="badge-s bs-belum">Belum</span>
              <?php endif; ?>
            </td>
            <td data-label="Estimasi" class="<?= $terlambat ? 'est-late' : 'est-ok' ?>"><?= $estFmt ?></td>
            <td data-label="Aksi">
              <div class="btn-acts">
                <button type="button" class="btn-act btn-detail" onclick="showDetail(<?= $t['id_transaksi'] ?>)" title="Lihat rincian pesanan">
                  Detail
                </button>
                <a href="<?= $waUrl ?>" target="_blank" class="btn-act btn-wa" title="Kirim pengingat WhatsApp">
                  WA
                </a>
                <?php if (!$sudahLunas): ?>
                <button type="button" class="btn-act btn-konfirm"
                        data-id="<?= $t['id_transaksi'] ?>"
                        data-nota="<?= htmlspecialchars($t['id_nota'],ENT_QUOTES) ?>"
                        data-nama="<?= htmlspecialchars($t['nama_pelanggan'],ENT_QUOTES) ?>"
                        data-lunas="0"
                        data-total="<?= $t['total_harga'] ?>"
                        data-dibayar="<?= $t['total_dibayar'] ?>"
                        data-sisa="<?= $sisaBayarTrx ?>"
                        data-metode="<?= htmlspecialchars($t['metode_bayar'] ?? '', ENT_QUOTES) ?>"
                        onclick="bukaModal(this)">
                  Ambil &amp; Lunas
                </button>
                <?php else: ?>
                <button type="button" class="btn-act btn-konfirm"
                        data-id="<?= $t['id_transaksi'] ?>"
                        data-nota="<?= htmlspecialchars($t['id_nota'],ENT_QUOTES) ?>"
                        data-nama="<?= htmlspecialchars($t['nama_pelanggan'],ENT_QUOTES) ?>"
                        data-lunas="1"
                        onclick="bukaModal(this)">
                  Sudah Diambil
                </button>
                <?php endif; ?>
              </div>
            </td>
          </tr>
          <tr class="trx-actions-row">
            <td colspan="7">
              <div class="btn-acts">
                <button type="button" class="btn-act btn-detail" onclick="showDetail(<?= $t['id_transaksi'] ?>)" title="Lihat rincian pesanan">
                  Detail
                </button>
                <a href="<?= $waUrl ?>" target="_blank" class="btn-act btn-wa" title="Kirim pengingat WhatsApp">
                  WA
                </a>
                <?php if (!$sudahLunas): ?>
                <button type="button" class="btn-act btn-konfirm"
                        data-id="<?= $t['id_transaksi'] ?>"
                        data-nota="<?= htmlspecialchars($t['id_nota'],ENT_QUOTES) ?>"
                        data-nama="<?= htmlspecialchars($t['nama_pelanggan'],ENT_QUOTES) ?>"
                        data-lunas="0"
                        data-total="<?= $t['total_harga'] ?>"
                        data-dibayar="<?= $t['total_dibayar'] ?>"
                        data-sisa="<?= $sisaBayarTrx ?>"
                        data-metode="<?= htmlspecialchars($t['metode_bayar'] ?? '', ENT_QUOTES) ?>"
                        onclick="bukaModal(this)">
                  Ambil &amp; Lunas
                </button>
                <?php else: ?>
                <button type="button" class="btn-act btn-konfirm"
                        data-id="<?= $t['id_transaksi'] ?>"
                        data-nota="<?= htmlspecialchars($t['id_nota'],ENT_QUOTES) ?>"
                        data-nama="<?= htmlspecialchars($t['nama_pelanggan'],ENT_QUOTES) ?>"
                        data-lunas="1"
                        onclick="bukaModal(this)">
                  Sudah Diambil
                </button>
                <?php endif; ?>
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

<!-- MODAL KONFIRMASI AMBIL -->
<div class="modal-overlay" id="modalKonfirm">
  <div class="modal-confirm-box">
    <div class="modal-icon-wrap">
      <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
        <path d="M5 13l4 4L19 7" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
      </svg>
    </div>
    <div class="modal-title">Konfirmasi Pengambilan</div>
    <div class="modal-nota" id="mNota"></div>
    <div class="modal-nama" id="mNama"></div>

    <div id="mSisaWrap" style="display:none">
      <div class="bayar-summary-card">
        <div class="bayar-summary-row"><span>Total Tagihan</span><strong id="mTotal">Rp0</strong></div>
        <div class="bayar-summary-row"><span>Sudah Dibayar (DP)</span><strong id="mDibayar">Rp0</strong></div>
        <div class="bayar-summary-row" id="mMetodeDpRow" style="display:none"><span>DP Dibayar Via</span><strong id="mMetodeDp">-</strong></div>
        <div class="bayar-summary-row highlight"><span>Sisa yang Harus Dibayar</span><strong id="mSisa">Rp0</strong></div>
      </div>
      <div class="form-group">
        <label class="form-label">Jumlah Dibayar untuk Pelunasan</label>
        <div class="input-rp">
          <span class="input-rp-prefix">Rp</span>
          <input type="number" id="inputJumlahPelunasan" class="form-control" min="1" step="1" placeholder="0">
        </div>
        <div class="item-hint" id="pelunasanHint"></div>
      </div>
    </div>

    <div class="metode-bayar-wrap" id="metodeBayarWrap">
      <label>Metode Pembayaran</label>
      <input type="hidden" id="inputMetodeBayarAmbil" value="tunai">
      <div class="method-toggle">
        <button type="button" class="method-btn active" data-metode="tunai" onclick="pilihMetodeAmbil(this)">Tunai (Cash)</button>
        <button type="button" class="method-btn" data-metode="transfer" onclick="pilihMetodeAmbil(this)">Transfer</button>
      </div>
    </div>

    <div class="form-group" id="wrapTransferFieldsAmbil" style="display:none">
      <div class="pengirim-summary" id="pengirimSummary">
        <div class="pengirim-summary-text">
          Data Pengirim<br>
          <strong id="pengirimSummaryText">-</strong>
        </div>
        <button type="button" onclick="bukaModalPengirim()">Ubah</button>
      </div>
      <button type="button" class="btn-isi-pengirim" id="btnIsiPengirim" onclick="bukaModalPengirim()" style="display:none">
        + Isi Nama Pengirim
      </button>
    </div>

    <div class="confirm-btns">
      <button class="btn-cancel" onclick="tutupModal('modalKonfirm')">Batal</button>
      <button class="btn-ok" id="btnOk">
        Ya, Sudah Diambil
      </button>
    </div>
  </div>
</div>

<!-- MODAL KECIL: DATA PENGIRIM TRANSFER (dipisah dari modal konfirmasi
     utama supaya tidak jadi satu form panjang -- muncul di atasnya) -->
<div class="modal-overlay-small" id="modalPengirim">
  <div class="modal-small-box">
    <div class="modal-small-title">Data Pengirim Transfer</div>
    <div class="modal-small-sub">Supaya gampang ditelusuri kalau ada selisih/komplain transfer.</div>
    <div class="form-group">
      <label class="form-label">Nama Pengirim</label>
      <input type="text" id="inputNamaPengirimAmbil" class="form-control" placeholder="Nama sesuai rekening/e-wallet pengirim">
      <label class="form-label" style="margin-top:8px">Bank / E-wallet & No. Rekening</label>
      <div class="bank-chip-row">
        <button type="button" class="bank-chip" onclick="pilihBankCepat('inputNoPengirimAmbil','BCA')">BCA</button>
        <button type="button" class="bank-chip" onclick="pilihBankCepat('inputNoPengirimAmbil','Mandiri')">Mandiri</button>
        <button type="button" class="bank-chip" onclick="pilihBankCepat('inputNoPengirimAmbil','BRI')">BRI</button>
        <button type="button" class="bank-chip" onclick="pilihBankCepat('inputNoPengirimAmbil','BNI')">BNI</button>
        <button type="button" class="bank-chip" onclick="pilihBankCepat('inputNoPengirimAmbil','ShopeePay')">ShopeePay</button>
        <button type="button" class="bank-chip" onclick="pilihBankCepat('inputNoPengirimAmbil','DANA')">DANA</button>
        <button type="button" class="bank-chip" onclick="pilihBankCepat('inputNoPengirimAmbil','OVO')">OVO</button>
        <button type="button" class="bank-chip" onclick="pilihBankCepat('inputNoPengirimAmbil','GoPay')">GoPay</button>
      </div>
      <input type="text" id="inputNoPengirimAmbil" class="form-control" style="margin-top:8px" placeholder="Contoh: BCA 1234567890, atau ShopeePay saja">
    </div>
    <div class="confirm-btns">
      <button class="btn-cancel" onclick="batalModalPengirim()">Batal</button>
      <button class="btn-ok" onclick="simpanModalPengirim()">Simpan</button>
    </div>
  </div>
</div>

<div class="toast-wrap" id="toastWrap"></div>

<script>
const rincianData = <?= $rincian_json_out ?>;
const CSRF_TOKEN   = <?= $csrf_token_out ?>;
let _id, _sudahLunas;

function formatRp(n) { return 'Rp' + Math.round(n).toLocaleString('id-ID'); }

function tutupModal(id) { document.getElementById(id)?.classList.remove('active'); }
document.querySelectorAll('.modal-overlay').forEach(el =>
    el.addEventListener('click', e => { if (e.target === el) el.classList.remove('active'); })
);

// ── MODAL DETAIL ──
function showDetail(id) {
    const d = rincianData[id];
    if (!d) return;

    let itemsHtml = '';
    if (d.items.length === 0) {
        itemsHtml = '<div style="color:#4B5563;font-size:13px">Tidak ada rincian item.</div>';
    } else {
        d.items.forEach(item => {
            itemsHtml +=
                '<div class="item-row">' +
                  '<div class="item-name">' + item.nama + '<small>' + item.kategori + ' &middot; ' + item.qty + ' ' + item.satuan + '</small></div>' +
                  '<div class="item-price">' + formatRp(item.subtotal) + '</div>' +
                '</div>';
        });
    }

    const metodeTxt = d.metode === 'transfer' ? 'Transfer' : (d.metode === 'tunai' ? 'Tunai' : '');
    let bayarBadge;
    if (d.bayar === 'sudah_bayar') {
        bayarBadge = '<span class="badge-s bs-lunas">Lunas' + (metodeTxt ? ' &middot; ' + metodeTxt : '') + '</span>';
    } else if (d.bayar === 'dp') {
        bayarBadge = '<span class="badge-s bs-dp">DP ' + formatRp(d.dibayar) + (metodeTxt ? ' &middot; ' + metodeTxt : '') + ' &middot; Kurang ' + formatRp(d.sisa) + '</span>';
    } else {
        bayarBadge = '<span class="badge-s bs-belum">Belum Lunas</span>';
    }

    // Info petugas bagian pencucian yang terakhir menandai nota ini "Siap
    // Diambil" -- bukan kasir yang buat transaksi awal.
    const petugasHtml = d.petugas
        ? '<div style="font-size:12px;color:#4B5563;margin-top:4px">Terakhir diubah oleh: <strong style="color:#101418">' + d.petugas + '</strong>' + (d.diubah_pada ? ' &middot; ' + d.diubah_pada : '') + '</div>'
        : '';

    document.getElementById('detailBody').innerHTML =
        '<div style="margin-bottom:6px">' +
          '<div class="nota-code" style="font-size:15px">' + d.nota + (d.terlambat ? '<span class="late-tag">TERLAMBAT</span>' : '') + '</div>' +
          '<div style="font-size:13px;color:#3A4450;margin-top:2px">' + d.nama + ' &middot; ' + d.no_hp + '</div>' +
          '<div style="font-size:12px;color:#4B5563;margin-top:4px">Masuk: ' + d.masuk + ' &middot; Estimasi: ' + d.estimasi + '</div>' +
          petugasHtml +
          '<div style="margin-top:8px">' + bayarBadge + '</div>' +
        '</div>' +
        '<div class="modal-sub-title">Rincian Item</div>' +
        itemsHtml +
        '<div class="total-box"><div class="lbl">Total</div><div class="val">' + formatRp(d.total) + '</div></div>';

    document.getElementById('modalDetail').classList.add('active');
}

// ── MODAL KONFIRMASI AMBIL ──
function bukaModal(btn) {
    _id         = btn.dataset.id;
    _sudahLunas = btn.dataset.lunas === '1';
    const total   = parseFloat(btn.dataset.total) || 0;
    const dibayar = parseFloat(btn.dataset.dibayar) || 0;
    const sisa    = parseFloat(btn.dataset.sisa) || 0;
    const metodeDp = btn.dataset.metode || '';
    document.getElementById('mNota').textContent = btn.dataset.nota;
    document.getElementById('mNama').textContent = btn.dataset.nama;
    // Tampilkan rincian tagihan + form nominal pelunasan & pilihan metode pembayaran hanya jika belum lunas
    const metodeWrap = document.getElementById('metodeBayarWrap');
    const sisaWrap   = document.getElementById('mSisaWrap');
    metodeWrap.style.display = _sudahLunas ? 'none' : 'block';
    sisaWrap.style.display   = (!_sudahLunas && sisa > 0) ? 'block' : 'none';
    if (!_sudahLunas && sisa > 0) {
        document.getElementById('mTotal').textContent   = formatRp(total);
        document.getElementById('mDibayar').textContent = formatRp(dibayar);
        document.getElementById('mSisa').textContent    = formatRp(sisa);
        // Info metode DP awal (transfer/tunai), supaya kasir tahu uang muka
        // pelanggan dulu dibayar lewat apa sebelum menerima pelunasan.
        const mMetodeDpRow = document.getElementById('mMetodeDpRow');
        if (metodeDp === 'transfer' || metodeDp === 'tunai') {
            document.getElementById('mMetodeDp').textContent = metodeDp === 'transfer' ? 'Transfer' : 'Tunai';
            mMetodeDpRow.style.display = 'flex';
        } else {
            mMetodeDpRow.style.display = 'none';
        }
        const inputPelunasan = document.getElementById('inputJumlahPelunasan');
        inputPelunasan.value = sisa;
        inputPelunasan.max   = sisa;
        document.getElementById('pelunasanHint').textContent =
            'Maksimal ' + formatRp(sisa) + '. Sudah terisi otomatis sesuai sisa tagihan, bisa diubah jika perlu.';
    }
    document.getElementById('inputMetodeBayarAmbil').value = 'tunai';
    document.querySelectorAll('#metodeBayarWrap .method-btn').forEach(b => b.classList.toggle('active', b.dataset.metode === 'tunai'));
    document.getElementById('inputNamaPengirimAmbil').value = '';
    document.getElementById('inputNoPengirimAmbil').value = '';
    document.getElementById('wrapTransferFieldsAmbil').style.display = 'none';
    document.getElementById('pengirimSummary').classList.remove('show');
    document.getElementById('btnIsiPengirim').style.display = 'block';
    document.getElementById('modalPengirim').classList.remove('active');
    document.getElementById('btnOk').onclick = submitKonfirm;
    document.getElementById('modalKonfirm').classList.add('active');
}

// Field nama/no pengirim kali ini dipisah jadi MODAL KECIL sendiri (bukan
// inline di modal konfirmasi utama) -- supaya modal utama tetap ringkas,
// dan kasir fokus isi 1 hal dulu (data pengirim) baru lanjut konfirmasi.
function pilihMetodeAmbil(btn) {
    document.querySelectorAll('#metodeBayarWrap .method-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById('inputMetodeBayarAmbil').value = btn.dataset.metode;
    const tampil = btn.dataset.metode === 'transfer';
    document.getElementById('wrapTransferFieldsAmbil').style.display = tampil ? 'block' : 'none';
    if (tampil) {
        // Baru pilih Transfer -> langsung minta isi data pengirim, kecuali
        // sudah pernah diisi sebelumnya (mis. sempat ganti ke Tunai lalu
        // balik lagi ke Transfer) -- saat itu cukup tampilkan ringkasannya.
        if (document.getElementById('inputNamaPengirimAmbil').value.trim() === '') {
            bukaModalPengirim();
        } else {
            perbaruiRingkasanPengirim();
        }
    } else {
        document.getElementById('inputNamaPengirimAmbil').value = '';
        document.getElementById('inputNoPengirimAmbil').value = '';
        document.getElementById('pengirimSummary').classList.remove('show');
        document.getElementById('btnIsiPengirim').style.display = 'block';
    }
}

function bukaModalPengirim() {
    document.getElementById('modalPengirim').classList.add('active');
    setTimeout(() => document.getElementById('inputNamaPengirimAmbil').focus(), 50);
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

function batalModalPengirim() {
    document.getElementById('modalPengirim').classList.remove('active');
    // Belum pernah isi nama sama sekali & batal -> balikin metode ke Tunai
    // otomatis, supaya tidak nyangkut di Transfer tanpa data pengirim.
    if (document.getElementById('inputNamaPengirimAmbil').value.trim() === '') {
        const btnTunai = document.querySelector('#metodeBayarWrap .method-btn[data-metode="tunai"]');
        if (btnTunai) pilihMetodeAmbil(btnTunai);
    }
}

function simpanModalPengirim() {
    const nama = document.getElementById('inputNamaPengirimAmbil').value.trim();
    if (!nama) {
        showToast('fa-circle-exclamation', 'Nama Pengirim wajib diisi.', 'err');
        return;
    }
    perbaruiRingkasanPengirim();
    document.getElementById('modalPengirim').classList.remove('active');
}

function perbaruiRingkasanPengirim() {
    const nama = document.getElementById('inputNamaPengirimAmbil').value.trim();
    const no   = document.getElementById('inputNoPengirimAmbil').value.trim();
    document.getElementById('pengirimSummaryText').textContent = nama + (no ? ' · ' + no : '');
    document.getElementById('pengirimSummary').classList.add('show');
    document.getElementById('btnIsiPengirim').style.display = 'none';
}

async function submitKonfirm() {
    // Jika belum lunas, otomatis tandai lunas saat konfirmasi diambil,
    // sertakan metode pembayaran & nominal pelunasan yang diisi kasir
    const tandaiLunas  = _sudahLunas ? '0' : '1';
    const metodeBayar  = _sudahLunas ? '' : document.getElementById('inputMetodeBayarAmbil').value;
    const namaPengirim = _sudahLunas ? '' : document.getElementById('inputNamaPengirimAmbil').value.trim();
    const noPengirim   = _sudahLunas ? '' : document.getElementById('inputNoPengirimAmbil').value.trim();
    if (!_sudahLunas && metodeBayar === 'transfer' && !namaPengirim) {
        showToast('fa-circle-exclamation', 'Nama Pengirim wajib diisi untuk pembayaran Transfer.', 'err');
        return;
    }
    let jumlahBayar = '';
    if (!_sudahLunas) {
        const sisaWrap = document.getElementById('mSisaWrap');
        if (sisaWrap.style.display !== 'none') {
            jumlahBayar = parseFloat(document.getElementById('inputJumlahPelunasan').value) || 0;
            if (jumlahBayar <= 0) {
                showToast('fa-circle-exclamation', 'Jumlah pelunasan harus diisi dan lebih dari 0.', 'err');
                return;
            }
        }
    }
    tutupModal('modalKonfirm');
    const fd = new FormData();
    fd.append('transaksi_id', _id);
    fd.append('status', 'selesai');
    fd.append('tandai_lunas', tandaiLunas);
    fd.append('metode_bayar', metodeBayar);
    fd.append('nama_pengirim', namaPengirim);
    fd.append('no_pengirim', noPengirim);
    fd.append('jumlah_bayar', jumlahBayar);
    fd.append('csrf_token', CSRF_TOKEN);
    try {
        const res  = await fetch('siap_diambil.php', { method:'POST', body:fd, credentials:'same-origin' });
        const data = await res.json();
        if (data.success) {
            const row = document.getElementById('row-' + _id);
            if (row) {
                row.classList.add('removing');
                const rowAksi = row.nextElementSibling;
                if (rowAksi && rowAksi.classList.contains('trx-actions-row')) {
                    rowAksi.classList.add('removing');
                }
                setTimeout(() => {
                    row.remove();
                    if (rowAksi && rowAksi.classList.contains('trx-actions-row')) {
                        rowAksi.remove();
                    }
                    updateJumlah(-1);
                    cekEmpty();
                }, 300);
            }
            showToast('fa-check-circle', data.nota + ' — Pesanan selesai diambil. Struk WA akan menyusul terkirim.', 'ok');
            // Antre notifikasi WA "pesanan selesai" (struk) — dikirim bertahap
            // (jeda 20-30 detik) lewat includes/notif_wa_widget.php, sama
            // seperti notifikasi WA lainnya.
            if (typeof antrekanNotifWa === 'function') {
                antrekanNotifWa('selesai', _id);
            }
        } else {
            showToast('fa-circle-exclamation', data.message || 'Gagal memproses konfirmasi.', 'err');
        }
    } catch(e) {
        showToast('fa-circle-exclamation', 'Gagal terhubung ke server.', 'err');
    }
}

function updateJumlah(delta) {
    const el = document.getElementById('jumlahSiap');
    if (!el) return;
    const n = Math.max(0, (parseInt(el.textContent)||0) + delta);
    el.textContent = n + ' nota';
}

function cekEmpty() {
    const rows = document.querySelectorAll('#tabelSiap tbody tr[id^="row-"]');
    if (rows.length === 0) {
        const panel = document.querySelector('.panel');
        if (panel && !document.getElementById('emptyDynamic')) {
            const el = document.createElement('div');
            el.id        = 'emptyDynamic';
            el.className = 'empty-state';
            el.innerHTML = '<div class="ei"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M5 13l4 4L19 7" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/></svg></div><p>Semua cucian sudah diambil.</p>';
            panel.replaceWith(el);
        }
    }
}

function filterTabel(kw) {
    const keyword = kw.toLowerCase().trim();
    const rows    = document.querySelectorAll('#tabelSiap tbody tr[data-search]');
    let visible   = 0;
    rows.forEach(row => {
        const match = row.dataset.search.includes(keyword);
        row.style.display = match ? '' : 'none';
        const rowAksi = row.nextElementSibling;
        if (rowAksi && rowAksi.classList.contains('trx-actions-row')) {
            rowAksi.style.display = match ? '' : 'none';
        }
        if (match) visible++;
    });
    let emptyRow = document.getElementById('searchEmptyRow');
    const tbody  = document.querySelector('#tabelSiap tbody');
    if (visible === 0 && keyword !== '' && tbody) {
        if (!emptyRow) {
            emptyRow = document.createElement('tr');
            emptyRow.id = 'searchEmptyRow';
            emptyRow.innerHTML = '<td colspan="7" style="text-align:center;padding:28px;color:#4B5563">Tidak ditemukan hasil pencarian.</td>';
            tbody.appendChild(emptyRow);
        }
    } else {
        emptyRow?.remove();
    }
}

function showToast(iconClass, msg, type) {
    const wrap = document.getElementById('toastWrap');
    const el   = document.createElement('div');
    el.className = 'toast ' + type;
    el.innerHTML  = msg;
    wrap.appendChild(el);
    setTimeout(() => el.remove(), 3500);
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

<?php
$csrfTokenJs        = csrf_token();
$antrianNotifWaAwal = [];
require __DIR__ . '/includes/notif_wa_widget.php';
?>
</body>
</html>