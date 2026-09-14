<?php
/**
 * LAPORAN REKAP PENDAPATAN — versi cetak (kop surat) untuk Owner.
 *
 * Ini BUKAN generator PDF lewat library (TCPDF/mPDF dll — tidak dipasang
 * di project ini), melainkan halaman HTML yang dirancang khusus untuk
 * dicetak / disimpan sebagai PDF lewat dialog Print bawaan browser
 * (Ctrl+P → "Save as PDF" / "Simpan sebagai PDF"). Ini pendekatan yang
 * umum dipakai untuk laporan berkop-surat tanpa dependency tambahan.
 */
require_once __DIR__ . '/includes/config.php';
requireRole('owner');

function rupiahPdf($n): string {
    return 'Rp ' . number_format((float)$n, 0, ',', '.');
}

// ── FILTER PERIODE (sama seperti dashboard_owner.php) ──
$periode = $_GET['periode'] ?? 'hari_ini';
$allowed = ['hari_ini', 'minggu_ini', 'bulan_ini', 'tahun_ini', 'tahun_lalu', 'custom'];
if (!in_array($periode, $allowed, true)) $periode = 'hari_ini';

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

$where_periode = match ($periode) {
    'hari_ini'   => "DATE(t.created_at) = CURDATE()",
    'minggu_ini' => "YEARWEEK(t.created_at, 1) = YEARWEEK(CURDATE(), 1)",
    'bulan_ini'  => "YEAR(t.created_at) = YEAR(CURDATE()) AND MONTH(t.created_at) = MONTH(CURDATE())",
    'tahun_ini'  => "YEAR(t.created_at) = YEAR(CURDATE())",
    'tahun_lalu' => "YEAR(t.created_at) = YEAR(CURDATE()) - 1",
    // $mulai_custom/$akhir_custom sudah lolos tanggalValidOrNull() di atas.
    'custom'     => "DATE(t.created_at) BETWEEN '$mulai_custom' AND '$akhir_custom'",
};
// Label periode=custom dibikin "pintar" -- sama seperti dashboard_owner.php --
// supaya judul laporan nyambung dgn tab Hari/Bulan/Tanggal/Tahun yang diklik
// owner di dashboard (bukan cuma nampilin rentang tanggal mentah).
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
    'custom'     => labelPeriodeCustom($mulai_custom, $akhir_custom),
};

