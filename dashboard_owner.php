<?php
require_once __DIR__ . '/includes/config.php';
requireRole('owner');

function rupiah($n) {
    return 'Rp ' . number_format($n, 0, ',', '.');
}
function persen($today, $yesterday) {
    if ($yesterday <= 0) return $today > 0 ? 100 : 0;
    return round((($today - $yesterday) / $yesterday) * 100);
}
function statusBadge($status) {
    return match($status) {
        'dalam_antrian'   => ['label'=>'Dalam Antrian','bg'=>'#D6E7F7','color'=>'#3A4450'],
        'sedang_diproses' => ['label'=>'Diproses',     'bg'=>'#EAF2FB','color'=>'#2E74C0'],
        'siap_diambil'    => ['label'=>'Siap Diambil', 'bg'=>'#D6E7F7','color'=>'#1F5FA6'],
        'selesai'         => ['label'=>'Selesai',      'bg'=>'#EAF2FB','color'=>'#2E74C0'],
        default           => ['label'=>ucfirst($status),'bg'=>'#EAF2FB','color'=>'#164A85'],
    };
}

// ── FILTER PERIODE TRANSAKSI ──
// Tab yang ditampilkan ke owner: [Hari Ini] (statis) - [Hari ▾] (pilih 1
// hari dlm 7 hari terakhir) - [Bulan ▾] (pilih 1 bulan dlm tahun berjalan) -
// [Tanggal ▾] (kalender, pilih 1 tanggal bebas) - [Tahun ...] (satu tab per
// tahun, mulai dari tahun awal usaha s/d tahun berjalan, otomatis nambah
// tab baru tiap pergantian tahun -- tab tahun lama tidak pernah hilang).
//
// Semua pilihan Hari/Bulan/Tanggal/Tahun di atas dikirim lewat periode=custom
// (mulai & akhir) supaya cukup 1 jalur validasi (tanggalValidOrNull, sudah
// aman dari SQL injection) -- tidak perlu nambah enum periode baru.
// periode=minggu_ini/bulan_ini/tahun_ini/tahun_lalu tetap didukung di bawah
// supaya link lama/bookmark & laporan_pdf.php tidak rusak.
$periode = $_GET['periode'] ?? 'hari_ini';
$allowed = ['hari_ini', 'minggu_ini', 'bulan_ini', 'tahun_ini', 'tahun_lalu', 'custom'];
if (!in_array($periode, $allowed)) $periode = 'hari_ini';

// Periode custom: validasi 'mulai' & 'akhir' dari query string. Kalau salah
// satu tidak valid, atau akhir < mulai, fallback ke 'hari_ini' (paling aman
// daripada query SQL rentang tanggal yang aneh/kosong).
$mulai_custom = null;
$akhir_custom = null;
if ($periode === 'custom') {
    $mulai_custom = tanggalValidOrNull($_GET['mulai'] ?? null);
    $akhir_custom = tanggalValidOrNull($_GET['akhir'] ?? null);
    if (!$mulai_custom || !$akhir_custom || $akhir_custom < $mulai_custom) {
        $periode = 'hari_ini';
        $mulai_custom = $akhir_custom = null;
    }
}

$where_periode = match($periode) {
    'hari_ini'   => "DATE(t.created_at) = CURDATE()",
    'minggu_ini' => "YEARWEEK(t.created_at, 1) = YEARWEEK(CURDATE(), 1)",
    'bulan_ini'  => "YEAR(t.created_at) = YEAR(CURDATE()) AND MONTH(t.created_at) = MONTH(CURDATE())",
    'tahun_ini'  => "YEAR(t.created_at) = YEAR(CURDATE())",
    'tahun_lalu' => "YEAR(t.created_at) = YEAR(CURDATE()) - 1",
    // $mulai_custom/$akhir_custom sudah lolos tanggalValidOrNull() di atas
    // (format YYYY-MM-DD & tanggal valid) -- aman ditempel langsung ke SQL.
    'custom'     => "DATE(t.created_at) BETWEEN '$mulai_custom' AND '$akhir_custom'",
};

// Label periode=custom dibikin "pintar": kalau rentang mulai/akhir persis
// 1 hari, 1 bulan penuh, atau 1 tahun penuh, tampilkan label ringkas
// ("12/08/2026", "Bulan Agustus 2026", "Tahun 2026") -- bukan rentang
// tanggal mentah -- supaya nyambung dgn tab Hari/Bulan/Tahun yang diklik.
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

$label_periode = match($periode) {
    'hari_ini'   => 'Hari Ini',
    'minggu_ini' => 'Minggu Ini',
    'bulan_ini'  => 'Bulan Ini',
    'tahun_ini'  => 'Tahun ' . date('Y'),
    'tahun_lalu' => 'Tahun ' . (date('Y') - 1),
    'custom'     => labelPeriodeCustom($mulai_custom, $akhir_custom),
};

// ── OPSI UNTUK TAB "Hari ▾" / "Bulan ▾" / "Tanggal ▾" / "Tahun ..." ──
$tahunSekarang    = (int) date('Y');
$TAHUN_MULAI_USAHA = 2026; // tahun awal data transaksi sistem ini (lihat juga TAHUN_MIN di kalender JS)

// Dropdown "Hari": 6 hari terakhir SELAIN hari ini (Kemarin s/d 6 hari lalu)
// -- hari ini sudah punya tab statis sendiri. Tiap opsi = 1 hari (mulai=akhir).
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

// Dropdown "Bulan": 12 bulan di tahun berjalan (Januari - Desember).
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

// Tab "Tahun ...": 1 tab per tahun, dari $TAHUN_MULAI_USAHA s/d tahun
// berjalan -- otomatis nambah tab baru tiap pergantian tahun (tab tahun
// lama tetap ada, tidak dibatasi cuma "tahun ini/tahun lalu").
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

// Tab "Tanggal ▾": aktif kalau periode custom (1 hari ATAU rentang tanggal
// bebas, misalnya 13-21 Agustus) TAPI bukan salah satu yang sudah punya
// tab sendiri (Hari/Bulan/Tahun). Nilainya "mulai|akhir" supaya tombolnya
// bisa nampilin rentang tanggal, bukan cuma 1 tanggal.
$tanggalAktif = null;
if ($periode === 'custom' && $hariAktifLabel === null && $bulanAktifLabel === null && $tahunAktif === null) {
    $tanggalAktif = $mulai_custom . '|' . $akhir_custom;
}

