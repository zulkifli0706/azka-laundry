# Azka Laundry

Aplikasi web kasir laundry berbasis **PHP** dan **MySQL** untuk mencatat transaksi, memantau status cucian, mengelola pembayaran, dan melihat ringkasan usaha.

<!-- Taruh screenshot aplikasi di sini, contoh: -->
<!-- <img width="800" alt="Halaman kasir" src="images/kasir.png"> -->

## Fitur

- Pencatatan nota transaksi dengan banyak item layanan per nota
- Pengelompokan layanan berdasarkan kategori (kiloan, sepatu, bed cover, dan lainnya)
- Data pelanggan (nama dan nomor HP)
- Pembayaran tunai, transfer, atau gabungan, termasuk DP dan sisa tagihan
- Status pengerjaan cucian, status pengambilan, dan tanggal estimasi selesai
- Penanda nota yang melewati estimasi (terlambat)
- Halaman Insight untuk ringkasan omzet dan performa layanan

## Teknologi

PHP, MySQL, HTML, CSS

## Struktur database

| Tabel | Isi |
|---|---|
| transaksi | nota, pelanggan, total harga, status bayar, estimasi |
| status_cucian | status pengerjaan dan waktu pembaruan |
| detail_transaksi | item per nota, jumlah, subtotal |
| detail_barang | nama item dan satuan |
| kategori_barang | kategori layanan |

## Cara menjalankan

1. Pasang web server lokal dengan PHP dan MySQL (misalnya XAMPP).
2. Clone repositori ke folder web server (misalnya `htdocs`).

```
git clone https://github.com/zulkifli0706/azka-laundry.git
```

3. Buat database `<nama_database>` di MySQL, lalu import file `<nama_file>.sql`.
4. Sesuaikan koneksi database di `<file_konfigurasi>`.
5. Buka `http://localhost/azka-laundry` di browser.

## Proyek terkait

Data dummy dari aplikasi ini dianalisis dengan Python di repositori [azka-laundry-data-analysis](https://github.com/zulkifli0706/azka-laundry-data-analysis).
