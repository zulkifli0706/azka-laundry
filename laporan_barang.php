<?php
/**
 * LAPORAN REKAP PEMAKAIAN BARANG
 * ------------------------------
 * Menjawab pertanyaan: "item apa saja yang dipakai pelanggan, dan berapa
 * kali item tersebut dipakai?" — direkap per item, lengkap dengan qty,
 * omzet yang dihasilkan tiap item, serta insight otomatis (item terlaris,
 * kontribusi ke omzet, item yang tidak laku, dll).
 *
 * Catatan data: sebagian transaksi lama (sebelum modul Kelola Barang dipakai
 * konsisten) menyimpan detail item hanya lewat kolom `keterangan` dengan
 * detail_barang_id = NULL (ditandai prefix "[Arsip]"). Baris seperti ini
 * tetap dihitung, memakai nama dari `keterangan`, supaya rekap tidak bolong.
 */
require_once __DIR__ . '/includes/config.php';
requireRole('owner');

function rupiahLB($n): string {
    return 'Rp ' . number_format((float)$n, 0, ',', '.');
}

// ── FILTER PERIODE (disamakan persis dengan dashboard_owner.php: tab
// [Hari Ini] statis, dropdown [Hari ▾]/[Bulan ▾]/[Tahun ▾], dan tombol
// kalender [Tanggal] buat rentang tanggal bebas — semua dikirim lewat
// periode=custom&mulai=...&akhir=... supaya cukup 1 jalur validasi) ──
$periode = $_GET['periode'] ?? 'bulan_ini';
$allowed = ['hari_ini', 'minggu_ini', 'bulan_ini', 'tahun_ini', 'tahun_lalu', 'semua', 'custom'];
if (!in_array($periode, $allowed, true)) $periode = 'bulan_ini';

// Periode custom: validasi 'mulai' & 'akhir' dari query string. Kalau salah
// satu tidak valid, atau akhir < mulai, fallback ke 'bulan_ini'.
$mulai_custom = null;
$akhir_custom = null;
if ($periode === 'custom') {
    $mulai_custom = tanggalValidOrNull($_GET['mulai'] ?? null);
    $akhir_custom = tanggalValidOrNull($_GET['akhir'] ?? null);
    if (!$mulai_custom || !$akhir_custom || $akhir_custom < $mulai_custom) {
        $periode = 'bulan_ini';
        $mulai_custom = $akhir_custom = null;
    }
}

$where_periode = match ($periode) {
    'hari_ini'   => "DATE(t.created_at) = CURDATE()",
    'minggu_ini' => "YEARWEEK(t.created_at, 1) = YEARWEEK(CURDATE(), 1)",
    'bulan_ini'  => "YEAR(t.created_at) = YEAR(CURDATE()) AND MONTH(t.created_at) = MONTH(CURDATE())",
    'tahun_ini'  => "YEAR(t.created_at) = YEAR(CURDATE())",
    'tahun_lalu' => "YEAR(t.created_at) = YEAR(CURDATE()) - 1",
    'semua'      => "1=1",
    // $mulai_custom/$akhir_custom sudah lolos tanggalValidOrNull() di atas
    // (format YYYY-MM-DD & tanggal valid) -- aman ditempel langsung ke SQL.
    'custom'     => "DATE(t.created_at) BETWEEN '$mulai_custom' AND '$akhir_custom'",
};

// Label periode=custom dibikin "pintar": kalau rentang mulai/akhir persis
// 1 hari, 1 bulan penuh, atau 1 tahun penuh, tampilkan label ringkas.
if (!function_exists('labelPeriodeCustom')) {
    function labelPeriodeCustom(string $mulai, string $akhir): string {
        $mulaiTs = strtotime($mulai);
        $akhirTs = strtotime($akhir);
        if ($mulai === $akhir) {
            return date('d/m/Y', $mulaiTs);
        }
        if ($mulai === date('Y-01-01', $mulaiTs) && $akhir === date('Y-12-31', $mulaiTs)
            && date('Y', $mulaiTs) === date('Y', $akhirTs)) {
            return 'Tahun ' . date('Y', $mulaiTs);
        }
        if ($mulai === date('Y-m-01', $mulaiTs) && $akhir === date('Y-m-t', $mulaiTs)
            && date('Y-m', $mulaiTs) === date('Y-m', $akhirTs)) {
            return 'Bulan ' . date('F Y', $mulaiTs);
        }
        return date('d/m/Y', $mulaiTs) . ' - ' . date('d/m/Y', $akhirTs);
    }
}

$label_periode = match ($periode) {
    'hari_ini'   => 'Hari Ini (' . date('d/m/Y') . ')',
    'minggu_ini' => 'Minggu Ini',
    'bulan_ini'  => 'Bulan ' . date('F Y'),
    'tahun_ini'  => 'Tahun ' . date('Y'),
    'tahun_lalu' => 'Tahun ' . (date('Y') - 1),
    'semua'      => 'Semua Waktu',
    'custom'     => labelPeriodeCustom($mulai_custom, $akhir_custom),
};

// ── OPSI UNTUK TAB "Hari ▾" / "Bulan ▾" / "Tanggal ▾" / "Tahun ..." ──
$tahunSekarang     = (int) date('Y');
$TAHUN_MULAI_USAHA = 2026; // tahun awal data transaksi sistem ini

$HARI_ID_SINGKAT = ['Min','Sen','Sel','Rab','Kam','Jum','Sab'];
$opsiHari = [];
for ($i = 1; $i <= 6; $i++) {
    $ts  = strtotime("-$i day");
    $tgl = date('Y-m-d', $ts);
    $opsiHari[] = [
        'tanggal' => $tgl,
        'label'   => $i === 1 ? 'Kemarin' : ($HARI_ID_SINGKAT[(int)date('w', $ts)] . ', ' . date('d/m', $ts)),
    ];
}
$hariAktifLabel = null;
if ($periode === 'custom' && $mulai_custom === $akhir_custom) {
    foreach ($opsiHari as $o) {
        if ($o['tanggal'] === $mulai_custom) { $hariAktifLabel = $o['label']; break; }
    }
}

