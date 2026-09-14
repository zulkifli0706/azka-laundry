<?php
/**
 * CRON: Cek & perpanjang estimasi otomatis + kirim notifikasi WA "terlambat"
 * (estimasi_diperpanjang) TANPA perlu ada staf yang buka dashboard_pencucian.php
 * / antrian.php / sedang_diproses.php dulu.
 *
 * Sebelumnya cekPerpanjanganEstimasi() cuma jalan kalau salah satu halaman itu
 * dibuka (lihat includes/estimasi_helper.php). Script ini menjalankan fungsi
 * yang SAMA PERSIS, tapi dipicu oleh penjadwal server (cron), bukan oleh
 * page-load staf -- jadi tetap terkirim otomatis jam 16:00 walau tidak ada
 * satu pun staf yang membuka sistem sore itu.
 *
 * ── CARA PAKAI ──
 *
 * A) Kalau hosting kamu punya akses cron biasa (VPS / shared hosting cPanel
 *    dengan menu "Cron Jobs"), jadwalkan lewat PHP CLI, jam 16:00 tiap hari
 *    (H-2 sebelum tutup jam 18:00 -- sesuaikan kalau jam tutup beda):
 *
 *        0 16 * * * php /path/ke/azka-laundry/cron_notif_terlambat.php >> /path/ke/azka-laundry/logs/cron_terlambat.log 2>&1
 *
 *    Ganti "/path/ke/azka-laundry" dengan path folder project di server kamu.
 *
 * B) Kalau hosting kamu TIDAK punya cron beneran (banyak shared hosting murah
 *    begini), pakai layanan webcron gratis (mis. cron-job.org, EasyCron) yang
 *    nge-hit URL ini tiap jam 16:00. Supaya tidak bisa dipanggil sembarang
 *    orang dari internet, WAJIB isi CRON_SECRET di file .env, lalu akses:
 *
 *        https://domainkamu.com/cron_notif_terlambat.php?secret=ISI_RAHASIA_DI_ENV
 *
 *    Tanpa token yang cocok, script menolak jalan (403).
 *
 * Aman dijalankan berkali-kali / lewat 2 jalur sekaligus (idempotent) --
 * logika cekPerpanjanganEstimasi() sendiri sudah anti-dobel, lihat komentar
 * di includes/estimasi_helper.php.
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/estimasi_helper.php';
require_once __DIR__ . '/includes/meta_whatsapp.php';

// ── PROTEKSI AKSES: CLI selalu diizinkan; akses via web WAJIB secret yang cocok ──
$isCli = (php_sapi_name() === 'cli');
if (!$isCli) {
    $secretDiharapkan = envOrDefault('CRON_SECRET', '');
    $secretDiterima   = $_GET['secret'] ?? '';
    if ($secretDiharapkan === '' || !hash_equals($secretDiharapkan, $secretDiterima)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Akses ditolak. Isi CRON_SECRET di .env lalu akses dengan ?secret=....\n";
        exit;
    }
    header('Content-Type: text/plain; charset=utf-8');
}

function logBaris(string $pesan): void {
    $waktu = date('Y-m-d H:i:s');
    echo "[$waktu] $pesan\n";
}

logBaris('Mulai cek nota yang perlu diperpanjang estimasinya...');

// Jam tutup & jam mulai pengecekan (H-2) sama seperti dipakai halaman biasa.
// Sesuaikan di sini kalau jam operasional tokonya beda.
$JAM_TUTUP      = 18;
$JAM_CEK_SEBELUM = 2;

$idTerlambat = cekPerpanjanganEstimasi($pdo, $JAM_TUTUP, $JAM_CEK_SEBELUM);

if (empty($idTerlambat)) {
    logBaris('Tidak ada nota yang perlu diperpanjang saat ini. Selesai.');
    exit;
}

logBaris(count($idTerlambat) . ' nota diperpanjang estimasinya. Mengirim notifikasi WA satu per satu...');

$berhasil = 0;
$gagal    = 0;

foreach ($idTerlambat as $i => $transaksiId) {
    $ok = kirimNotifKeterlambatan($pdo, $transaksiId);
    if ($ok) {
        $berhasil++;
        logBaris("  - transaksi #$transaksiId: terkirim");
    } else {
        $gagal++;
        logBaris("  - transaksi #$transaksiId: GAGAL (lihat error_log untuk detail)");
    }

    // Jeda kecil antar pesan (~0.8-1.2 detik), sama seperti antrean WA di
    // browser (notif_wa_widget.php) -- jaga-jaga burst rate limit Meta.
    if ($i < count($idTerlambat) - 1) {
        usleep((int) ((800 + mt_rand(0, 400)) * 1000));
    }
}

logBaris("Selesai. Berhasil: $berhasil, Gagal: $gagal.");