// ── RINGKASAN ──
$stmt = $pdo->prepare("
    SELECT
        COUNT(*) AS total_trx,
        COALESCE(SUM(t.total_dibayar), 0) AS total_diterima,
        COALESCE(SUM(t.total_harga - t.total_dibayar), 0) AS total_belum,
        COALESCE(SUM(t.total_harga), 0) AS total_omzet,
        COALESCE(SUM(CASE WHEN t.status_bayar='sudah_bayar' THEN 1 ELSE 0 END), 0) AS jml_lunas,
        COALESCE(SUM(CASE WHEN t.status_bayar='dp' THEN 1 ELSE 0 END), 0) AS jml_dp,
        COALESCE(SUM(CASE WHEN t.status_bayar='belum_bayar' THEN 1 ELSE 0 END), 0) AS jml_belum
    FROM transaksi t
    WHERE $where_periode
");
$stmt->execute();
$ringkasan = $stmt->fetch();

// ── BREAKDOWN METODE BAYAR (Tunai vs Transfer) ──
// Dipakai owner untuk rekonsiliasi kas: berapa yang harusnya ada di laci
// kasir (tunai) vs berapa yang harus muncul di rekening bank (transfer).
// Difilter dengan periode YANG SAMA seperti ringkasan di atas (berdasar
// transaksi.created_at, bukan tanggal pembayaran) supaya angkanya
// konsisten & bisa dijumlah balik ke "PENDAPATAN DITERIMA" di atas.
$stmtMetode = $pdo->prepare("
    SELECT p.metode, COALESCE(SUM(p.jumlah_bayar), 0) AS total
    FROM pembayaran p
    JOIN transaksi t ON t.id_transaksi = p.transaksi_id
    WHERE $where_periode
    GROUP BY p.metode
");
$stmtMetode->execute();
$metodeRows = $stmtMetode->fetchAll();
$totalTunai = 0.0;
$totalTransfer = 0.0;
foreach ($metodeRows as $m) {
    if ($m['metode'] === 'transfer') $totalTransfer = (float) $m['total'];
    else $totalTunai = (float) $m['total'];
}
$totalMetodeGabungan = $totalTunai + $totalTransfer;
$pctTunai    = $totalMetodeGabungan > 0 ? round($totalTunai / $totalMetodeGabungan * 100) : 0;
$pctTransfer = $totalMetodeGabungan > 0 ? (100 - $pctTunai) : 0;

// ── RINCIAN TRANSAKSI (urut lama -> baru, biar enak dibaca seperti laporan) ──
$stmt2 = $pdo->prepare("
    SELECT t.id_transaksi, t.id_nota, t.nama_pelanggan, t.total_harga, t.total_dibayar, t.status_bayar, t.created_at
    FROM transaksi t
    WHERE $where_periode
    ORDER BY t.created_at ASC
    LIMIT 1000
");
$stmt2->execute();
$daftar = $stmt2->fetchAll();

// Ambil SEMUA baris pembayaran untuk transaksi-transaksi di atas dalam 1
// query (bukan query per-baris di dalam loop tabel -- bisa sampai 1000
// baris kalau LIMIT-nya kena), lalu di-groupBy transaksi_id di PHP supaya
// teksMetodeBayarGabungan() bisa langsung dipanggil per transaksi tanpa
// query tambahan sama sekali.
$pembayaranPerTrx = [];
if (!empty($daftar)) {
    $idsTrx = array_column($daftar, 'id_transaksi');
    $inTrx  = implode(',', array_map('intval', $idsTrx));
    $stmtBayarSemua = $pdo->query("
        SELECT transaksi_id, jumlah_bayar, metode, nama_pengirim, no_pengirim
        FROM pembayaran
        WHERE transaksi_id IN ($inTrx)
        ORDER BY created_at ASC, id_pembayaran ASC
    ");
    foreach ($stmtBayarSemua->fetchAll() as $b) {
        $pembayaranPerTrx[$b['transaksi_id']][] = $b;
    }
}

$namaOwner   = $_SESSION['user']['name'] ?? 'Owner';
$waktuCetak  = date('d/m/Y H:i');
$namaUsaha   = 'Azka Laundry';
$alamatUsaha = 'Jl. Cisitu Lama 1 No. 9A, Bandung';
$teleponUsaha= '0895-1215-7474';
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Laporan Rekap Pendapatan - <?= htmlspecialchars($label_periode) ?></title>
<style>
  @page { size: A4; margin: 16mm 14mm; }
  * { box-sizing: border-box; }
  body {
    font-family: 'Segoe UI', Arial, sans-serif;
    color: #101418;
    font-size: 12.5px;
    margin: 0;
    padding: 0;
    background: #F3F4F6;
  }
  .sheet {
    max-width: 800px;
    margin: 24px auto;
    background: #FFFFFF;
    padding: 28px 34px 40px;
    box-shadow: 0 4px 24px rgba(16,20,24,0.12);
  }

  /* ── KOP SURAT ── */
  .kop { position:relative; display:flex; justify-content:center; align-items:center; border-bottom:3px solid #101418; padding-bottom:14px; margin-bottom:18px; min-height:58px; }
  .kop-logo { position:absolute; left:0; top:50%; transform:translateY(-50%); }
  .kop-text { text-align:center; }
  .kop-nama { font-size:22px; font-weight:800; color:#101418; letter-spacing:0.3px; margin:0 0 2px; }
  .kop-tagline { font-size:11.5px; color:#3A4450; margin:0 0 6px; font-style:italic; }
  .kop-alamat { font-size:11.5px; color:#101418; margin:0; line-height:1.5; }

  .judul-laporan { text-align:center; margin: 6px 0 22px; }
  .judul-laporan h1 { font-size:16px; font-weight:800; text-decoration:underline; text-underline-offset:4px; margin:0 0 4px; letter-spacing:0.5px; }
  .judul-laporan .periode { font-size:12.5px; color:#101418; }

  .meta-row { display:flex; justify-content:space-between; font-size:11.5px; color:#101418; margin-bottom:16px; }

  /* ── RINGKASAN ── */
  .ringkasan-grid { display:grid; grid-template-columns: repeat(4, 1fr); gap:10px; margin-bottom:22px; }
  .ring-box { border:1px solid #D1D5DB; border-radius:8px; padding:10px 12px; background:#FFFFFF; }
  .ring-box .num { font-size:15px; font-weight:800; color:#101418; }
  .ring-box .lbl { font-size:10.5px; color:#3A4450; margin-top:2px; }
  .ring-box.hijau .num { color:#101418; }
  .ring-box.kuning .num { color:#101418; }
  .ring-box.merah .num { color:#101418; }

  /* ── BREAKDOWN METODE BAYAR ── */
  .metode-grid { display:grid; grid-template-columns: repeat(2, 1fr); gap:10px; margin-bottom:22px; }
  .metode-box { border:1px solid #D1D5DB; border-radius:8px; padding:12px 14px; background:#FFFFFF; }
  .metode-box .metode-top { display:flex; justify-content:space-between; align-items:baseline; margin-bottom:6px; }
  .metode-box .metode-lbl { font-size:11px; font-weight:700; color:#101418; text-transform:uppercase; letter-spacing:0.3px; }
  .metode-box .metode-pct { font-size:11px; color:#3A4450; font-weight:700; }
  .metode-box .metode-num { font-size:16px; font-weight:800; color:#101418; }
  .metode-bar-track { background:#F3F4F6; border-radius:20px; height:6px; overflow:hidden; margin-top:8px; }
  .metode-bar-fill { background:#101418; height:100%; border-radius:20px; }

  /* ── TABEL ── */
  table { width:100%; border-collapse:collapse; margin-bottom:8px; }
  thead th {
    background:#FFFFFF; color:#101418; font-size:10.5px; font-weight:700;
    text-align:left; padding:7px 8px; text-transform:uppercase; letter-spacing:0.3px;
    border-top:2px solid #101418; border-bottom:2px solid #101418;
  }
  thead th.num { text-align:right; }
  tbody td { padding:9px 8px; border-bottom:1px solid #E5E7EB; font-size:11.5px; vertical-align:top; color:#101418; }
  tbody td.num { text-align:right; font-variant-numeric: tabular-nums; }
  .status-chip { display:inline; padding:0; border-radius:0; font-size:11.5px; font-weight:700; background:none; }
  .st-lunas { color:#101418; }
  .st-dp    { color:#101418; font-style:italic; }
  .st-belum { color:#101418; text-decoration:underline; }

  tfoot td { padding:9px 8px; font-weight:800; font-size:12px; border-top:2px solid #101418; }
  tfoot td.num { text-align:right; }

  .empty-note { text-align:center; padding:24px; color:#4B5563; font-style:italic; }

  /* ── TANDA TANGAN ── */
  .ttd-wrap { display:flex; justify-content:space-between; margin-top:52px; padding:0 20px; }
  .ttd-box { text-align:center; width:220px; font-size:12px; }
  .ttd-space { height:60px; }
  .ttd-name { border-top:1px solid #101418; padding-top:4px; font-weight:700; }

  .footer-note { margin-top:28px; font-size:10px; color:#4B5563; text-align:center; border-top:1px dashed #D1D5DB; padding-top:10px; }

  /* ── TOMBOL CETAK (tidak ikut tercetak) ── */
  .print-bar { max-width:800px; margin:0 auto 0; padding:14px 34px 0; text-align:right; }
  .btn-print {
    background:#1F5FA6; color:#FFFFFF; border:none; padding:10px 20px; border-radius:8px;
    font-size:13px; font-weight:700; cursor:pointer; display:inline-flex; align-items:center; gap:8px;
  }
  .btn-print:hover { background:#1F5FA6; }
  .btn-back { background:#FFFFFF; color:#101418; border:1px solid #D6E7F7; padding:10px 16px; border-radius:8px; font-size:13px; font-weight:600; text-decoration:none; margin-right:8px; }

  @media print {
    body { background:#FFFFFF; }
    .sheet { box-shadow:none; margin:0; padding:0; max-width:none; }
    .print-bar { display:none; }
  }
</style>
</head>
<body>

<div class="print-bar">
  <a href="dashboard_owner.php?periode=<?= urlencode($periode) ?>" class="btn-back">&larr; Kembali</a>
  <button class="btn-print" onclick="window.print()">Cetak / Simpan sebagai PDF</button>
</div>

<div class="sheet">

  <!-- KOP SURAT -->
  <div class="kop">
    <div class="kop-logo">
      <svg width="58" height="58" viewBox="0 0 120 120" xmlns="http://www.w3.org/2000/svg">
        <defs>
          <linearGradient id="badgeGrad" x1="0%" y1="0%" x2="100%" y2="100%">
            <stop offset="0%" stop-color="#4E90D9"/>
            <stop offset="100%" stop-color="#2E74C0"/>
          </linearGradient>
        </defs>
        <circle cx="60" cy="60" r="58" fill="url(#badgeGrad)"/>
        <g transform="translate(60,62)">
          <circle cx="0" cy="2" r="26" fill="none" stroke="#FFFFFF" stroke-width="5"/>
          <circle cx="0" cy="5" r="14" fill="none" stroke="#FFFFFF" stroke-width="4.5"/>
          <rect x="-11" y="-31" width="22" height="8" rx="4" fill="#FFFFFF"/>
        </g>
      </svg>
    </div>
    <div class="kop-text">
      <p class="kop-nama"><?= htmlspecialchars(strtoupper($namaUsaha)) ?></p>
      <p class="kop-tagline">Bersih &middot; Rapih &middot; Wangi</p>
      <p class="kop-alamat">
        <?= htmlspecialchars($alamatUsaha) ?><br>
        Telp/WhatsApp: <?= htmlspecialchars($teleponUsaha) ?>
      </p>
    </div>
  </div>

  <!-- JUDUL -->
  <div class="judul-laporan">
    <h1>LAPORAN REKAP PENDAPATAN</h1>
    <div class="periode">Periode: <?= htmlspecialchars($label_periode) ?></div>
  </div>

  <div class="meta-row">
    <div>Dicetak oleh: <strong><?= htmlspecialchars($namaOwner) ?></strong></div>
    <div>Tanggal cetak: <?= $waktuCetak ?></div>
  </div>

  <!-- RINGKASAN -->
  <div class="ringkasan-grid">
    <div class="ring-box">
      <div class="num"><?= (int)$ringkasan['total_trx'] ?></div>
      <div class="lbl">TOTAL TRANSAKSI</div>
    </div>
    <div class="ring-box hijau">
      <div class="num"><?= rupiahPdf($ringkasan['total_diterima']) ?></div>
      <div class="lbl">PENDAPATAN DITERIMA</div>
    </div>
    <div class="ring-box kuning">
      <div class="num"><?= rupiahPdf($ringkasan['total_belum']) ?></div>
      <div class="lbl">PIUTANG / SISA TAGIHAN</div>
    </div>
    <div class="ring-box">
      <div class="num"><?= rupiahPdf($ringkasan['total_omzet']) ?></div>
      <div class="lbl">TOTAL OMZET (SELURUH NOTA)</div>
    </div>
  </div>

  <!-- BREAKDOWN METODE BAYAR (rekonsiliasi kas: tunai vs transfer) -->
  <div class="metode-grid">
    <div class="metode-box">
      <div class="metode-top">
        <span class="metode-lbl">Tunai (Cash)</span>
        <span class="metode-pct"><?= $pctTunai ?>%</span>
      </div>
      <div class="metode-num"><?= rupiahPdf($totalTunai) ?></div>
      <div class="metode-bar-track"><div class="metode-bar-fill" style="width:<?= $pctTunai ?>%"></div></div>
    </div>
    <div class="metode-box">
      <div class="metode-top">
        <span class="metode-lbl">Transfer</span>
        <span class="metode-pct"><?= $pctTransfer ?>%</span>
      </div>
      <div class="metode-num"><?= rupiahPdf($totalTransfer) ?></div>
      <div class="metode-bar-track"><div class="metode-bar-fill" style="width:<?= $pctTransfer ?>%"></div></div>
    </div>
  </div>

  <!-- TABEL RINCIAN -->
  <table>
    <thead>
      <tr>
        <th style="width:26px">No</th>
        <th>Tanggal</th>
        <th>No. Nota</th>
        <th>Nama Pelanggan</th>
        <th class="num">Total</th>
        <th class="num">Dibayar</th>
        <th class="num">Sisa</th>
        <th>Metode</th>
        <th>Status</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($daftar)): ?>
      <tr><td colspan="9" class="empty-note">Tidak ada transaksi pada periode ini.</td></tr>
      <?php else: ?>
      <?php $no = 1; foreach ($daftar as $d):
          $sisa = sisaBayar($d['total_harga'], $d['total_dibayar']);
          $metodeTxt = metodeRingkasUntukLaporan($pembayaranPerTrx[$d['id_transaksi']] ?? []);
          [$chipClass, $chipLabel] = match ($d['status_bayar']) {
              'sudah_bayar' => ['st-lunas', 'Lunas'],
              'dp'          => ['st-dp', 'DP'],
              default       => ['st-belum', 'Belum Bayar'],
          };
      ?>
      <tr>
        <td><?= $no++ ?></td>
        <td><?= date('d/m/y', strtotime($d['created_at'])) ?></td>
        <td><?= htmlspecialchars($d['id_nota']) ?></td>
        <td><?= htmlspecialchars($d['nama_pelanggan']) ?></td>
        <td class="num"><?= number_format($d['total_harga'],0,',','.') ?></td>
        <td class="num"><?= number_format($d['total_dibayar'],0,',','.') ?></td>
        <td class="num"><?= number_format($sisa,0,',','.') ?></td>
        <td style="font-size:10.5px"><?= htmlspecialchars($metodeTxt) ?></td>
        <td><span class="status-chip <?= $chipClass ?>"><?= $chipLabel ?></span></td>
      </tr>
      <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
    <?php if (!empty($daftar)): ?>
    <tfoot>
      <tr>
        <td colspan="4">TOTAL</td>
        <td class="num"><?= number_format($ringkasan['total_omzet'],0,',','.') ?></td>
        <td class="num"><?= number_format($ringkasan['total_diterima'],0,',','.') ?></td>
        <td class="num"><?= number_format($ringkasan['total_belum'],0,',','.') ?></td>
        <td></td>
        <td></td>
      </tr>
    </tfoot>
    <?php endif; ?>
  </table>

  <!-- TANDA TANGAN -->
  <div class="ttd-wrap">
    <div class="ttd-box">
      <div>Dibuat oleh,</div>
      <div class="ttd-space"></div>
      <div class="ttd-name">Kasir</div>
    </div>
    <div class="ttd-box">
      <div>Mengetahui,</div>
      <div class="ttd-space"></div>
      <div class="ttd-name"><?= htmlspecialchars($namaOwner) ?><br><span style="font-weight:400">Owner <?= htmlspecialchars($namaUsaha) ?></span></div>
    </div>
  </div>

  <div class="footer-note">
    Laporan ini dibuat otomatis oleh Sistem Kasir <?= htmlspecialchars($namaUsaha) ?> pada <?= $waktuCetak ?>.
  </div>

</div>

</body>
</html>
