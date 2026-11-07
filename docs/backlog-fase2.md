# Backlog Fase 2 (Alur pasien inti) · Tahap A selesai · Tahap B: rawat jalan + RME

> Sumber tiket aktif. Kebutuhan tetap di `docs/prd.md` (baca per bagian). Cara kerja, format tiket, worktree, Korlap,
> antislop, dan laporan 5 baris: skill `kerja-tiket`. Status diubah lewat PR yang menyelesaikan tiketnya.
> Tahap B dirinci 7 Nov 2026 (bawah). Tahap C–D sengaja hanya kerangka; dirinci perencana setelah Tahap B selesai.

## Prasyarat yang belum tertutup (bukan penghalang build, penghalang pilot)
- Penutupan formal Fase 1: uji manual pemilik + persetujuan log 17.2 (PRD 16.1 butir 3). Build Tahap A boleh jalan ke staging (16.1 butir 5).
- 1.10 SSO tetap terhambat data IdP.
- Pilot butuh: kredensial sandbox BPJS/SATUSEHAT dari RS mitra, SUS ≥ 70, design partner.

## Aturan tiket
Format dan aturan agen ada di skill `kerja-tiket` § 1–2 dan § 5. Ringkas: satu tiket satu PR; ukuran S/M; jalur
`BE+FE` = kontrak dulu lalu paralel; ambigu → `Asumsi:` di PR; laporan akhir 5 baris.

**Skema basis data** memakai yang sudah ada di `docker/postgres/init/sql/02-database.sql` (`pasien`, `kunjungan`,
`tagihan`, `keuangan`, `integrasi`, `inti`, …). Skema baru = "[Perlu pemilik]".

## Tahap A — tiket