$NAMA_BULAN = ['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
$opsiBulan = [];
for ($m = 1; $m <= 12; $m++) {
    $mulaiBulan = sprintf('%04d-%02d-01', $tahunSekarang, $m);
    $opsiBulan[] = [
        'mulai' => $mulaiBulan,
        'akhir' => date('Y-m-t', strtotime($mulaiBulan)),
        'label' => $NAMA_BULAN[$m - 1],
    ];
}
$bulanAktifLabel = null;
if ($periode === 'custom') {
    foreach ($opsiBulan as $o) {
        if ($o['mulai'] === $mulai_custom && $o['akhir'] === $akhir_custom) { $bulanAktifLabel = $o['label']; break; }
    }
}

$opsiTahun = [];
for ($y = $TAHUN_MULAI_USAHA; $y <= $tahunSekarang; $y++) {
    $opsiTahun[] = ['tahun' => $y, 'mulai' => "$y-01-01", 'akhir' => "$y-12-31"];
}
$tahunAktif = null;
if ($periode === 'custom') {
    foreach ($opsiTahun as $o) {
        if ($o['mulai'] === $mulai_custom && $o['akhir'] === $akhir_custom) { $tahunAktif = $o['tahun']; break; }
    }
}

$tanggalAktif = null;
if ($periode === 'custom' && $hariAktifLabel === null && $bulanAktifLabel === null && $tahunAktif === null) {
    $tanggalAktif = $mulai_custom . '|' . $akhir_custom;
}

// ── REKAP PEMAKAIAN PER ITEM ──
// "Jumlah pemakaian" = berapa baris detail_transaksi yang mencantumkan item
// ini (setiap baris = satu kali item itu dipesan pelanggan dalam satu nota).
$stmt = $pdo->prepare("
    SELECT
        item_id, nama_item, nama_kategori, satuan, is_aktif,
        COUNT(*)                        AS jumlah_pemakaian,
        COUNT(DISTINCT transaksi_id)    AS jumlah_nota,
        SUM(qty)                        AS total_qty,
        SUM(subtotal)                   AS total_omzet
    FROM (
        SELECT
            dt.transaksi_id,
            dt.qty,
            dt.subtotal,
            COALESCE(db.id_barang, 0) AS item_id,
            COALESCE(db.nama_item, TRIM(REPLACE(SUBSTRING_INDEX(dt.keterangan,'(',1),'[Arsip]','')), 'Item Tanpa Nama') AS nama_item,
            COALESCE(kb.nama_kategori, IF(db.id_barang IS NULL, 'Arsip / Tanpa Kategori', 'Tanpa Kategori')) AS nama_kategori,
            COALESCE(db.satuan, 'pcs') AS satuan,
            db.is_aktif AS is_aktif
        FROM detail_transaksi dt
        JOIN transaksi t ON t.id_transaksi = dt.transaksi_id
        LEFT JOIN detail_barang db ON db.id_barang = dt.detail_barang_id
        LEFT JOIN kategori_barang kb ON kb.id_kategori = db.kategori_id
        WHERE $where_periode
    ) x
    GROUP BY item_id, nama_item, nama_kategori, satuan, is_aktif
    ORDER BY jumlah_pemakaian DESC, total_omzet DESC
");
$stmt->execute();
$rekap = $stmt->fetchAll();

// ── HITUNG STATISTIK RINGKAS ──
$total_pemakaian   = array_sum(array_column($rekap, 'jumlah_pemakaian'));
$total_omzet_semua = array_sum(array_column($rekap, 'total_omzet'));
$total_item_unik   = count($rekap);

$item_terlaris = $rekap[0] ?? null;

// Rekap per kategori (untuk kartu "Kategori Favorit")
$per_kategori = [];
foreach ($rekap as $r) {
    $k = $r['nama_kategori'];
    if (!isset($per_kategori[$k])) $per_kategori[$k] = ['pemakaian' => 0, 'omzet' => 0];
    $per_kategori[$k]['pemakaian'] += (int)$r['jumlah_pemakaian'];
    $per_kategori[$k]['omzet']     += (float)$r['total_omzet'];
}
arsort($per_kategori);
$kategori_favorit = array_key_first($per_kategori);

// ── BARANG PALING SERING TERLAMBAT ──
// Dipindahkan dari dashboard_owner.php supaya menyatu dengan rekap pemakaian
// barang lainnya di satu halaman, dan ikut filter periode di atas (bukan
// cuma terpaku ke bulan berjalan seperti sebelumnya).
// Definisi "terlambat":
// - Nota yang SUDAH selesai (siap_diambil/selesai): terlambat kalau tanggal status
//   berubah jadi siap_diambil/selesai (s.updated_at) itu SETELAH tanggal_estimasi.
// - Nota yang masih jalan (antrian/proses): terlambat kalau tanggal_estimasi < hari ini.
$KONDISI_TERLAMBAT = "
    (
        t.pernah_terlambat = 1
        OR
        (s.status IN ('siap_diambil','selesai') AND DATE(s.updated_at) > t.tanggal_estimasi)
        OR
        (s.status NOT IN ('siap_diambil','selesai') AND t.tanggal_estimasi < CURDATE())
    )
";
$stmt2 = $pdo->prepare("
    SELECT COALESCE(il.nama_item, 'Tanpa Nama Item') AS barang,
           COUNT(DISTINCT dt.transaksi_id) AS jml
    FROM detail_transaksi dt
    JOIN transaksi t        ON t.id_transaksi = dt.transaksi_id
    JOIN status_cucian s    ON s.transaksi_id = t.id_transaksi
    LEFT JOIN detail_barang il ON il.id_barang = dt.detail_barang_id
    WHERE t.tanggal_estimasi IS NOT NULL
      AND $where_periode
      AND $KONDISI_TERLAMBAT
    GROUP BY barang
    ORDER BY jml DESC
    LIMIT 5
");
$stmt2->execute();
$top_barang_terlambat = $stmt2->fetchAll();
$max_barang_terlambat = !empty($top_barang_terlambat) ? $top_barang_terlambat[0]['jml'] : 0;

$namaOwner = htmlspecialchars($_SESSION['user']['name']);
$inisial   = strtoupper(substr($_SESSION['user']['name'], 0, 2));

// Data untuk chart (top 10)
$chartTop = array_slice($rekap, 0, 10);
$chartLabelsJs = json_encode(array_map(fn($r) => $r['nama_item'], $chartTop));
$chartDataJs   = json_encode(array_map(fn($r) => (int)$r['jumlah_pemakaian'], $chartTop));
$rekapJson     = json_encode($rekap, JSON_HEX_QUOT | JSON_HEX_TAG);
$periodeJs     = json_encode($periode);
$mulaiCustomJs = json_encode($mulai_custom);
$akhirCustomJs = json_encode($akhir_custom);
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>Rekap Frekuensi Cucian — Azka Laundry</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<style>
:root {
    --sky: #2E74C0; --sky-deep:#1F5FA6; --sky-soft:#EAF2FB; --sky-pale:#EAF2FB;
    --cloud:#FFFFFF; --border:#D6E7F7; --ink:#164A85;
}
* { margin:0; padding:0; box-sizing:border-box; font-family:'Plus Jakarta Sans',sans-serif; }
body { color:#101418; background:var(--sky-pale); min-height:100vh; }

.sidebar { width:230px; height:100vh; height:100dvh; background:var(--sky-deep); position:fixed; left:0; top:0; display:flex; flex-direction:column; z-index:100; overflow-y:auto; }
.sidebar-brand { padding:20px; border-bottom:1px solid rgba(255,255,255,0.14); display:flex; align-items:center; gap:10px; }
.sidebar-brand svg { width:28px; height:28px; flex-shrink:0; }
.sidebar-brand h4 { color:#FFFFFF; font-size:16px; font-weight:700; margin:0; }
.sidebar-brand small { color:rgba(255,255,255,0.55); font-size:11px; }
.sidebar-menu { padding:14px 10px; flex:1; }
.sidebar-menu a { display:flex; align-items:center; gap:10px; color:rgba(255,255,255,0.68); text-decoration:none; padding:10px 12px; border-radius:8px; margin-bottom:2px; font-size:13px; font-weight:500; }
.sidebar-menu a:hover, .sidebar-menu a.active { background:rgba(255,255,255,0.14); color:#FFFFFF; }
.sidebar-footer { padding:14px 10px calc(14px + env(safe-area-inset-bottom)); border-top:1px solid rgba(255,255,255,0.14); flex-shrink:0; }
.sidebar-footer a { display:flex; align-items:center; gap:10px; color:rgba(255,255,255,0.55); text-decoration:none; padding:9px 12px; border-radius:8px; font-size:13px; }
.sidebar-footer a:hover { background:rgba(255,255,255,0.14); color:#FFFFFF; }
.main { margin-left:230px; }

.hamburger-btn { display:none; position:fixed; top:12px; left:14px; z-index:200; background:var(--sky-deep); color:#FFFFFF; border:none; width:42px; height:42px; border-radius:10px; font-size:18px; align-items:center; justify-content:center; cursor:pointer; box-shadow:0 3px 10px rgba(16,20,24,0.28); }
.sidebar-overlay { display:none; position:fixed; inset:0; background:rgba(22,74,133,0.5); z-index:100; }
.sidebar-overlay.show { display:block; }
.sidebar { transition:transform 0.28s ease; }
@media (max-width:900px) {
    .hamburger-btn { display:flex; }
    .sidebar { transform:translateX(-100%); box-shadow:2px 0 20px rgba(16,20,24,0.28); }
    .sidebar.open { transform:translateX(0); }
    .main { margin-left:0 !important; }
    .topbar { padding:12px 16px 12px 62px !important; flex-wrap:wrap; row-gap:8px; }
    .content { padding:16px !important; }
}

.topbar { background:var(--cloud); border-bottom:1px solid var(--border); padding:14px 26px; display:flex; justify-content:space-between; align-items:center; position:sticky; top:0; z-index:99; }
.topbar-title { font-size:17px; font-weight:700; color:#101418; }
.topbar-title small { display:block; font-size:12px; color:#3A4450; font-weight:400; margin-top:2px; }
.topbar-right { display:flex; align-items:center; gap:12px; }
.avatar { width:36px; height:36px; border-radius:50%; background:var(--sky); display:flex; align-items:center; justify-content:center; color:#FFFFFF; font-weight:700; font-size:12px; }
.user-name { font-size:13px; font-weight:600; color:#101418; }
.user-role { font-size:11px; color:#4B5563; }
.content { padding:22px 26px; }
.container { max-width:1300px; margin:auto; }

.card { background:var(--cloud); border-radius:14px; padding:20px; border:1px solid var(--border); margin-bottom:20px; }
.card-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; flex-wrap:wrap; gap:10px; }
.card-title { font-size:15px; font-weight:700; color:#101418; }

.periode-tabs { display:flex; background:var(--sky-soft); border-radius:10px; padding:3px; gap:2px; border:1px solid var(--border); flex-wrap:wrap; }
.periode-tab { text-decoration:none; padding:7px 14px; border-radius:8px; font-size:12px; font-weight:600; color:#3A4450; white-space:nowrap; }
.periode-tab:hover { color:var(--sky-deep); background:rgba(255,255,255,0.6); }
.periode-tab.active { background:var(--cloud); color:var(--sky-deep); border:1px solid var(--border); }
.periode-tab-custom { border:none; cursor:pointer; display:inline-flex; align-items:center; gap:6px; font-family:inherit; background:none; }
.periode-tab-dd { border:none; cursor:pointer; display:inline-flex; align-items:center; gap:6px; font-family:inherit; background:none; }
.dd-caret { display:inline-block; color:inherit; flex-shrink:0; vertical-align:middle; }
.dd-cal-icon { color:inherit; flex-shrink:0; }
.periode-dropdown { position:relative; }
.periode-dd-panel {
  display:none; position:absolute; top:calc(100% + 6px); left:0; z-index:150;
  background:var(--cloud); border:1px solid var(--border); border-radius:10px;
  box-shadow:0 8px 24px rgba(16,20,24,0.14); padding:6px; min-width:150px;
  max-height:280px; overflow-y:auto;
}
.periode-dd-panel.show { display:block; }
.periode-dd-item { display:block; text-decoration:none; padding:8px 12px; border-radius:7px; font-size:12.5px; font-weight:600; color:#3A4450; white-space:nowrap; }
.periode-dd-item:hover { background:var(--sky-soft); color:var(--sky-deep); }
.periode-dd-item.active { background:var(--sky-deep); color:#FFFFFF; }

/* ── MODAL KALENDER PERIODE CUSTOM (sama seperti dashboard_owner.php) ── */
.modal-overlay { display:none; position:fixed; inset:0; background:rgba(22,74,133,0.45); z-index:200; align-items:center; justify-content:center; padding:16px; }
.modal-overlay.active { display:flex; }
.modal { background:var(--cloud); border-radius:16px; width:100%; max-width:480px; padding:24px; position:relative; max-height:90vh; overflow-y:auto; }
.kal-modal { max-width:400px; padding:0; overflow:hidden; }
.kal-header { background:var(--sky-deep); color:#FFFFFF; padding:16px 20px; display:flex; align-items:center; gap:12px; position:relative; }
.kal-header h3 { font-size:15px; font-weight:700; flex:1; text-align:center; margin:0; }
.kal-header button { background:none; border:none; color:#FFFFFF; font-size:18px; cursor:pointer; width:28px; height:28px; display:flex; align-items:center; justify-content:center; }

.kal-picker-bar { display:flex; align-items:center; gap:8px; padding:12px 18px; border-bottom:1px solid var(--border); background:var(--cloud); }
.kal-picker-btn { background:var(--sky-soft); border:1px solid var(--border); color:var(--sky-deep); font-weight:700; font-size:13px; padding:8px 12px; border-radius:8px; cursor:pointer; display:inline-flex; align-items:center; gap:6px; font-family:inherit; }
.kal-picker-btn.open { background:var(--sky-deep); color:#FFFFFF; border-color:var(--sky-deep); }
.kal-today-btn { margin-left:auto; background:none; border:1px solid var(--border); color:#3A4450; font-weight:700; font-size:12px; padding:8px 12px; border-radius:8px; cursor:pointer; font-family:inherit; }
.kal-today-btn:hover { background:var(--sky-soft); }

.kal-picker-panel { border-bottom:1px solid var(--border); background:var(--sky-pale); max-height:220px; overflow-y:auto; }
.kal-picker-list { display:flex; flex-direction:column; }
.kal-picker-list .kal-picker-item { padding:11px 18px; font-size:13px; font-weight:600; color:#101418; cursor:pointer; }
.kal-picker-list .kal-picker-item:hover { background:var(--sky-soft); }
.kal-picker-list .kal-picker-item.active { background:var(--sky-deep); color:#FFFFFF; }
.kal-picker-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:8px; padding:14px 18px; }
.kal-picker-grid .kal-picker-item { text-align:center; padding:10px 4px; font-size:13px; font-weight:600; color:#101418; cursor:pointer; border-radius:8px; background:var(--cloud); border:1px solid var(--border); }
.kal-picker-grid .kal-picker-item:hover { background:var(--sky-soft); }
.kal-picker-grid .kal-picker-item.active { background:var(--sky-deep); color:#FFFFFF; border-color:var(--sky-deep); }

.kal-body { padding:14px 18px; max-height:52vh; overflow-y:auto; scroll-behavior:smooth; }
.kal-month-title { font-size:14px; font-weight:800; color:#101418; padding:4px 0 2px; position:sticky; top:-14px; background:var(--cloud); z-index:1; }
.kal-weekdays { display:grid; grid-template-columns:repeat(7,1fr); text-align:center; font-size:11px; font-weight:700; color:#3A4450; margin:8px 0 4px; }
.kal-weekdays span.sun { color:#B23B3B; }
.kal-days { display:grid; grid-template-columns:repeat(7,1fr); row-gap:4px; }
.kal-day { text-align:center; padding:7px 0; font-size:12px; font-weight:600; color:#101418; cursor:pointer; border-radius:8px; position:relative; }
.kal-day.empty { cursor:default; }
.kal-day.sun { color:#B23B3B; }
.kal-day.today { box-shadow:inset 0 0 0 1.5px var(--sky-deep); }
.kal-day:hover:not(.empty) { background:var(--sky-soft); }
.kal-day.in-range { background:var(--sky); color:#FFFFFF; border-radius:0; }
.kal-day.range-start, .kal-day.range-end { background:var(--sky-deep); color:#FFFFFF !important; border-radius:8px; font-weight:800; }
.kal-day.range-start { border-top-right-radius:0; border-bottom-right-radius:0; }
.kal-day.range-end { border-top-left-radius:0; border-bottom-left-radius:0; }
.kal-day.single { border-radius:8px; }

.kal-footer { border-top:1px solid var(--border); padding:14px 18px; display:flex; align-items:center; justify-content:space-between; gap:12px; background:var(--sky-pale); }
.kal-footer-dates { font-size:11px; color:#3A4450; line-height:1.5; }
.kal-footer-dates b { display:block; font-size:12px; color:#101418; }
.kal-footer-btn { background:var(--sky-deep); color:#FFFFFF; border:none; padding:10px 20px; border-radius:10px; font-size:13px; font-weight:700; cursor:pointer; white-space:nowrap; }
.kal-footer-btn:disabled { background:#B9C2CC; cursor:not-allowed; }

.btn { display:inline-flex; align-items:center; gap:6px; padding:8px 16px; border-radius:9px; font-size:13px; font-weight:700; cursor:pointer; border:none; font-family:inherit; text-decoration:none; }
.btn-sm { padding:5px 10px; font-size:11px; border-radius:7px; }
.btn-excel { background:#1F5FA6; color:#FFFFFF; }
.btn-excel:hover { background:#1F5FA6; }
.btn-back { background:#FFFFFF; color:#101418; border:1px solid var(--border); }

.stat-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:16px; margin-bottom:20px; }
.stat-card { background:var(--cloud); border-radius:14px; padding:18px 20px; border:1px solid var(--border); position:relative; overflow:hidden; }
.stat-card::before { content:''; position:absolute; top:0; left:0; right:0; height:3px; background:var(--sky); }
.stat-label { font-size:12px; color:#3A4450; font-weight:600; margin-bottom:4px; }
.stat-num { font-size:24px; font-weight:800; color:#101418; line-height:1.2; }
.stat-sub { font-size:11.5px; color:#4B5563; margin-top:4px; }
@media(max-width:1024px){ .stat-grid{grid-template-columns:repeat(2,1fr);} }
@media(max-width:600px){ .stat-grid{grid-template-columns:1fr;} }

.insight-box { background:var(--sky-soft); border:1px solid var(--border); border-left:4px solid var(--sky-deep); border-radius:10px; padding:14px 16px; margin-bottom:10px; font-size:13px; color:#101418; line-height:1.5; }
.insight-box strong { color:var(--sky-deep); }
.insight-box.warn { border-left-color:#3A4450; }

.chart-container { position:relative; height:320px; width:100%; }

table { width:100%; border-collapse:collapse; font-size:13px; }
thead th { background:var(--sky-pale); color:#3A4450; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.3px; padding:10px; text-align:left; border-bottom:1px solid var(--border); }
thead th.num { text-align:right; }
tbody td { padding:10px; border-bottom:1px solid var(--sky-pale); vertical-align:middle; }
tbody td.num { text-align:right; font-variant-numeric:tabular-nums; }
tbody tr:hover { background:var(--sky-pale); }
.rank-badge { display:inline-flex; align-items:center; justify-content:center; width:22px; height:22px; border-radius:50%; background:var(--sky-soft); color:var(--sky-deep); font-weight:800; font-size:11px; }
.rank-badge.top1 { background:var(--sky-deep); color:#FFFFFF; }
.item-kategori { font-size:11px; color:#4B5563; margin-top:2px; }
.progress-bar-wrap { width:100%; height:6px; background:var(--sky-pale); border-radius:4px; overflow:hidden; margin-top:6px; }
.progress-bar-fill { height:100%; background:var(--sky-deep); border-radius:4px; }
.empty-note { text-align:center; padding:30px; color:#4B5563; font-style:italic; }
.chip-nonaktif { display:inline-block; font-size:10px; font-weight:700; color:#101418; background:var(--sky-soft); border:1px solid var(--border); padding:2px 7px; border-radius:20px; margin-left:6px; }

.rank-item { margin-bottom:12px; }
.rank-item:last-child { margin-bottom:0; }
.rank-top { display:flex; justify-content:space-between; align-items:center; margin-bottom:5px; font-size:12.5px; }
.rank-name { font-weight:600; color:#101418; display:flex; align-items:center; gap:6px; }
.rank-no { width:18px; height:18px; border-radius:5px; background:var(--sky-soft); color:var(--sky-deep); font-size:10px; font-weight:800; display:inline-flex; align-items:center; justify-content:center; flex-shrink:0; }
.rank-count { font-weight:700; color:#101418; }
.rank-bar-track { background:var(--sky-pale); border-radius:20px; height:7px; overflow:hidden; }
.rank-bar-fill { background:var(--ink); height:100%; border-radius:20px; }
.empty-state { text-align:center; padding:30px 20px; color:#4B5563; font-size:13px; }
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
    <div><h4>Azka Laundry</h4><small>Owner Panel</small></div>
  </div>
  <div class="sidebar-menu">
    <a href="dashboard_owner.php">Dashboard</a>
    <a href="laporan_barang.php" class="active">Rekap Frekuensi Cucian</a>
    <a href="kelola_barang.php">Kelola Kategori &amp; Cucian</a>
    <a href="kelola_karyawan.php">Kelola Karyawan</a>
  </div>
  <div class="sidebar-footer"><a href="logout.php">Logout</a></div>
</div>

<div class="main">
  <div class="topbar">
    <div class="topbar-title">
      Rekap Frekuensi Cucian
      <small>Frekuensi tiap barang dicuci pelanggan &amp; insight otomatis</small>
    </div>
    <div class="topbar-right">
      <div style="text-align:right">
        <div class="user-name"><?= $namaOwner ?></div>
        <div class="user-role">Owner</div>
      </div>
      <div class="avatar"><?= $inisial ?></div>
    </div>
  </div>

  <div class="content">
  <div class="container">

  <!-- FILTER PERIODE -->
  <div class="card">
    <div class="card-header">
      <div style="display:flex;flex-direction:column;gap:6px">
        <div class="card-title">Periode</div>
        <div class="periode-tabs" id="periodeTabs">
          <a href="?periode=hari_ini" class="periode-tab <?= $periode==='hari_ini' ?'active':'' ?>">Hari Ini</a>

          <div class="periode-dropdown">
            <button type="button" class="periode-tab periode-tab-dd <?= $hariAktifLabel ?'active':'' ?>" onclick="toggleDropdown(event,'ddHari')">
              <?= $hariAktifLabel ? htmlspecialchars($hariAktifLabel) : 'Hari' ?> <svg class="dd-caret" width="9" height="6" viewBox="0 0 10 6" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path d="M0 0L10 0L5 6Z"></path></svg>
            </button>
            <div class="periode-dd-panel" id="ddHari">
              <?php foreach ($opsiHari as $o): ?>
              <a href="?periode=custom&mulai=<?= $o['tanggal'] ?>&akhir=<?= $o['tanggal'] ?>" class="periode-dd-item <?= ($mulai_custom===$o['tanggal'] && $akhir_custom===$o['tanggal']) ?'active':'' ?>"><?= htmlspecialchars($o['label']) ?></a>
              <?php endforeach; ?>
            </div>
          </div>

          <div class="periode-dropdown">
            <button type="button" class="periode-tab periode-tab-dd <?= $bulanAktifLabel ?'active':'' ?>" onclick="toggleDropdown(event,'ddBulan')">
              <?= $bulanAktifLabel ? htmlspecialchars($bulanAktifLabel) : 'Bulan' ?> <svg class="dd-caret" width="9" height="6" viewBox="0 0 10 6" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path d="M0 0L10 0L5 6Z"></path></svg>
            </button>
            <div class="periode-dd-panel" id="ddBulan">
              <?php foreach ($opsiBulan as $o): ?>
              <a href="?periode=custom&mulai=<?= $o['mulai'] ?>&akhir=<?= $o['akhir'] ?>" class="periode-dd-item <?= ($mulai_custom===$o['mulai'] && $akhir_custom===$o['akhir']) ?'active':'' ?>"><?= htmlspecialchars($o['label']) ?></a>
              <?php endforeach; ?>
            </div>
          </div>

          <?php
          $tanggalTabLabel = 'Tanggal';
          if ($tanggalAktif) {
              [$tglMulaiTab, $tglAkhirTab] = explode('|', $tanggalAktif);
              $tanggalTabLabel = ($tglMulaiTab === $tglAkhirTab)
                  ? date('d/m/Y', strtotime($tglMulaiTab))
                  : date('d/m', strtotime($tglMulaiTab)) . ' - ' . date('d/m/Y', strtotime($tglAkhirTab));
          }
          ?>
          <button type="button" class="periode-tab periode-tab-custom <?= $tanggalAktif ?'active':'' ?>" onclick="bukaKalender()">
            <svg class="dd-cal-icon" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
            <?= htmlspecialchars($tanggalTabLabel) ?> <svg class="dd-caret" width="9" height="6" viewBox="0 0 10 6" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path d="M0 0L10 0L5 6Z"></path></svg>
          </button>

          <div class="periode-dropdown">
            <button type="button" class="periode-tab periode-tab-dd <?= $tahunAktif ?'active':'' ?>" onclick="toggleDropdown(event,'ddTahun')">
              <?= $tahunAktif ? 'Tahun '.$tahunAktif : 'Tahun' ?> <svg class="dd-caret" width="9" height="6" viewBox="0 0 10 6" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path d="M0 0L10 0L5 6Z"></path></svg>
            </button>
            <div class="periode-dd-panel" id="ddTahun">
              <?php foreach ($opsiTahun as $o): ?>
              <a href="?periode=custom&mulai=<?= $o['mulai'] ?>&akhir=<?= $o['akhir'] ?>" class="periode-dd-item <?= $tahunAktif===$o['tahun'] ?'active':'' ?>">Tahun <?= $o['tahun'] ?></a>
              <?php endforeach; ?>
            </div>
          </div>

          <a href="?periode=semua" class="periode-tab <?= $periode==='semua' ?'active':'' ?>">Semua Waktu</a>
        </div>
      </div>
      <div>
        <a class="btn btn-back btn-sm" href="dashboard_owner.php">&larr; Kembali</a>
        <button class="btn btn-excel btn-sm" onclick="unduhRekap()">Excel</button>
      </div>
    </div>
  </div>

  <!-- STATISTIK RINGKAS -->
  <div class="stat-grid">
    <div class="stat-card">
      <div class="stat-label">Total Frekuensi Pemesanan</div>
      <div class="stat-num"><?= number_format($total_pemakaian,0,',','.') ?>x</div>
      <div class="stat-sub"><?= $total_item_unik ?> jenis item berbeda</div>
    </div>
    <div class="stat-card">
      <div class="stat-label">Cucian Paling Sering Dilakukan</div>
      <div class="stat-num" style="font-size:17px"><?= $item_terlaris ? htmlspecialchars($item_terlaris['nama_item']) : '-' ?></div>
      <div class="stat-sub"><?= $item_terlaris ? number_format($item_terlaris['jumlah_pemakaian'],0,',','.').'x dicuci' : 'Belum ada data' ?></div>
    </div>
    <div class="stat-card">
      <div class="stat-label">Kategori Favorit</div>
      <div class="stat-num" style="font-size:17px"><?= $kategori_favorit ? htmlspecialchars($kategori_favorit) : '-' ?></div>
      <div class="stat-sub"><?= $kategori_favorit ? number_format($per_kategori[$kategori_favorit]['pemakaian'],0,',','.').'x dicuci' : '' ?></div>
    </div>
  </div>

  <!-- CHART TOP 10 -->
  <?php if ($total_pemakaian > 0): ?>
  <div class="card">
    <div class="card-title" style="margin-bottom:14px">Top 10 Cucian Paling Sering Dilakukan</div>
    <div class="chart-container"><canvas id="chartTop10"></canvas></div>
  </div>
  <?php endif; ?>

  <!-- BARANG PALING SERING TERLAMBAT -->
  <div class="card" id="barang-terlambat">
    <div class="card-header">
      <div class="card-title">Cucian Paling Sering Tidak Sesuai Estimasi</div>
    </div>
    <div style="font-size:12px;color:#4B5563;margin-bottom:14px">Berdasar nota terlambat pada periode <?= htmlspecialchars($label_periode) ?></div>
    <?php if (empty($top_barang_terlambat)): ?>
    <div class="empty-state">
      <p>Belum ada nota terlambat pada periode ini.</p>
    </div>
    <?php else: ?>
    <?php foreach ($top_barang_terlambat as $i => $k):
      $pctLate = $max_barang_terlambat > 0 ? round(($k['jml'] / $max_barang_terlambat) * 100) : 0;
    ?>
    <div class="rank-item">
      <div class="rank-top">
        <span class="rank-name"><span class="rank-no"><?= $i+1 ?></span><?= htmlspecialchars($k['barang']) ?></span>
        <span class="rank-count"><?= $k['jml'] ?> nota</span>
      </div>
      <div class="rank-bar-track"><div class="rank-bar-fill" style="width:<?= $pctLate ?>%"></div></div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <!-- TABEL REKAP -->
  <div class="card">
    <div class="card-title" style="margin-bottom:14px">Rekap Lengkap Frekuensi Cucian</div>
    <div style="overflow-x:auto">
    <table>
      <thead>
        <tr>
          <th style="width:34px">#</th>
          <th>Nama Item</th>
          <th class="num">Frekuensi</th>
          <th class="num">Jumlah Nota</th>
          <th class="num">Total Qty</th>
          <th class="num">Total Omzet</th>
          <th class="num">% dari Total</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($rekap)): ?>
        <tr><td colspan="7" class="empty-note">Tidak ada data pemesanan barang pada periode ini.</td></tr>
        <?php else: $no=1; foreach ($rekap as $r):
            $pctBar = $total_pemakaian > 0 ? round(($r['jumlah_pemakaian'] / $total_pemakaian) * 100, 1) : 0;
        ?>
        <tr>
          <td><span class="rank-badge <?= $no===1?'top1':'' ?>"><?= $no ?></span></td>
          <td>
            <div style="font-weight:600"><?= htmlspecialchars($r['nama_item']) ?><?= (int)$r['is_aktif']===0 && (int)$r['item_id']>0 ? '<span class="chip-nonaktif">Nonaktif</span>' : '' ?></div>
            <div class="item-kategori"><?= htmlspecialchars($r['nama_kategori']) ?></div>
            <div class="progress-bar-wrap"><div class="progress-bar-fill" style="width:<?= min($pctBar*2,100) ?>%"></div></div>
          </td>
          <td class="num"><strong><?= number_format($r['jumlah_pemakaian'],0,',','.') ?>x</strong></td>
          <td class="num"><?= number_format($r['jumlah_nota'],0,',','.') ?></td>
          <td class="num"><?= number_format($r['total_qty'],2,',','.') ?> <?= htmlspecialchars($r['satuan']) ?></td>
          <td class="num"><?= rupiahLB($r['total_omzet']) ?></td>
          <td class="num"><?= $pctBar ?>%</td>
        </tr>
        <?php $no++; endforeach; endif; ?>
      </tbody>
    </table>
    </div>
  </div>

  </div>
  </div>
</div>

<!-- MODAL KALENDER PERIODE CUSTOM -->
<div class="modal-overlay" id="modalKalender">
  <div class="modal kal-modal">
    <div class="kal-header">
      <button onclick="tutupModal('modalKalender')" aria-label="Tutup" style="font-size:16px;line-height:1;">&times;</button>
      <h3>Pilih Rentang Tanggal</h3>
      <span style="width:28px"></span>
    </div>

    <div class="kal-picker-bar">
      <button class="kal-picker-btn" id="kalBtnBulan" onclick="toggleKalPicker('bulan')">Bulan <svg class="dd-caret" width="9" height="6" viewBox="0 0 10 6" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path d="M0 0L10 0L5 6Z"></path></svg></button>
      <button class="kal-picker-btn" id="kalBtnTahun" onclick="toggleKalPicker('tahun')">Tahun <svg class="dd-caret" width="9" height="6" viewBox="0 0 10 6" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path d="M0 0L10 0L5 6Z"></path></svg></button>
      <button class="kal-today-btn" type="button" onclick="lompatHariIniKalender()">Hari Ini</button>
    </div>

    <div class="kal-picker-panel" id="kalPanelBulan" style="display:none"></div>
    <div class="kal-picker-panel" id="kalPanelTahun" style="display:none"></div>

    <div class="kal-body" id="kalBody">
      <div id="kalMonths"></div>
    </div>

    <div class="kal-footer">
      <div class="kal-footer-dates">
        Dari<b id="kalLabelMulai">-</b>
      </div>
      <div class="kal-footer-dates">
        Sampai<b id="kalLabelAkhir">-</b>
      </div>
      <button class="kal-footer-btn" id="kalTerapkanBtn" onclick="terapkanPeriodeKalender()" disabled>Terapkan</button>
    </div>
  </div>
</div>

<script>
function tutupModal(id) { document.getElementById(id).classList.remove('active'); }
</script>

<script>
// ── DROPDOWN TAB "Hari ▾" / "Bulan ▾" / "Tahun ▾" ──
(function () {
    window.toggleDropdown = function (evt, panelId) {
        evt.stopPropagation();
        const panel = document.getElementById(panelId);
        const sedangTampil = panel.classList.contains('show');
        document.querySelectorAll('.periode-dd-panel.show').forEach(p => p.classList.remove('show'));
        if (!sedangTampil) panel.classList.add('show');
    };
    document.addEventListener('click', function (e) {
        if (!e.target.closest('.periode-dropdown')) {
            document.querySelectorAll('.periode-dd-panel.show').forEach(p => p.classList.remove('show'));
        }
    });
})();
</script>

<script>
// ── FILTER PERIODE CUSTOM (kalender scroll 12 bulan + picker tahun/bulan) ──
(function () {
    const BULAN_ID = ['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
    const HARI_ID  = ['Sen','Sel','Rab','Kam','Jum','Sab','Min'];

    const now = new Date();
    const TAHUN_MIN = 2026;
    const TAHUN_MAX = now.getFullYear() + 1;

    let selectedYear = now.getFullYear();

    const mulaiAwal = <?= $mulaiCustomJs ?>;
    const akhirAwal = <?= $akhirCustomJs ?>;
    let kalMulai = mulaiAwal || null;
    let kalAkhir = (akhirAwal && akhirAwal !== mulaiAwal) ? akhirAwal : null;

    function fmtDate(y, m, d) {
        return `${y}-${String(m + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
    }
    function fmtLabel(dateStr) {
        if (!dateStr) return '-';
        const [y, m, d] = dateStr.split('-').map(Number);
        return `${d} ${BULAN_ID[m - 1].substring(0, 3)} ${y}`;
    }
    function todayStr() {
        return fmtDate(now.getFullYear(), now.getMonth(), now.getDate());
    }

    function setBtnBulanLabel(monthIdx) {
        document.getElementById('kalBtnBulan').innerHTML =
            `${BULAN_ID[monthIdx]} <svg class="dd-caret" width="9" height="6" viewBox="0 0 10 6" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path d="M0 0L10 0L5 6Z"></path></svg>`;
    }
    function setBtnTahunLabel(year) {
        document.getElementById('kalBtnTahun').innerHTML =
            `${year} <svg class="dd-caret" width="9" height="6" viewBox="0 0 10 6" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path d="M0 0L10 0L5 6Z"></path></svg>`;
    }

    let scrollTicking = false;
    function updateLabelDariScroll() {
        const body = document.getElementById('kalBody');
        const blocks = body.querySelectorAll('.kal-month-block');
        let current = null;
        blocks.forEach(b => {
            if (b.offsetTop - body.scrollTop <= 36) current = b;
        });
        if (!current) return;
        const [, y, m] = current.id.split('-');
        setBtnBulanLabel(parseInt(m, 10));
        setBtnTahunLabel(parseInt(y, 10));
    }

    function renderTahunPanel() {
        let html = '<div class="kal-picker-list">';
        for (let y = TAHUN_MAX; y >= TAHUN_MIN; y--) {
            html += `<div class="kal-picker-item ${y === selectedYear ? 'active' : ''}" onclick="pilihTahunKalender(${y})">${y}</div>`;
        }
        html += '</div>';
        document.getElementById('kalPanelTahun').innerHTML = html;
    }

    function renderBulanPanel() {
        let html = '<div class="kal-picker-grid">';
        BULAN_ID.forEach((nm, idx) => {
            html += `<div class="kal-picker-item" onclick="pilihBulanKalender(${idx})">${nm.substring(0, 3)}</div>`;
        });
        html += '</div>';
        document.getElementById('kalPanelBulan').innerHTML = html;
    }

    window.toggleKalPicker = function (jenis) {
        const panelBulan = document.getElementById('kalPanelBulan');
        const panelTahun = document.getElementById('kalPanelTahun');
        const btnBulan   = document.getElementById('kalBtnBulan');
        const btnTahun   = document.getElementById('kalBtnTahun');

        if (jenis === 'bulan') {
            panelTahun.style.display = 'none'; btnTahun.classList.remove('open');
            const showing = panelBulan.style.display === 'block';
            panelBulan.style.display = showing ? 'none' : 'block';
            btnBulan.classList.toggle('open', !showing);
            if (!showing) renderBulanPanel();
        } else {
            panelBulan.style.display = 'none'; btnBulan.classList.remove('open');
            const showing = panelTahun.style.display === 'block';
            panelTahun.style.display = showing ? 'none' : 'block';
            btnTahun.classList.toggle('open', !showing);
            if (!showing) renderTahunPanel();
        }
    };

    window.pilihTahunKalender = function (y) {
        selectedYear = y;
        document.getElementById('kalPanelTahun').style.display = 'none';
        document.getElementById('kalBtnTahun').classList.remove('open');
        setBtnTahunLabel(y);
        setBtnBulanLabel(0);
        renderKalender();
        requestAnimationFrame(() => lompatKeBulan(selectedYear, 0, 'auto'));
    };

    window.pilihBulanKalender = function (monthIdx) {
        document.getElementById('kalPanelBulan').style.display = 'none';
        document.getElementById('kalBtnBulan').classList.remove('open');
        setBtnBulanLabel(monthIdx);
        setBtnTahunLabel(selectedYear);
        lompatKeBulan(selectedYear, monthIdx, 'smooth');
    };

    window.lompatHariIniKalender = function () {
        selectedYear = now.getFullYear();
        setBtnBulanLabel(now.getMonth());
        setBtnTahunLabel(now.getFullYear());
        renderKalender();
        requestAnimationFrame(() => lompatKeBulan(now.getFullYear(), now.getMonth(), 'auto'));
    };

    function lompatKeBulan(year, month, perilaku) {
        const target = document.getElementById(`kalMonthBlock-${year}-${month}`);
        if (target) target.scrollIntoView({ behavior: perilaku, block: 'start' });
    }

    document.getElementById('kalBody').addEventListener('scroll', function () {
        if (scrollTicking) return;
        scrollTicking = true;
        requestAnimationFrame(() => { updateLabelDariScroll(); scrollTicking = false; });
    });

    function renderBulan(year, month) {
        const first = new Date(year, month, 1);
        const startOffset = (first.getDay() + 6) % 7;
        const totalHari = new Date(year, month + 1, 0).getDate();

        let html = `<div class="kal-month-block" id="kalMonthBlock-${year}-${month}">
            <div class="kal-month-title">${BULAN_ID[month]} ${year}</div>
            <div class="kal-weekdays">${HARI_ID.map(h => `<span class="${h==='Min'?'sun':''}">${h}</span>`).join('')}</div>
            <div class="kal-days">`;

        for (let i = 0; i < startOffset; i++) html += `<div class="kal-day empty"></div>`;

        for (let d = 1; d <= totalHari; d++) {
            const dateStr = fmtDate(year, month, d);
            const isSunday = (first.getDay() + d - 1) % 7 === 6;
            const isToday = dateStr === todayStr();
            let cls = 'kal-day' + (isSunday ? ' sun' : '') + (isToday ? ' today' : '');

            if (kalMulai && dateStr === kalMulai && (!kalAkhir || kalAkhir === kalMulai)) cls += ' range-start single';
            else if (kalMulai && dateStr === kalMulai) cls += ' range-start';
            else if (kalAkhir && dateStr === kalAkhir) cls += ' range-end';
            else if (kalMulai && kalAkhir && dateStr > kalMulai && dateStr < kalAkhir) cls += ' in-range';

            html += `<div class="${cls}" onclick="pilihTanggalKalender('${dateStr}')">${d}</div>`;
        }
        html += `</div></div>`;
        return html;
    }

    function renderKalender() {
        let html = '';
        for (let m = 0; m < 12; m++) html += renderBulan(selectedYear, m);
        document.getElementById('kalMonths').innerHTML = html;

        document.getElementById('kalLabelMulai').textContent = fmtLabel(kalMulai);
        document.getElementById('kalLabelAkhir').textContent = fmtLabel(kalAkhir || kalMulai);
        document.getElementById('kalTerapkanBtn').disabled = !kalMulai;
    }

    window.pilihTanggalKalender = function (dateStr) {
        if (!kalMulai || kalAkhir) {
            kalMulai = dateStr;
            kalAkhir = null;
        } else if (dateStr < kalMulai) {
            kalAkhir = kalMulai;
            kalMulai = dateStr;
        } else {
            kalAkhir = dateStr;
        }
        renderKalender();
    };

    window.bukaKalender = function () {
        const acuan = kalMulai ? new Date(kalMulai) : now;
        selectedYear = acuan.getFullYear();
        document.getElementById('kalPanelBulan').style.display = 'none';
        document.getElementById('kalPanelTahun').style.display = 'none';
        setBtnBulanLabel(acuan.getMonth());
        setBtnTahunLabel(selectedYear);
        renderKalender();
        document.getElementById('modalKalender').classList.add('active');
        requestAnimationFrame(() => lompatKeBulan(selectedYear, acuan.getMonth(), 'auto'));
    };

    window.terapkanPeriodeKalender = function () {
        if (!kalMulai) return;
        const akhir = kalAkhir || kalMulai;
        window.location.href = '?periode=custom&mulai=' + kalMulai + '&akhir=' + akhir;
    };
})();
</script>

<script>
function toggleSidebar(){document.getElementById('sidebarEl').classList.toggle('open');document.getElementById('sidebarOverlay').classList.toggle('show');}
function closeSidebar(){document.getElementById('sidebarEl').classList.remove('open');document.getElementById('sidebarOverlay').classList.remove('show');}

const rekapData = <?= $rekapJson ?>;
const periodeAktif = <?= $periodeJs ?>;

<?php if ($total_pemakaian > 0): ?>
new Chart(document.getElementById('chartTop10'), {
    type: 'bar',
    data: {
        labels: <?= $chartLabelsJs ?>,
        datasets: [{
            label: 'Frekuensi',
            data: <?= $chartDataJs ?>,
            backgroundColor: '#2E74C0',
            borderRadius: 6,
            maxBarThickness: 34
        }]
    },
    options: {
        indexAxis: 'y',
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: { x: { beginAtZero: true, ticks: { precision: 0 } } }
    }
});
<?php endif; ?>

function unduhRekap(){
    const rows = rekapData.map((r, i) => ({
        'No': i+1,
        'Nama Item': r.nama_item,
        'Kategori': r.nama_kategori,
        'Frekuensi': r.jumlah_pemakaian,
        'Jumlah Nota': r.jumlah_nota,
        'Total Qty': r.total_qty,
        'Satuan': r.satuan,
        'Total Omzet': r.total_omzet
    }));
    const ws = XLSX.utils.json_to_sheet(rows);
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, 'Rekap Barang');
    XLSX.writeFile(wb, `rekap_frekuensi_cucian_${periodeAktif}.xlsx`);
}
</script>
</body>
</html>