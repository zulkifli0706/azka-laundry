<?php
/**
 * Endpoint PUBLIK (tanpa login) untuk pelanggan men-download invoice PDF
 * mereka sendiri. Linknya dilampirkan sebagai header dokumen di template
 * WhatsApp "pesanan_selesai_v4" (lihat includes/notif_helper.php ->
 * kirimNotifSelesai() dan docs/whatsapp-templates.md).
 *
 * Sengaja TIDAK pakai requireRole()/session login sama sekali -- pelanggan
 * buka dari WA di HP mereka, bukan dari dashboard kasir/owner. Keamanan
 * diganti dengan token tanda tangan (lihat includes/invoice_pdf.php ->
 * tokenInvoice()) supaya orang tidak bisa asal ganti ?nota=INV-0002 di URL
 * untuk melihat invoice pelanggan lain -- tanpa token yang cocok, ditolak.
 *
 * URL: /invoice.php?nota=INV-0001&t=<token>
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/invoice_pdf.php';

$idNota = trim((string) ($_GET['nota'] ?? ''));
$token  = trim((string) ($_GET['t'] ?? ''));

if ($idNota === '' || $token === '' || !tokenInvoiceValid($idNota, $token)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Link invoice tidak valid atau sudah kedaluwarsa.';
    exit;
}

$stmt = $pdo->prepare('SELECT id_transaksi FROM transaksi WHERE id_nota = ?');
$stmt->execute([$idNota]);
$row = $stmt->fetch();

if (!$row) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Invoice tidak ditemukan.';
    exit;
}

try {
    $pdf = buatInvoicePdf($pdo, (int) $row['id_transaksi']);
} catch (Throwable $e) {
    error_log('Gagal generate invoice PDF untuk nota ' . $idNota . ': ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Terjadi kesalahan saat membuat invoice.';
    exit;
}

// 'D' = paksa dialog download di browser dengan nama file di bawah ini.
// Kalau ingin bisa dibuka langsung di tab (bukan auto-download), ganti
// jadi 'I'. Untuk lampiran dokumen WhatsApp, Meta yang mengambil file ini
// via HTTP GET biasa lalu mengirimkannya sebagai attachment ke pelanggan,
// jadi header 'D' di sini sebenarnya baru relevan kalau pelanggan buka
// linknya langsung di browser (mis. tombol "Lihat/download" di aplikasi
// WhatsApp mereka kadang membuka browser).
$namaFile = 'Invoice-' . preg_replace('/[^A-Za-z0-9\-_]/', '', $idNota) . '.pdf';
$pdf->Output('D', $namaFile);
