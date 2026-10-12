# Runbook operasional Karsitara SIMRS (fase 1)

> **Status: draf** untuk tiket 1.12 butir be 4. Semua contoh memakai nilai fiktif: domain `simrs.rs-contoh.invalid`,
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
| Dockerfile aplikasi dan `docker-compose` produksi | **Belum tersedia.** `docker-compose.yml` di root hanya untuk pengembangan (sandi bawaan, port terbuka). Runbook ini memakai pemasangan langsung (php-fpm + nginx + systemd). | backlog 1.12 butir be 4 |
| Disk S3/MinIO untuk `IDENTITAS_DISK`, `SDM_DOKUMEN_DISK`, `AUDIT_ANCHOR_DISK` | **Belum tersedia**: `config/filesystems.php` punya disk `s3`, tetapi paket adaptor `league/flysystem-aws-s3-v3` tidak ada di `composer.json`. Sementara memakai disk `local` + mount penyimpanan terpisah (lihat konfigurasi-env.md §8). | backlog "Sesudah 1.8a-be"; ADR 0003 |
| PgBouncer, Octane, replika baca, Reverb | Diminta PRD 14.1/14.1.1, **belum ada** di kode atau konfigurasi repo. Fase 1 berjalan dengan php-fpm langsung ke PostgreSQL. | PRD 14.1 pola backend 1–2 |
| Pemindaian antivirus unggahan | **Belum ada** (PRD 14.2). | backlog "Sesudah 1.8a-be" |
| Isi paket referensi resmi (wilayah, UCUM, LOINC, kode variabel RME) | **Belum dikemas**; menunggu berkas resmi. Langkah 4 wizard belum bisa selesai tanpanya. | `backend/database/referensi/SUMBER.md` |
| Layar konfigurasi integrasi (1.9-fe) | **Belum ada**; kredensial integrasi baru lewat API. | backlog 1.9 |
| Notifikasi Horizon `LongWaitDetected` | Ambang 60 detik ada di `config/horizon.php`, tetapi tujuan notifikasinya belum diatur. | proses-dan-jadwal.md §6 |
| Ukuran server (CPU/RAM/disk) | **Belum ditetapkan**; menunggu hasil uji beban 1.12 butir be 1. | instalasi.md §1 |
