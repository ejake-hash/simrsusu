# Cadangan dan pemulihan

Draf, tiket 1.12. Contoh: folder aplikasi `/srv/karsitara`, folder arsip `/arsip` (mount penyimpanan terpisah di
server basis data), id kunci cadangan `<id-kunci-cadangan>`. Jalur dan alat menyesuaikan server RS; jangan tulis
nilai nyata ke repo.

Yang **sudah diuji**: cadangan logis `pg_dump -Fc` lalu pulih ke database kosong, dengan data sintetis
([hasil-uji-pemulihan.md](hasil-uji-pemulihan.md)). Yang **belum diuji**: PITR (arsip WAL), enkripsi dan salinan
luar lokasi, pemulihan berkas, dan semua langkah di server RS. Bagian yang belum diuji ditandai.

## 1. Sasaran

| Sasaran | Nilai | Sumber |
|---|---|---|
| RPO (data hilang paling banyak) | ≤ 15 menit | PRD 14 baris Ketersediaan, PRD 11.4 |
| RTO (layanan pulih paling lama) | ≤ 4 jam | PRD 14 baris Ketersediaan |
| Bentuk cadangan | PITR (WAL archiving) + cadangan penuh harian terenkripsi | PRD 11.4 |
| Lokasi | Salinan wajib di lokasi berbeda dari RS | PRD 11.4, 14.2 baris Infrastruktur; Permenkes 24/2022 Ps. 20 ayat (4)–(5) |
| Uji pemulihan | Bulanan | PRD 11.4, 14.2 baris Infrastruktur |
| Retensi | Log audit mengikuti masa retensi rekam medis (paling singkat 25 tahun sejak kunjungan terakhir) | PRD 11.4 |

Cadangan logis harian saja memberi RPO sampai 24 jam. RPO ≤ 15 menit hanya tercapai dengan PITR (§3.1).

## 2. Yang dicadangkan

| Isi | Letak | Cara | Kapan |
|---|---|---|---|
| Basis data `simrs` (semua skema domain, `audit`, `sistem`) | server basis data | PITR (§3.1) + `pg_dump` harian terenkripsi (§3.2) | terus-menerus / harian |
| Berkas paket identitas (logo, motif, foto) | disk `IDENTITAS_DISK`; disk `local` = `storage/app/private/identitas/` | arsip terenkripsi (§5) | harian |
| Dokumen izin praktik pegawai | disk `SDM_DOKUMEN_DISK`; disk `local` = `storage/app/private/izin-praktik/` | arsip terenkripsi (§5) | harian |
| Anchor rantai audit | disk `AUDIT_ANCHOR_DISK` + `AUDIT_ANCHOR_DIREKTORI`; bawaan `storage/app/private/audit/anchor/anchor-rantai-YYYY-MM.jsonl` | sudah di penyimpanan terpisah (§6); ikut diarsip | harian |
| Log anchor | `AUDIT_ANCHOR_LOG`, bawaan `storage/logs/audit-anchor.log` | dikirim ke SIEM; ikut diarsip | harian |
| Kunci dan rahasia (`.env`) | server aplikasi | **vault RS**, terpisah dari cadangan data (§4) | setiap berubah |
| Konfigurasi server | nginx, pool php-fpm, unit systemd, cron, `valkey.conf`, `postgresql.conf`, `pg_hba.conf` | catatan instalasi RS (di luar repo) | setiap berubah |

**Tidak dicadangkan:** Valkey. Instans cache (`allkeys-lru`, tanpa persistensi) dibangun ulang sendiri. Instans sesi +
queue memakai AOF (`appendonly yes`) untuk mulai ulang biasa, tetapi tidak perlu dipulihkan dari cadangan: bila hilang,
pengguna masuk ulang, penghitung rate limit mulai dari nol, dan pekerjaan queue yang belum jalan hilang. Kiriman
integrasi tidak hilang karena tercatat di `integrasi.kotak_keluar` dan dikirim ulang oleh putaran tiap menit
(`PengirimKiriman::putaran`); email notifikasi yang masih di queue hilang (notifikasinya tetap ada di aplikasi).

