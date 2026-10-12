# Proses, queue, dan jadwal

Draf, tiket 1.12. Contoh: folder `/srv/karsitara`, pengguna sistem `www-data`, PHP 8.4 dari paket distribusi, domain
`simrs.rs-contoh.invalid`. Sumber utama: `backend/routes/console.php`, `backend/config/horizon.php`,
`backend/config/queue.php`, `App\Core\Queue\JenisQueue`, dan `$signature` perintah konsol di `backend/app`.
Diperiksa terhadap `main` per 12 Okt 2026 (sesudah 1.9-fe); cabang 1.5 (`feat/akses-lapis45`) tidak menambah jadwal
atau perintah.

## 1. Ringkasan proses di server aplikasi

| Proses | Dijalankan oleh | Tugas | Bila mati |
|---|---|---|---|
| nginx | systemd (paket distribusi) | TLS, berkas SPA, meneruskan `/api` dan `/sanctum` ke php-fpm | Situs tidak bisa dibuka |
| php-fpm (pool `karsitara`) | systemd (paket distribusi) | Permintaan API | API `502`/`504` |
| Horizon | systemd, unit `karsitara-horizon` (§4) | Pekerja queue per jenis | Email notifikasi, eskalasi tugas, dan kiriman integrasi berhenti; menumpuk di Valkey |
| Scheduler | cron tiap menit, `php artisan schedule:run` (§3) | Menjalankan 13 jadwal | Partisi bulanan tidak dibuat, anchor audit berhenti, eskalasi dan outbox tidak diputar |

Semua proses aplikasi berjalan sebagai `www-data`, membaca `.env` dan cache konfigurasi yang sama
(`bootstrap/cache/config.php`). Setelah `.env` berubah atau rilis baru, ketiganya harus memuat ulang:

```bash
cd /srv/karsitara/backend
sudo -u www-data php artisan optimize          # cache konfigurasi, rute, event, view
sudo systemctl reload php8.4-fpm               # opcache.validate_timestamps=0 (nginx-php.md §5)
sudo -u www-data php artisan horizon:terminate # systemd menyalakan ulang Horizon dengan konfigurasi baru
# Scheduler tidak perlu dimuat ulang: setiap menit cron menjalankan proses PHP baru.
```

## 2. php-fpm

