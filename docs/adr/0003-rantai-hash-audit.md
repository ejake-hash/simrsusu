# 0003. Rantai hash audit, partisi bulanan, dan jangkar per jam

- Status: Diterima
- Tanggal: 2026-10-11
- Terkait: PRD 7.57.2 butir d, 11.1, 11.2 (`audit.log_aktivitas`), 14.2; tiket feat/audit-rantai

## Konteks

PRD 7.57.2 butir d (syarat PP 71/2019 Ps. 59 ayat 3) meminta perubahan informasi elektronik "dapat
diketahui", dengan `audit.log_aktivitas` memakai **hash berantai per hari dan cap waktu**. PRD 11.2
menandai tabel ini untuk dipartisi per bulan, dan retensinya mengikuti RM (paling singkat 25 tahun,
partisi lama boleh dipindah ke penyimpanan dingin tetapi tetap bisa dibaca).

Hak `simrs_app` (hanya `SELECT, INSERT` di skema `audit`) mencegah aplikasi mengubah log, tetapi
tidak mencegah pemilik tabel, superuser, atau orang yang memegang cadangan basis data. Yang
dibutuhkan adalah cara **mendeteksi** perubahan, bukan hanya mencegahnya.

## Keputusan

### 1. Partisi

- `audit.log_aktivitas` berpartisi `RANGE (terjadi_pada)`, satu partisi per bulan UTC, PK
  `(id, terjadi_pada)`, plus partisi `DEFAULT` sebagai jaring pengaman.
- Partisi dibuat fungsi `audit.siapkan_partisi(acuan, bulan_ke_depan)`. Perintah terjadwal
  `audit:siapkan-partisi` (harian 02.00) membuat bulan ini + 3 bulan ke depan. Server aplikasi
  tidak perlu kredensial DDL. Tanpa pg_partman. Karena fungsi ini menjalankan DDL atas nama
  pemiliknya, ia dikeraskan (semua butir diuji di `RantaiAuditTest::test_fungsi_partisi_dikeraskan`):

  | Butir | Ketentuan |
  |---|---|
  | Pemilik | `simrs_migrator`, `SECURITY DEFINER`; `simrs_app` tidak bisa mengganti isinya |
  | `search_path` | dikunci `pg_catalog, pg_temp` di **semua** fungsi skema `audit`; nama objek ditulis lengkap |
  | `EXECUTE` | dicabut dari `PUBLIC`, hanya `simrs_app` |
  | Validasi masukan | `acuan` dan `bulan_ke_depan` wajib; `bulan_ke_depan` 0..24; `acuan` dalam 1 tahun ke belakang sampai 10 tahun ke depan, supaya `simrs_app` tidak bisa membuat ribuan partisi |

- Bila suatu bulan sudah punya baris di `DEFAULT` (perintah terjadwal tidak jalan berbulan-bulan),
  partisinya **tidak** dibuat otomatis: fungsi melaporkan `bentrok_default` dan perintah keluar
  dengan galat. Memindahkan baris dari `DEFAULT` berarti menghapus dan menyisipkan ulang baris audit,
  jadi itu prosedur DBA (lihat Konsekuensi), bukan kemampuan aplikasi.
- Setiap kali berjalan, perintah partisi menulis **log warning** bila `DEFAULT` berisi baris (jumlah
  dan rentang waktu saja, tanpa isi baris), walau bulan yang disiapkan tidak bentrok.

### 2. Rantai hash

- `hash_rantai = SHA-256(hash_rantai baris sebelumnya || audit.kanonik_log(baris))`, dalam hex.
  Baris pertama memakai 64 karakter `0`.
- "Sebelumnya" ditentukan kolom `urutan` (sequence), bukan `terjadi_pada`: cap waktu diambil
  aplikasi sebelum kunci, jadi bisa sedikit tidak berurutan; `urutan` diambil di bawah kunci.
  `urutan` boleh berlubang (transaksi yang dibatalkan), verifikasi tidak menganggap lubang sebagai
  putus.
- Serialisasi kanonik hanya didefinisikan sekali, di fungsi SQL `audit.kanonik_log`:
  `jsonb_build_array(id, urutan, terjadi_pada UTC dengan mikrodetik, pengguna_id, aksi, subjek_jenis,
  subjek_id, sebelum, sesudah, host(ip), perangkat)::text`. `jsonb` mengurutkan kunci objek, jadi
  hasilnya deterministik.
- Hash diisi **trigger `BEFORE INSERT`** (`audit.sambung_rantai`), bukan oleh PHP. Setiap insert,
  termasuk yang tidak lewat `CatatAktivitas`, selalu tersambung ke rantai.
- Trigger mengambil `pg_advisory_xact_lock` sebelum membaca hash terakhir dan mengambil `urutan`,
  sehingga penulis bersamaan antre dan rantai tidak bercabang. Kunci dilepas saat transaksi
  pemanggil selesai. Trigger menolak transaksi selain `READ COMMITTED`, karena di sana snapshot
  diambil sebelum kunci dan baris penulis sebelumnya tidak terlihat.

### 3. Verifikasi

`audit:verifikasi-rantai {--dari} {--sampai}` menghitung ulang hash di database untuk rentang yang
diminta dan melaporkan **baris pertama yang putus** (urutan, id, cap waktu; isi baris tidak dicetak).
Lalu memeriksa jangkar yang jatuh di rentang itu.