Sumber jalur berkas: `KelolaProfilRs::gantiBerkas` (`identitas/<jenis>/...`), `Sdm\Application\KelolaKredensial`
(`izin-praktik/<pegawai>/...`), `Core\Audit\BerkasAnchor`, `config/filesystems.php` (akar disk `local` =
`storage/app/private`), `config/logging.php`.

## 3. Basis data

### 3.1 PITR: arsip WAL dan cadangan dasar (rencana, belum diuji)

Rencana ini memakai alat bawaan PostgreSQL 16. Alat khusus (mis. pgBackRest/WAL-G) adalah dependensi server baru dan
butuh keputusan pemilik (keputusan no. 3 di README). **Wajib diuji di server staging RS sebelum go-live.**

`postgresql.conf` di server basis data (contoh dari dokumentasi PostgreSQL, folder diganti):

```conf
wal_level = replica
archive_mode = on
archive_command = 'test ! -f /arsip/wal/%f && cp %p /arsip/wal/%f'
archive_timeout = 300        # paksa ganti segmen WAL tiap 5 menit agar RPO ≤ 15 menit tetap tercapai saat sepi
```

`archive_mode` butuh restart PostgreSQL. `/arsip` adalah penyimpanan terpisah dari disk data PostgreSQL, terenkripsi
di tingkat disk (PRD 14.2 baris Data), dan disalin ke lokasi kedua di luar RS. Pantau `pg_stat_archiver`
(`failed_count`, `last_failed_time`): bila arsip gagal, WAL menumpuk di `pg_wal` dan disk data bisa penuh.

Cadangan dasar (mis. mingguan), di server basis data sebagai `postgres`:

```bash
sudo -u postgres pg_basebackup -D /arsip/dasar/$(date +%Y%m%d) -Ft -z -Xs -P
```

Pemulihan ke titik waktu (garis besar, PostgreSQL 12+): hentikan PostgreSQL, sisihkan folder data lama, ekstrak
cadangan dasar terbaru sebelum titik target, isi `restore_command = 'cp /arsip/wal/%f %p'`,
`recovery_target_time = '<waktu target>'`, `recovery_target_action = 'promote'`, buat berkas `recovery.signal`, lalu
jalankan PostgreSQL. Sesudah itu lanjutkan dari §7 langkah 5 (jumlah baris, berkas, aplikasi, verifikasi).

### 3.2 Cadangan logis harian terenkripsi

Bentuk dan perintah `pg_dump`/`pg_restore` sama dengan uji pemulihan. Dijalankan di server basis data sebagai
`postgres` (autentikasi `peer` lokal, tanpa sandi di skrip). Enkripsi memakai kunci **publik** `gpg`, sehingga server
basis data tidak bisa membuka cadangannya sendiri; kunci privat ada di vault (§4). Alat enkripsi lain boleh dipakai
bila RS sudah punya standar (keputusan no. 3).

