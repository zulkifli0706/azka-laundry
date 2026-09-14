<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/meta_whatsapp.php';
require_once __DIR__ . '/includes/proses_helper.php';
requireRole('bagian_pencucian', 'owner', 'kasir');

header('Content-Type: application/json');

if (!verify_csrf()) {
    echo json_encode(['success' => false, 'message' => 'Sesi kedaluwarsa, silakan muat ulang halaman.']);
    exit;
}

// Catatan: transaksi_id divalidasi per-aksi di bawah, bukan di sini secara
// global -- aksi 'lanjut_tahap_item' tidak mengirim transaksi_id dari
// frontend (diturunkan dari detail_id), jadi validasi global di titik ini
// akan selalu menolaknya sebelum sempat masuk ke branch yang benar.
$transaksi_id = (int)($_POST['transaksi_id'] ?? 0);

// kirimNotifSiapDiambil() sekarang ada di includes/proses_helper.php,
// supaya bisa dipakai bareng oleh kirim_notif_wa.php.

try {
    // ── AKSI: LANJUT KE TAHAP PROSES BERIKUTNYA ──
    // Dipakai oleh bagian pencucian di proses.php untuk menggerakkan
    // tahap dicuci -> dikeringkan -> disetrika -> dikemas sesuai jenis
    // layanan item dalam transaksi tsb. Begitu tahap terakhir selesai,
    // otomatis pindah status ke 'siap_diambil' + kirim notif WA.
    if (($_POST['aksi'] ?? '') === 'lanjut_tahap') {
        if (!$transaksi_id) {
            echo json_encode(['success' => false, 'message' => 'Data tidak valid']);
            exit;
        }

        $cek = $pdo->prepare("SELECT status, tahap_proses FROM status_cucian WHERE transaksi_id = ?");
        $cek->execute([$transaksi_id]);
        $row = $cek->fetch();

        if (!$row || $row['status'] !== 'sedang_diproses') {
            echo json_encode(['success' => false, 'message' => 'Transaksi tidak sedang dalam proses.']);
            exit;
        }

        $tahapBerikut = tahapBerikutnya($pdo, $transaksi_id, $row['tahap_proses']);

        if ($tahapBerikut === null) {
            // Tahap terakhir (kemas) sudah selesai -> siap diambil
            $pdo->prepare("UPDATE status_cucian SET status='siap_diambil', tahap_proses=NULL, updated_by=?, updated_at=NOW() WHERE transaksi_id=?")
                ->execute([$_SESSION['user']['id_user'], $transaksi_id]);
            echo json_encode(['success' => true, 'selesai' => true, 'transaksi_id' => $transaksi_id, 'butuh_notif_siap' => true]);
        } else {
            $pdo->prepare("UPDATE status_cucian SET tahap_proses=?, updated_by=?, updated_at=NOW() WHERE transaksi_id=?")
                ->execute([$tahapBerikut, $_SESSION['user']['id_user'], $transaksi_id]);
            echo json_encode([
                'success'      => true,
                'selesai'      => false,
                'tahap'        => $tahapBerikut,
                'tahap_label'  => labelTahap($tahapBerikut, tipeDominanTransaksi($pdo, $transaksi_id), kategoriDominanTransaksi($pdo, $transaksi_id)),
            ]);
        }
        exit;
    }

    // ── AKSI: LANJUT TAHAP SATU ITEM (real per-barang) ──
    // Dipakai di modal Detail proses.php: setiap item dalam satu nota
    // punya tombol "Lanjut Tahap" sendiri, jadi item A bisa lebih maju
    // dari item B walaupun satu nota yang sama. Begitu SEMUA item nota
    // ini sampai tahap 'kemas', nota otomatis pindah ke 'siap_diambil'.
    if (($_POST['aksi'] ?? '') === 'lanjut_tahap_item') {
        $detail_id = (int)($_POST['detail_id'] ?? 0);
        if (!$detail_id) {
            echo json_encode(['success' => false, 'message' => 'Data tidak valid']);
            exit;
        }

        $cek = $pdo->prepare("
            SELECT dt.transaksi_id, COALESCE(il.tipe_proses, 'cuci_setrika') AS tipe_proses,
                   il.custom_tahap, ds.tahap_proses, s.status, kl.nama_kategori
            FROM detail_transaksi dt
            LEFT JOIN detail_barang il    ON il.id_barang = dt.detail_barang_id
            LEFT JOIN kategori_barang kl ON kl.id_kategori = il.kategori_id
            LEFT JOIN detail_status ds   ON ds.detail_transaksi_id = dt.id_detail_transaksi
            JOIN status_cucian s         ON s.transaksi_id = dt.transaksi_id
            WHERE dt.id_detail_transaksi = ?
        ");
        $cek->execute([$detail_id]);
        $row = $cek->fetch();

        if (!$row) {
            echo json_encode(['success' => false, 'message' => 'Item tidak ditemukan.']);
            exit;
        }
        if ($row['status'] !== 'sedang_diproses') {
            echo json_encode(['success' => false, 'message' => 'Transaksi tidak sedang dalam proses.']);
            exit;
        }

        $tahapBerikut = tahapBerikutnyaItem($row['tipe_proses'], $row['tahap_proses'], $row['custom_tahap'] ?? null);
        if ($tahapBerikut === null) {
            echo json_encode(['success' => false, 'message' => 'Item ini sudah sampai tahap akhir.']);
            exit;
        }

        // Baris detail_status seharusnya sudah otomatis ada lewat trigger
        // saat item dibuat, tapi tetap upsert untuk jaga-jaga data lama.
        $pdo->prepare("
            INSERT INTO detail_status (detail_transaksi_id, tahap_proses, updated_by, updated_at)
            VALUES (?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE tahap_proses = VALUES(tahap_proses), updated_by = VALUES(updated_by), updated_at = NOW()
        ")->execute([$detail_id, $tahapBerikut, $_SESSION['user']['id_user']]);

        $transaksiId  = (int) $row['transaksi_id'];
        $selesaiSemua = sinkronStatusBatch($pdo, $transaksiId, $_SESSION['user']['id_user']);

        echo json_encode([
            'success'          => true,
            'selesai_semua'    => $selesaiSemua,
            'tahap'            => $tahapBerikut,
            'tahap_label'      => labelTahap($tahapBerikut, $row['tipe_proses'], $row['nama_kategori']),
            'is_terakhir'      => $tahapBerikut === 'kemas',
            'transaksi_id'     => $transaksiId,
            'butuh_notif_siap' => $selesaiSemua,
        ]);
        exit;
    }

    // ── AKSI: MUNDUR KE TAHAP SEBELUMNYA (satu item) ──
    // Kebalikan dari 'lanjut_tahap_item' -- dipakai tombol "Mundur Tahap"
    // di modal Detail proses.php, mis. kalau petugas salah pencet "Lanjut"
    // atau barangnya ternyata perlu dikerjakan ulang dari tahap sebelumnya.
    if (($_POST['aksi'] ?? '') === 'mundur_tahap_item') {
        $detail_id = (int)($_POST['detail_id'] ?? 0);
        if (!$detail_id) {
            echo json_encode(['success' => false, 'message' => 'Data tidak valid']);
            exit;
        }

        $cek = $pdo->prepare("
            SELECT dt.transaksi_id, COALESCE(il.tipe_proses, 'cuci_setrika') AS tipe_proses,
                   il.custom_tahap, ds.tahap_proses, s.status, kl.nama_kategori
            FROM detail_transaksi dt
            LEFT JOIN detail_barang il    ON il.id_barang = dt.detail_barang_id
            LEFT JOIN kategori_barang kl ON kl.id_kategori = il.kategori_id
            LEFT JOIN detail_status ds   ON ds.detail_transaksi_id = dt.id_detail_transaksi
            JOIN status_cucian s         ON s.transaksi_id = dt.transaksi_id
            WHERE dt.id_detail_transaksi = ?
        ");
        $cek->execute([$detail_id]);
        $row = $cek->fetch();

        if (!$row) {
            echo json_encode(['success' => false, 'message' => 'Item tidak ditemukan.']);
            exit;
        }
        if ($row['status'] !== 'sedang_diproses') {
            echo json_encode(['success' => false, 'message' => 'Transaksi tidak sedang dalam proses.']);
            exit;
        }

        $tahapSebelum = tahapSebelumnyaItem($row['tipe_proses'], $row['tahap_proses'], $row['custom_tahap'] ?? null);
        if ($tahapSebelum === null) {
            echo json_encode(['success' => false, 'message' => 'Item ini sudah di tahap paling awal.']);
            exit;
        }

        $pdo->prepare("
            UPDATE detail_status SET tahap_proses = ?, updated_by = ?, updated_at = NOW()
            WHERE detail_transaksi_id = ?
        ")->execute([$tahapSebelum, $_SESSION['user']['id_user'], $detail_id]);

        $transaksiId = (int) $row['transaksi_id'];
        // Sinkronkan tahap batch (badge ringkasan) supaya ikut mundur juga.
        // Tidak mungkin balik status jadi 'siap_diambil' di sini karena kita
        // baru saja MEMUNDURKAN salah satu item, jadi aman dipanggil langsung.
        sinkronStatusBatch($pdo, $transaksiId, $_SESSION['user']['id_user']);

        echo json_encode([
            'success'      => true,
            'tahap'        => $tahapSebelum,
            'tahap_label'  => labelTahap($tahapSebelum, $row['tipe_proses'], $row['nama_kategori']),
            'transaksi_id' => $transaksiId,
        ]);
        exit;
    }

    // ── AKSI LAMA: TRANSISI STATUS UTAMA (tetap didukung untuk kompatibilitas) ──
    $status      = $_POST['status'] ?? '';
    $validStatus = ['dalam_antrian', 'sedang_diproses', 'siap_diambil', 'selesai'];

    if (!$transaksi_id || !in_array($status, $validStatus, true)) {
        echo json_encode(['success' => false, 'message' => 'Data tidak valid']);
        exit;
    }

    $cek = $pdo->prepare("SELECT status FROM status_cucian WHERE transaksi_id = ?");
    $cek->execute([$transaksi_id]);
    $row = $cek->fetch();

    // Saat mulai diproses, tentukan tahap AWAL sesuai jenis layanan item
    // di transaksi ini (mis. 'setrika_saja' langsung mulai dari tahap
    // 'setrika', bukan 'cuci').
    $tahapAwal = ($status === 'sedang_diproses')
        ? tahapBerikutnya($pdo, $transaksi_id, null)
        : null;

    if ($row) {
        $pdo->prepare("UPDATE status_cucian SET status=?, tahap_proses=?, updated_by=?, updated_at=NOW() WHERE transaksi_id=?")
            ->execute([$status, $tahapAwal, $_SESSION['user']['id_user'], $transaksi_id]);
    } else {
        $pdo->prepare("INSERT INTO status_cucian (transaksi_id, status, tahap_proses, updated_by) VALUES (?,?,?,?)")
            ->execute([$transaksi_id, $status, $tahapAwal, $_SESSION['user']['id_user']]);
    }

    // ── NOTIFIKASI WA "SIAP DIAMBIL" ──
    // Tidak dikirim langsung di sini -- cukup ditandai lewat 'butuh_notif_siap'
    // supaya browser yang antrekan pengiriman (dengan jeda antar pesan).
    $butuhNotifSiap = ($status === 'siap_diambil');

    echo json_encode(['success' => true, 'transaksi_id' => $transaksi_id, 'butuh_notif_siap' => $butuhNotifSiap]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}