| Kejadian | Terdeteksi sebagai |
|---|---|
| Isi satu baris diubah | putus di baris itu |
| Baris tengah dihapus | putus di baris sesudahnya |
| Baris ekor dihapus | rantai tampak utuh; **jangkar** tidak cocok (urutan hilang) |
| Seluruh rantai dihitung ulang setelah pemalsuan | rantai tampak utuh; **jangkar** tidak cocok |

### 4. Jangkar per jam di luar database

`audit:ekspor-jangkar` berjalan **setiap jam** (menit ke-5). Ia mengambil baris dengan `urutan`
terbesar yang terjadi sebelum awal jam berjalan, lalu menyalin
`{batas, urutan, id, terjadi_pada, hash_rantai, diekspor_pada}` ke dua tempat:

1. berkas JSON Lines di disk Laravel `AUDIT_JANGKAR_DISK`, satu berkas per bulan di
   `AUDIT_JANGKAR_DIREKTORI` (24 baris per hari selama masa retensi tidak menumpuk di satu berkas);
2. channel log terpisah `audit_jangkar` (`storage/logs/audit-jangkar.log`), bukan log aplikasi.

Jangkar menyimpan pasangan `(urutan, hash)`, bukan "kepala rantai pada batas jam". Baris dari
transaksi yang baru commit sesudah ekspor tidak membuat jangkar salah. Jam tanpa aktivitas
menghasilkan pasangan yang sama dan tidak ditulis ulang.

**Jendela risiko.** Baris yang ditulis sesudah jangkar terakhir belum terlindungi jangkar: penghapusan
baris ekor atau penghitungan ulang rantai dalam jendela itu tidak terdeteksi. Dengan jangkar per jam,
jendela ini paling lama **± 1 jam 5 menit** (bukan 24 jam seperti jangkar harian). PRD 7.57.2 meminta
"per hari"; per jam memenuhi dan lebih ketat. Bila perlu lebih kecil lagi, jadwal bisa dirapatkan
tanpa mengubah format.

## Alternatif yang dipertimbangkan

| Alternatif | Alasan tidak dipilih |
|---|---|
| Hash dihitung di PHP | Insert langsung ke tabel bisa melewatinya; dua definisi serialisasi (PHP dan baca ulang dari `jsonb`/`inet`/`timestamptz`) mudah berbeda dan memberi alarm palsu |
| Tabel "kepala rantai" satu baris | Butuh `UPDATE` di skema `audit`, yang sengaja tidak dimiliki `simrs_app` |
| `terjadi_pada` sebagai urutan rantai | Cap waktu bisa sama atau tidak berurutan antarproses |
| pg_partman | Ekstensi tambahan di server RS dan versi yang harus dikelola; kebutuhan kita cukup satu fungsi |
| Perintah partisi memakai koneksi `simrs_migrator` | Kredensial DDL harus ada di server aplikasi saat berjalan |
| Tanda tangan digital per baris | Kunci privat di server aplikasi tidak menambah banyak terhadap penyerang yang sudah menguasai server; jangkar eksternal lebih murah. Bisa ditambah nanti untuk jangkar (RFC 3161 atau TTE) |

## Konsekuensi

- **Titik serialisasi tunggal.** Semua penulisan audit antre di satu kunci, dan kunci dipegang
  sampai transaksi pemanggil commit. Di dalam transaksi bisnis, panggil `CatatAktivitas::catat()`
  sedekat mungkin dengan akhir transaksi. Uji beban perlu mengukur ini sebelum go-live (PRD 14.1).
- Menulis audit di dalam transaksi `REPEATABLE READ`/`SERIALIZABLE` ditolak trigger.
- Mengambil hash terakhir membaca indeks `urutan` di setiap partisi (Merge Append + LIMIT 1);
  biayanya naik pelan seiring jumlah partisi. Bila partisi lama dipindah ke penyimpanan dingin
  (PRD retensi), partisi itu harus tetap terpasang atau dicatat jangkarnya, supaya verifikasi bisa
  mulai dari jangkar terakhir sebelum batas pindah.
- **Jangkar hanya berguna bila tempat simpannya terpisah.** Di produksi, `AUDIT_JANGKAR_DISK`
  diarahkan ke penyimpanan yang tidak bisa ditimpa admin basis data (mis. bucket MinIO dengan object
  lock, server lain), dan channel `audit_jangkar` dikirim ke SIEM. Disk `local` bawaan hanya untuk
  pengembangan.
- Perlu cron `schedule:run`. Bila partisi tidak dibuat, baris masuk `DEFAULT` (tidak hilang) dan
  perintah partisi berikutnya menulis warning.
  Prosedur DBA bila terjadi `bentrok_default` (sudah dicoba di `simrs_test`): dalam satu transaksi
  sebagai superuser (`session_replication_role` butuh superuser), salin
  baris bulan itu dari `DEFAULT` ke tabel sementara, hapus dari `DEFAULT`, buat partisi, sisipkan
  kembali dengan `session_replication_role = replica` agar trigger tidak menghitung ulang hash, lalu
  jalankan `audit:verifikasi-rantai`.
- Superuser tetap bisa mengubah apa pun, termasuk menonaktifkan trigger. Rantai + jangkar membuat
  perubahan itu **terlihat**, tidak membuatnya mustahil.
- Tes paralel (4 proses x 40 baris, mulai serentak) membuktikan kunci diperlukan: tanpa
  `pg_advisory_xact_lock`, verifikasi gagal di ketiga percobaan.
