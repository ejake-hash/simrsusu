# Runbook operasional Karsitara SIMRS (fase 1)

> **Status: draf** untuk tiket 1.12 butir be 4, diperiksa terhadap kode `main` per 12 Oktober 2026 (sesudah 1.9-fe).
> Pemasangan di server RS sungguhan belum pernah dilakukan; yang sudah diuji hanya pemulihan cadangan logis
> ([hasil-uji-pemulihan.md](hasil-uji-pemulihan.md)). Semua contoh memakai nilai fiktif: domain `simrs.rs-contoh.invalid`,
> folder aplikasi `/srv/karsitara`, nilai rahasia `<...>`. Jangan menulis rahasia, alamat IP, atau nama RS nyata ke
> berkas di repo ini. Isian yang khas per RS ditulis di catatan instalasi RS sendiri (di luar repo).

Runbook ini untuk memasang **satu instalasi = satu RS** (PRD 3.4) di server on-premise atau pusat data dalam negeri,
tanpa kontainer aplikasi. Fakta di sini diambil dari kode dan konfigurasi repo; sumbernya disebut di tiap bagian.
Bila runbook dan kode berbeda, **kode yang berlaku** dan runbook diperbaiki lewat PR.

## Urutan baca dan kerja

| No | Berkas | Isi | Kapan |
|---|---|---|---|
| 1 | [instalasi.md](instalasi.md) | Kebutuhan server, PostgreSQL, Valkey, kode, migrasi, build SPA, langkah awal (superadmin, MFA, wizard, referensi) | Pemasangan pertama dan pembaruan versi |
| 2 | [konfigurasi-env.md](konfigurasi-env.md) | Semua variabel `.env` backend: wajib produksi, opsional, dan **dilarang** | Sebelum migrasi pertama; setiap ubah konfigurasi |
| 3 | [nginx-php.md](nginx-php.md) | nginx (TLS, header keamanan, SPA, `/api`), real IP + `TRUSTED_PROXIES`, php.ini dan php-fpm | Sebelum situs dibuka ke pengguna |
| 4 | [proses-dan-jadwal.md](proses-dan-jadwal.md) | php-fpm, Horizon (queue per jenis), cron `schedule:run`, daftar jadwal, perintah konsol, pemantauan dasar | Setelah instalasi; rujukan harian |
| 5 | [cadangan-pemulihan.md](cadangan-pemulihan.md) | PITR + cadangan harian terenkripsi, berkas, kunci, urutan pemulihan, uji pemulihan | Sebelum go-live; uji bulanan |
| 6 | [hasil-uji-pemulihan.md](hasil-uji-pemulihan.md) | Bukti uji pemulihan ke database kosong (data sintetis) | Rujukan; diperbarui setiap uji |
| 7 | [hasil-uji-beban.md](hasil-uji-beban.md) | Uji beban endpoint fase 1 (k6) dan penulisan audit (pgbench), titik jenuh, temuan | Rujukan ukuran server; diulang ke staging |

Ringkas urutan pemasangan pertama:

1. Siapkan server dan paket (instalasi.md §1–2).
2. Buat role dan database dengan skrip repo (instalasi.md §3).
3. Jalankan Valkey dua instans (instalasi.md §4).
4. Ambil kode, isi `.env`, buat kunci (instalasi.md §5, konfigurasi-env.md).
5. Migrasi sebagai `simrs_migrator`, siapkan partisi, cache konfigurasi (instalasi.md §6).
6. Build SPA, pasang nginx dan php-fpm (instalasi.md §7, nginx-php.md).
7. Jalankan Horizon dan cron scheduler (proses-dan-jadwal.md).
8. Buat dua superadmin, isi jaringan RS, jalankan wizard penyiapan (instalasi.md §8).
9. Pasang cadangan dan uji pemulihan sebelum melayani pasien (cadangan-pemulihan.md).

## Yang belum tersedia di repo (jangan dikarang saat memasang)