// ── STATISTIK ──
$trx_hari_ini  = $pdo->query("SELECT COUNT(*) FROM transaksi WHERE DATE(created_at)=CURDATE()")->fetchColumn();
$trx_kemarin   = $pdo->query("SELECT COUNT(*) FROM transaksi WHERE DATE(created_at)=CURDATE()-INTERVAL 1 DAY")->fetchColumn();
// Pendapatan = uang yang BENAR-BENAR diterima (total_dibayar), termasuk DP sebagian,
// bukan cuma transaksi yang berstatus "sudah_bayar" penuh.
$pend_hari_ini = $pdo->query("SELECT COALESCE(SUM(total_dibayar),0) FROM transaksi WHERE DATE(created_at)=CURDATE()")->fetchColumn();
$pend_kemarin  = $pdo->query("SELECT COALESCE(SUM(total_dibayar),0) FROM transaksi WHERE DATE(created_at)=CURDATE()-INTERVAL 1 DAY")->fetchColumn();
$pct_trx  = persen($trx_hari_ini, $trx_kemarin);
$pct_pend = persen($pend_hari_ini, $pend_kemarin);

// status_cucian tidak menyimpan histori (transaksi_id unik), jadi GROUP BY
// langsung sudah benar. Status 'selesai' (transaksi sudah diambil pelanggan,
// di luar 3 status utama dalam_antrian/sedang_diproses/siap_diambil) memang
// tidak ditampilkan sebagai kartu sendiri di dashboard ini.
$status_data = $pdo->query("SELECT status, COUNT(*) total FROM status_cucian GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
$diproses = $status_data['sedang_diproses'] ?? 0;
$siap     = $status_data['siap_diambil']    ?? 0;
$antrian  = $status_data['dalam_antrian']   ?? 0;

// FIX: status_cucian TIDAK menyimpan histori — satu baris per transaksi
// (transaksi_id unik). Akar masalah sebenarnya: status 'selesai' tidak
// dikenali sebagai "sudah tuntas" oleh kondisi lama (s.status != 'siap_diambil'),
// jadi transaksi yang sudah selesai/diambil tapi estimasinya lewat tanggal
// ikut terhitung "terlambat". Status yang dianggap TIDAK terlambat: yang
// sudah diambil pelanggan (siap_diambil ATAU selesai).
$terlambat = $pdo->query("
    SELECT COUNT(*)
    FROM transaksi t
    JOIN status_cucian s ON t.id_transaksi = s.transaksi_id
    WHERE t.tanggal_estimasi IS NOT NULL
      AND (t.tanggal_estimasi < CURDATE() OR t.pernah_terlambat = 1)
      AND s.status NOT IN ('siap_diambil', 'selesai')
")->fetchColumn();

// Catatan: analisis "Barang Paling Sering Terlambat" dipindahkan & disatukan
// ke laporan_barang.php (Rekap Frekuensi Cucian) supaya semua data pemakaian
// barang — termasuk yang terlambat — ada di satu halaman, dan ikut filter
// periode halaman itu (bukan cuma bulan berjalan seperti sebelumnya).

// ── TRANSAKSI BERDASARKAN PERIODE ──
// status_cucian tidak menyimpan histori (transaksi_id unik), LEFT JOIN
// langsung sudah aman dan tidak menggandakan baris.
$limit_trx = 20;
if (in_array($periode, ['tahun_ini', 'tahun_lalu'], true)) $limit_trx = 100;
if ($periode === 'custom' && $mulai_custom !== $akhir_custom) $limit_trx = 100;
$stmt = $pdo->prepare("
    SELECT t.id_nota, t.nama_pelanggan, t.total_harga, t.status_bayar, t.total_dibayar, t.created_at, s.status
    FROM transaksi t
    LEFT JOIN status_cucian s ON t.id_transaksi = s.transaksi_id
    WHERE $where_periode
    ORDER BY t.created_at DESC
    LIMIT $limit_trx
");
$stmt->execute();
$transaksi = $stmt->fetchAll();

// Hitung ringkasan untuk periode terpilih
$stmt_sum = $pdo->prepare("
    SELECT
        COUNT(*) AS total_trx,
        COALESCE(SUM(t.total_dibayar), 0) AS total_diterima,
        COALESCE(SUM(t.total_harga - t.total_dibayar), 0) AS total_belum,
        COALESCE(SUM(CASE WHEN t.status_bayar='sudah_bayar' THEN 1 ELSE 0 END), 0) AS jml_lunas,
        COALESCE(SUM(CASE WHEN t.status_bayar='dp' THEN 1 ELSE 0 END), 0) AS jml_dp,
        COALESCE(SUM(CASE WHEN t.status_bayar='dp' THEN (t.total_harga - t.total_dibayar) ELSE 0 END), 0) AS sisa_dp,
        COALESCE(SUM(CASE WHEN t.status_bayar='belum_bayar' THEN 1 ELSE 0 END), 0) AS jml_belum,
        COALESCE(SUM(CASE WHEN t.status_bayar='belum_bayar' THEN t.total_harga ELSE 0 END), 0) AS sisa_belum
    FROM transaksi t
    WHERE $where_periode
");
$stmt_sum->execute();
$ringkasan = $stmt_sum->fetch();

// ── CHART 7 HARI ──
// Pakai total_dibayar supaya DP yang sudah masuk juga terhitung sebagai pendapatan hari itu.
$pendapatan = $pdo->query("
    SELECT DATE(created_at) tanggal, COALESCE(SUM(total_dibayar),0) total
    FROM transaksi WHERE created_at>=CURDATE()-INTERVAL 6 DAY
    GROUP BY DATE(created_at)
")->fetchAll();

$chart = [];
for ($i=6; $i>=0; $i--) {
    $tgl = date('Y-m-d', strtotime("-$i days"));
    $chart[$tgl] = 0;
}
foreach ($pendapatan as $p) $chart[$p['tanggal']] = (int)$p['total'];

$chartLabels = [];
$chartData   = [];
foreach ($chart as $tgl => $total) {
    $chartLabels[] = date('D, d/m', strtotime($tgl));
    $chartData[]   = $total;
}

$namaOwner      = htmlspecialchars($_SESSION['user']['name']);
$inisial        = strtoupper(substr($_SESSION['user']['name'], 0, 2));
$transaksi_json = json_encode($transaksi,    JSON_HEX_QUOT|JSON_HEX_TAG);
$chartLabelsJs  = json_encode($chartLabels);
$chartDataJs    = json_encode($chartData);
$periodeJs      = json_encode($periode);
$labelPeriodeJs = json_encode($label_periode);
$mulaiCustomJs  = json_encode($mulai_custom);
$akhirCustomJs  = json_encode($akhir_custom);
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>Dashboard Owner — Azka Laundry</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<style>
:root {
    --sky:        #2E74C0;
    --sky-deep:   #1F5FA6;
    --sky-ink:    #1F5FA6;
    --sky-soft:   #EAF2FB;
    --sky-pale:   #EAF2FB;
    --cloud:      #FFFFFF;
    --border:     #D6E7F7;
    --ink:        #164A85;
    --gray:       #2E74C0;
    --gray-soft:  #4E90D9;
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
.sidebar-menu a i { width:16px; text-align:center; }
.sidebar-footer { padding:14px 10px calc(14px + env(safe-area-inset-bottom)); border-top:1px solid rgba(255,255,255,0.14); flex-shrink:0; }
.sidebar-footer a { display:flex; align-items:center; gap:10px; color:rgba(255,255,255,0.55); text-decoration:none; padding:9px 12px; border-radius:8px; font-size:13px; }
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
    .topbar { padding:12px 14px 12px 62px !important; flex-wrap:nowrap !important; }
    .topbar-title { font-size:14px !important; min-width:0; flex:1 1 auto; overflow:hidden; }
    .topbar-title small { white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .topbar-right { flex-shrink:0; }
    .content { padding:16px !important; }
}
@media (max-width: 480px) {
    .topbar-right { gap:6px !important; }
    .topbar-title small { display:none; } /* sembunyikan subjudul biar nama owner tetap muat sejajar di kanan */
    .user-role { display:none; } /* cukup nama + avatar di layar sempit */
    .stat-num { font-size:20px !important; }
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
.card { background:var(--cloud); border-radius:14px; padding:20px; border:1px solid var(--border); }
.card-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; flex-wrap:wrap; gap:10px; }
.card-title { font-size:15px; font-weight:700; color:#101418; display:flex; align-items:center; gap:8px; }

.stat-grid { display:grid; grid-template-columns:repeat(6,1fr); gap:16px; margin-bottom:20px; }
.stat-card { background:var(--cloud); border-radius:14px; padding:20px; border:1px solid var(--border); position:relative; overflow:hidden; }
.stat-card::before { content:''; position:absolute; top:0; left:0; right:0; height:3px; background:var(--sky); }
.stat-card.warn::before   { background:#3A4450; }
.stat-card.danger::before { background:#101418; }
.stat-card.info::before   { background:var(--sky-deep); }
.stat-icon  { font-size:20px; margin-bottom:8px; color:var(--sky); }
.stat-card.warn   .stat-icon { color:#3A4450; }
.stat-card.danger .stat-icon { color:#101418; }
.stat-card.info   .stat-icon { color:var(--sky-deep); }
.stat-label { font-size:12px; color:#3A4450; font-weight:600; margin-bottom:4px; }
.stat-num   { font-size:28px; font-weight:800; color:#101418; line-height:1; }
.stat-num.sm{ font-size:19px; }
.stat-delta { font-size:12px; margin-top:6px; font-weight:600; }
.up   { color:#1B2126; } .down { color:#101418; } .neu { color:#3A4450; }

.main-grid   { display:grid; grid-template-columns:1.6fr 1fr; gap:16px; margin-bottom:20px; align-items:start; }
.trx-list-wrap { max-height:560px; overflow-y:auto; padding-right:6px; margin-right:-6px; }
.trx-list-wrap::-webkit-scrollbar { width:6px; }
.trx-list-wrap::-webkit-scrollbar-track { background:transparent; }
.trx-list-wrap::-webkit-scrollbar-thumb { background:var(--border); border-radius:10px; }

/* ── PERIODE TABS ── */
.periode-tabs { display:flex; background:var(--sky-soft); border-radius:10px; padding:3px; gap:2px; border:1px solid var(--border); flex-wrap:wrap; }
.periode-tab { text-decoration:none; padding:7px 14px; border-radius:8px; font-size:12px; font-weight:600; color:#3A4450; white-space:nowrap; }
.periode-tab:hover { color:var(--sky-deep); background:rgba(255,255,255,0.6); }
.periode-tab.active { background:var(--cloud); color:var(--sky-deep); border:1px solid var(--border); }
.periode-tab-custom { border:none; cursor:pointer; display:inline-flex; align-items:center; gap:6px; font-family:inherit; }
.periode-tab-dd { border:none; cursor:pointer; display:inline-flex; align-items:center; gap:6px; font-family:inherit; }
.periode-tab-dd i, .periode-tab-custom i.fa-chevron-down { font-size:9px; transition:transform 0.15s; }
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

/* ── MODAL KALENDER PERIODE CUSTOM ── */
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
.kal-month-block { margin-bottom:14px; }
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

/* ── RINGKASAN MINI ── */
.ringkasan-bar { display:grid; grid-template-columns:repeat(4,1fr); gap:10px; margin-bottom:14px; }

.search-nota-wrap { display:flex; align-items:center; gap:8px; background:var(--sky-pale); border:1px solid var(--border); border-radius:10px; padding:9px 12px; margin-bottom:14px; color:#4B5563; }
.search-nota-wrap svg { flex-shrink:0; }
.search-nota-wrap input { flex:1; border:none; background:transparent; outline:none; font-size:13px; color:#101418; font-family:inherit; }
.search-nota-wrap input::placeholder { color:#8A94A3; }
.search-nota-wrap button { border:none; background:var(--border); color:#3A4450; width:20px; height:20px; border-radius:50%; font-size:14px; line-height:1; cursor:pointer; flex-shrink:0; }
.ring-item {
  text-align:left; background:var(--cloud); border:1px solid var(--border); border-radius:10px;
  padding:12px 14px; position:relative; overflow:hidden;
}
.ring-item::before { content:''; position:absolute; top:0; left:0; right:0; height:3px; background:var(--sky); }
.ring-item.lunas::before { background:#1F5FA6; }
.ring-item.dp::before    { background:#3A4450; }
.ring-item.belum::before { background:#101418; }
.ring-num  { font-size:19px; font-weight:800; color:#101418; line-height:1.2; }
.ring-num.green { color:#1B2126; }
.ring-num.orange{ color:#3A4450; }
.ring-num.red   { color:#101418; }
.ring-sub  { font-size:11.5px; color:#4B5563; font-weight:600; margin-top:2px; }
.ring-label{ font-size:11px; color:#4B5563; margin-top:6px; font-weight:700; text-transform:uppercase; letter-spacing:0.3px; }

.empty-state { text-align:center; padding:40px 20px; color:#4B5563; }
.empty-state .empty-icon { font-size:30px; margin-bottom:10px; display:block; }
.empty-state p { font-size:13px; }

.trx-row { display:flex; justify-content:space-between; align-items:center; padding:11px 8px; border-bottom:1px solid var(--border); border-radius:8px; margin:0 -8px; }
.trx-row:last-child { border-bottom:none; }
.trx-row:hover { background:var(--sky-pale); }

/* Modal Detail */
.detail-header { display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:16px; padding-bottom:14px; border-bottom:1px solid var(--border); }
.detail-nota  { font-size:13px; font-weight:700; color:var(--sky-deep); font-family:monospace; }
.detail-nama  { font-size:17px; font-weight:700; color:#101418; margin:4px 0 6px; }
.detail-meta  { font-size:12px; color:#4B5563; }
.detail-table { width:100%; border-collapse:collapse; font-size:13px; margin-bottom:14px; }
.detail-table th { background:var(--sky-pale); color:#3A4450; font-size:11px; font-weight:700; padding:8px 10px; text-align:left; border-bottom:1px solid var(--border); }
.detail-table td { padding:10px 10px; border-bottom:1px solid var(--sky-pale); color:#101418; vertical-align:top; }
.detail-table tr:last-child td { border-bottom:none; }
.detail-table .td-right { text-align:right; font-weight:700; color:#101418; }
.detail-total-row { display:flex; justify-content:space-between; align-items:center; padding:12px 14px; background:var(--sky-soft); border-radius:10px; margin-top:4px; }
.detail-section-title { font-size:11px; font-weight:700; color:#3A4450; text-transform:uppercase; letter-spacing:0.4px; margin-bottom:8px; }
.detail-bayar-row { display:flex; justify-content:space-between; align-items:center; gap:10px; padding:9px 12px; background:var(--sky-pale); border:1px solid var(--border); border-radius:9px; margin-bottom:7px; }
.detail-bayar-row:last-of-type { margin-bottom:0; }
.detail-bayar-label { font-size:11.5px; font-weight:700; color:#101418; }
.detail-bayar-metode { font-size:11.5px; color:#3A4450; margin-top:2px; }
.detail-bayar-jumlah { font-size:13px; font-weight:700; color:var(--sky-deep); }
.detail-bayar-tgl { font-size:10.5px; color:#4B5563; margin-top:2px; }
.detail-total-label { font-size:13px; font-weight:700; color:#101418; }
.detail-total-num   { font-size:18px; font-weight:800; color:var(--sky-deep); }
.detail-badges { display:flex; gap:8px; margin-top:4px; flex-wrap:wrap; }
.detail-loading { text-align:center; padding:30px; color:#4B5563; font-size:13px; }
.detail-error   { text-align:center; padding:20px; color:#101418; font-size:13px; }
.trx-nota  { font-size:13px; font-weight:700; color:var(--sky-deep); font-family:monospace; }
.trx-nama  { font-size:12px; color:#3A4450; margin-top:2px; }
.trx-tgl   { font-size:10px; color:#4B5563; margin-top:2px; }
.trx-harga { font-size:13px; font-weight:700; color:var(--sky-deep); text-align:right; }
.trx-bayar { font-size:11px; margin-top:3px; text-align:right; }
.badge { display:inline-block; padding:4px 10px; border-radius:20px; font-size:11px; font-weight:700; margin-top:4px; }

.chart-container { position:relative; height:200px; width:100%; }

/* ── RANKING KATEGORI TERLAMBAT ── */
.rank-item { margin-bottom:12px; }
.rank-item:last-child { margin-bottom:0; }
.rank-top { display:flex; justify-content:space-between; align-items:center; margin-bottom:5px; font-size:12.5px; }
.rank-name { font-weight:600; color:#101418; display:flex; align-items:center; gap:6px; }
.rank-no { width:18px; height:18px; border-radius:5px; background:var(--sky-soft); color:var(--sky-deep); font-size:10px; font-weight:800; display:inline-flex; align-items:center; justify-content:center; flex-shrink:0; }
.rank-count { font-weight:700; color:#101418; }
.rank-bar-track { background:var(--sky-pale); border-radius:20px; height:7px; overflow:hidden; }
.rank-bar-fill { background:#101418; height:100%; border-radius:20px; }

.btn { display:inline-flex; align-items:center; gap:6px; padding:8px 16px; border-radius:9px; font-size:13px; font-weight:700; cursor:pointer; border:none; transition:all 0.18s; font-family:inherit; text-decoration:none; }
.btn-sm     { padding:5px 10px; font-size:11px; border-radius:7px; }
.btn-excel  { background:#1F5FA6; color:#FFFFFF; }
.btn-pdf    { background:#101418; color:#FFFFFF; text-decoration:none; display:inline-flex; align-items:center; gap:6px; }
.btn-pdf:hover { background:#101418; color:#FFFFFF; }
.btn-excel:hover { background:#1F5FA6; }

.modal-overlay { display:none; position:fixed; inset:0; background:rgba(22,74,133,0.45); z-index:200; align-items:center; justify-content:center; padding:16px; }
.modal-overlay.active { display:flex; }
.modal { background:var(--cloud); border-radius:16px; width:100%; max-width:480px; padding:24px; position:relative; max-height:90vh; overflow-y:auto; }
.modal-close { position:absolute; top:16px; right:16px; background:none; border:none; font-size:20px; cursor:pointer; color:#4B5563; line-height:1; }

.warning-bar { background:var(--sky-pale); border:1px solid var(--border); border-left:4px solid var(--ink); border-radius:12px; padding:12px 16px; margin-bottom:20px; display:flex; align-items:center; gap:10px; font-size:13px; color:#101418; font-weight:600; }

@media(max-width:1200px) { .stat-grid{grid-template-columns:repeat(3,1fr);} }
@media(max-width:1024px) { .stat-grid{grid-template-columns:repeat(2,1fr);} .main-grid{grid-template-columns:1fr;} }
@media(max-width:600px)  {
    .stat-grid{grid-template-columns:repeat(2,1fr); gap:8px;}
    .stat-card{padding:12px 14px;}
    .stat-num{font-size:20px;}
    .stat-delta{font-size:10.5px;}
    .ringkasan-bar{grid-template-columns:1fr 1fr;}
    .periode-tabs{flex-wrap:wrap;}
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
      <small>Owner Panel</small>
    </div>
  </div>
  <div class="sidebar-menu">
    <a href="dashboard_owner.php" class="active">Dashboard</a>
    <a href="laporan_barang.php">Rekap Frekuensi Cucian</a>
    <a href="kelola_barang.php">Kelola Kategori &amp; Cucian</a>
    <a href="kelola_karyawan.php">Kelola Karyawan</a>
  </div>
  <div class="sidebar-footer">
    <a href="logout.php">Logout</a>
  </div>
</div>

<div class="main">
  <div class="topbar">
    <div class="topbar-title">
      Dashboard Owner
      <small>Laporan pendapatan &amp; ringkasan transaksi</small>
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

  <?php if (isset($_GET['ok'])): ?><div class="badge" style="background:#D6E7F7;color:#1B2126;display:block;margin-bottom:16px;padding:12px 16px;font-size:13px"><?= htmlspecialchars($_GET['ok']) ?></div><?php endif; ?>
  <?php if ($terlambat > 0): ?>
  <div class="warning-bar"><span>Ada <strong><?= $terlambat ?> transaksi</strong> yang melewati estimasi selesai dan belum siap diambil.</span></div>
  <?php endif; ?>

  <!-- STATISTIK -->
  <div class="stat-grid">
    <div class="stat-card">
      <div class="stat-label">Transaksi Hari Ini</div>
      <div class="stat-num"><?= $trx_hari_ini ?></div>
      <div class="stat-delta <?= $pct_trx>=0?'up':'down' ?>"><?= $pct_trx>=0?'↑':'↓' ?> <?= abs($pct_trx) ?>% dari kemarin</div>
    </div>
    <div class="stat-card">
      <div class="stat-label">Pendapatan Hari Ini</div>
      <div class="stat-num sm"><?= rupiah($pend_hari_ini) ?></div>
      <div class="stat-delta <?= $pct_pend>=0?'up':'down' ?>"><?= $pct_pend>=0?'↑':'↓' ?> <?= abs($pct_pend) ?>% dari kemarin</div>
    </div>
    <div class="stat-card warn">
      <div class="stat-label">Dalam Antrian</div>
      <div class="stat-num"><?= $antrian ?></div>
      <div class="stat-delta neu">belum mulai diproses</div>
    </div>
    <div class="stat-card info">
      <div class="stat-label">Sedang Diproses</div>
      <div class="stat-num"><?= $diproses ?></div>
      <div class="stat-delta neu">sedang dikerjakan</div>
    </div>
    <div class="stat-card">
      <div class="stat-label">Siap Diambil</div>
      <div class="stat-num"><?= $siap ?></div>
      <div class="stat-delta neu">menunggu diambil pelanggan</div>
    </div>
    <div class="stat-card <?= $terlambat>0?'danger':'' ?>">
      <div class="stat-label">Terlambat</div>
      <div class="stat-num"><?= $terlambat ?></div>
      <div class="stat-delta <?= $terlambat>0?'down':'neu' ?>"><?= $terlambat>0 ? 'lewat estimasi selesai' : 'semua tepat waktu' ?></div>
    </div>
  </div>

  <!-- TRANSAKSI + CHART -->
  <div class="main-grid">

    <div class="card">
      <div class="card-header">
        <div style="display:flex;flex-direction:column;gap:6px">
          <div class="card-title">Daftar Transaksi</div>
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
          </div>
        </div>
        <div>
          <a class="btn btn-pdf btn-sm" href="laporan_pdf.php?periode=<?= urlencode($periode) ?><?= $periode==='custom' ? '&mulai='.urlencode($mulai_custom).'&akhir='.urlencode($akhir_custom) : '' ?>" target="_blank">Cetak PDF</a>
          <button class="btn btn-excel btn-sm" onclick="unduhTransaksi()">Excel</button>
        </div>
      </div>

      <!-- RINGKASAN MINI -->
      <div class="ringkasan-bar">
        <div class="ring-item">
          <div class="ring-num"><?= $ringkasan['total_trx'] ?></div>
          <div class="ring-label">Total Transaksi</div>
        </div>
        <div class="ring-item lunas">
          <div class="ring-num green"><?= $ringkasan['jml_lunas'] ?></div>
          <div class="ring-sub"><?= rupiah($ringkasan['total_diterima']) ?> diterima</div>
          <div class="ring-label">Sudah Lunas</div>
        </div>
        <div class="ring-item dp">
          <div class="ring-num orange"><?= $ringkasan['jml_dp'] ?></div>
          <div class="ring-sub"><?= rupiah($ringkasan['sisa_dp']) ?> sisa tagihan</div>
          <div class="ring-label">DP</div>
        </div>
        <div class="ring-item belum">
          <div class="ring-num red"><?= $ringkasan['jml_belum'] ?></div>
          <div class="ring-sub"><?= rupiah($ringkasan['sisa_belum']) ?> sisa tagihan</div>
          <div class="ring-label">Belum Lunas</div>
        </div>
      </div>

      <!-- PENCARIAN NO. NOTA -->
      <div class="search-nota-wrap">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
        <input type="text" id="inputCariNota" placeholder="Cari No. Nota (mis. AZK2608...)" oninput="cariNota()" autocomplete="off">
        <button type="button" id="btnClearCari" onclick="clearCariNota()" style="display:none">&times;</button>
      </div>
      <div id="cariNotaEmpty" style="display:none;padding:16px;text-align:center;color:#4B5563;font-size:13px">Tidak ada nota yang cocok dengan pencarian ini di daftar yang sedang ditampilkan.</div>

      <?php if (empty($transaksi)): ?>
      <div class="empty-state">
                <p>Tidak ada transaksi untuk <strong><?= $label_periode ?></strong></p>
      </div>
      <?php else: ?>
      <div class="trx-list-wrap">
      <?php foreach ($transaksi as $t):
        $st = statusBadge($t['status'] ?? 'dalam_antrian');
        $tgl_fmt = date('d/m/Y H:i', strtotime($t['created_at']));
      ?>
      <div class="trx-row" data-nota="<?= htmlspecialchars(strtolower($t['id_nota']), ENT_QUOTES) ?>" data-nama="<?= htmlspecialchars(strtolower($t['nama_pelanggan']), ENT_QUOTES) ?>" onclick="lihatDetail('<?= htmlspecialchars($t['id_nota'],ENT_QUOTES) ?>')" style="cursor:pointer;" title="Klik untuk lihat detail">
        <div>
          <div class="trx-nota"><?= htmlspecialchars($t['id_nota']) ?></div>
          <div class="trx-nama"><?= htmlspecialchars($t['nama_pelanggan']) ?></div>
          <?php if ($periode !== 'hari_ini'): ?>
          <div class="trx-tgl"><?= $tgl_fmt ?></div>
          <?php endif; ?>
          <span class="badge" style="background:<?= $st['bg'] ?>;color:<?= $st['color'] ?>"><?= $st['label'] ?></span>
        </div>
        <div style="display:flex;align-items:center;gap:10px">
          <div style="text-align:right">
            <div class="trx-harga"><?= rupiah($t['total_harga']) ?></div>
            <?php if ($t['status_bayar'] === 'sudah_bayar'): ?>
            <div class="trx-bayar up">Lunas</div>
            <?php elseif ($t['status_bayar'] === 'dp'): ?>
            <div class="trx-bayar down">Kurang <?= rupiah(sisaBayar($t['total_harga'], $t['total_dibayar'])) ?></div>
            <?php else: ?>
            <div class="trx-bayar down">Belum Bayar</div>
            <?php endif; ?>
          </div>
                  </div>
      </div>
      <?php endforeach; ?>
      </div>
      <?php if (count($transaksi) >= $limit_trx): ?>
      <div style="text-align:center;padding:10px 0 0;font-size:12px;color:#4B5563">Menampilkan <?= count($transaksi) ?> transaksi terbaru — unduh Excel untuk data lengkap</div>
      <?php endif; ?>
      <?php endif; ?>
    </div>

    <!-- CHART PENDAPATAN (kolom kanan) -->
    <div style="display:flex;flex-direction:column;gap:16px">

      <div class="card">
        <div class="card-header">
          <div class="card-title">Pendapatan 7 Hari</div>
        </div>
        <div class="chart-container">
          <canvas id="chartPendapatan"></canvas>
        </div>
      </div>

    </div>

  </div>
</div>
  </div>
</div>

<!-- MODAL DETAIL TRANSAKSI -->
<div class="modal-overlay" id="modalDetail">
  <div class="modal" style="max-width:540px">
    <button class="modal-close" onclick="tutupModal('modalDetail')">&times;</button>
    <div id="detailContent">
      <div class="detail-loading">Memuat detail transaksi...</div>
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
// ── DROPDOWN TAB "Hari ▾" / "Bulan ▾" ──
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
    // Rentang tahun yang bisa dipilih di panel Tahun: mulai dari 2026 (awal
    // data transaksi sistem ini) s/d 1 tahun ke depan dari tahun berjalan.
    const TAHUN_MIN = 2026;
    const TAHUN_MAX = now.getFullYear() + 1;

    let selectedYear = now.getFullYear(); // tahun yang lagi ditampilkan (12 bulan Jan-Des tahun ini)

    // Modal kalender ini dipakai buat pilih RENTANG tanggal bebas (klik
    // tanggal mulai, lalu klik tanggal akhir -- persis seperti kalender
    // check-in/check-out). Kalau cuma 1 tanggal yang diklik (kalAkhir masih
    // kosong) waktu "Terapkan" ditekan, dianggap 1 hari (mulai = akhir).
    const mulaiAwal = <?= $mulaiCustomJs ?>;
    const akhirAwal = <?= $akhirCustomJs ?>;
    let kalMulai = mulaiAwal || null; // 'YYYY-MM-DD'
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

    // ── LABEL TOMBOL "Bulan"/"Tahun" DI ATAS: selalu nunjukin bulan & tahun
    //    yang lagi kelihatan di layar, ter-update otomatis waktu di-scroll. ──
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
            // Blok yang judulnya sudah lewat/pas di puncak area scroll -> itu
            // bulan yang lagi dibaca user sekarang.
            if (b.offsetTop - body.scrollTop <= 36) current = b;
        });
        if (!current) return;
        const [, y, m] = current.id.split('-'); // id: kalMonthBlock-<tahun>-<bulanIdx>
        setBtnBulanLabel(parseInt(m, 10));
        setBtnTahunLabel(parseInt(y, 10));
    }

    // ── PANEL PILIH TAHUN (list tap, terbaru di atas) ──
    function renderTahunPanel() {
        let html = '<div class="kal-picker-list">';
        for (let y = TAHUN_MAX; y >= TAHUN_MIN; y--) {
            html += `<div class="kal-picker-item ${y === selectedYear ? 'active' : ''}" onclick="pilihTahunKalender(${y})">${y}</div>`;
        }
        html += '</div>';
        document.getElementById('kalPanelTahun').innerHTML = html;
    }

    // ── PANEL PILIH BULAN (grid 3 kolom, loncat ke blok bulan itu) ──
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
        // Setelah ganti tahun, langsung loncat ke Januari tahun itu.
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

    // Update label Bulan/Tahun otomatis mengikuti scroll (di-throttle lewat
    // requestAnimationFrame biar tidak berat saat scroll cepat).
    document.getElementById('kalBody').addEventListener('scroll', function () {
        if (scrollTicking) return;
        scrollTicking = true;
        requestAnimationFrame(() => { updateLabelDariScroll(); scrollTicking = false; });
    });

    function renderBulan(year, month) {
        const first = new Date(year, month, 1);
        const startOffset = (first.getDay() + 6) % 7; // Senin = 0
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
        // Kalender rentang (klik tanggal mulai, lalu klik tanggal akhir):
        // - Belum ada tanggal terpilih, ATAU rentang sebelumnya sudah lengkap
        //   (mulai & akhir sudah ke-set) -> klik ini jadi tanggal MULAI baru,
        //   mulai rentang baru dari nol.
        // - Sudah ada tanggal mulai tapi akhir belum -> klik ini jadi tanggal
        //   AKHIR. Kalau tanggal yang diklik lebih awal dari tanggal mulai,
        //   tukar posisinya (biar mulai selalu <= akhir).
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
        // Tampilkan tahun dari tanggal yang sudah dipilih (kalau ada), atau tahun berjalan.
        const acuan = kalMulai ? new Date(kalMulai) : now;
        selectedYear = acuan.getFullYear();
        document.getElementById('kalPanelBulan').style.display = 'none';
        document.getElementById('kalPanelTahun').style.display = 'none';
        setBtnBulanLabel(acuan.getMonth());
        setBtnTahunLabel(selectedYear);
        renderKalender();
        document.getElementById('modalKalender').classList.add('active');
        // Loncat ke bulan acuan begitu modal & scroll container sudah kebentuk.
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
// ── DATA ──
const transaksiData = <?= $transaksi_json ?>;
const chartLabels   = <?= $chartLabelsJs ?>;
const chartData     = <?= $chartDataJs ?>;
const periodeAktif  = <?= $periodeJs ?>;
const labelPeriode  = <?= $labelPeriodeJs ?>;

// ── CHART ──
const ctx = document.getElementById('chartPendapatan').getContext('2d');
new Chart(ctx, {
    type: 'bar',
    data: {
        labels: chartLabels,
        datasets: [{
            label: 'Pendapatan (Rp)',
            data: chartData,
            backgroundColor: chartData.map(v =>
                v > 0 ? 'rgba(46,116,192,0.75)' : 'rgba(214,231,247,0.8)'
            ),
            borderColor: chartData.map(v =>
                v > 0 ? '#1F5FA6' : '#D6E7F7'
            ),
            borderWidth: 1.5,
            borderRadius: 8,
            borderSkipped: false,
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false },
            tooltip: {
                callbacks: {
                    label: ctx => 'Rp ' + ctx.raw.toLocaleString('id-ID')
                }
            }
        },
        scales: {
            x: {
                grid: { display: false },
                ticks: { font: { size: 11 }, color: '#4E90D9' }
            },
            y: {
                beginAtZero: true,
                grid: { color: '#D6E7F7' },
                ticks: {
                    font: { size: 10 },
                    color: '#4E90D9',
                    callback: v => v >= 1000 ? 'Rp'+(v/1000)+'rb' : 'Rp'+v
                }
            }
        }
    }
});

// ── MODAL ──
function tutupModal(id) { document.getElementById(id).classList.remove('active'); }
document.querySelectorAll('.modal-overlay').forEach(el => {
    el.addEventListener('click', e => { if (e.target===el) el.classList.remove('active'); });
});

// ── DETAIL TRANSAKSI ──
function rupiah(n) {
    return 'Rp ' + parseInt(n).toLocaleString('id-ID');
}

// Pencarian No. Nota -- filter KLIEN-SIDE, cuma menyaring baris yang sudah
// ter-render di daftar transaksi (yang sudah dibatasi periode aktif di
// atas). Kalau nota yang dicari ada di luar periode/limit yang sedang
// ditampilkan, ganti dulu periode-nya (mis. ke "Tahun") baru cari lagi.
function cariNota() {
    const q = document.getElementById('inputCariNota').value.trim().toLowerCase();
    document.getElementById('btnClearCari').style.display = q ? 'block' : 'none';
    const rows = document.querySelectorAll('.trx-row');
    let adaYangCocok = false;
    rows.forEach(row => {
        const nota = row.dataset.nota || '';
        const nama = row.dataset.nama || '';
        const cocok = q === '' || nota.includes(q) || nama.includes(q);
        row.style.display = cocok ? '' : 'none';
        if (cocok) adaYangCocok = true;
    });
    document.getElementById('cariNotaEmpty').style.display = (q && !adaYangCocok) ? 'block' : 'none';
}

function clearCariNota() {
    document.getElementById('inputCariNota').value = '';
    cariNota();
}

async function lihatDetail(idNota) {
    const el = document.getElementById('detailContent');
    el.innerHTML = '<div class="detail-loading">Memuat detail transaksi...</div>';
    document.getElementById('modalDetail').classList.add('active');

    // Path dinamis — ambil dari lokasi halaman ini
    const base = window.location.pathname.substring(0, window.location.pathname.lastIndexOf('/') + 1);
    const url  = base + 'get_detail_transaksi.php?id_nota=' + encodeURIComponent(idNota);

    try {
        const res = await fetch(url, { credentials: 'same-origin' });

        if (!res.ok) {
            el.innerHTML = `<div class="detail-error">Server error: HTTP ${res.status}<br><small style="color:#4B5563">Pastikan file <code>get_detail_transaksi.php</code> sudah ada di folder yang sama.</small></div>`;
            return;
        }

        const text = await res.text();
        let data;
        try {
            data = JSON.parse(text);
        } catch(e) {
            el.innerHTML = `<div class="detail-error">Response tidak valid.<br><small style="color:#4B5563;font-family:monospace;font-size:10px">${text.substring(0,200)}</small></div>`;
            return;
        }

        if (!data.success) {
            el.innerHTML = `<div class="detail-error">${data.message ?? 'Gagal memuat data.'}</div>`;
            return;
        }

        const t  = data.transaksi;
        const st = statusBadgeJs(t.status ?? 'dalam_antrian');
        let bayarBadge;
        if (t.status_bayar === 'sudah_bayar') {
            bayarBadge = '<span class="badge" style="background:#D6E7F7;color:#1B2126">Sudah Lunas</span>';
        } else if (t.status_bayar === 'dp') {
            bayarBadge = '<span class="badge" style="background:#D6E7F7;color:#3A4450">DP ' + rupiah(t.total_dibayar) + ' &middot; Kurang ' + rupiah(t.sisa_bayar) + '</span>';
        } else {
            bayarBadge = '<span class="badge" style="background:#D6E7F7;color:#3A4450">Belum Bayar</span>';
        }
        const tglMasuk = t.created_at ? new Date(t.created_at).toLocaleString('id-ID',{day:'2-digit',month:'long',year:'numeric',hour:'2-digit',minute:'2-digit'}) : '-';
        const tglDiambil = t.tanggal_diambil ? new Date(t.tanggal_diambil).toLocaleString('id-ID',{day:'2-digit',month:'long',year:'numeric',hour:'2-digit',minute:'2-digit'}) : null;
        const tglEstimasi = t.tanggal_estimasi ?? '-';

        let itemsHtml = '';
        if (data.items && data.items.length > 0) {
            data.items.forEach(item => {
                itemsHtml += `
                <tr>
                    <td>${item.nama_layanan}</td>
                    <td style="text-align:center;color:#3A4450">${item.jumlah} ${item.satuan}</td>
                    <td class="td-right">${rupiah(item.harga_satuan)}</td>
                    <td class="td-right">${rupiah(item.subtotal)}</td>
                </tr>`;
            });
        } else {
            itemsHtml = '<tr><td colspan="4" style="text-align:center;color:#4B5563;padding:16px">Tidak ada item</td></tr>';
        }

        const catatanHtml = t.catatan
            ? `<div style="margin-top:12px;padding:10px 12px;background:#EAF2FB;border-radius:8px;border-left:3px solid #3A4450;font-size:12px;color:#3A4450"><strong>Catatan:</strong> ${t.catatan}</div>`
            : '';

        // Rincian pembayaran (metode + nama/no. pengirim kalau transfer) --
        // supaya owner bisa menelusuri transfer tanpa buka database langsung.
        let pembayaranHtml = '';
        if (data.pembayaran && data.pembayaran.length > 0) {
            const barisPembayaran = data.pembayaran.map((p, i) => {
                const label = i === 0 ? 'DP / Pembayaran 1' : ('Pembayaran ' + (i + 1));
                const tglBayar = p.created_at ? new Date(p.created_at).toLocaleDateString('id-ID',{day:'2-digit',month:'short',year:'numeric'}) : '-';
                let metodeTxt = p.metode === 'transfer' ? 'Transfer' : 'Tunai';
                if (p.metode === 'transfer' && p.nama_pengirim) {
                    metodeTxt = 'Transfer &middot; a.n. ' + p.nama_pengirim + (p.no_pengirim ? ' (' + p.no_pengirim + ')' : '');
                }
                return `
                <div class="detail-bayar-row">
                    <div>
                        <div class="detail-bayar-label">${label}</div>
                        <div class="detail-bayar-metode">${metodeTxt}</div>
                    </div>
                    <div style="text-align:right">
                        <div class="detail-bayar-jumlah">${rupiah(p.jumlah_bayar)}</div>
                        <div class="detail-bayar-tgl">${tglBayar}</div>
                    </div>
                </div>`;
            }).join('');
            pembayaranHtml = `
                <div class="detail-section-title" style="margin-top:14px">Rincian Pembayaran</div>
                ${barisPembayaran}`;
        }

        el.innerHTML = `
            <div class="detail-header">
                <div>
                    <div class="detail-nota">${t.id_nota}</div>
                    <div class="detail-nama">${t.nama_pelanggan}</div>
                    <div class="detail-meta">${t.no_hp ?? '-'} &nbsp;·&nbsp; Masuk: ${tglMasuk}</div>
                    ${tglDiambil ? `<div class="detail-meta" style="margin-top:3px">Diambil: ${tglDiambil}</div>` : ''}
                    <div class="detail-meta" style="margin-top:3px">Estimasi selesai: ${tglEstimasi}</div>
                    <div class="detail-badges">
                        <span class="badge" style="background:${st.bg};color:${st.color}">${st.label}</span>
                        ${bayarBadge}
                    </div>
                </div>
            </div>

            <table class="detail-table">
                <thead>
                    <tr>
                        <th>Barang</th>
                        <th style="text-align:center">Jml</th>
                        <th style="text-align:right">Harga/Satuan</th>
                        <th style="text-align:right">Subtotal</th>
                    </tr>
                </thead>
                <tbody>${itemsHtml}</tbody>
            </table>

            <div class="detail-total-row">
                <div class="detail-total-label">Total Pembayaran</div>
                <div class="detail-total-num">${rupiah(t.total_harga)}</div>
            </div>
            ${pembayaranHtml}
            ${catatanHtml}
        `;
    } catch(err) {
        el.innerHTML = `<div class="detail-error">Gagal terhubung ke server.<br><small style="color:#4B5563">${err.message}</small></div>`;
    }
}

function statusBadgeJs(status) {
    const map = {
        'dalam_antrian'  : { label:'Dalam Antrian', bg:'#D6E7F7', color:'#3A4450' },
        'sedang_diproses': { label:'Diproses',      bg:'#EAF2FB', color:'#2E74C0' },
        'siap_diambil'   : { label:'Siap Diambil',  bg:'#D6E7F7', color:'#1F5FA6' },
        'selesai'        : { label:'Selesai',       bg:'#EAF2FB', color:'#2E74C0' },
    };
    return map[status] ?? { label: status, bg:'#EAF2FB', color:'#164A85' };
}

function unduhTransaksi() {
    const rows = transaksiData.map(t => {
        const total   = parseInt(t.total_harga) || 0;
        const dibayar = parseInt(t.total_dibayar) || 0;
        const sisa    = Math.max(total - dibayar, 0);
        const statusTxt = t.status_bayar === 'sudah_bayar' ? 'Lunas'
                         : t.status_bayar === 'dp' ? 'DP (Bayar Sebagian)'
                         : 'Belum Bayar';
        return {
            'No. Nota'      : t.id_nota,
            'Nama Pelanggan': t.nama_pelanggan,
            'Total (Rp)'    : total,
            'Sudah Dibayar (Rp)': dibayar,
            'Sisa (Rp)'     : sisa,
            'Status Bayar'  : statusTxt,
            'Status Cucian' : t.status ?? 'dalam_antrian',
            'Tanggal'       : t.created_at
        };
    });
    const ws = XLSX.utils.json_to_sheet(rows);
    ws['!cols'] = [{wch:16},{wch:28},{wch:14},{wch:16},{wch:14},{wch:18},{wch:18},{wch:22}];
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, 'Transaksi ' + labelPeriode);
    XLSX.writeFile(wb, `transaksi_azka_laundry_${periodeAktif}.xlsx`);
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