| No | Tiket | Jalur | Ukuran | Prasyarat |
|---|---|---|---|---|
| 2.0 | Persiapan kerja Fase 2 | BE (chore) | S | — · **Sebagian selesai** (PR aturan Fase 2): uji satu worktree lulus (slot 1, port 8001/5174, DB `simrs_test_keen_pearl`, 367 tes hijau, `down -v` ditolak deny); sisa uji dua worktree bersamaan, e2e, bersihkan, Korlap pada 2.1 |
| 2.1 | Penjamin dan kelas tarif | BE+FE | M | 2.0 |
| 2.2 | Buku tarif + usulan tarif berjenjang | BE+FE | M | 2.1 |
| 2.3 | Wizard langkah 6 aktif → instalasi siap melayani | BE+FE | S | 2.2 |
| 2.4 | Jadwal praktik dokter dan kuota poli | BE+FE | M | 2.0 |
| 2.5 | Cari pasien satu kolom + pasien baru | BE+FE | M | 2.3 · **Selesai** (BE PR #127, FE PR #128 dengan data tiruan v0.19.0; integrasi FE selesai: build produksi memanggil backend asli, tiruan hanya `vite` dev dengan `VITE_API_TIRUAN=true` dan unit test; bukti `e2e/pendaftaran.spec.ts` 1440 px + axe per layar, PR #137) |
| 2.6 | Kunjungan poli + episode + relasi perawatan | BE+FE | M | 2.4, 2.5 · **FE selesai** (PR #130, layar pendaftaran kunjungan poli, data tiruan v0.20.0, integrasi FE selesai: backend asli, bukti `e2e/pendaftaran.spec.ts` 1440 px + axe, PR #137/#141); **BE selesai** (PR #129: kunjungan, episode, relasi perawatan lapis 4, batal otomatis; merge setelah pemilik memeriksa lapis 4) |
| 2.7 | Kepesertaan, rujukan, dan SEP (simulator VClaim) | BE+FE | M | 2.6 · **BE selesai** (PR #138: peserta/rujukan via X-Nomor, `integrasi.sep` lewat outbox + simulator insert SEP, tugas SEP_TERTUNDA) · **FE selesai** (PR #141: panel SEP di Pendaftaran kunjungan poli, e2e 1440 px + axe) |
| 2.8 | Antrean loket dan poli + display | BE+FE | M | 2.6 · **BE selesai** (PR #140: antrean poli dari `KunjunganDibuat`, panggil/lewati/sisip/batal dari `aturan_antrean`, display token perangkat `display_antrean` + ETag, `GET /antrean` ditambah ke kontrak) · **FE selesai** (PR #144: layar petugas, display TV dengan token lewat fragmen URL, e2e keluar Tahap A) |
| 2.9 | Duplikat pasien + usulan gabung RM | BE+FE | M | 2.5 · **Selesai** (satu PR BE+FE, kontrak v0.21.0; usulan dua tahap meniru pola 1.8c, tabel sendiri `pasien.usulan_gabung`; unmerge = utang) |

Urutan paralel (dua agen hanya bila kuota cukup; satu agen = kolom BE lalu FE berurutan):

| Langkah | Agen BE | Agen FE |
|---|---|---|
| 1 | 2.0, lalu kontrak 2.1 + 2.4 | — (atau tinjau PR) |
| 2 | 2.1-be → 2.4-be | 2.1-fe → 2.4-fe (data tiruan dari kontrak) |
| 3 | kontrak 2.2 → 2.2-be → 2.3-be | 2.2-fe → 2.3-fe |
| 4 | kontrak 2.5 + 2.6 → 2.5-be → 2.6-be | 2.5-fe → 2.6-fe |
| 5 | 2.7-be → 2.9-be | 2.8-fe → 2.7-fe |
| 6 | 2.8-be | 2.9-fe, e2e keluar Tahap A |

---

### 2.0 · Persiapan kerja Fase 2
Tujuan : sesi dan kartu Korlap mulai dengan aturan dan alat yang sama.
Selesai di PR aturan Fase 2: backlog ini, `kerja-tiket` (aturan agen, laporan 5 baris, Korlap, antislop),
`peninjau` (antislop, ≤ 10 temuan), skill `antislop*`, `scripts/worktree-siapkan.sh` dan `worktree-bersihkan.sh`.
Sisa (tiket ini):
- Uji di Mac: dua worktree disiapkan bersamaan tanpa bentrok DB/port/Valkey/cookie; `composer test` dan satu spec e2e
  jalan di keduanya; `worktree-bersihkan.sh` meninggalkan 0 DB `simrs_*_<slug>` dan 0 berkas slot. Perbaiki skrip bila gagal.
- Uji coba Korlap pada 2.1 (lihat `kerja-tiket` § 4); hasil (dipakai/tidak) dicatat di sini.
Di luar: perubahan PRD.

### 2.1 · Penjamin dan kelas tarif
Tujuan : admin data induk mencatat penjamin (BPJS, umum, asuransi, perusahaan, Jasa Raharja, Jamkesda, sponsor riset) dan kelas rawat yang dipakai tarif.
FR     : FR-01-12 (data induk), FR-28-07 (atribut penagihan: masa berlaku GL, plafon, jatuh tempo), FR-28-12 (kelas III/II/I/VIP/KRIS)
Akses  : sub-menu baru `master_data.penjamin` lihat/input/perbaiki (daftarkan di `MenuBawaan`)
Tabel  : `inti.penjamin` baru. Kelas rawat **sudah ada** (migrasi lokasi/ruang/TT 1.8b) — pakai, jangan buat tabel kelas baru
API    : `GET/POST/PATCH /api/v1/data-induk/penjamin` (keyset, If-Match)
Uji    :
- Given penjamin BPJS sudah ada, When input kode sama, Then 422 `validasi_gagal`.
- Akun tanpa `master_data.penjamin.input` → tombol tidak tampil dan API 403 `akses_ditolak`.
- Penjamin nonaktif tidak muncul di pilihan pendaftaran, tetap terbaca di riwayat.
Di luar: COB/penjamin ganda (Tahap D), kontrak harga per penjamin (Fase 3).

### 2.2 · Buku tarif + usulan tarif berjenjang
Tujuan : tarif disahkan lewat Usulan Perubahan, berlaku per tanggal, kasir tidak bisa mengubah harga.
FR     : FR-28-12, FR-28-13, FR-28-02, FR-54-02; keputusan pemilik 17.2 #23
Akses  : sub-menu baru `master_data.tarif` (lihat; input hanya sebelum siap melayani), `master_data.usulan` (usul/verifikasi/setujui)
Tabel  : `keuangan.tarif` + tabel versi (11.1), komponen jasa sarana/jasa pelayanan/BHP, harga per kelas
API    : `GET /api/v1/data-induk/tarif`, `GET /api/v1/data-induk/tarif/{id}/versi`; perubahan lewat endpoint usulan 1.8c. Mesin usulan kini terkunci ke `ruang_tt` (CHECK `usulan_jenis_cek`, `Rule::in(['ruang_tt'])` di `UsulanDataIndukController`, baris `inti.alur_usulan`): migrasi memperluas CHECK + tambah alur `tarif`, validasi controller dari daftar terdaftar, bukan literal
Uji    :
- Sebelum siap melayani: impor/isi tarif langsung diizinkan (penyiapan). Sesudahnya: jalur langsung 409 `harus_lewat_usulan`.
- Usulan disahkan dengan tanggal berlaku besok → hari ini harga lama, besok harga baru (uji dengan jam beku).
- Pengusul tidak bisa memverifikasi usulannya sendiri.
- Impor CSV tarif awal (penyiapan) menolak baris tanpa kelas atau harga negatif dan melaporkan nomor baris.
Di luar: paket tarif INA-CBG, analisis unit cost (lampiran berkas saja).
Utang BE (2.2): impor CSV tarif awal (penyiapan) belum ada; kontrak v0.18.0 tanpa endpoint tulis. `KelolaTarif::buatLangsung` sudah menjaga 409 `harus_lewat_usulan`; endpoint/perintah impor (tolak baris tanpa kelas atau harga negatif, lapor nomor baris) dibuat bersama 2.3 setelah kontraknya ditambah.

### 2.3 · Wizard langkah 6 aktif → siap melayani
Tujuan : langkah "Penjamin & tarif" bisa diselesaikan sehingga instalasi menjadi siap melayani (K6).
FR     : FR-54-08 (langkah 6), 7.54 "Siap melayani"
Akses  : `pengaturan.penyiapan` lihat/perbaiki
Kode   : `Core/Instalasi/Penyiapan.php` — keluarkan `penjamin_tarif` dari `FASE_2` (409 `langkah_menunggu_fase_2`); `formularium` tetap
Uji    :
- Langkah 6 hanya bisa dikonfirmasi bila ≥ 1 penjamin aktif dan ≥ 1 tarif per kelas aktif.
- Konfirmasi yang membuat langkah 1–6 selesai mengisi `profil_rs.siap_melayani_sejak` sekali (tidak dikosongkan).
- Modul pendaftaran menolak 409 selama belum siap melayani (penjaga `instalasi.siap_melayani`).
Di luar: langkah 7 formularium (Tahap B).

### 2.4 · Jadwal praktik dokter dan kuota poli
Tujuan : pendaftaran melihat dokter, jam, ruang/lantai, dan sisa kuota per poli.
FR     : FR-01-15, 7.07.1
Akses  : sub-menu baru `master_data.jadwal_praktik` lihat/input/perbaiki
Tabel  : `kunjungan.jadwal_praktik` (pegawai dengan SIP aktif dari 1.8d, poli, ruang dari 1.8b, hari/jam, kuota JKN/non-JKN), `kunjungan.jadwal_pengecualian` (cuti/libur)
API    : `GET /api/v1/jadwal-praktik?tanggal=&poli_id=` (dengan terisi/kuota)
Uji    :
- Dokter tanpa SIP aktif tidak bisa dijadwalkan (422).
- Pengecualian cuti membuat slot tanggal itu tidak tersedia.
- Jadwal tumpang tindih untuk dokter yang sama ditolak.
Di luar: sinkron jadwal ke Antrean Online BPJS (Tahap B).

### 2.5 · Cari pasien satu kolom + pasien baru
Tujuan : petugas menemukan pasien lama dalam satu kolom atau mendaftarkan pasien baru dengan No. RM seumur hidup.
FR     : FR-01-01, FR-01-04, FR-01-05; prototipe `pendaftaran.html` (wajib/dibuang/diubah: tentukan di PR kontrak)
Akses  : `pendaftaran.pasien` lihat/input/perbaiki; bagian baru `pendaftaran.pasien.identitas` (NIK utuh, sensitif)
Tabel  : `pasien.pasien` (+ versi), `pasien.identitas` (NIK/paspor/kartu BPJS), `pasien.kontak_darurat`; generator No. RM tanpa celah dan tidak dipakai ulang
API    : `GET /api/v1/pasien?q=` (NIK, No. RM, No. kartu, nama + tgl lahir), `POST /api/v1/pasien`, `PATCH /api/v1/pasien/{id}`
Uji    :
- NIK yang sama untuk pasien kedua → 409 dengan rujukan No. RM yang ada (kecuali bayi tanpa NIK).
- Mr. X tanpa identitas mendapat No. RM dan penanda; bisa dilengkapi kemudian dengan jejak.
- Akun tanpa bagian identitas menerima NIK termasking dari server, bukan disembunyikan di layar.
- Data uji hanya sintetis; pencarian nama memakai indeks trigram dengan keyset.
Di luar: Dukcapil (FR-01-03), bayi terhubung ibu (Tahap C), gelang (Tahap C).

### 2.6 · Kunjungan poli + episode + relasi perawatan
Tujuan : pasien terdaftar ke poli dan dokter; episode terbentuk otomatis; tim yang merawat mendapat akses lapis 4.
FR     : FR-01-06 (jenis poliklinik saja dulu), FR-01-15, FR-01-21, FR-00-01, FR-00-04; 9.2.5 lapis 4
Akses  : `pendaftaran.pasien` input/perbaiki + aksi baru `batal`
Tabel  : `kunjungan.episode`, `kunjungan.kunjungan`, `akses.relasi_perawatan` **baru** (1.5 hanya punya kolom `label_sensitivitas.butuh_relasi_perawatan`; `AccessEvaluator` lapis 4 saat ini menganggap tak ada yang merawat). Perubahan `AccessEvaluator` wajib ditinjau `peninjau` + uji IDOR; ini menjalankan aturan 9.2.5, bukan melonggarkan keamanan
API    : `POST /api/v1/kunjungan`, `POST /api/v1/kunjungan/{id}/batal`, `GET /api/v1/kunjungan?tanggal=&poli_id=`
Uji    :
- Kunjungan kedua pasien yang sama dalam rentang aturan 7.00.2 masuk episode yang sama; di luar rentang → episode baru.
- Kuota habis → 409 dengan sisa slot terdekat.
- Dokter jadwal itu mendapat relasi perawatan; data berlabel pasien terbuka untuknya dan tetap "Terbatas" untuk dokter lain.
- Kunjungan tidak dilayani sampai jam tutup → batal otomatis (job terjadwal) dengan alasan.
- Event `KunjunganDibuat` terbit di bus kejadian (dipakai 2.7, 2.8).
Di luar: IGD, rawat inap, penunjang langsung (Tahap C).
Utang (PR #129): relasi perawatan dokter jadwal berlaku sepanjang hari kunjungan; tiket yang menambah status `dilayani`/`selesai` memanggil `KelolaRelasiPerawatan::akhiri` bila perlu. MasterData (keterisian jadwal) bergantung ke `Pendaftaran\Application\KeterisianKunjungan`; pindahkan ke antarmuka bersama bila batas modul ditegakkan.

### 2.7 · Kepesertaan, rujukan, SEP (simulator VClaim)
Tujuan : SEP terbit dari layar pendaftaran tanpa pindah aplikasi.
FR     : FR-01-07, FR-01-08, FR-01-10, FR-50-01; gateway 1.9 (outbox, circuit breaker); aturan "SEP tidak terbit bila rujukan kedaluwarsa kecuali IGD"
Akses  : `pendaftaran.sep` lihat/input/perbaiki + aksi `hapus`
Tabel  : `integrasi.sep` (terikat kunjungan, episode, tagihan nanti)
API    : `GET /api/v1/bpjs/peserta/{no}`, `GET /api/v1/bpjs/rujukan/{no}`, `POST /api/v1/kunjungan/{id}/sep`
Uji    :
- Rujukan sisa ≤ 7 hari ditandai sebelum SEP; kedaluwarsa → ditolak dengan pesan jelas.
- Simulator mengembalikan galat BPJS → pesan ditampilkan apa adanya + kode, kunjungan tetap tersimpan.
- Circuit breaker terbuka → layar menawarkan "simpan, terbitkan SEP nanti" dan tugas masuk kotak kerja.
- Respons simulator mengikuti catatan lapangan (di luar repo); tidak ada kredensial nyata.
Di luar: biometrik (FR-01-09), surat kontrol/SPRI (Tahap C), sandbox nyata (syarat pilot).

Utang 2.7 (PR #138): pekerja belum memeriksa SEP sudah terbit di BPJS sebelum kirim ulang (bentuk respons monitoring belum ada di catatan lapangan; kini hanya dedup BPJS); dua permintaan serentak dengan `Idempotency-Key` sama → yang kedua 409 `sep_sedang_diproses`, bukan respons pertama; masa berlaku rujukan 90 hari dan isi SEP (`suplesi`, `klsRawatHak`) perlu verifikasi katalog VClaim; `SepController` menyalin format `nik_samaran` (ikut PR #136).

### 2.8 · Antrean loket dan poli + display
Tujuan : pasien punya satu QR kunjungan; petugas memanggil; display menampilkan nomor tanpa membuka identitas.
FR     : FR-02-01, 02-03, 02-04, 02-08, 02-09, 02-11, 02-15, 02-16; prototipe `displayantrean.html`
Akses  : `pendaftaran.antrean` lihat/perbaiki; display = akun perangkat tanpa data pasien
Tabel  : `kunjungan.antrean`, `kunjungan.antrean_jejak` (jam tiap titik untuk INM)
API    : `POST /api/v1/antrean/{id}/panggil|lewati|sisip|batal`, `GET /api/v1/display/{area}` (polling 5–10 dtk dulu; Reverb menyusul di Tahap C bersama papan IGD — K1)
Uji    :
- Display hanya nomor + inisial; poli sensitif memakai nama netral; suara tanpa nama.
- Setiap transisi status menyimpan jam server.
- Panggil ulang/lewati/sisip mengikuti nilai di data induk, bukan konstanta.
Di luar: task ID Antrean Online BPJS (Tahap B), APM/anjungan (Tahap B), TTS dengan suara penyedia luar.

Utang 2.8 (PR #140): label `privasi_tinggi` per pasien belum ada, jadi inisial display baru disembunyikan untuk pasien tanpa identitas; layar data induk `aturan_antrean` dan kolom antrean poli (prefiks, area, nama netral) belum ada; event `AntreanBerubah` belum punya pelanggan. Sisip serentak di antrean dokter yang sama hanya mengunci barisnya sendiri, jadi dua sisip bisa mendapat `urutan` sama (seri dipecah nomor, urutan tetap sah); `urutan` `decimal(20,10)` kehabisan presisi setelah ±33 sisip beruntun di sela yang sama (kunci baris jadwal atau penomoran ulang bila terjadi).

### 2.9 · Duplikat pasien + usulan gabung RM
Tujuan : duplikat terdeteksi saat input dan digabung lewat usulan berjenjang yang diverifikasi Rekam Medis.
FR     : FR-01-02
Akses  : `pendaftaran.master_pasien` + aksi baru `usul`, `verifikasi` (verifikator = peran Rekam Medis)
Uji    :
- Pasien baru dengan nama mirip + tgl lahir sama memunculkan peringatan sebelum simpan.
- Gabung memindahkan kunjungan ke RM tujuan; RM lama ditandai "digabung ke …", tidak dihapus; bisa ditelusuri.
- Pengusul ≠ verifikator.
Di luar: pemisahan RM (unmerge) — catat sebagai utang bila tidak muat.
Utang 2.9: pemisahan RM (unmerge) belum ada; notifikasi kotak kerja ke Rekam Medis belum ada. (Identitas RM lama kini dipindah saat gabung, PR #135.)

### Keluar Tahap A (di staging, data sintetis)
- [x] e2e: pasien baru BPJS → kunjungan poli → SEP (simulator) → antrean → dipanggil → display (PR #144, `frontend/e2e/antrean.spec.ts`).
- [x] Penjaga siap melayani terbukti menolak pendaftaran di instalasi yang belum lengkap (PR #135,
  `KunjunganTest::test_instalasi_belum_siap_menolak_pasien_baru_dan_kunjungan_tanpa_memakai_no_rm`).
- Kedua butir di atas baru terbukti di CI (data sintetis), belum di staging: staging belum ada.
- [x] Backlog Tahap B ditulis rinci (oleh perencana), PRD diselaraskan sekali (PR backlog Tahap B, PRD v0.10.22).

## Utang dan keputusan tertunda (audit repo 6 Nov 2026)
Audit menyeluruh oleh sesi; perbaikan sudah merge di #133 (bom waktu cookie tes), #134 (akses: cache versi, `catat()` di akhir,
batas sandi, `Gate::authorize`), #135 (pendaftaran: penjaga siap melayani, jadwal/cuti vs kunjungan, dokter keluar/SIP, gabung
RM), #137 (layar pendaftaran, e2e admisi). Sisa:

**[Perlu pemilik]**
- PR #136: `nik_samaran` hanya 4 digit akhir. Format kontrak lama (4 awal + 4 akhir) bisa direkonstruksi dari tanggal lahir + kode wilayah.
- Form pasien: kode agama/pendidikan/pekerjaan/status kawin/kelurahan masih hard-coded di `DialogPasien.vue` dan ditolak backend
  (422). Butuh (a) kontrak endpoint baca `GET /referensi?kelompok=` dan `GET /wilayah?induk=`, (b) berkas paket referensi resmi
  (`backend/database/referensi/SUMBER.md`, utang 1.8e).
- Endpoint lookup (`/poliklinik`, `/jadwal-praktik`, `/data-induk/penjamin`) hanya menerima izin `master_data.*.lihat`; layar
  kunjungan admisi butuh ketiganya. Saat ini diatasi lewat peran (data contoh #137). Terima juga izin `pendaftaran.pasien.input`?
- `AlurPersetujuan::alasanTidakBoleh`: satu orang dengan dua peran penyetuju bisa menyetujui dua baris dalam satu grup. PRD belum mengatur.
- `throttle:api` (120/menit per akun dan IP) membuat spec e2e pendaftaran/antrean yang diulang cepat gagal "Terlalu banyak
  permintaan" (sekali jalan di CI aman). Pindahkan endpoint SEP/antrean petugas ke `throttle:api-layar-kerja`? Pelonggaran
  rate limit = keputusan pemilik.
- Token perangkat display disimpan di `localStorage` (PR #144; token mesin berlingkup `display`, bisa dicabut). Sisa risiko
  XSS se-origin → CSP untuk SPA perlu ditetapkan (belum ada kebijakan CSP di PRD/deploy).

**Utang teknis (tiket kecil / disisipkan ke tiket terkait)**
- ~~Libur RS mengunci semua baris `jadwal_praktik` (termasuk nonaktif) sampai commit (PR #146)~~ — **Selesai**: hanya baris aktif yang dikunci (PR utang kunci libur RS).
- `PanelSep.vue` menolak terbit untuk rujukan kedaluwarsa tanpa melihat IGD (backend mengizinkan IGD); perlu disesuaikan saat layar IGD.
- ~~Layar Fase 2 butuh e2e terhadap backend asli per layar~~ (PR e2e-fase2-backend-asli): proyek Playwright `fase2` @1440 px + axe —
  `penjamin.spec` (2.1), `buku-tarif.spec` (2.2 + usulan tarif tiga orang), `jadwal-praktik.spec` (2.4 + cuti), `gabung-rm.spec`
  (2.9 + peringatan duplikat); `penyiapan.spec` kini menguji langkah 6 aktif (409 urutan, bukan fase 2). Sisa: konfirmasi langkah 6
  sampai siap melayani belum di e2e (butuh langkah 2–5 lengkap: logo, paket referensi). Lapisan tiruan (`*/tiruan/`) tetap untuk spec unit.
- Sub-menu `master_data.penjamin`, `master_data.tarif`, `master_data.jadwal_praktik` belum masuk `MenuBawaan::TERSEDIA`: layar sudah
  tersambung ke backend asli tetapi tidak tampil di menu (hanya lewat tautan penyiapan/URL). → **Selesai** (tiket 2.10).
- Pemilih dokter di layar Jadwal praktik memakai `GET /pegawai` (`sdm.pegawai.lihat`), jadi admin jadwal butuh izin itu juga
  (data contoh: diberikan ke `contoh_data_induk`). Endpoint lookup dokter ber-SIP untuk `master_data.jadwal_praktik.input`? Keputusan pemilik.

## Tahap B — tiket (rawat jalan + RME)

Dirinci perencana 7 Nov 2026 dari PRD 7.00.5, 7.01.1, 7.02.1, 7.07, 7.22, 7.26, FR-54-08, UC-33. Skema yang dipakai sudah ada di
`02-database.sql` (`klinis`, `permintaan`, `farmasi`, `logistik`, `kunjungan`, `akses`, `audit`); tidak ada skema baru.
Kolom tabel = PRD 11.2; kolom yang belum perlu boleh ditunda, kolom baru di luar 11.2 ditulis `Asumsi:` di PR kontrak.

**Aturan Tahap B (berlaku untuk semua tiket di bawah):**
- Isian klinis menyimpan penulis, jam server, dan status (draf/sah). Pengesahan memakai T1 PIN lewat `TtdModal` + modul `Ttd` yang
  ada (D.4, 7.57). Perbaikan ≤ 2 × 24 jam = versi baru; sesudahnya klinis lewat adendum (FR-26-06). Isi klinis tidak masuk log/audit.
- Nilai klinis (ambang tanda vital, skor skrining, interaksi obat, dosis ginjal) hanya dari data induk yang disetujui RS. Data induk
  itu belum ada → fiturnya tidak dibuat di Tahap B (lihat [Perlu pemilik] di bawah), bukan dikodekan keras.
- Endpoint BPJS/KFA hanya dari dokumen resmi atau catatan lapangan yang memuat bentuk permintaan **dan** respons. Catatan lapangan
  (`catatan-lapangan-bridging.md`, di luar repo) hanya mencantumkan path Antrean RS (§ 11–12) dan KFA (§ 18) tanpa body/respons,
  jadi adapter keduanya **[Perlu pemilik]** (2.19, 2.22). Bagian internal (pencatatan task ID, kode KFA manual) tetap jalan.
- Layar kerja klinis (worklist, CPPT, pemeriksaan) memakai `throttle:api-layar-kerja`; daftar lewat `Paginasi::respons()`.
- Relasi perawatan (lapis 4): tiket yang memberi peran klinis pada kunjungan memakai `KelolaRelasiPerawatan` (2.6), tidak membuat jalur
  lain. Tiket yang mengisi status `dilayani`/`selesai` menutup utang 2.6 (`akhiri`).

| No | Tiket | Jalur | Ukuran | Prasyarat · Status |
|---|---|---|---|---|
| 2.10 | Sub-menu penjamin, tarif, jadwal praktik tampil di menu | BE | S | — · **Selesai** (PR 2.10: TERSEDIA + migrasi `pasang()`, ketiga layar masuk `LAYAR` `fase1.spec`) |
| 2.11 | Katalog obat + formularium (penyiapan) | BE+FE | M | — · **Selesai** (PR #153 BE: `logistik.barang`, `farmasi.formularium(_item)`, impor CSV, kontrak v0.23.0; PR FE: layar `/farmasi/formularium`, TERSEDIA + migrasi `pasang()`, `fase1` LAYAR) |
| 2.12 | Wizard langkah 7 + usulan formularium | BE+FE | S | 2.11 · Siap |
| 2.13 | Worklist poli + asesmen awal perawat | BE+FE | M | — · **BE selesai** (PR 2.13-be: modul `RekamMedis` — `klinis.catatan_klinis(_versi)`, `klinis.observasi` berpartisi, `klinis.alergi`; worklist + tahap perawat→dokter di `Antrean`) · FE Siap |
| 2.14 | CPPT episode (`CpptLinimasa`) + pengesahan PIN | BE+FE | M | 2.13 · Siap |
| 2.15 | Pemeriksaan dokter: diagnosis ICD-10 + selesai kunjungan | BE+FE | M | 2.14 · Siap |
| 2.16 | Order penunjang bersama (7.00.5) | BE+FE | M | 2.14 · Siap |
| 2.17 | E-resep rawat jalan → antrean farmasi | BE+FE | M | 2.11, 2.14 · Siap |
| 2.18 | Task ID antrean 1–7 tercatat internal | BE | S | 2.15, 2.17 · Siap |
| 2.19 | [Perlu pemilik] Adapter Antrean RS BPJS + simulator | BE | M | 2.18 + dokumen katalog Antrean RS · Terhambat |
| 2.20 | APM tahap 1: anjungan, nomor loket, cek jadwal | BE+FE | M | — · Siap |
| 2.21 | Akses darurat (break-glass) + `audit.log_akses` | BE+FE | M | 2.14 · Siap |
| 2.22 | [Perlu pemilik] Pencarian KFA via SATUSEHAT (simulator) | BE+FE | S | 2.11 + dokumen respons KFA v2 · Terhambat |

Urutan (satu agen = kolom BE lalu FE berurutan; dua agen hanya bila kuota cukup):

| Langkah | Agen BE | Agen FE |
|---|---|---|
| 1 | 2.10, kontrak 2.11 + 2.13 | — (atau tinjau PR) |
| 2 | 2.11-be → 2.13-be | 2.11-fe → 2.13-fe |
| 3 | kontrak 2.12 + 2.14 → 2.12-be → 2.14-be | 2.12-fe → 2.14-fe |
| 4 | kontrak 2.15 + 2.16 + 2.17 → 2.15-be → 2.16-be | 2.15-fe → 2.16-fe |
| 5 | 2.17-be → 2.18 | 2.17-fe |
| 6 | kontrak 2.20 + 2.21 → 2.21-be (Opus, keamanan) → 2.20-be | 2.21-fe → 2.20-fe, e2e keluar Tahap B |

---

### 2.10 · Sub-menu penjamin, tarif, jadwal praktik tampil di menu
Tujuan : admin data induk membuka Penjamin, Buku tarif, dan Jadwal praktik dari menu, bukan hanya lewat tautan penyiapan.
FR     : FR-54-04 (menu per izin, 9.2.5); utang Tahap A (audit 6 Nov)
Kode   : tambahkan `master_data.penjamin`, `master_data.tarif`, `master_data.jadwal_praktik` ke `MenuBawaan::TERSEDIA`; migrasi baru memanggil
         `MenuBawaan::pasang()` (pola `2026_11_07_120000_pendaftaran_pasien_tersedia.php`)
Uji    :
- Given akun dengan `master_data.penjamin.lihat`, When `GET /api/v1/saya/menu`, Then sub-menu Penjamin `tersedia: true`.
- Akun tanpa izin ketiganya → sub-menu tidak ada di respons menu.
- Migrasi idempoten (dijalankan dua kali tanpa galat; `HakAksesMenuTest` hijau).
Di luar: endpoint lookup untuk izin `pendaftaran.pasien.input` (keputusan pemilik, daftar [Perlu pemilik] di atas).

### 2.11 · Katalog obat + formularium (penyiapan)
Tujuan : instalasi punya katalog obat/BMHP dan formularium RS yang dipakai e-resep, diisi langsung selama penyiapan.
FR     : FR-22-06, FR-22-17, FR-07-07 (bagian kesesuaian formularium/Fornas); FR-54-08 langkah 7
Akses  : `farmasi.formularium` lihat/input/perbaiki (daftarkan di `MenuBawaan`, TERSEDIA saat layar selesai)
Tabel  : `logistik.barang` (kolom 11.2; `kode_kfa` boleh kosong, diisi manual sampai 2.22), `farmasi.formularium` (jenis fornas/rs/penjamin,
         penjamin_id, versi, berlaku_dari), `farmasi.formularium_item` (restriksi, maks_per_resep, kronis)
API    : `GET/POST/PATCH /api/v1/data-induk/barang` (keyset, If-Match), `GET/POST /api/v1/data-induk/formularium`,
         `GET/POST/PATCH /api/v1/data-induk/formularium/{id}/item`, `POST /api/v1/data-induk/barang/impor` (CSV sinkron, pola `/lokasi/impor`)
Uji    :
- Given barang generik dan paten zat yang sama, When disimpan, Then dua kode terpisah (FR-22-06); kode RS ganda → 422 `validasi_gagal`.
- Impor CSV menolak baris tanpa satuan terkecil atau jenis tidak dikenal dan melaporkan nomor baris; satu baris salah = tidak ada yang
  disimpan (pola `/lokasi/impor`, kontrak v0.23.0).
- Sesudah siap melayani: tulis langsung ke formularium/item → 409 `harus_lewat_usulan` (FR-54-02; jalur usulan di 2.12). Barang tetap langsung.
- Akun tanpa `farmasi.formularium.input` → tombol tidak dirender dan API 403.
Di luar: stok/batch/harga beli (Fase 3 logistik), interaksi obat dan dosis (data induk belum ada), KFA otomatis (2.22), formularium penjamin
per kontrak (FR-45-01, Fase 3).

### 2.12 · Wizard langkah 7 + usulan formularium
Tujuan : langkah "Formularium" bisa diselesaikan, dan perubahan formularium sesudah siap melayani lewat Usulan Perubahan.
FR     : FR-54-08 langkah 7, FR-54-02 (fase 2–3 tarif dan formularium)
Akses  : `pengaturan.penyiapan` lihat/perbaiki; `master_data.usulan` usul/verifikasi/setujui
Kode   : `Core/Instalasi/Penyiapan.php` — keluarkan `formularium` dari `FASE_2`; mesin usulan 1.8c: tambah jenis `formularium` (CHECK + baris
         `inti.alur_usulan`, pola 2.2)
Uji    :
- Langkah 7 terpenuhi bila ≥ 1 formularium jenis `rs` berlaku hari ini dengan ≥ 1 item barang aktif; keterangan kurang tampil ("0 item").
- Langkah 7 **tidak** menjadi syarat siap melayani (FR-54-08: langkah 1–6).
- Usulan tambah/hapus item disahkan dengan tanggal berlaku besok → hari ini formularium lama, besok baru (jam beku); pengusul ≠ verifikator.
Di luar: syarat langkah 8–9.
Asumsi perencana: syarat langkah 7 di atas (PRD 7.54 menulis "belum ada syarat"); dimasukkan ke PRD saat penyelarasan Tahap B.

### 2.13 · Worklist poli + asesmen awal perawat
Tujuan : perawat poli melihat pasien yang menunggu, memanggil, dan mengisi asesmen awal; pasien lalu menunggu dokter.
FR     : FR-07-01, FR-07-02, FR-07-21 (asesmen keperawatan RM-24), FR-02-09, FR-26-15 (alergi)
Akses  : `rawat_jalan.worklist` lihat; `rawat_jalan.asesmen_perawat` lihat/input/perbaiki
Tabel  : `klinis.catatan_klinis` (jenis `asesmen`, tatanan `rj`, profesi `perawat`), `klinis.observasi` (TTV dengan kode LOINC dari paket
         referensi; partisi bulanan, pola `SiapkanPartisi`), `klinis.alergi`
API    : `GET /api/v1/rawat-jalan/worklist?poli_id=&tanggal=` (dari `kunjungan.antrean` + status asesmen), `POST /api/v1/kunjungan/{id}/asesmen-awal`,
         `PATCH /api/v1/asesmen-awal/{id}` (If-Match), `GET/POST /api/v1/pasien/{id}/alergi`
Uji    :
- IMT dihitung dari BB/TB di server; TTV disimpan sebagai observasi berkode LOINC dengan jam server. Tanpa penanda ambang (data induk belum ada).
- Asesmen disimpan → antrean pindah ke tahap dokter (event `AsesmenPerawatSelesai`); worklist dokter hanya pasien dokter itu, perawat seluruh poli (7.07.1).
- Asesmen tidak memberi relasi perawatan (lapis 4; relasi perawat menunggu jadwal dinas Fase 4): asesmen berlabel tampil "Terbatas" bagi
  akun yang bukan penulisnya dan tidak lolos lapis 4 (kontrak v0.23.0).
- Alergi yang tercatat tampil di ringkasan pasien semua kunjungan berikutnya.
- Akun tanpa `rawat_jalan.asesmen_perawat.input` → formulir tidak dirender dan API 403; IDOR kunjungan poli lain → 403.
Utang 2.13-be: label sensitivitas pada catatan klinis belum punya jalur pemberian, jadi `Terbatas` belum pernah dikirim untuk asesmen
(dibangun bersama CPPT 2.14); peran perawat poli perlu `pendaftaran.antrean.perbaiki` untuk memanggil; modul 07 wajib aktif di `inti.modul_aktif`.
Di luar: skor skrining otomatis (Morse, nyeri, gizi — data induk templat belum ada; isian kategori oleh perawat), form builder (FR-26-07),
draf resep perawat, poli khusus (FR-07-15).

### 2.14 · CPPT episode (`CpptLinimasa`) + pengesahan PIN
Tujuan : semua PPA menulis CPPT di episode yang sama dan membaca linimasa lintas kunjungan; catatan sah terkunci dengan PIN.
FR     : FR-07-04, FR-07-13, FR-07-19, FR-26-02, FR-26-06, FR-26-18, FR-26-23; 7.26.1 butir 1 dan 5
Regulasi: Permenkes 24/2022 Ps. 16 ayat 2 dan 4, Ps. 30 ayat 5–7 [High]
Akses  : `rawat_jalan.cppt` lihat/input/perbaiki/sahkan; `rawat_jalan.pemeriksaan` lihat
Tabel  : `klinis.catatan_klinis` (jenis `soap`), `klinis.catatan_klinis_versi`, `klinis.catatan_klinis_adendum`; `ttd.permintaan` objek `catatan_klinis`
API    : `GET /api/v1/episode/{id}/cppt?profesi=&tatanan=&kursor=` (juga lintas episode pasien: `GET /api/v1/pasien/{id}/cppt`),
         `POST /api/v1/kunjungan/{id}/cppt`, `PATCH /api/v1/cppt/{id}` (If-Match), `POST /api/v1/cppt/{id}/sahkan`, `POST /api/v1/cppt/{id}/adendum`
Uji    :
- Catatan sah bisa diperbaiki penulisnya ≤ 2 × 24 jam dengan alasan → versi lama tersimpan; sesudahnya `PATCH` → 409 dan layar menawarkan adendum (B-14).
- Pengesahan memakai PIN T1 milik penulis sendiri (aturan UX 5.1: TTE hanya pemilik akun); PIN salah tidak mengubah status.
- Linimasa menampilkan seluruh episode berurutan waktu dengan filter profesi/pos; kunjungan dua poli hari yang sama tampil dalam satu linimasa.
- Catatan berlabel sensitif dikirim `{"terbatas": true}` ke akun tanpa relasi/izin label (9.2.5); isi catatan tidak ada di log/audit.
- Template SOAP per poli dari data induk; tanpa template → formulir SOAP polos.
Bawaan dari 2.13: kolom/jalur label sensitivitas catatan klinis dibuat di sini, dan asesmen awal perawat ikut memakainya ("Terbatas" bagi
bukan penulis yang tidak lolos lapis 4); TTE asesmen perawat ikut pengesahan PIN tiket ini.
Di luar: verifikasi DPJP atas PPA lain dan TBaK (FR-26-03, 26-04), supervisi PPDS (FR-07-14, kunjungan belum punya `pemeriksa_id`),
entri otomatis kamar operasi (Tahap C), persetujuan PMIK untuk perbaikan > 2 × 24 jam data administratif.

### 2.15 · Pemeriksaan dokter: diagnosis ICD-10 + selesai kunjungan
Tujuan : dokter membuka RM pasien dari worklist, mencatat diagnosis, dan menyelesaikan kunjungan.
FR     : FR-07-03, FR-07-05, FR-02-09; 7.02.1 (task 4 saat dokter membuka RM, 5 saat selesai)
Akses  : `rawat_jalan.pemeriksaan` lihat/input/perbaiki/sahkan
Tabel  : `klinis.diagnosis` (kode dari paket referensi ICD-10 yang dimuat RS, K7); `kunjungan.kunjungan.status` `dilayani` → `selesai`
API    : `POST /api/v1/kunjungan/{id}/mulai-periksa`, `POST /api/v1/kunjungan/{id}/selesai`, `GET/POST/PATCH /api/v1/kunjungan/{id}/diagnosis`,
         `GET /api/v1/paket-referensi/{sistem}/kode?q=` (keyset, trigram)
Uji    :
- Mulai periksa mengubah kunjungan → `dilayani` dan menerbitkan `PemeriksaanDimulai` dengan jam server; selesai → `selesai`, `PemeriksaanSelesai`,
  relasi perawatan diakhiri sesuai aturan 2.6 (`KelolaRelasiPerawatan::akhiri`).
- Selesai tanpa diagnosis utama atau dengan CPPT dokter masih draf → 409 dengan daftar yang kurang.
- Paket ICD-10 belum dimuat → pencarian 409 `paket_referensi_belum_dimuat`, layar menunjuk langkah 4 penyiapan.
- Ringkasan klinis (FR-07-03) hanya dari data yang sudah ada: diagnosis aktif, alergi, kunjungan terakhir; hasil lab menyusul Tahap C.
Di luar: Diagnosa Rutin (FR-07-05 bagian per dokter, setelah ada data), tindakan ICD-9-CM + tarif (FR-07-10, bersama tagihan Tahap D),
rujuk/surat kontrol/SPRI (Tahap C), PRMRJ (FR-07-20).

### 2.16 · Order penunjang bersama (7.00.5)
Tujuan : dokter memesan lab/radiologi lewat satu komponen yang nanti dipakai IGD dan rawat inap tanpa dibuat ulang.
FR     : FR-00-08, FR-07-09, FR-07-27; 7.00.5 butir 1 dan 3
Akses  : bagian baru `rawat_jalan.pemeriksaan.order` input/batal (pola bagian 9.2.1); lihat order lewat `rawat_jalan.pemeriksaan.lihat`
Tabel  : `permintaan.permintaan_pemeriksaan` (+ `episode_id` diisi saat dipesan — Asumsi di PR kontrak bila kolom di luar 11.2),
         `permintaan.permintaan_item` (item = baris tarif berlaku 2.2), `permintaan.paket_order`
API    : `POST /api/v1/kunjungan/{id}/permintaan-pemeriksaan`, `GET /api/v1/episode/{id}/permintaan-pemeriksaan`,
         `POST /api/v1/permintaan-pemeriksaan/{id}/batal`, `GET /api/v1/data-induk/tarif?jenis=lab|rad` (katalog item)
Uji    :
- Order terikat kunjungan **dan** episode saat dipesan; pemindahan pasien kemudian tidak mengubah keterikatan (7.00.5 butir 1).
- Item yang sama sudah dipesan dokter lain untuk pasien hari itu → peringatan sebelum kirim, bisa dilanjutkan dengan alasan (FR-07-27).
- Order cito/rutin, indikasi klinis wajib; event `PermintaanPemeriksaanDibuat` terbit di bus kejadian untuk Tahap C.
- Komponen Vue `OrderPenunjang` berada di `src/components` bersama (D.4), menerima `kunjungan_id`, tanpa logika khusus poli.
- Order batal hanya sebelum diterima penunjang; batal tidak menghapus baris (status + alasan).
Di luar: penerimaan, sampling, hasil, hasil kritis, antrean `L-`/`R-` (Tahap C), ServiceRequest SATUSEHAT (Tahap D), konsul antar-poli (FR-07-25).

### 2.17 · E-resep rawat jalan → antrean farmasi
Tujuan : dokter menulis dan mengesahkan resep; pasien otomatis masuk satu antrean farmasi hari itu.
FR     : FR-07-06 (bagian jumlah otomatis untuk n hari), FR-07-07 (alergi, duplikasi, di luar formularium), FR-02-10, FR-22-01 (tampilan antrean)
Akses  : bagian baru `rawat_jalan.pemeriksaan.resep` input/sahkan/batal; `farmasi.resep` lihat (antrean depo); `GET /pasien/{id}/alergi`
         ikut menerima `farmasi.resep.lihat` (kontrak v0.23.0 menyerahkan jalur baca farmasi ke tiket ini)
Tabel  : `farmasi.resep`, `farmasi.resep_item`, `farmasi.peringatan_resep`; `kunjungan.antrean` titik `farmasi`
API    : `POST /api/v1/kunjungan/{id}/resep`, `PATCH /api/v1/resep/{id}` (draf, If-Match), `POST /api/v1/resep/{id}/sahkan` (PIN T1),
         `POST /api/v1/resep/{id}/batal`, `GET /api/v1/resep?depo_id=&tanggal=`
Uji    :
- Peringatan dibuat server: zat yang tercatat di alergi pasien, duplikasi zat aktif lintas resep hari itu (semua poli), barang di luar
  formularium berlaku untuk penjamin kunjungan. Peringatan tidak tertelan: sahkan butuh tanggapan per peringatan (aturan UX 5.1).
- Resep disahkan → antrean farmasi dibuat; resep kedua dari poli lain hari yang sama memakai antrean yang sama (satu per pasien per hari); racikan ditandai.
- Resep draf belum masuk antrean; resep sah tidak bisa diubah (batal + resep baru).
- Sahkan hanya oleh dokter jadwal kunjungan dengan SIP berlaku (lapis 5) → selain itu 403 dengan kode lapis 5.
Di luar: telaah, penyiapan, penyerahan, kembalikan ke dokter (Tahap C), obat kronis JKN 7 + 23 (FR-07-08, bersama depo Tahap C), komponen
racikan (FR-22-09), interaksi dan dosis ginjal (data induk belum ada), MedicationRequest SATUSEHAT (Tahap D).

### 2.18 · Task ID antrean 1–7 tercatat internal
Tujuan : setiap kunjungan JKN punya jam task 1–7 dari kejadian sistem, siap dikirim begitu adapter Antrean tersedia.
FR     : FR-02-02 (bagian pencatatan), FR-02-06, FR-02-16
Tabel  : `kunjungan.antrean_task` (task_no, terjadi_pada, status_kirim); kiriman ditulis ke `integrasi.kotak_keluar` tujuan `antrean` berstatus
         `tertahan` sampai 2.19
Kode   : pelanggan event `KunjunganDibuat` (3), `AntreanBerubah` (2.8; pelanggan pertama, menutup utang 2.8), `AsesmenPerawatSelesai`,
         `PemeriksaanDimulai` (4), `PemeriksaanSelesai` (5), resep sah/antrean farmasi (5–7 sesuai 7.02.1)
API    : `GET /api/v1/kunjungan/{id}/task-antrean` (izin `integrasi.bpjs.lihat`)
Uji    :
- Pemetaan kejadian → nomor task disimpan sebagai data (tabel/konfigurasi), bukan konstanta di kode, karena 7.02.1 bertanda *wajib dicocokkan
  dengan katalog terbaru* [Medium].
- Kejadian yang sama dua kali tidak membuat dua baris task (idempoten per antrean + task_no).
- Kunjungan non-JKN tidak membuat baris task.
Di luar: pengiriman ke BPJS (2.19), dasbor kelengkapan (Dasbor 6.5, Fase 4), task 1–3 dari loket (menunggu nomor loket 2.20 dan kode booking 2.19).

### 2.19 · [Perlu pemilik] Adapter Antrean RS BPJS + simulator
Tujuan : task ID, tambah/batal antrean, antrean farmasi, dan referensi jadwal dokter terkirim lewat outbox ke Antrean RS (dua arah Mobile JKN).
FR     : FR-02-02, FR-50-02, FR-01-24; 7.02.1
Terhambat: catatan lapangan § 11–12 hanya memuat path (`/antrean/add`, `/antrean/updatewaktu`, `/antrean/farmasi/add`, `/antrean/batal`,
`/ref/*`, `/jadwaldokter/*`, sisi Mobile JKN `/antrol/*`) tanpa body permintaan, kode respons, atau aturan kode booking. Pemilik menyediakan
katalog Antrean RS resmi (atau catatan lapangan berisi contoh permintaan/respons) dan memutuskan pemetaan task. Setelah itu tiket dirinci
dengan pola 2.7 (simulator di `SimulatorIntegrasi`, circuit breaker, tidak ada kredensial nyata).
Di luar: sinkron jadwal ke HFIS (catatan lapangan § 11.4: `updatejadwaldokter` hanya mengubah jadwal yang sudah ada, H-1).

### 2.20 · APM tahap 1: anjungan, nomor loket, cek jadwal
Tujuan : pasien mengambil nomor loket (biasa/prioritas) dan melihat jadwal dokter di anjungan tanpa akun petugas.
FR     : FR-01-23, FR-01-26 (nomor loket prioritas), FR-01-28, FR-01-30 (status online saja), FR-02-01 (loket `A`/`P`), FR-02-07
Akses  : anjungan = perangkat bertoken (pola `TokenPerangkatDisplay` + perintah konsol `TokenDisplay`), cakupan sempit, rate limit per perangkat;
         petugas loket `pendaftaran.antrean` lihat/perbaiki
Tabel  : `kunjungan.anjungan`, `kunjungan.sesi_anjungan`; `kunjungan.antrean` titik `loket`
API    : `POST /api/v1/anjungan/nomor-loket`, `GET /api/v1/anjungan/jadwal-praktik?tanggal=` (tanpa data pasien), loket petugas memakai
         `GET /api/v1/antrean?titik=loket` dan aksi panggil/lewati 2.8
Uji    :
- Token anjungan hanya membuka dua endpoint itu; token display atau sesi petugas ke endpoint anjungan → 401/403, dan sebaliknya.
- Sesi anjungan berakhir setelah tidak aktif sesuai nilai data induk (bawaan 60 detik, 7.01.1); layar kembali ke awal tanpa sisa data.
- Nomor loket prioritas `P-` didahulukan di antrean loket; jam ambil dan jam dipanggil tersimpan di `antrean_jejak`.
- Layar anjungan: tombol ≥ 64 px, teks ≥ 20 px, kontras AA, axe bersih di 1440 dan 1024 px (layar sentuh).
Di luar: daftar mandiri pasien lama BPJS (FR-01-25: butuh 2.19 dan biometrik yang *perlu verifikasi* [Low]), check-in reservasi Mobile JKN
(FR-01-24, 2.19), mode farmasi (setelah depo Tahap C), cetak struk/printer (FR-01-27; struk tampil di layar dulu), layar data induk anjungan.

### 2.21 · Akses darurat (break-glass) + `audit.log_akses`
Tujuan : klinisi membuka RM pasien di luar cakupan saat darurat dengan alasan wajib; aksesnya terbatas waktu dan ditinjau.
FR     : FR-26-12, FR-26-26; UC-33; 9.2.4 (bukan wewenang superadmin), 9.2.5
Regulasi: Permenkes 24/2022 Ps. 29–30 [R]
Akses  : aksi akses darurat untuk peran klinis (kode di PR kontrak, `butuh_mfa`); tinjauan oleh Kepala Instalasi RM/pejabat PDP lewat kotak kerja
Tabel  : `akses.akses_darurat` (11.2), `audit.log_akses` (partisi bulanan, `simrs_app` SELECT/INSERT); jenis tugas baru tinjauan pasca-akses (7.53)
API    : `POST /api/v1/pasien/{id}/akses-darurat` (alasan wajib), `GET /api/v1/akses-darurat?status=`, `POST /api/v1/akses-darurat/{id}/tinjau`
Uji    :
- Klinisi tanpa relasi membuka CPPT berlabel → "Terbatas" + tawaran akses darurat; setelah alasan diisi, data terbuka hanya untuk pasien itu
  sampai `berakhir_pada` (durasi dari data induk, bawaan 4 jam UC-33), lalu tertutup lagi tanpa tindakan.
- Superadmin tanpa peran klinis → 403 (9.2.4). Akun tanpa MFA terverifikasi → 403 `mfa_ulang_diperlukan`.
- Setiap pembacaan selama akses darurat tercatat di `audit.log_akses` (pengguna, pasien, layar, alasan) tanpa isi klinis.
- Tugas tinjauan terbit ke Kepala RM/DPO dengan tenggat; hasil tinjauan tersimpan dan tidak bisa diubah.
- Perubahan `AccessEvaluator` ditinjau `peninjau` + uji IDOR (pasien lain dengan akses darurat pasien A → tetap 403).
Di luar: layar Pencarian & Riwayat RM penuh (7.26.2), pelepasan informasi (FR-26-11, 26-17).

### 2.22 · [Perlu pemilik] Pencarian KFA via SATUSEHAT (simulator)
Tujuan : petugas farmasi mencari dan mengisi kode KFA barang dari kamus nasional, bukan mengetik manual.
FR     : FR-22-05
Terhambat: catatan lapangan § 18 hanya memuat `GET {host}/kfa-v2/products/all?keyword=&size=` dan format kode (`92…`/`93…`), tanpa bentuk respons.
Pemilik menyediakan dokumen API KFA v2 resmi atau contoh respons. Sampai itu `kode_kfa` diisi manual/impor (2.11) dan laporan "barang tanpa KFA"
dipakai sebagai pengganti (catatan lapangan: obat tanpa KFA dilewati SATUSEHAT tanpa galat).

### Keluar Tahap B (CI, data sintetis)
- e2e: pasien dari antrean poli → asesmen perawat → dokter membuka RM (task 4) → CPPT sah dengan PIN → diagnosis → order lab → resep sah →
  antrean farmasi → kunjungan selesai (task 5), @1440 px + axe.
- Akses darurat terbukti membuka dan menutup sendiri (uji jam beku) dan tercatat di `audit.log_akses`.
- 2.19 dan 2.22 tetap terhambat bila dokumen belum ada; tidak menghalangi keluar Tahap B.

**[Perlu pemilik] dari perencanaan Tahap B**
- Sumber data induk interaksi obat, dosis vs eGFR, ambang tanda vital, dan templat skor skrining (Morse/nyeri/gizi): dikurasi RS atau
  basis data berlisensi? Tanpa itu FR-07-07 (interaksi, dosis) dan penanda TTV tidak dibangun.
- Katalog Antrean RS BPJS dan dokumen KFA v2 (2.19, 2.22).
- Kunjungan belum punya `dpjp_id`/`pemeriksa_id` (PPDS, 7.07.1): supervisi peserta didik (FR-07-14) dijadwalkan di tahap mana?
- Selisih PRD ↔ kode dari penyelarasan v0.10.22 (PRD tidak diubah): kolom cari HMAC bernama `*_sidik` di kode, PRD 11.1 menulis `*_hash`;
  Jadwal praktik ada di Master Data (`master_data.jadwal_praktik`), pohon menu 9.1 menaruhnya di Rawat Jalan. Mana yang dibakukan?

## Kerangka tahap berikutnya (dirinci kemudian)
- **C — Penunjang, IGD, rawat inap**: lab/radiologi + hasil mengikuti pasien, telaah resep & depo (+ obat kronis 7 + 23, racikan, mode farmasi APM), IGD + triase + papan (Reverb), rujukan, surat kontrol, admisi RI dari peta TT, SPRI, pemulangan, gelang, verifikasi DPJP dan TBaK.
- **D — Kasir dan pelaporan**: tagihan otomatis per episode, tindakan ICD-9-CM, pemisahan tanggungan, pembayaran, tutup kasir; SATUSEHAT Encounter/Condition/Observation/ServiceRequest/MedicationRequest via outbox; Aplicares dari peta TT.