| Hal | Keadaan | Rujukan |
|---|---|---|
| Dockerfile aplikasi dan `docker-compose` produksi | **Belum tersedia.** `docker-compose.yml` di root hanya untuk pengembangan (sandi bawaan `postgres`/`simrs_app`/`simrs_migrator`, semua port dibuka ke host, Valkey tanpa sandi). Runbook ini memakai **pemasangan langsung** (php-fpm + nginx + systemd). Perlu keputusan pemilik, lihat di bawah. | backlog 1.12 butir be 4 |
| Disk S3/MinIO untuk `IDENTITAS_DISK`, `SDM_DOKUMEN_DISK`, `AUDIT_ANCHOR_DISK` | **Belum tersedia**: `config/filesystems.php` punya disk `s3`, tetapi paket adaptor `league/flysystem-aws-s3-v3` tidak ada di `composer.json`. Sementara memakai disk `local` + mount penyimpanan terpisah (lihat konfigurasi-env.md §8). | backlog "Sesudah 1.8a-be"; ADR 0003 |
| PgBouncer, Octane, replika baca, Reverb | Diminta PRD 14.1/14.1.1, **belum ada** di kode atau konfigurasi repo. Fase 1 berjalan dengan php-fpm langsung ke PostgreSQL. | PRD 14.1 pola backend 1–2 |
| Pemindaian antivirus unggahan | **Belum ada** (PRD 14.2). | backlog "Sesudah 1.8a-be" |
| Isi paket referensi resmi (wilayah, UCUM, LOINC, kode variabel RME) | **Belum dikemas**; menunggu berkas resmi. Langkah 4 wizard belum bisa selesai tanpanya. | `backend/database/referensi/SUMBER.md` |
| Notifikasi Horizon `LongWaitDetected` | Ambang 60 detik ada di `config/horizon.php`, tetapi tujuan notifikasinya belum diatur (`HorizonServiceProvider` tidak memanggil `Horizon::route*NotificationsTo`). | [proses-dan-jadwal.md §6](proses-dan-jadwal.md#6-pemantauan-dasar) |
| Layar modul aktif | **Belum ada**; modul 53 dan 54 aktif dari migrasi (`inti.modul_aktif`), cukup untuk langkah 9 wizard. | `2026_10_11_120000_create_inti_modul_aktif_table.php` |
| Pemeriksaan rantai audit berkala | `audit:verifikasi-rantai` **tidak** dijadwalkan di `routes/console.php`; hanya `audit:ekspor-anchor` yang berjalan tiap jam. | [proses-dan-jadwal.md §3](proses-dan-jadwal.md#3-scheduler-dan-daftar-jadwal) |
| Pembersihan `sistem.failed_jobs` dan snapshot metrik Horizon | `queue:prune-failed` dan `horizon:snapshot` **tidak** dijadwalkan. | `routes/console.php` |
| Ukuran server (CPU/RAM/disk) | **Belum ditetapkan resmi**; pegangan awal dari uji lokal ([hasil-uji-beban.md](hasil-uji-beban.md)), angka resmi dari uji staging. | instalasi.md §1 |

Layar konfigurasi integrasi (1.9-fe) **sudah ada**: Pengaturan Sistem → Konfigurasi integrasi (`/pengaturan/integrasi`,
sub-menu `pengaturan.integrasi`), dengan pemantauan di Integrasi dan Laporan → SATUSEHAT / Bridging BPJS / Log integrasi.

## Selisih dengan PRD (dicatat, belum diputuskan)

| Hal | PRD | Kode/runbook | Sumber |
|---|---|---|---|
| Batas waktu permintaan HTTP | 30 detik (14.1.1 butir 2) | nginx `fastcgi_read_timeout 60s`, PHP `max_execution_time = 60`: unggah paket referensi 10 MB dua kali (backlog 1.8e). Transaksi pemuatan referensi sendiri boleh sampai 180 detik (`SET LOCAL statement_timeout = '180s'` di `PemuatReferensi`), jadi berkas besar (wilayah) sebaiknya lewat `referensi:muat` di konsol. | [nginx-php.md §3](nginx-php.md#3-batas-waktu-dan-ukuran-harus-selaras) |
| Batas waktu klien API eksternal | 10 detik (14.1.1 butir 2) | `INTEGRASI_BATAS_WAKTU` bawaan 20 detik, uji koneksi 15 detik (`config/integrasi.php`). Panggilan berjalan di queue `integrasi` (batas pekerja 60 detik), bukan di permintaan pengguna. | `config/integrasi.php`, `JenisQueue` |
| Font dan ikon di-host sendiri | wajib di produksi (14.1 pola frontend 4) | `frontend/index.html` masih memuat Google Fonts; CSP di nginx-php.md mengizinkannya sementara. | `frontend/index.html` |
| PgBouncer, Octane, replika baca | wajib (14.1 pola backend 1–2) | belum ada; php-fpm langsung ke PostgreSQL | — |
| Kunci di KMS/HSM dengan rotasi tahunan | 14.2 baris Data | kunci di `.env` (salinan di vault RS). `APP_KEY` belum bisa dirotasi (sidik NIK pegawai). `TTD_KUNCI` dan `KREDENSIAL_KUNCI` bisa dirotasi. | [proses-dan-jadwal.md §5](proses-dan-jadwal.md#5-rotasi-kunci) |

## Keputusan yang perlu pemilik repo

Butir ini tidak diputuskan oleh runbook. Sampai diputuskan, runbook memakai pilihan sementara yang ditulis di kolom kanan.

| No | Keputusan | Pilihan sementara di runbook |
|---|---|---|
| 1 | **Compose produksi.** Butir be 4 menyebut "compose produksi", tetapi repo belum punya Dockerfile aplikasi maupun compose produksi. Perlu diputuskan: dibuat (tiket baru; menambah citra kontainer dan pemindaian citra di CI, PRD 14.2 baris Rantai pasok) atau runbook pemasangan langsung dianggap memenuhi butir be 4. | Pemasangan langsung (php-fpm + nginx + systemd). |
| 2 | **Disk S3/MinIO** untuk `IDENTITAS_DISK`, `SDM_DOKUMEN_DISK`, `AUDIT_ANCHOR_DISK`: butuh dependensi baru `league/flysystem-aws-s3-v3`. | Disk `local` + mount penyimpanan terpisah. |
| 3 | **Alat cadangan dan PITR**: alat bawaan PostgreSQL (`pg_basebackup` + `archive_command`) atau alat khusus (mis. pgBackRest/WAL-G, dependensi server baru); alat enkripsi cadangan. | Alat bawaan PostgreSQL + `gpg` kunci publik; PITR belum diuji. |
| 4 | **Anchor audit sesudah pemulihan yang kehilangan data**: anchor yang ditulis sesudah titik pulih tidak akan pernah cocok lagi. Perlu prosedur resmi (berita acara, arsip anchor lama). | Usulan prosedur di [cadangan-pemulihan.md §8](cadangan-pemulihan.md#8-verifikasi-sesudah-pulih), belum disahkan. |
| 5 | **Verifikasi rantai audit berkala**: belum dijadwalkan di kode. Sejak 1.12 verifikasi berjalan per jendela (`AUDIT_VERIFIKASI_JENDELA`), jadi tidak lagi melewati `statement_timeout` 5 detik; seluruh tabel ±38 ribu baris/detik (uji lokal). | Jalankan harian per rentang tanggal (`--dari/--sampai`) lewat timer systemd. |
| 6 | **Selisih batas waktu** dengan PRD (HTTP 60 vs 30 detik; klien eksternal 20 vs 10 detik), tabel di atas. | Mengikuti kode. |
| 7 | **Tujuan peringatan** (email/alat pemantau RS) untuk Horizon, ruang disk, cadangan gagal. | Pemeriksaan lewat skrip dan log; tujuan per RS. |
| 8 | **`DB_SSLMODE=verify-full`**: `config/database.php` belum punya opsi `sslrootcert`, jadi sertifikat CA harus dipasang di jalur bawaan libpq pengguna `www-data`. Belum diuji. | Lihat [konfigurasi-env.md §3](konfigurasi-env.md#3-basis-data). |
| 9 | **Ukuran server** (CPU/RAM/disk, `pm.max_children`, `max_connections`). | Pegangan awal ±15 permintaan/detik per pekerja PHP (uji lokal 1.12); angka resmi dari uji staging. |