Konfigurasi pool, php.ini, dan batas waktu ada di [nginx-php.md §5–6](nginx-php.md#5-phpini-fpm-dan-cli). Hal yang
berkaitan dengan proses lain:

- `pm.max_children` + 18 pekerja Horizon + 1 proses scheduler + koneksi migrasi saat rilis harus muat di
  `max_connections` PostgreSQL (belum ada PgBouncer).
- `reload` (bukan `restart`) cukup setelah rilis; permintaan yang sedang berjalan diselesaikan dulu.
- Pemeriksaan hidup: `GET /up` (Laravel, `bootstrap/app.php`); pemeriksaan siap: `GET /api/v1/siap` (§6).

## 3. Scheduler dan daftar jadwal

Laravel scheduler butuh satu baris cron yang berjalan **setiap menit** (ADR 0003, komentar di `routes/console.php`).
Pasang di satu server aplikasi saja; bila kelak ada lebih dari satu server, semua jadwal sudah memakai `onOneServer()`
dengan kunci di Valkey instans `default` (store `redis`, `lock_connection` `default`, `config/cache.php`).

`/etc/cron.d/karsitara`:

```cron
# Laravel scheduler Karsitara SIMRS (routes/console.php). Pengguna www-data, setiap menit.
* * * * * www-data cd /srv/karsitara/backend && php artisan schedule:run >> /dev/null 2>&1 && touch storage/framework/schedule-terakhir
```

Baris `touch` hanya penanda hidup untuk pemantauan (§6); bukan bagian dari kode. Error perintah terjadwal tetap
tercatat di log Laravel (`storage/logs/`).

Periksa:

```bash
sudo -u www-data php artisan schedule:list    # harus 13 baris, sama dengan tabel di bawah
```

### Daftar jadwal (13)

Sumber: `backend/routes/console.php`. Zona: "RS" = `INSTALASI_ZONA_WAKTU`, "audit" = `AUDIT_ZONA_WAKTU` (keduanya
bawaan `Asia/Jakarta`); "aplikasi" = zona aplikasi `UTC` (`config/app.php`). Semua `onOneServer()`. Semua perintah
"aman diulang" menurut komentar kelasnya.

| Waktu | Zona | Perintah / pekerjaan | Fungsi | Sumber kelas |
|---|---|---|---|---|
| setiap menit | aplikasi | pekerjaan `ProsesEskalasiTugas` → queue `notifikasi` | Pengingat, teguran, dan eskalasi tugas yang waktunya tercapai (FR-53-03). Unik 120 detik (`ShouldBeUnique`). | `Notifikasi\Jobs\ProsesEskalasiTugas` |
| setiap menit | aplikasi | pekerjaan `ProsesKotakKeluar` → queue `integrasi` | Putaran outbox: kirim kiriman `menunggu` yang jatuh tempo (maks 100 per sistem, 1 bila circuit breaker setengah terbuka), periksa ulang kiriman `tertahan`. Unik 120 detik. | `Integrasi\Jobs\ProsesKotakKeluar` |
| tiap jam menit ke-5 | aplikasi | `audit:ekspor-anchor` | Salin `(urutan, id, hash_rantai)` baris audit terakhir sebelum awal jam ke berkas anchor (`AUDIT_ANCHOR_DISK`) dan channel log `audit_anchor`. Jam tanpa aktivitas tidak menulis ulang. | `Core\Audit\Console\EksporAnchor` |
| 00.00 | RS | `master-data:terapkan-usulan` | Terapkan usulan data induk yang disetujui dan jatuh tempo (1.8c). | `MasterData\Console\TerapkanUsulan` |
| 00.05 | RS | `master-data:rekam-kapasitas-tt` | Rekam kapasitas TT harian per bangsal dan kelas, penyebut BOR/BTO/TOI (1.8b). | `MasterData\Console\RekamKapasitasTempatTidur` |
| 00.10 | RS | `sdm:proses-pegawai-keluar` | Pegawai yang tanggal keluarnya tercapai: akun nonaktif, sesi diputus (PRD 9.2.6). | `Sdm\Console\ProsesPegawaiKeluar` |
| 00.15 | RS | `integrasi:sinkron-aplicares` | Sinkron menyeluruh ketersediaan TT ke Aplicares sesudah rekaman kapasitas 00.05 (1.9-be bagian 3, #82). | `Integrasi\Console\SinkronAplicaresCommand` |
| 01.00 | RS | `akses:kedaluwarsakan-persetujuan` | Persetujuan akses yang lewat 7 hari tanpa keputusan menjadi kedaluwarsa (1.2d). | `Akses\Console\KedaluwarsakanPersetujuan` |
| 02.00 | audit | `audit:siapkan-partisi` | Partisi `audit.log_aktivitas` bulan ini + `AUDIT_PARTISI_BULAN_KE_DEPAN` (bawaan 3). Menulis warning bila partisi `DEFAULT` berisi baris; gagal bila `bentrok_default`. | `Core\Audit\Console\SiapkanPartisi` |
| 02.10 | audit | `notifikasi:siapkan-partisi` | Partisi `notifikasi.notifikasi` bulan ini + 3 bulan. | `Notifikasi\Console\SiapkanPartisi` |
| 02.20 | audit | `integrasi:siapkan-partisi` | Partisi `integrasi.kotak_keluar` dan `integrasi.log_api` bulan ini + 3 bulan (`--bulan=3`). | `Integrasi\Console\SiapkanPartisi` |
| 02.30 | audit | `integrasi:hapus-log-lama` | `DROP` partisi bulanan `integrasi.log_api` yang seluruhnya lebih tua dari `INTEGRASI_LOG_HARI` (min. 30 hari). | `Integrasi\Console\HapusLogLama` |
| 06.00 | RS | `notifikasi:periksa-izin-praktik` | Buat tugas untuk izin praktik yang akan berakhir (FR-53-05). | `Notifikasi\Console\PeriksaIzinPraktik` |

Catatan:

- Dua pekerjaan "setiap menit" hanya **dimasukkan** ke queue oleh scheduler; yang menjalankan adalah Horizon. Bila
  Horizon mati, keduanya menumpuk (dibatasi kunci unik 120 detik).
- Perintah lain berjalan langsung di proses `schedule:run`, berurutan. Pukul 00.05 `rekam-kapasitas-tt` dan
  `ekspor-anchor` jatuh di menit yang sama dan berjalan bergantian.
- **Jendela 02.00–02.35 zona audit** dipakai jadwal yang membuat partisi (DDL lewat fungsi `SECURITY DEFINER`) atau
  menghapus partisi (`DROP`); 00.00–00.15 zona RS berisi pekerjaan harian data induk dan SDM. Cadangan logis harian
  dijadwalkan di luar kedua jendela ini ([cadangan-pemulihan.md §3.2](cadangan-pemulihan.md#32-cadangan-logis-harian-terenkripsi)).
- **Tidak dijadwalkan di kode** (lihat [README](README.md#yang-belum-tersedia-di-repo-jangan-dikarang-saat-memasang)):
  `audit:verifikasi-rantai`, `queue:prune-failed`, `horizon:snapshot`. Usulan timer verifikasi rantai ada di §6.

## 4. Horizon (queue per jenis)

Sumber: `config/horizon.php`, `config/queue.php`, `App\Core\Queue\JenisQueue` (PRD 14.1.1 butir 4). Satu supervisor
per jenis queue, dibentuk dari enum, sehingga sistem luar yang macet tidak memakai pekerja jenis lain.

| Supervisor | Queue | Koneksi (`retry_after`) | Batas waktu per pekerjaan | Pekerja maks (`production`) | Pekerjaan yang ada di fase 1 |
|---|---|---|---|---|---|
| `queue-integrasi` | `integrasi` | `redis` (150 s) | 60 s | 6 | `KirimKiriman`, `ProsesKotakKeluar` |
| `queue-notifikasi` | `notifikasi` | `redis` (150 s) | 30 s | 3 | `KirimEmailNotifikasi`, `ProsesEskalasiTugas` |
| `queue-dokumen` | `dokumen` | `redis` (150 s) | 120 s | 4 | belum ada |
| `queue-laporan` | `laporan` | `redis-laporan` (960 s) | 900 s | 2 | belum ada |
| `queue-bawaan` | `bawaan` | `redis` (150 s) | 60 s | 3 | belum ada |

Pengaturan bersama (`defaults`): `minProcesses 1`, `balance auto` (strategi `time`), `tries 1`, `memory 128` MB per
pekerja, `maxTime`/`maxJobs` 0. Jumlah pekerja maksimum hanya berlaku bila `APP_ENV=production`; total 18. Horizon
master dibatasi 64 MB. Semua queue memakai Valkey instans `default` (`noeviction`).

Lain-lain dari `config/horizon.php`:

- Ambang `LongWaitDetected` 60 detik untuk setiap queue, tetapi tujuan notifikasinya **belum diatur** (README).
- Riwayat pekerjaan selesai disimpan 60 menit, pekerjaan gagal 7 hari di Valkey. Pekerjaan gagal juga dicatat di
  `sistem.failed_jobs` (`QUEUE_FAILED_DRIVER` bawaan `database-uuids`) dan tidak dibersihkan otomatis.
- Dasbor `/horizon` ditolak untuk semua akun di luar `APP_ENV=local` (`HorizonServiceProvider`); nginx juga
  menjawab 404. Pemantauan lewat perintah konsol (§6).

`/etc/systemd/system/karsitara-horizon.service`:

```ini
[Unit]
Description=Karsitara SIMRS - Horizon (pekerja queue)
After=network-online.target
Wants=network-online.target

[Service]
User=www-data
Group=www-data
WorkingDirectory=/srv/karsitara/backend
ExecStart=/usr/bin/php artisan horizon
Restart=always
RestartSec=5
# horizon:terminate dan systemctl stop: master menghentikan supervisor dan menunggu pekerjaan berjalan selesai.
# Batas terpanjang adalah queue laporan (900 s), jadi beri jeda lebih dari itu sebelum SIGKILL.
KillMode=mixed
KillSignal=SIGTERM
TimeoutStopSec=960

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now karsitara-horizon
sudo -u www-data php artisan horizon:status            # "Horizon is running."
sudo -u www-data php artisan horizon:supervisors       # lima supervisor queue-*
```

`Restart=always` membuat `php artisan horizon:terminate` (saat rilis atau ubah `.env`) cukup untuk memuat kode baru.
Unit ini belum diuji di server RS.

## 5. Rotasi kunci

PRD 14.2 meminta kunci dirotasi (tahunan dan saat ada pergantian personel). Keadaan kode:

| Kunci | Bisa dirotasi | Cara | Sumber |
|---|---|---|---|
| `KREDENSIAL_KUNCI` | Ya | Langkah di bawah; enkripsi ulang lewat `integrasi:rotasi-kunci`. | `Integrasi\Console\RotasiKunciKredensial`, `config/integrasi.php` |
| `TTD_KUNCI` | Ya, bertahap | Kunci lama dipindah ke `TTD_KUNCI_LAMA`. Kredensial TTE lama tetap terbuka dengan kunci lama (id kunci di `ttd.kredensial.parameter_kdf->>'kunci'`) dan baru dibungkus ulang dengan kunci baru saat pemiliknya mengaktifkan ulang atau mengganti PIN. Tidak ada perintah enkripsi ulang massal (PIN tidak diketahui server). | `Ttd\Infrastructure\KunciServer`, `Ttd\Application\KelolaTtd` |
| `APP_KEY` | **Belum** | Sidik NIK pegawai (`Pegawai::sidik`) memakai `APP_KEY`; belum ada perintah hitung ulang (utang 1.8d). | `Sdm\Models\Pegawai` |

Rotasi `KREDENSIAL_KUNCI`:

```bash
cd /srv/karsitara/backend
php -r 'echo "base64:".base64_encode(random_bytes(32)), PHP_EOL;'   # kunci baru; simpan di vault dulu
# Ubah .env: KREDENSIAL_KUNCI_LAMA=<kunci lama>   KREDENSIAL_KUNCI=<kunci baru>
sudo -u www-data php artisan optimize
sudo systemctl reload php8.4-fpm && sudo -u www-data php artisan horizon:terminate
sudo -u www-data php artisan integrasi:rotasi-kunci          # "<n> kredensial dienkripsi ulang."; diaudit integrasi.kunci_dirotasi
# Uji koneksi tiap sistem di Pengaturan Sistem → Konfigurasi integrasi, lalu kosongkan KREDENSIAL_KUNCI_LAMA,
# optimize, reload, horizon:terminate. Kunci lama dihapus dari vault sesudah cadangan yang memakainya kedaluwarsa.
```

Rotasi `TTD_KUNCI`: sama untuk `.env` (`TTD_KUNCI_LAMA=<lama>`, `TTD_KUNCI=<baru>`), lalu pantau sisa kredensial
dengan kunci lama:

```sql
SELECT parameter_kdf->>'kunci' AS id_kunci, count(*) FROM ttd.kredensial WHERE status = 'aktif' GROUP BY 1;
```

`TTD_KUNCI_LAMA` baru boleh dikosongkan bila tidak ada lagi kredensial aktif dengan id kunci lama; bila dikosongkan
lebih cepat, pemilik kredensial itu mendapat `503 ttd_belum_dikonfigurasi` dan harus mengaktifkan ulang TTE.

## 6. Pemantauan dasar

PRD 14.1.1 butir 10 dan 14.2 baris Pemantauan meminta ambang peringatan dan SIEM; alat pemantau dan tujuan peringatan
dipilih RS (keputusan no. 7 di README). Yang bisa diperiksa dari repo:

| Hal | Cara | Normal |
|---|---|---|
| Siap melayani | `curl -fsS https://simrs.rs-contoh.invalid/api/v1/siap` (rate limit 60/menit per IP, `RATE_LIMIT_SIAP_PER_IP_PER_MENIT`) | `{"status":"siap",...}`; `503 layanan_tidak_tersedia` menyebut komponen gagal (`basis_data`, `valkey_sesi`, `valkey_cache`) |
| Hidup | `GET /up` dari IP pemantau | `200` |
| Horizon | `sudo -u www-data php artisan horizon:status` | `running` |
| Panjang queue per jenis | `sudo -u www-data php artisan queue:monitor redis:integrasi,redis:notifikasi,redis:dokumen,redis:bawaan,redis-laporan:laporan --max=100` | di bawah ambang yang ditetapkan RS |
| Pekerjaan gagal | `sudo -u www-data php artisan queue:failed`; atau `SELECT count(*) FROM sistem.failed_jobs WHERE failed_at > now() - interval '1 day'` | 0 baru |
| Outbox integrasi | Layar Integrasi dan Laporan → SATUSEHAT / Bridging BPJS; atau `SELECT tujuan, status, count(*) FROM integrasi.kotak_keluar GROUP BY 1, 2` | `gagal`/`tertahan` ditindaklanjuti pemilik unit |
| Scheduler hidup | Umur `storage/framework/schedule-terakhir` (baris cron §3) | < 2 menit |
| Anchor audit | Berkas `storage/app/private/audit/anchor/anchor-rantai-YYYY-MM.jsonl` bertambah; `mountpoint -q storage/app/private/audit/anchor` | Baris terakhir < 2 jam saat RS beraktivitas; mount terpasang |
| Partisi `DEFAULT` | Warning `Partisi DEFAULT audit.log_aktivitas berisi baris` di log; atau `SELECT count(*) FROM audit.log_aktivitas_default` (juga `notifikasi.notifikasi_default`, `integrasi.kotak_keluar_default`, `integrasi.log_api_default`) | 0; bila tidak, prosedur DBA di ADR 0003 |
| Memori Valkey sesi + queue | `valkey-cli -p 6379 -a '<sandi>' INFO memory` (`used_memory` vs `maxmemory`) | jauh di bawah `maxmemory`; saat penuh **tulis ditolak** (`noeviction`): login dan queue gagal |
| Ruang disk | `df -h` untuk data PostgreSQL, arsip WAL, `storage/logs`, mount anchor | sesuai ambang RS |
| Log aplikasi | `storage/logs/laravel-*.log` level `ERROR`/`CRITICAL`; kirim ke SIEM. Isinya sudah disamarkan (`App\Core\Log\InstallMasking`) | — |
| Cadangan | Lihat [cadangan-pemulihan.md §3](cadangan-pemulihan.md#3-basis-data) | berkas harian ada dan tidak kosong |

**Usulan** (tidak ada di kode; keputusan no. 5 di README): verifikasi rantai audit harian untuk hari kemarin lewat
timer systemd. `audit:verifikasi-rantai` berjalan sebagai `simrs_app` dengan `statement_timeout` 5 detik; verifikasi
seluruh tabel akan melewati batas itu saat tabel besar, jadi pakai rentang satu hari. Waktu yang dibutuhkan untuk
satu hari data RS belum diukur.

`/etc/systemd/system/karsitara-verifikasi-audit.service`:

```ini
[Unit]
Description=Karsitara SIMRS - verifikasi rantai audit hari kemarin

[Service]
Type=oneshot
User=www-data
WorkingDirectory=/srv/karsitara/backend
ExecStart=/bin/sh -c 'H=$(TZ=Asia/Jakarta date -d yesterday +%%F); exec php artisan audit:verifikasi-rantai --dari=$H --sampai=$H'
```

`/etc/systemd/system/karsitara-verifikasi-audit.timer`:

```ini
[Unit]
Description=Jadwal verifikasi rantai audit harian

[Timer]
OnCalendar=*-*-* 04:00:00
Persistent=true

[Install]
WantedBy=timers.target
```

Keluar dengan status gagal bila rantai putus atau anchor tidak cocok (`Rantai PUTUS pada urutan ...`, `Anchor ...
TIDAK COCOK`); arahkan kegagalan unit ini ke peringatan RS. Pesan hanya memuat penanda baris, bukan isi audit.

## 7. Perintah konsol operasional

Semua dijalankan sebagai `www-data` dari `/srv/karsitara/backend`. Sumber: `$signature` di kelas yang disebut.

| Perintah | Kegunaan | Kapan | Sumber |
|---|---|---|---|
| `akses:superadmin-pertama [--nama-pengguna=] [--nama=] [--nip=] [--email=]` | Buat superadmin bernama dengan MFA (paling banyak 2 lewat konsol); sandi lewat prompt tersembunyi | Instalasi baru ([instalasi.md §8.1](instalasi.md#81-superadmin-pertama-dan-mfa)) | `Akses\Console\BuatSuperadminPertama` |
| `referensi:muat <wilayah\|referensi\|icd10\|icd9cm\|loinc\|ucum> <berkas.csv> --versi= --sumber= [--lisensi=] [--pratinjau]` | Muat satu versi paket referensi; LOINC wajib `--lisensi` | Wizard langkah 4, pembaruan referensi | `MasterData\Console\MuatReferensi` |
| `audit:verifikasi-rantai [--dari=Y-m-d] [--sampai=Y-m-d]` | Periksa rantai hash dan anchor; gagal pada baris putus pertama | Harian (usulan §6), sesudah pemulihan | `Core\Audit\Console\VerifikasiRantai` |
| `audit:ekspor-anchor [--sampai=<tanggal-waktu>]` | Tulis anchor sekarang (terjadwal tiap jam) | Uji mount anchor; sesudah pemulihan | `Core\Audit\Console\EksporAnchor` |
| `audit:siapkan-partisi [--bulan=]` | Buat partisi audit (terjadwal) | Instalasi baru; bila jadwal terlewat | `Core\Audit\Console\SiapkanPartisi` |
| `notifikasi:siapkan-partisi` | Buat partisi notifikasi (terjadwal) | Instalasi baru; bila jadwal terlewat | `Notifikasi\Console\SiapkanPartisi` |
| `integrasi:siapkan-partisi [--bulan=3]` | Buat partisi kotak keluar dan log integrasi (terjadwal) | Instalasi baru; bila jadwal terlewat | `Integrasi\Console\SiapkanPartisi` |
| `integrasi:hapus-log-lama` | Hapus partisi log integrasi lewat masa simpan (terjadwal) | Bila jadwal terlewat | `Integrasi\Console\HapusLogLama` |
| `integrasi:sinkron-aplicares` | Kirim ulang ketersediaan TT menyeluruh ke Aplicares (terjadwal) | Bila jadwal terlewat; sesudah pemulihan | `Integrasi\Console\SinkronAplicaresCommand` |
| `integrasi:rotasi-kunci` | Enkripsi ulang kredensial integrasi dengan `KREDENSIAL_KUNCI` aktif | Rotasi kunci (§5) | `Integrasi\Console\RotasiKunciKredensial` |
| `notifikasi:proses-eskalasi` | Jalankan eskalasi tugas sekali, langsung (versi konsol dari pekerjaan terjadwal) | Bila queue `notifikasi` bermasalah | `Notifikasi\Console\ProsesEskalasi` |
| `notifikasi:periksa-izin-praktik` | Buat tugas izin praktik yang akan berakhir (terjadwal) | Bila jadwal terlewat | `Notifikasi\Console\PeriksaIzinPraktik` |
| `master-data:terapkan-usulan` | Terapkan usulan data induk jatuh tempo (terjadwal) | Bila jadwal terlewat | `MasterData\Console\TerapkanUsulan` |
| `master-data:rekam-kapasitas-tt [--tanggal=Y-m-d]` | Rekam kapasitas TT (terjadwal); bisa untuk tanggal terlewat | Bila jadwal terlewat | `MasterData\Console\RekamKapasitasTempatTidur` |
| `sdm:proses-pegawai-keluar` | Nonaktifkan akun pegawai keluar (terjadwal) | Bila jadwal terlewat | `Sdm\Console\ProsesPegawaiKeluar` |
| `akses:kedaluwarsakan-persetujuan` | Kedaluwarsakan persetujuan akses lewat 7 hari (terjadwal) | Bila jadwal terlewat | `Akses\Console\KedaluwarsakanPersetujuan` |
| `integrasi:simulator <sistem> <mode>` | Atur simulator integrasi | **Tidak untuk produksi**: ditolak bila `APP_ENV=production` | `Integrasi\Console\SimulatorMode` |

Perintah Laravel/Horizon yang dipakai runbook: `optimize`, `optimize:clear`, `config:clear`, `cache:clear`, `about`,
`down`/`up`, `migrate --database=pgsql_migrator`, `migrate:status`, `schedule:list`, `schedule:run`, `horizon`,
`horizon:status`, `horizon:supervisors`, `horizon:terminate`, `horizon:pause`/`horizon:continue`, `queue:failed`,
`queue:retry`, `queue:monitor`.
