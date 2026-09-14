<?php
/**
 * Endpoint HEARTBEAT -- dipanggil otomatis lewat JavaScript (fetch) tiap 30
 * menit dari dashboard_kasir.php, dashboard_owner.php, dan
 * dashboard_pencucian.php SELAMA tab dashboard itu terbuka di browser.
 *
 * Tujuannya CUMA satu: memastikan includes/config.php ikut ter-load (dan
 * dengan begitu jalankanCekTerlambatOtomatis() ikut jalan) walau tidak ada
 * satu pun staf/pelanggan yang benar-benar buka/refresh halaman lain
 * setelah jam 16:00 -- selama ada minimal 1 tab dashboard yang terbuka di
 * suatu tempat (komputer kasir, owner, atau pencucian), heartbeat inilah
 * yang jadi "detak jantung"-nya, paling telat 30 menit dari jam
 * pengecekan (bukan berjam-jam menunggu ada yang buka halaman lagi).
 *
 * SENGAJA dibuat seringan mungkin:
 * - requireLogin() saja (bukan requireRole tertentu) -- boleh dipanggil
 *   dari role kasir/owner/bagian_pencucian manapun, cukup asal user-nya
 *   memang sedang login (bukan endpoint publik/anonim).
 * - Tidak ada query database tambahan di file ini sendiri -- proses berat
 *   (cek nota telat + kirim WA) sepenuhnya ditangani di dalam
 *   jalankanCekTerlambatOtomatis() yang otomatis jalan begitu
 *   config.php di-require di baris paling atas, DAN itu sendiri sudah
 *   dijaga maksimal 1x proses berat per hari (lihat komentar di
 *   includes/config.php).
 * - Response-nya seminimal mungkin (cuma 2 huruf), supaya tidak
 *   membebani bandwidth walau dipanggil berkali-kali oleh banyak tab.
 */
require_once __DIR__ . '/includes/config.php';
requireLogin();

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => true]);