Jam: **03.00 zona RS**, di luar jendela 00.00–02.35 tempat jadwal aplikasi membuat atau menghapus partisi
([proses-dan-jadwal.md §3](proses-dan-jadwal.md#3-scheduler-dan-daftar-jadwal)). `integrasi:hapus-log-lama` memakai
`DROP TABLE` yang menunggu kunci tabel yang dipegang `pg_dump`, lalu gagal karena `statement_timeout` 5 detik.

`/usr/local/sbin/karsitara-cadangan-db` (pemilik root, mode 0750):

```bash
#!/usr/bin/env bash
# Cadangan logis harian basis data simrs (runbook cadangan-pemulihan.md §3.2). Dijalankan sebagai postgres.
set -euo pipefail
umask 077
TGL=$(date +%Y%m%d-%H%M)
TUJUAN=/arsip/logis
pg_dump -Fc -d simrs \
  | gpg --batch --yes --encrypt --recipient '<id-kunci-cadangan>' --output "$TUJUAN/simrs-$TGL.dump.gpg"
test -s "$TUJUAN/simrs-$TGL.dump.gpg"
```

`/etc/cron.d/karsitara-cadangan` di server basis data:

```cron
0 3 * * * postgres /usr/local/sbin/karsitara-cadangan-db
```

Lalu salin `/arsip/logis` ke lokasi kedua di luar RS (alat salin dan masa simpan menurut kebijakan RS). Catatan:

- Hanya database `simrs`. Role tingkat klaster **tidak** ikut; di server pemulihan dibuat ulang dari
  `docker/postgres/init/sql/01-role.sql` dengan sandi dari vault (bukan dari cadangan).
- Hak tingkat database (`CONNECT`/`TEMPORARY`) juga **tidak** ikut `pg_dump` tanpa `--create`; diberikan ulang
  sebelum `pg_restore` (§7 langkah 3). Hak tabel, hak bawaan (`ALTER DEFAULT PRIVILEGES`), skema, ekstensi, fungsi
  `SECURITY DEFINER`, dan trigger rantai audit ikut cadangan.
- Cadangan logis memuat data pasien. Perlakukan sama dengan basis data produksi; jangan dipulihkan ke lingkungan uji
  atau pengembangan (PRD 14.2: data uji sintetis, bukan salinan produksi).

## 4. Kunci dan rahasia yang dicadangkan terpisah

Simpan di vault RS, **terpisah** dari cadangan data: siapa pun yang memegang cadangan dan kunci sekaligus bisa membaca
seluruh data. Uji pemulihan (§9) memakai salinan dari vault, bukan dari server lama, agar salinan vault ikut teruji.

| Rahasia | Dipakai untuk | Bila hilang | Sumber |
|---|---|---|---|
| `APP_KEY` | Enkripsi rahasia TOTP (`akses.pengguna.mfa_rahasia_enkripsi`), NIK pegawai (`sdm.pegawai.nik_enkripsi`), rahasia TOTP sementara saat daftar MFA, cookie; kunci HMAC sidik NIK | Rahasia MFA dan NIK tidak bisa dibuka (error saat dibaca); semua MFA didaftar ulang, NIK diisi ulang | `Akses\Models\Pengguna`, `Sdm\Models\Pegawai`, `MfaChallenge` |
| `TTD_KUNCI` dan `TTD_KUNCI_LAMA` | Lada PIN TTE; membuka kunci privat tanda tangan pengguna | Kredensial TTE tidak bisa dibuka (`503 ttd_belum_dikonfigurasi`); setiap pemilik mengaktifkan ulang TTE. Tanda tangan lama tetap bisa diverifikasi (kunci publik di DB) | `Ttd\Infrastructure\KunciServer`, `Ttd\Domain\KunciTtd` |
| `KREDENSIAL_KUNCI` dan `KREDENSIAL_KUNCI_LAMA` | Enkripsi kredensial BPJS/SATUSEHAT (`integrasi.kredensial_api`) | Kredensial integrasi diminta ulang dari pemberinya dan diisi ulang | `Integrasi\Infrastructure\PenyandiKredensial` |
| Sandi role `simrs_app`, `simrs_migrator`, superuser `postgres` | Koneksi basis data; `01-role.sql` saat pemulihan | Diatur ulang oleh superuser | `01-role.sql`, `.env` |
| Sandi Valkey (dua instans), SMTP | `.env` | Diatur ulang | `config/database.php`, `config/mail.php` |
| Kunci privat `gpg` cadangan (dan frasa sandinya) | Membuka cadangan logis dan arsip berkas | **Semua cadangan terenkripsi tidak bisa dibuka** | §3.2, §5 |
| Kunci privat sertifikat TLS | nginx; PostgreSQL bila TLS antar-server | Terbitkan ulang | nginx-php.md |

Kunci yang sudah dirotasi tetap disimpan selama masih ada cadangan yang memakainya (contoh: cadangan bulan lalu memuat
kredensial integrasi yang dienkripsi dengan `KREDENSIAL_KUNCI` lama). Rotasi: [proses-dan-jadwal.md §5](proses-dan-jadwal.md#5-rotasi-kunci).

## 5. Berkas di server aplikasi (belum diuji)

Berkas paket identitas dan dokumen izin praktik dirujuk oleh basis data (jalur di `inti.profil_rs.paket_identitas` dan
tabel izin praktik). Berkas tidak pernah dihapus aplikasi ("berkas lama tetap ada", `KelolaProfilRs`), jadi arsip
berkas yang lebih baru dari cadangan basis data tetap cocok.

`/usr/local/sbin/karsitara-cadangan-berkas` di server aplikasi (root, cron 03.30):

```bash
#!/usr/bin/env bash
# Arsip harian berkas aplikasi (runbook cadangan-pemulihan.md §5).
set -euo pipefail
umask 077
TGL=$(date +%Y%m%d-%H%M)
B=/srv/karsitara/backend/storage
tar -C "$B" -cf - app/private/identitas app/private/izin-praktik app/private/audit/anchor logs/audit-anchor.log \
  | gpg --batch --yes --encrypt --recipient '<id-kunci-cadangan>' --output "/arsip-aplikasi/berkas-$TGL.tar.gpg"
test -s "/arsip-aplikasi/berkas-$TGL.tar.gpg"
```

Folder yang belum pernah dibuat aplikasi (mis. belum ada unggahan izin praktik) membuat `tar` gagal; buat foldernya
dulu atau sesuaikan daftar. Bila kelak disk S3/MinIO dipakai, berkas dicadangkan di sisi penyimpanan objek.

## 6. Anchor audit di penyimpanan terpisah

Rantai hash di `audit.log_aktivitas` mendeteksi baris yang diubah atau dihapus di tengah. Baris ekor yang dihapus, atau
rantai yang dihitung ulang seluruhnya, hanya terdeteksi lewat **anchor** yang tersimpan di luar jangkauan admin basis
data dan admin server aplikasi (ADR 0003 bagian 4 dan Konsekuensi).

Keadaan kode:

- `audit:ekspor-anchor` berjalan tiap jam (menit ke-5) dan menulis ke dua tempat: berkas JSON Lines per bulan di
  `AUDIT_ANCHOR_DISK`/`AUDIT_ANCHOR_DIREKTORI`, dan channel log `audit_anchor` (`AUDIT_ANCHOR_LOG`).
- Disk S3/MinIO dengan object lock belum bisa dipakai (adaptor belum ada, keputusan no. 2). Sementara: disk `local` +
  mount dari server lain tepat di `storage/app/private/audit/anchor`
  ([konfigurasi-env.md §8](konfigurasi-env.md#8-berkas-dan-audit-disk)).
- Berkas bulan berjalan **ditulis ulang utuh** setiap jam: `Storage::append()` membaca isi lama lalu menulis
  isi lama + baris baru (`Illuminate\Filesystem\FilesystemAdapter::append`). Atribut *append-only* atau WORM per
  berkas karena itu **tidak bisa** dipasang pada berkas bulan berjalan; tulis akan gagal.
- Disk `local` diset `'throw' => false`: kegagalan tulis tidak membuat perintah gagal. Bila mount terlepas, berkas
  ditulis ke disk server aplikasi tanpa peringatan. Pantau ([proses-dan-jadwal.md §6](proses-dan-jadwal.md#6-pemantauan-dasar)).

Yang dilakukan RS (belum diuji):

1. Ekspor folder anchor dari server lain (bukan server basis data, bukan server aplikasi), dikelola orang yang bukan
   admin basis data. Server aplikasi hanya diberi hak tulis ke folder itu.
2. Di server penyimpanan itu: snapshot berkala (mis. tiap jam atau harian) dengan masa simpan sepanjang retensi audit,
   dan berkas bulan yang sudah lewat dijadikan tidak bisa diubah (mis. `chattr +i` atau fitur WORM penyimpanan).
   Penulis di server aplikasi tetap bisa menimpa berkas bulan berjalan; snapshot di sisi penyimpanan yang menjaganya.
3. Channel log `audit_anchor` dikirim ke SIEM RS (salinan kedua yang tidak bisa diubah dari server aplikasi).
4. Arsip anchor ikut cadangan harian (§5) dan salinan luar lokasi.

Partisi audit lama yang kelak dipindah ke penyimpanan dingin harus tetap terpasang atau dicatat anchornya, agar
verifikasi bisa mulai dari anchor terakhir sebelum batas pindah (ADR 0003, Konsekuensi).

## 7. Urutan pemulihan

Urutan langkah 1–5 **sama dengan uji pemulihan 12 Okt 2026** ([hasil-uji-pemulihan.md](hasil-uji-pemulihan.md)).
Langkah 6–10 belum diuji. Catat waktu tiap langkah untuk dibandingkan dengan RTO 4 jam.

1. **Server PostgreSQL 16 kosong** dengan paket ekstensi `pg_trgm` dan `unaccent` (contrib). Buat role tingkat
   klaster dengan skrip repo; sandi dari vault:

   ```bash
   cd /srv/karsitara   # salinan repo dengan tag yang sama dengan instalasi
   psql -v ON_ERROR_STOP=1 -d postgres \
     -v migrator_password="$SANDI_MIGRATOR" -v app_password="$SANDI_APP" \
     -f docker/postgres/init/sql/01-role.sql
   ```

2. **Database kosong** milik `simrs_migrator`:

   ```bash
   psql -v ON_ERROR_STOP=1 -d postgres -c "CREATE DATABASE simrs OWNER simrs_migrator"
   ```

3. **Hak tingkat database sebelum `pg_restore`** (tidak ikut `pg_dump`):

   ```bash
   psql -v ON_ERROR_STOP=1 -d postgres -c "REVOKE ALL ON DATABASE simrs FROM PUBLIC" \
     -c "GRANT CONNECT, TEMPORARY ON DATABASE simrs TO simrs_app"
   ```

   `02-database.sql` juga memberi `CONNECT` ke `simrs_analitik` dan `simrs_baca`. Keduanya `NOLOGIN` sampai fase 4,
   jadi belum dibutuhkan dan tidak ikut diuji. **Jangan** menjalankan `02-database.sql` ke database ini: skema,
   ekstensi, dan hak sudah ada di cadangan.

4. **Buka cadangan dan pulihkan** sebagai `postgres`. Dekripsi di mesin yang memegang kunci privat, ke folder
   bermode 0700, lalu hapus berkas terbuka sesudah selesai:

   ```bash
   gpg --decrypt simrs-<tanggal>.dump.gpg > /pulih/simrs.dump
   pg_restore --exit-on-error -d simrs /pulih/simrs.dump
   ```

   Untuk PITR, langkah 1–4 diganti pemulihan titik waktu (§3.1), lalu lanjut ke langkah 5.

5. **Bandingkan jumlah baris** dengan sumber (bila sumber masih ada) atau dengan catatan saat cadangan dibuat. Tabel
   yang dipakai uji: `akses.pengguna`, `akses.peran`, `akses.menu`, `inti.unit_organisasi`, `audit.log_aktivitas`,
   `sistem.migrations`.

6. **Berkas**: pulihkan arsip §5 ke `/srv/karsitara/backend/storage` (pemilik `www-data`). Folder anchor dipulihkan
   hanya bila penyimpanan anchor juga hilang; bila tidak, biarkan anchor di penyimpanan terpisah apa adanya.

7. **Server aplikasi**: kode dengan tag rilis yang sama dengan isi `sistem.migrations`; `.env` dengan `APP_KEY`,
   `TTD_KUNCI`, `KREDENSIAL_KUNCI` (dan kunci lama) **dari vault**. Lalu:

   ```bash
   cd /srv/karsitara/backend
   sudo -u www-data php artisan optimize
   sudo -u www-data php artisan cache:clear      # kosongkan cache lama di Valkey cache (bukan sesi, bukan rate limit)
   sudo systemctl reload php8.4-fpm
   ```

   **Horizon dan cron scheduler belum dinyalakan** (langkah 9).

8. **Verifikasi** (§8).

9. **Rekonsiliasi integrasi, lalu nyalakan Horizon dan cron.** Bila cadangan lebih lama dari saat kejadian, kiriman
   yang sudah terkirim sesudah titik cadangan kembali berstatus `menunggu` di `integrasi.kotak_keluar`, dan putaran
   tiap menit akan mengirimnya lagi ke BPJS/SATUSEHAT. Periksa dulu kiriman `menunggu` dan `tertahan` yang dibuat
   sebelum titik cadangan bersama pemilik integrasi (prosedur resmi belum ada; keputusan no. 4 di README). Sesudah itu:

   ```bash
   sudo systemctl start karsitara-horizon
   # pasang baris cron /etc/cron.d/karsitara (proses-dan-jadwal.md §3) dari catatan instalasi RS
   sudo -u www-data php artisan audit:ekspor-anchor
   ```

10. **Catat hasil** (waktu tiap langkah, hasil verifikasi, data yang hilang) dalam berita acara RS. Hasil pemulihan di
    server RS **tidak** ditulis ke repo ini (repo publik); repo hanya memuat hasil uji dengan data sintetis.

## 8. Verifikasi sesudah pulih

Sama dengan uji pemulihan:

```bash
cd /srv/karsitara/backend
sudo -u www-data php artisan migrate:status                       # tidak ada migrasi tertunda
sudo -u www-data php artisan audit:verifikasi-rantai --sampai=<tanggal cadangan, Y-m-d>   # Rantai utuh; Anchor cocok
curl -fsS https://simrs.rs-contoh.invalid/api/v1/siap             # {"status":"siap",...}
psql -h <host-db> -U simrs_app -d simrs -c 'SHOW statement_timeout'   # 5s
psql -d simrs -c "SELECT has_table_privilege('simrs_app', 'audit.log_aktivitas', 'INSERT') AS audit_insert,
  has_table_privilege('simrs_app', 'audit.log_aktivitas', 'DELETE') AS audit_delete,
  has_table_privilege('simrs_app', 'akses.pengguna', 'UPDATE') AS pengguna_update,
  has_database_privilege('simrs_app', 'simrs', 'CONNECT') AS app_connect,
  has_database_privilege('public', 'simrs', 'CONNECT') AS public_connect"
# Harapan: t, f, t, t, f
```

Masuk lewat web dengan akun superadmin (MFA harus diterima: bukti `APP_KEY` benar) dan, bila TTE sudah dipakai, uji
satu tanda tangan (bukti `TTD_KUNCI` benar). Uji koneksi integrasi di Pengaturan Sistem → Konfigurasi integrasi
(bukti `KREDENSIAL_KUNCI` benar).

**Anchor sesudah pemulihan yang kehilangan data.** `audit:verifikasi-rantai` tanpa `--sampai` memeriksa **semua**
anchor sampai ekor. Anchor yang ditulis sesudah titik cadangan menunjuk urutan yang tidak ada lagi di basis data hasil
pulih, sehingga perintah itu gagal dengan `Anchor <batas> TIDAK COCOK: urutan <n> hilang`. Itu bukti jendela data yang
hilang, bukan kerusakan cadangan. Karena itu verifikasi sesudah pulih memakai `--sampai=<tanggal cadangan>`: dengan
`--sampai`, anchor yang urutannya melewati ekor rentang tidak diperiksa (`VerifikasiRantai::periksaAnchor`).

Sesudah aplikasi berjalan lagi, baris audit baru memakai ulang nomor urutan itu dengan hash berbeda, jadi anchor lama
dari jendela yang hilang **tidak akan pernah cocok lagi** dan verifikasi tanpa `--sampai` terus gagal. Usulan
(belum disahkan, keputusan no. 4): dengan berita acara, pindahkan baris anchor yang `batas`-nya sesudah titik
cadangan ke berkas arsip di luar `AUDIT_ANCHOR_DIREKTORI` (perintah hanya membaca berkas `*.jsonl` di folder itu,
tidak termasuk subfolder), simpan arsip itu bersama berita acara, lalu jalankan `audit:verifikasi-rantai` penuh.

## 9. Jadwal uji pemulihan

| Kapan | Uji | Catatan |
|---|---|---|
| Sebelum go-live | Pemulihan logis (§7) **dan** PITR ke titik waktu tertentu, di server terpisah | Wajib sebelum data pasien pertama masuk (instalasi.md §9) |
| Bulanan | Bergantian: bulan ganjil pemulihan logis dari salinan **luar lokasi**, bulan genap PITR | PRD 11.4 dan 14.2 meminta uji bulanan; pembagian ganjil/genap adalah usulan runbook |
| Sesudah rilis yang memuat migrasi besar | Pemulihan logis dari cadangan sebelum rilis | Membuktikan cadangan sebelum migrasi bisa dipakai bila rilis gagal (migrasi maju saja) |
| Setiap rotasi kunci | Buka satu cadangan lama dengan kunci dari vault | Membuktikan kunci lama masih tersimpan |

Uji memakai server terpisah di jaringan RS, bukan server produksi, dan hasilnya (data RS) tidak dibawa keluar. Ukur
RTO dari awal langkah 1 sampai `/api/v1/siap` menjawab `siap`, dan RPO dari waktu cadangan/WAL terakhir yang
terpulihkan. Format hasil mengikuti tabel di [hasil-uji-pemulihan.md](hasil-uji-pemulihan.md); hasil uji di server RS
disimpan di catatan RS, bukan di repo.
