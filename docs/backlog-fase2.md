# Backlog Fase 2 (Alur pasien inti) · Tahap A selesai · Tahap B selesai (kecuali 2.19, 2.22) · Tahap C: IGD, rawat inap, penunjang

> Sumber tiket aktif. Kebutuhan tetap di `docs/prd.md` (baca per bagian). Cara kerja, format tiket, worktree, Korlap,
> antislop, dan laporan 5 baris: skill `kerja-tiket`. Status diubah lewat PR yang menyelesaikan tiketnya.
> Tahap B dirinci 7 Nov 2026, Tahap C dirinci 9 Nov 2026 (bawah). Tahap D sengaja hanya kerangka; dirinci setelah Tahap C selesai.

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
- 2.14-fe (kontrak di luar endpoint tiket): (a) `GET /label-sensitivitas` hanya untuk `pengaturan.akun.lihat`, jadi PPA tidak bisa
  memilih label catatan dari layar CPPT/asesmen (pemilih label disembunyikan, penjelasan tampil). Buka baca daftar label aktif untuk
  pemegang `rawat_jalan.cppt.input`/`.asesmen_perawat.input`? (b) `BarisWorklist` tanpa `episode_id`: lingkup "Episode kunjungan ini"
  baru bisa dipilih setelah ada catatan pada kunjungan itu. Tambah `episode_id` ke worklist?
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
- Tinjauan 2.11/2.13 (8 Nov 2026), saran yang belum dikerjakan:
  - 2.11-be: dua impor CSV barang serentak dengan kode sama → 500 (kunci unik), bukan 422 `impor_tidak_valid`. Jarang (hanya penyiapan).
  - 2.11-fe: `DialogFormularium.vue` — akun tanpa `master_data.penjamin.lihat` mendapat banner 403 saat memilih jenis penjamin (beri
    pesan jelas); `DialogItem.vue` — hasil pencarian barang tanpa penjaga respons usang; `formularium.spec` menulis formularium langsung
    (gagal bila instalasi e2e sudah siap melayani) dan datanya menumpuk antarjalan.
  - 2.13-fe: `DialogAsesmen.vue` — sesudah 409 `asesmen_sudah_ada`/412, versi baru menimpa ketikan tanpa pemberitahuan; peran contoh
    `contoh_perawat` memegang `pendaftaran.antrean.perbaiki` yang juga membuka lewati/batal nomor (beri komentar di seeder atau pecah aksi
    panggil); filter tahap saat pindah sub-menu diperbaiki tanpa uji navigasi.
- E2E `rawat-jalan.spec` (proyek sesudah `fase2`) pernah gagal karena sesi hilang/429 akun admisi yang dipakai beruntun, lulus saat rerun
  (PR #156). Rerun dulu; perbaikan: tunggu jatah batas laju penuh di awal spec (pola `tungguJatahPenuh` di `fase1.spec`).
  Flake kedua (PR #188, 9 Nov 2026, lulus saat rerun): `rawat-jalan.spec.ts:205` "dokter memeriksa…" gagal strict mode, karena
  `listitem` berisi `ZZC01` ditemukan 3 kali di panel Pemeriksaan (diagnosis sintetis menumpuk). Perbaikan: saring item diagnosis
  dengan penanda unik per jalan, atau `.first()` sesudah memastikan jumlahnya.
- Layar CPPT dan worklist (2.13/2.14) tidak masuk `LAYAR` `fase1.spec`: modul 07/26 baru diaktifkan `lokal:kunjungan-contoh` di
  `rawat-jalan.spec` (berjalan sesudah fase1), dan akun `fase1` tidak memegang izin rawat jalan. Diuji @1440 + axe di `rawat-jalan.spec`;
  empat lebar belum. Pindahkan ke proyek malam sesudah `rawat-jalan`?
- Lokal: `php artisan test` penuh (±453 uji) melebihi `memory_limit` 128M bawaan PHP Homebrew; CI hijau. Jalankan lokal dengan
  `php -d memory_limit=512M artisan test` atau setel `memory_limit` di php.ini pengembang.
- Migrasi `2026_10_11_120100_create_akses_menu_peran_izin_tables` diubah PR #131 (menambah `usul` ke `izin_jenis_aksi_cek`) setelah
  sudah jalan. DB yang dimigrasi sebelum 24 Okt masih memakai constraint lama, lalu gagal di `2026_10_19_100000_create_inti_penjamin_table`
  karena `MenuBawaan::pasang()` (isi terbaru) menyisipkan izin `usul` sebelum `2026_10_24_100000_gabung_rm_pasien` memperluas constraint.
  CI tidak menangkapnya karena selalu memakai DB baru. Perbaikan maju-saja: migrasi bertanggal sebelum `2026_10_19_100000` yang
  menyamakan constraint (idempoten, `DROP … IF EXISTS` + `ADD`); larang mengubah migrasi yang sudah di-merge (cek CI?). Sementara:
  pasang constraint versi 10_24 secara manual lalu `migrate` (dilakukan di DB lokal folder utama 8 Nov 2026).
- `MaskingLogTest::test_log_manual_dengan_binding_pesan_mentah_dan_jejak_argumen_tetap_tersamar` gagal di lokal (juga di `main` tanpa
  perubahan, 8 Nov 2026): log berisi `SQLSTATE[22P02] invalid input syntax for type uuid: "?"`, bukan pesan yang diharapkan. CI lulus →
  kemungkinan beda versi PostgreSQL/ekstensi atau pengaturan lokal. Selidiki sebelum menganggapnya flake.
- Antrean farmasi (2.17) terbentuk dengan nomor `F-` dan area `farmasi`, tetapi panggil/layani dari depo belum ada: endpoint
  /antrean/{id}/panggil memakai izin `pendaftaran.antrean`. Alur depo (telaah, siap, serah, panggil) = Tahap C.
- `keuangan.tarif_versi.kategori` (2.16, lab/rad) belum bisa diisi lewat API: usulan tarif belum memuat `kategori` (perubahan kontrak
  usulan di luar 2.16 → [Perlu pemilik]). Sampai itu katalog order (`GET /data-induk/tarif?jenis=`) kosong di instalasi nyata;
  lokal diisi `lokal:kunjungan-contoh` (butir `CONTOH-LAB-*`/`CONTOH-RAD-01`). `paket_order` (FR-07-09) juga belum ada endpoint.
- Tinjauan keamanan susulan 2.20-be (#183, 9 Nov 2026), saran yang belum dikerjakan:
  - `GET /anjungan` memanggil `pendaftaran()`, jadi perangkat mode `farmasi` mendapat 409 dan tidak bisa lapor online (FR-01-30).
    `show` cukup mengirim `mode` dan `layanan: []`.
  - Tidak ada uji bahwa `SesiSpaStateful` terkecualikan: POST `nomor-loket` dengan `Referer` dari `SANCTUM_STATEFUL_DOMAINS` + Bearer,
    tanpa token CSRF, harus 201 (kios yang dilayani dari domain SPA).
  - `Anjungan` memakai `HasApiTokens`: rute `auth:sanctum` di masa depan akan menerima token perangkat. Tambah uji struktural atau
    komentar peringatan di `config/sanctum.php`.
  - `TokenPerangkat` tidak memperbarui `last_used_at`; pemakaian token curian sulit dilacak.
  - Batas per IP 600/menit dipakai bersama semua kios di balik NAT yang sama; pastikan `TRUSTED_PROXIES` produksi benar.
  - `POST nomor-loket` tidak idempoten (ketuk dua kali = dua nomor): `Idempotency-Key` per sesi layar, bisa bersama 2.20-fe.
  - PRD 7.01.1 "jejak audit per sesi": `sesi_anjungan` hanya mencatat nomor loket, penelusuran jadwal tidak tercatat. Asumsi tahap 1:
    cukup, karena tanpa data pasien.
- Tinjauan 2.20-fe (9 Nov 2026), saran yang belum dikerjakan: tombol kios yang dinonaktifkan saat `sibuk` (`AnjunganPage.vue`) kehilangan
  fokus ke body bagi pengguna keyboard; pakai `aria-disabled` + penjaga di handler. Kios utamanya layar sentuh, jadi kecil.
  Pemilih "Loket Anda" (antrean loket) memuat semua ruang aktif lewat `GET /lokasi?jenis=ruang&status=aktif` lalu menyaring
  `fungsi = administrasi` di klien (`staleTime` 60 detik); di RS besar ini beberapa halaman berurutan, dan lobi ikut terdaftar.
  Usul: filter `fungsi` di `GET /lokasi` atau penanda loket admisi di data induk ([Perlu pemilik]: kontrak di luar tiket), sementara
  naikkan `staleTime`. Tombol Panggil nomor loket nonaktif tanpa penjelasan sebelum loket dipilih: tambah pesan singkat.
  Petugas loket di RS nyata butuh izin `master_data.organisasi.lihat` untuk pemilih ini (catat di penyiapan peran).
- Tinjauan #201 (2.28-fe, 10 Nov 2026): polling 15 detik lewat `refetchInterval` biasa di Admisi rawat inap, Worklist, Antrean,
  Triase, Disposisi, Pendaftaran IGD, dan Antrean resep tidak mengirim `X-Permintaan-Latar`, jadi layar yang terbuka tanpa interaksi
  terus memperpanjang batas diam sesi (PRD 14.2). Usul: refetch berkala memakai header latar lewat composable polling bersama
  (pola `useKueriBerPolling` papan IGD/peta TT), satu tiket kecil untuk semua layar itu.

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
| 2.12 | Wizard langkah 7 + usulan formularium | BE+FE | S | 2.11 · **Selesai** (kontrak #159 v0.24.0; BE #160: syarat `formularium_rs`, jenis usulan `formularium`; PR FE: dialog formularium berganti mode usulan sesudah 409 `harus_lewat_usulan`, tautan langkah 7) |
| 2.13 | Worklist poli + asesmen awal perawat | BE+FE | M | — · **Selesai** (PR BE #154: modul `RekamMedis` — `klinis.catatan_klinis(_versi)`, `klinis.observasi` berpartisi, `klinis.alergi`; worklist + tahap perawat→dokter di `Antrean`. PR FE: modul `rawat-jalan` — worklist, asesmen awal, alergi; `rawat_jalan.worklist` dan `.asesmen_perawat` TERSEDIA di menu; e2e `rawat-jalan.spec.ts`) |
| 2.14 | CPPT episode (`CpptLinimasa`) + pengesahan PIN | BE+FE | M | 2.13 · **Selesai** (kontrak #159 v0.24.0; BE #161: CPPT episode, sahkan PIN, adendum, label catatan klinis. PR FE: sub-menu `rawat_jalan.cppt` TERSEDIA — linimasa pasien/episode + filter profesi/pos, SOAP polos, sahkan/perbaiki/adendum ber-TTE, label + TTE asesmen perawat; e2e `rawat-jalan.spec.ts`) |
| 2.15 | Pemeriksaan dokter: diagnosis ICD-10 + selesai kunjungan | BE+FE | M | 2.14 · **Selesai** (kontrak #167 v0.25.0; BE #168: `klinis.diagnosis`, mulai periksa/selesai + kejadian task 4/5, relasi diakhiri, antrean poli selesai, ringkasan klinis, cari kode paket RS. PR FE: sub-menu `rawat_jalan.pemeriksaan` TERSEDIA — panel Pemeriksaan dari worklist tahap dokter: ringkasan klinis, alergi, diagnosis ICD-10 lewat pencarian paket RS, selesaikan kunjungan; `lokal:kunjungan-contoh` memuat paket ICD-10 sintetis bila belum ada; e2e `rawat-jalan.spec.ts`) |
| 2.16 | Order penunjang bersama (7.00.5) | BE+FE | M | 2.14 · **Selesai** (kontrak #172 v0.26.0; BE #173: `permintaan.permintaan_pemeriksaan`/`_item`, bagian izin `rawat_jalan.pemeriksaan.order`, `keuangan.tarif_versi.kategori`, cek order ganda, kejadian `PermintaanPemeriksaanDibuat`; PR FE: komponen bersama `src/shared/components/OrderPenunjang.vue` di panel Pemeriksaan, e2e `rawat-jalan.spec.ts`) |
| 2.17 | E-resep rawat jalan → antrean farmasi | BE+FE | M | 2.11, 2.14 · **Selesai** (kontrak #175 v0.27.0; BE #176: `farmasi.resep`/`_item`/`peringatan_resep`, bagian izin `rawat_jalan.pemeriksaan.resep` (sahkan bersyarat SIP), peringatan alergi/duplikasi/non_fornas, antrean farmasi `F-` lewat `ResepDisahkan`, objek TTE `resep`, akun sintetis `apoteker`; PR FE: panel Resep di Pemeriksaan (draf, alasan peringatan, sahkan PIN T1), sub-menu `farmasi.resep` TERSEDIA: Farmasi → Antrean resep per depo, e2e `rawat-jalan.spec.ts`) |
| 2.18 | Task ID antrean 1–7 tercatat internal | BE | S | 2.15, 2.17 · **Selesai** (PR 2.18: `kunjungan.antrean_task` + `pemetaan_task_antrean` sebagai data, pelanggan 6 kejadian, `GET /kunjungan/{id}/task-antrean` v0.28.0; kiriman outbox ditunda ke 2.19) |
| 2.19 | [Perlu pemilik] Adapter Antrean RS BPJS + simulator | BE | M | 2.18 + dokumen katalog Antrean RS · Terhambat |
| 2.20 | APM tahap 1: anjungan, nomor loket, cek jadwal | BE+FE | M | — · **Selesai** (kontrak #181 v0.30.0; BE #183: `kunjungan.anjungan`/`sesi_anjungan`, token perangkat `antrean:token-anjungan`, nomor loket `A-`/`P-`, jadwal anjungan, `GET /antrean?titik=loket`; tinjauan keamanan susulan #186. PR FE: layar kios `/anjungan` tanpa akun (token lewat `#token=`, sesi diam kembali ke awal, tombol ≥ 64 px, teks ≥ 20 px), pilihan titik Loket di Pendaftaran → Antrean & display dengan alasan prioritas; e2e `anjungan.spec.ts` @1440 + 1024 px + axe) |
| 2.21 | Akses darurat (break-glass) + `audit.log_akses` | BE+FE | M | 2.14 · **Selesai** (kontrak #179 v0.29.0; BE #180: `akses.akses_darurat`, `audit.log_akses`, izin `rawat_jalan.cppt.darurat`, tinjauan Kepala RM/DPO; PR FE #182: tawaran "Buka akses darurat" di CPPT, sub-menu `rekam_medis.akses_darurat` TERSEDIA: Rekam Medis → Tinjauan akses darurat, akun sintetis `dokter.darurat`, e2e `akses-darurat.spec.ts`) |
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
Utang 2.12: usulan formularium tidak bisa diperbaiki-lalu-kirim-ulang dari layar (pengusul mengajukan usulan baru; usulan dikembalikan tidak
mengunci objek); satu perubahan = satu usulan dari dialog (mesin menerima 50 butir); impor item CSV dan mengaktifkan kembali item tidak lewat
usulan; formularium tidak tercatat di `inti.data_induk_versi` (jejak di audit + usulan); belum ada uji jalur `gagal_diterapkan` formularium.
Langkah 8–9 kini menunggu langkah 7 (urutan FR-54-08): RS sebaiknya mengisi formularium sebelum konfirmasi langkah 6.

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
- Akses darurat (2.21): batas jumlah pembukaan per akun per hari (kini hanya `throttle:api`)? Tenggat tinjauan bawaan 1×24 jam
  (`TINJAUAN_AKSES_DARURAT`, data jenis tugas) perlu verifikasi RS. Peran pemilik data RM untuk menyetujui `rawat_jalan.cppt.darurat`
  ditetapkan RS lewat `PUT /izin/{kode}/penyetuju`; sebelum itu izin ini tidak bisa diberikan.
- Anjungan (tinjauan keamanan susulan #183, 9 Nov 2026; tanpa celah wajib):
  (a) PRD 11.2 `anjungan` menulis kolom `token_perangkat_hash` (dan `printer`); kode memakai token Sanctum di
  `sistem.personal_access_tokens` (hash SHA-256, kemampuan `anjungan`, cabut lewat `TokenAnjungan --cabut`), pola yang sama dengan
  `display_antrean`. Selaraskan PRD ke kode?
  (b) Uji penerimaan 2.20 butir 1 menyebut token anjungan membuka "dua endpoint"; kode dan openapi v0.30.0 membuka tiga (`GET /anjungan`
  untuk konfigurasi + tanda online FR-01-30). Ubah rumusan jadi "endpoint `/anjungan/*`"?
  (c) Token kios yang bocor bisa dipakai dari internet: tanpa ikatan jaringan RS, `POST nomor-loket` ikut kuota 30/menit per perangkat,
  dan nomor `P-` selalu didahulukan, jadi antrean loket bisa dibanjiri atau diserobot. Pilihan sebelum pilot: batasi `/anjungan/*` ke
  `autentikasi.mfa.jaringan_rs` atau daftar IP per perangkat, limiter `nomor-loket` lebih ketat, dan/atau token berkedaluwarsa + rotasi.
  Aturan jaringan/kedaluwarsa belum ada di PRD.

## Tahap C · tiket (IGD, rawat inap, penunjang, farmasi depo, pemulangan)

Dirinci 9 Nov 2026 dari PRD 7.00.2–7.00.5, 7.03, 7.04, 7.05, 7.08, 7.19, 7.20, 7.22 dan 11.2 (`kunjungan`, `klinis`, `permintaan`,
`lab`, `radiologi`, `farmasi`, `logistik`). Semua skema sudah ada di `02-database.sql`; tidak ada skema baru.

**Aturan Tahap C (berlaku untuk semua tiket di bawah, tambahan atas aturan Tahap B):**
- Kunjungan dan episode saat ini hanya `poliklinik` / `rawat_jalan_harian` (CHECK di migrasi 2026_10_22_120000). Jenis baru (IGD, rawat inap)
  dibuka lewat migrasi maju yang memperluas CHECK; aturan pembentukan dan penggabungan episode mengikuti tabel 7.00.2, tidak dikarang.
- Perintah perpindahan pasien (rawat inap, pindah ruang, pulang, rujuk) hanya lewat matriks perpindahan 7.00.3 yang disimpan sebagai **data
  induk** (FR-00-02); perintah di luar matriks ditolak. Kejadian (hasil keluar, obat diserahkan, pasien tiba) lewat bus kejadian (FR-00-04).
- Nilai klinis (nilai rujukan lab per umur/JK, batas kritis, daftar skrining radiologi, instrumen triase, batas lama menunggu kamar) hanya
  dari **data induk yang disetujui RS**. Tiket boleh membangun struktur data induknya; isinya di lokal/e2e sintetis berlabel "contoh".
- Antarmuka luar tanpa dokumen resmi (alat lab HL7/ASTM, PACS/DICOM MWL, SISRUTE, VClaim surat kontrol/SPRI, Reverb) **tidak** dibangun di
  tiket ini: hasil dan ekspertise diinput dari layar, dan adaptor menjadi [Perlu pemilik] di bawah. Papan IGD dan peta TT memakai polling
  ETag (pola display 2.8, K1) sampai Reverb diputuskan.
- Hasil penunjang mengikuti pasien (7.00.5 butir 2, FR-00-09): penerima = pemesan **dan** DPJP/ruang tempat pasien berada saat hasil keluar,
  lewat kotak kerja/notifikasi 7.53 yang sudah ada.
- Hasil, ekspertise, telaah, dan resume yang sudah disahkan tidak diubah; koreksi lewat adendum bertanda (FR-26-06), pola 2.14.
- Keputusan pemilik 9 Nov 2026: episode IGD > 6 jam dibentuk otomatis sesudah 6 jam dan aktivitas kunjungan IGD (order, resep,
  catatan) ikut pindah ke episode baru; ini menyimpang dari PRD 7.00.5 butir 1 dan diselaraskan saat penyelarasan PRD per fase.

| No | Tiket | Jalur | Ukuran | Prasyarat · Status |
|---|---|---|---|---|
| 2.23 | Kunjungan IGD: registrasi cepat + episode | BE+FE | M | — · **Selesai** (BE+kontrak #191 v0.31.0: `/kunjungan-igd`, jam tiba server, episode rawat jalan harian, satu IGD aktif per pasien; episode IGD > 6 jam dibentuk otomatis beserta aktivitasnya, keputusan pemilik 9 Nov 2026; FE #192: layar Pendaftaran IGD) |
| 2.24 | Triase IGD dan ulang triase | BE+FE | S | 2.23 · **Selesai** (satu PR kontrak+BE+FE v0.32.0: `/kunjungan/{id}/triase` hanya ditambah, berlaku = terbaru, riwayat menyimpan salinan kode/nama instrumen dan label skala saat triase; data induk `inti.instrumen_triase` tanpa isi produksi + layar Master Data → Instrumen triase (`/data-induk/instrumen-triase`, edit langsung berizin + dasar + audit, pola penjamin; keputusan pemilik 9 Nov); field `triase` di `/kunjungan-igd` hanya bagi `igd.triase.lihat`, izin daftar tetap `igd.pendaftaran.lihat` (keputusan pemilik 9 Nov); layar IGD → Triase; perbaikan tinjauan #191: `KunciKunjungan` satu sumber urutan kunci pasien → kunjungan (CPPT, asesmen awal, order penunjang, batal kunjungan, episode IGD > 6 jam). Asumsi: `tiba_pada` dibaca dari `kunjungan.tiba_pada`, tidak disalin ke `kunjungan.triase` walau PRD 11.2 mencantumkannya) |
| 2.25 | Papan pasien IGD (polling) | BE+FE | M | 2.24 · **Selesai** (BE+kontrak #196 v0.34.0: GET `/igd/papan` polling ETag/304 `no-store`, maks 200 baris + `meta.terpotong`, urut belum ditriase → merah → kuning → hijau → hitam lalu jam tiba, tanda tanggap dan 6 jam dihitung server; PUT `/kunjungan/{id}/area-igd`; data induk area IGD dan aturan papan (`master_data.papan_igd`); FE #197: layar IGD → Papan pasien IGD (urutan server, lencana berteks + ikon, filter area, tempatkan per baris, polling 15 detik If-None-Match lewat sesi petugas dengan `X-Permintaan-Latar`, muat di 1024 px) dan Master Data → Area & batas papan IGD (dasar perubahan, If-Match), sub-menu TERSEDIA; keputusan pemilik 9 Nov 2026: tanggap tanpa tahap peringatan, berakhir saat dokter mulai mengisi asesmen awal, ambang mendekati 6 jam kosong = mati, belum ditriase di atas) |
| 2.26 | Matriks perpindahan sebagai data induk + disposisi IGD | BE+FE | M | 2.23 · **Selesai** (BE+kontrak #194 v0.33.0: `kunjungan.aturan_perpindahan` isi awal tabel 7.00.3 + APS/DOA, GET `/data-induk/matriks-perpindahan`, perubahan lewat usulan `matriks_perpindahan`; POST/GET `/kunjungan/{id}/disposisi` hanya ditambah, izin sel matriks lewat AccessEvaluator, `igd.disposisi.input` bersyarat SIP; pulang/APS/rujuk/meninggal/DOA menutup kunjungan IGD, rawat inap `menunggu_kamar`, kamar operasi `menunggu_kamar_operasi`; FE #195: layar IGD → Disposisi, Master Data → Matriks perpindahan (usulan tambah/ubah/nonaktifkan sel), tautan Disposisi di Pendaftaran IGD, sub-menu TERSEDIA; akun e2e `dokter.poli` memegang `igd.pendaftaran.lihat` + `igd.disposisi.*`) |
| 2.27 | Peta tempat tidur dan status TT | BE+FE | M | — · **Selesai** (BE+kontrak #198 v0.35.0: GET `/peta-tempat-tidur` polling ETag/304 `no-store`, maks 1000 TT + `meta.terpotong`, saring gedung/bangsal, cakupan unit bangsal; POST `/tempat-tidur/{id}/status` dibersihkan → kosong siap dengan riwayat dan audit; FE #199: layar Rawat Inap → Peta tempat tidur (gedung → lantai → bangsal → kamar, status berteks + ikon, TT ditutup tanpa aksi, filter gedung/bangsal ke API dan lantai/status/kode TT di layar, ringkasan per status, Tandai kosong siap berizin perbaiki, polling sama dengan papan IGD), sub-menu TERSEDIA, modul 08 aktif di `lokal:kunjungan-contoh`) |
| 2.28 | SPRI, antrean permintaan kamar, alokasi TT | BE+FE | M | 2.26, 2.27 · **Selesai** (BE+kontrak #200 v0.36.0: POST `/kunjungan/{id}/spri` dokter poli lewat izin sel matriks bersyarat SIP, disposisi IGD rawat inap memakai layanan yang sama; `kunjungan.surat_kontrol` jenis spri + `kunjungan.permintaan_tempat_tidur` satu aktif per kunjungan; GET `/permintaan-tempat-tidur` antrean aktif terlama dulu dengan lama menunggu dari server; POST `/permintaan-tempat-tidur/{id}/alokasi` kunci permintaan lalu TT, TT → dipesan; permintaan batal lewat kejadian disposisi/pembatalan kunjungan, TT kembali kosong siap; papan IGD `permintaan_kamar`; tugas eskalasi menunggu kamar nonaktif sampai RS menyetel; FE #201: layar Pendaftaran → Admisi rawat inap (antrean berkursor dengan asal, kelas diminta, rencana, lama menunggu dari server, status berteks + ikon, TT teralokasi; Alokasikan TT bagi izin input memilih TT `dapat_dipilih` dari peta TT yang disaring kelas diminta, 409 memuat ulang antrean dan pilihan TT dulu), Buat SPRI di Pemeriksaan poli dengan ringkasan SPRI, papan IGD menampilkan permintaan kamar (status + kode TT/bangsal), peta TT menandai TT dijadwalkan tutup, sub-menu TERSEDIA, admisi contoh melihat peta TT) |
| 2.29 | Admisi rawat inap: penempatan TT, pindah ruang, episode rawat inap | BE+FE | M | 2.28 · **Selesai** (BE+kontrak #202 v0.37.0: POST `/permintaan-tempat-tidur/{id}/tempatkan` membuka kunjungan rawat inap (DPJP awal = pemberi perintah rawat) dan menggabung episode 7.00.2, TT dipesan → terisi; POST `/permintaan-tempat-tidur/{id}/batal` beralasan, TT kembali kosong siap; POST `/kunjungan/{id}/pindah-ruang` lewat sel matriks rawat_inap → rawat_inap; satu pasien satu TT (409 `pasien_sudah_ditempatkan`/`pasien_sedang_dirawat`); `hari_rawat` peta TT terisi; status TT `melewati_rencana_lama_rawat`; FE #203 v0.38.0: peta TT membawa `kunjungan_rawat_inap_id` opsional hanya bagi `rawat_inap.pindah_ruang.input` di cakupan bangsal TT (keputusan pemilik 10 Nov 2026); Admisi rawat inap → Tempatkan (konfirmasi dua penanda identitas + TT tujuan, serah terima RM-15 opsional) dan Batalkan (alasan wajib) per baris, 409 per kode dengan antrean dimuat ulang; peta TT menampilkan "Hari ke-N" dan status Melewati rencana lama rawat (lencana berteks + ikon, tepi `--danger`, ikut ringkasan per status); Peta TT → Pindah ruang dari TT berpasien (TT tujuan `dapat_dipilih` kosong siap selain asal, keterangan pindah kelas, alasan dan serah terima RM-15 wajib, peta dimuat ulang); akun contoh `dokter.poli` melihat peta TT, TT contoh CTHG-2-202-4 kosong siap) |
| 2.30 | Lab: daftar kerja dan spesimen (order → diterima lab) | BE+FE | M | — · Siap |
| 2.31 | Lab: hasil, nilai rujukan data induk, validasi, hasil mengikuti pasien | BE+FE | M | 2.30 · Siap |
| 2.32 | Lab: pelaporan nilai kritis (lapor, baca ulang, DPJP, CPPT) | BE+FE | S | 2.31 · Siap |
| 2.33 | Radiologi: worklist, skrining, pelaksanaan | BE+FE | M | — · Siap |
| 2.34 | Radiologi: ekspertise ber-TTE, adendum, temuan kritis | BE+FE | M | 2.33 · Siap |
| 2.35 | Farmasi: telaah resep dan kembalikan ke dokter | BE+FE | M | — · Siap |
| 2.36 | Farmasi: stok depo per batch (saldo awal, mutasi gudang → depo) | BE+FE | M | — · Siap |
| 2.37 | Farmasi: penyiapan, penyerahan, PIO, kurangi stok | BE+FE | M | 2.35, 2.36 · Siap |
| 2.38 | Farmasi: racikan dengan komponen dan BUD | BE+FE | M | 2.37 · Siap |
| 2.39 | Farmasi: obat kronis JKN 7 + 23 hari | BE+FE | M | 2.37 · Siap |
| 2.40 | Pemulangan: rencana pulang, resume medis ber-TTE, daftar periksa | BE+FE | M | 2.29 · Siap |
| 2.41 | Konsul / rujukan internal antar-poli | BE+FE | S | — · Siap |
| 2.42 | Mode display papan IGD (token perangkat) | BE+FE | M | 2.25 · Siap |

Urutan (satu agen; kolom BE lalu FE berurutan): 2.23 → 2.24 → 2.26 → 2.25 → 2.27 → 2.28 → 2.29 → 2.40; penunjang 2.30 → 2.31 → 2.32,
2.33 → 2.34; farmasi 2.35 → 2.36 → 2.37 → 2.38/2.39; 2.41 kapan saja. Tiap tiket M: PR "BE+kontrak" lalu PR FE (keputusan pemilik 9 Nov).

### 2.23 · Kunjungan IGD: registrasi cepat + episode
Tujuan : pasien gawat terdaftar di IGD dalam hitungan detik, identitas boleh dilengkapi kemudian, dan masuk episode yang benar.
FR     : FR-03-01, FR-00-01 (bagian IGD); 7.00.2 baris "Rawat jalan harian" dan "IGD > 6 jam"; 7.00.3 baris Pendaftaran → IGD
Tabel  : `kunjungan.kunjungan` (`jenis_kunjungan` + `igd`), `kunjungan.episode` (jenis + `igd_lebih_6_jam` bila nama dari 7.00.2 dipakai;
         nama kode di PR kontrak), pasien tanpa identitas lengkap memakai alur pasien sementara yang ada (bila belum ada: [Perlu pemilik])
API    : `POST /api/v1/kunjungan-igd` (kontrak menetapkan isian minimal), `PATCH` pelengkapan identitas lewat endpoint pasien yang ada
Uji    :
- Registrasi IGD hanya butuh isian minimal 7.03 FR-03-01; jam tiba = jam server.
- Kunjungan IGD pasien yang hari itu sudah punya episode rawat jalan harian masuk episode yang sama (7.00.2 baris 1).
- Kunjungan IGD yang melewati 6 jam tanpa rawat inap membentuk episode "IGD > 6 jam" sesuai 7.00.2 (jam beku dalam uji).
- Akses `igd.pendaftaran.input` (kode final di kontrak); tanpa izin → 403; pasien tetap tanpa duplikat (pencarian pasien 2.5 dipakai).
Di luar: triase (2.24), papan (2.25), mode korban massal (FR-03-10), PONEK (FR-03-11), SEP IGD (pola 2.7, menyusul).

### 2.24 · Triase IGD dan ulang triase
Tujuan : setiap pasien IGD punya kategori triase yang tercatat, bisa diulang, dan menentukan urutan papan.
FR     : FR-03-02
Tabel  : `kunjungan.triase` (11.2; ulang triase = baris baru, bukan ubah); instrumen dan definisi kategori = data induk RS
API    : `POST /api/v1/kunjungan/{id}/triase`, `GET /api/v1/kunjungan/{id}/triase`
Uji    :
- Kategori hanya merah/kuning/hijau/hitam (FR-03-02); skala/instrumen dari data induk aktif, tanpa nilai klinis di kode.
- Ulang triase menambah baris dengan jam server dan petugas; triase berlaku = terbaru; riwayat tetap tampil.
- Triase hanya untuk kunjungan IGD aktif; kunjungan selesai/batal → 409.
Di luar: skor EWS (FR-08-10, data induk belum ada).
Catatan tinjauan #191: **selesai di PR 2.24**. `KunciKunjungan::pasienLaluKunjungan` mengunci pasien → kunjungan; `KelolaCppt`,
`KelolaAsesmenAwal`, dan `KelolaPermintaanPemeriksaan` membaca `episode_id` dari baris terkunci itu (uji `CpptTest`);
`KelolaKunjungan` (batal) dan `BentukEpisodeIgd` memakai kunci yang sama.
Asumsi: `tiba_pada` triase dibaca dari `kunjungan.tiba_pada` (PRD 11.2 menaruhnya di `triase`; disalin = dua sumber jam tiba).
Menyusul: definisi kategori per RS sebagai data induk (layar instrumen triase sudah ada di PR ini).

### 2.25 · Papan pasien IGD (polling)
Tujuan : dokter dan perawat IGD melihat semua pasien IGD dengan triase, waktu tanggap, dan lama di IGD dalam satu layar.
FR     : FR-03-03, FR-03-04, FR-03-05 (bagian papan), FR-00-06; K1 (polling ETag sampai Reverb)
Tabel  : `kunjungan.papan_igd` (11.2) atau tampilan dari kunjungan + triase (keputusan di PR kontrak, Asumsi)
API    : `GET /api/v1/igd/papan` (ETag/If-None-Match, `no-store`)
Uji    :
- Urut triase lalu jam tiba (FR-03-03); area Resusitasi/Observasi/Hijau dari data induk.
- Waktu tanggap = jam tiba → dokter mulai mengisi asesmen awal; tanda `menunggu` sampai batas (bawaan 5 menit, FR-03-04/KPI
  FR-03-05) lalu `lewat_batas`, tanpa tahap peringatan; tanda mendekati 6 jam bila RS mengisi ambangnya (FR-00-06).
- Data pasien hanya untuk pemegang izin papan IGD; respons 304 bila tidak berubah.
- Layar @1440 + axe; status tidak hanya warna (PRD 5.1 butir 4).
Di luar: KPI dasbor (FR-03-05 bagian dasbor, Fase 4), Reverb, eskalasi menunggu kamar (2.28).
Keputusan pemilik 9 Nov 2026: tanpa tahap peringatan tanggap; ambang mendekati 6 jam diisi RS (kosong = mati); waktu tanggap
berakhir saat dokter mulai mengisi asesmen awal; belum ditriase di atas.
Asumsi BE #196: `GET /igd/papan` tidak memakai `Paginasi::respons()`: maks 200 baris + `meta.terpotong`, urutan prioritas
klinis (triase lalu jam tiba) bukan kursor waktu, dan satu ETag untuk seluruh papan.
Asumsi FE #197: polling papan memakai sesi petugas sebagai permintaan latar (tidak memperpanjang batas diam 15 menit,
PRD 14.2), jadi papan di layar besar tanpa interaksi ikut keluar sesi (keputusan pemilik 9 Nov 2026: papan 2.25 hanya
layar kerja petugas; monitor dinding bertoken perangkat = tiket 2.42). Tempatkan area = pilih lalu tombol Tempatkan (bukan kirim saat pilihan berubah) agar navigasi papan ketik
tidak mengirim penempatan berulang.

### 2.26 · Matriks perpindahan sebagai data induk + disposisi IGD
Tujuan : keputusan akhir pasien IGD tercatat sebagai perintah yang sah menurut matriks 7.00.3, bukan teks bebas.
FR     : FR-00-02, FR-00-03 (serah terima RM-15 bagian data), FR-03-09
Tabel  : data induk matriks perpindahan (asal × tujuan × peran pemberi perintah; isi awal = tabel 7.00.3), perintah perpindahan
         (pemutus, alasan, asal, tujuan, serah terima, status episode)
API    : `GET /api/v1/data-induk/matriks-perpindahan`, `POST /api/v1/kunjungan/{id}/disposisi`
Uji    :
- Disposisi IGD: pulang, rawat inap, OK, rujuk, APS, meninggal, DOA (FR-03-09); tujuan di luar matriks → 422 dengan kode jelas.
- Hanya peran yang diizinkan matriks (dokter DPJP/jaga) yang boleh memberi perintah; peran lain → 403.
- Disposisi pulang/APS menutup kunjungan IGD; rawat inap membuat permintaan kamar (dipakai 2.28), tidak langsung menempatkan pasien.
- Matriks diubah lewat usulan data induk yang ada (pola 1.x usulan), bukan langsung.
Di luar: alur meninggal B-05/B-06 rinci, rujuk keluar SISRUTE/VClaim ([Perlu pemilik]).
Asumsi FE #195: usulan matriks yang dikembalikan diperbaiki dengan usulan baru dari layar Matriks perpindahan (pola formularium),
bukan kirim ulang; kode izin pemberi perintah diketik bebas (pola dicek layar, keberadaan izin dicek server) karena belum ada daftar
izin untuk pengelola data induk.

### 2.27 · Peta tempat tidur dan status TT
Tujuan : admisi dan kepala ruang melihat semua TT per ruang dengan statusnya dari satu sumber.
FR     : FR-08-01, FR-08-20, FR-08-04 (bagian status)
Tabel  : `inti.tempat_tidur` (1.8b), `kunjungan.riwayat_status_tempat_tidur` (11.2)
API    : `GET /api/v1/peta-tempat-tidur?bangsal_id=&gedung_id=` (ETag), `POST /api/v1/tempat-tidur/{id}/status` (dibersihkan → kosong siap)
Uji    :
- Status per TT: terisi (angka hari rawat), kosong siap, dibersihkan, dipesan, tidak dipakai, rencana pulang (FR-08-01); TT ditutup 1.8b
  tidak bisa dipilih.
- Filter dan kelompok gedung/lantai/bangsal + cari kode TT (FR-08-20).
- Perubahan status tercatat di riwayat dengan pelaku dan sumber kejadian; TT "dibersihkan" → "kosong siap" oleh petugas berizin.
- Layar @1440 + axe, status tidak hanya warna.
Di luar: SIRANAP/Aplicares (Tahap D), KPI BOR (Fase 4), housekeeping penuh.
Asumsi BE #198: modul baru `RawatInap` (08). `GET /peta-tempat-tidur` tanpa `Paginasi::respons()`: maks 1000 TT +
`meta.terpotong`, satu ETag untuk seluruh peta. TT ditutup (1.8b) tetap tampil `tidak_dipakai`, `ditutup` true, tidak dapat
dipilih (status tersimpan tidak diubah); TT yang baru berlaku di tanggal mendatang tidak tampil. `hari_rawat` null sampai
penempatan (2.29). Cakupan = unit organisasi bangsal (cakupan unit memuat turunannya): GET menyaring, POST di luar cakupan 403.
Riwayat status hanya ditambah (`simrs_app` tanpa UPDATE, pola triase); layanan `UbahStatusTempatTidur` menerima status asal
yang sah dari pemanggil (2.28/2.29 menetapkan transisinya sendiri). Bangsal nonaktif / ruang renovasi tetap tampil dengan
status tersimpan. TT di luar cakupan: 404 diperiksa sebelum 403 (data induk tanpa data pasien, diterima tinjauan). Batas
1000 baris belum diuji.
Asumsi FE #199: pilihan gedung dan bangsal diambil dari TT yang pernah tampil di peta (tanpa endpoint daftar tersendiri),
jadi tetap ada sesudah satu gedung/bangsal dipilih. Ringkasan jumlah per status mengikuti gedung, bangsal, dan lantai
terpilih, tidak ikut filter status dan kode TT. TT ditutup dihitung dan disaring sebagai status tampil "Ditutup", terpisah
dari "Tidak dipakai". Sesudah Tandai kosong siap (berhasil atau 409), peta dimuat ulang dulu, baru pesan tampil.

### 2.28 · SPRI, antrean permintaan kamar, alokasi TT
Tujuan : semua permintaan kamar (IGD, poli, elektif) ada di satu antrean dengan lama menunggu, lalu dialokasikan ke TT yang kosong.
FR     : FR-08-03, FR-03-06, FR-03-07; 7.00.2 baris "Rawat inap" (terbentuk saat SPRI)
Tabel  : `kunjungan.permintaan_tempat_tidur`, `kunjungan.surat_kontrol` jenis `spri` (catatan internal; nomor BPJS menyusul [Perlu pemilik])
API    : `POST /api/v1/kunjungan/{id}/spri`, `GET /api/v1/permintaan-tempat-tidur`, `POST /api/v1/permintaan-tempat-tidur/{id}/alokasi`
Uji    :
- SPRI hanya dari dokter yang diizinkan matriks 2.26; membuat permintaan kamar berstatus menunggu dengan asal dan kelas diminta.
- Alokasi hanya ke TT kosong siap; dua alokasi serentak ke TT yang sama → satu berhasil, satu 409.
- TT teralokasi berstatus "dipesan" di peta TT (2.27) dan di papan IGD (FR-03-06).
- Menunggu melewati batas data induk RS → tugas eskalasi ke Kepala IGD dengan jam eskalasi (FR-03-07, 7.53).
Di luar: penempatan pasien (2.29), deposit/naik kelas (Tahap D).
Catatan tinjauan #194 (wajib di 2.28) — butir 1–3 selesai di #200:
1. ~~Perintah `menunggu_kamar` tidak pernah ditutup (`kunjungan.perpindahan_pos` hanya ditambah): antrean kamar harus digabung
   dengan status kunjungan, atau permintaan kamar membatalkan diri lewat kejadian `DisposisiDicatat` (pasien APS/meninggal
   sesudah menunggu kamar).~~ Selesai #200: permintaan batal lewat `DisposisiDicatat` (status `selesai`) dan
   `KunjunganDibatalkan`; antrean hanya permintaan aktif.
2. ~~Izin sel matriks bisa diusulkan ke izin non-klinis (`ButirMatriksPerpindahan` hanya memeriksa keberadaan izin): tolak atau
   peringatkan izin tanpa `butuh_kewenangan_klinis` sebelum 2.28 memakai sel SPRI.~~ Selesai #200: 422 `izin_bukan_klinis`.
3. ~~Sel poliklinik memakai `rawat_jalan.pemeriksaan.sahkan` yang bukan `IZIN_KLINIS`: putuskan sebelum `poliklinik → rawat_inap`
   dipakai.~~ Selesai #200: izin itu kini `IZIN_KLINIS`.
Catatan tinjauan #198 (wajib di 2.28/2.29):
1. ~~`KelolaTempatTidur::tutup()` belum mengunci baris TT dan belum menolak TT berstatus terisi/dipesan (`PemeriksaPenempatan`
   masih `PenempatanBelumAda`): kunci baris dan baca `status_saat_ini` sebelum alokasi/penempatan memakai status itu.~~
   Selesai #200: `tutup()` mengunci baris TT `FOR UPDATE` dan menolak `terisi`/`dipesan` (409 `tempat_tidur_terisi`).
2. Status "melewati rencana lama rawat" (FR-08-01) perlu perluasan CHECK status di `inti.tempat_tidur` dan
   `kunjungan.riwayat_status_tempat_tidur` lewat migrasi baru di 2.29. (Tetap untuk 2.29.)
Keputusan pemilik 10 Nov 2026:
1. Batas lama menunggu kamar sebelum eskalasi ke Kepala IGD (FR-03-07): jenis tugas `PERMINTAAN_KAMAR_MENUNGGU` dipasang
   **nonaktif** sampai RS mengisi batasnya sendiri di layar Jenis Tugas (pola "kosong = mati" papan IGD).
2. Usulan matriks perpindahan yang memasang `izin_perintah` tanpa `butuh_kewenangan_klinis` ditolak 422 `izin_bukan_klinis`,
   saat diajukan (uji kering) maupun saat diterapkan.
3. `rawat_jalan.pemeriksaan.sahkan` menjadi izin klinis (`MenuBawaan::IZIN_KLINIS`, migrasi memanggil `pasang()`); pemegang
   tanpa SIP/SPK berlaku tidak lagi bisa menyelesaikan kunjungan poli maupun memberi SPRI (mengencangkan).
Asumsi BE #200: angka jenis tugas menunggu kamar (batas 240 menit dari contoh PRD FR-53-05 "> 4 jam", pengingat −60,
teguran 0, eskalasi 240) hanya pengisi CHECK `batas_menit > 0` selama nonaktif, bukan nilai klinis; RS wajib menyetel batas
dan unit teguran (unit IGD, kepalanya = Kepala IGD) sebelum mengaktifkan. Pelaksana tugas = peran pemegang
`pendaftaran.admisi.input` (tanpa peran itu: pemberi perintah); hanya permintaan asal IGD yang dieskalasi. SPRI endpoint hanya
untuk kunjungan poli (IGD → 409 `spri_igd_lewat_disposisi`, rawat inap IGD lewat disposisi yang memakai layanan yang sama);
SPRI juga dicatat sebagai perintah `perpindahan_pos` `menunggu_kamar`, serah terima opsional (RM-15 saat penempatan 2.29).
Episode rawat inap belum dibentuk: jam SPRI (`surat_kontrol.diterbitkan_pada` = `permintaan.diminta_pada`) disimpan sebagai
awal rawat inap untuk 2.29. Permintaan IGD tanpa kelas diminta (disposisi tidak memilih kelas) menerima TT kelas apa pun;
kelas berbeda dari kelas diminta → 422 `kelas_tidak_sesuai` (titip/naik kelas Tahap D). Antrean = satu antrean RS untuk izin
`pendaftaran.admisi.lihat` di cakupan mana pun (permintaan belum punya bangsal); alokasi dibatasi cakupan unit bangsal TT.
Permintaan batal bila disposisi menutup kunjungan IGD (status `selesai`), bukan bila menunggu kamar operasi. Permintaan
poli tetap aktif sesudah kunjungan poli selesai (pasien menunggu kamar), batal hanya bila kunjungan dibatalkan. Uji
serentak memakai urutan kunci + 409 status (repo belum punya pola uji dua koneksi). Usulan yang izinnya berubah tidak klinis
sesudah diajukan berakhir `gagal_diterapkan` (mesin usulan), bukan respons 422. Nomor SPRI BPJS [Perlu pemilik].
Perbaikan tinjauan #200: SPRI hanya untuk kunjungan poli `dilayani` (yang `terdaftar` bisa batal otomatis jam tutup jadwal
dan ikut membatalkan permintaannya); izin sel matriks diperiksa di cakupan mana pun sebelum keberadaan/jenis kunjungan
dibaca, 409 `spri_igd_lewat_disposisi` tanpa rincian. Asumsi: antrean diurutkan dan lama menunggu dihitung dari
`menunggu_sejak` = max(jam SPRI, awal tanggal rencana zona RS), jadi SPRI terencana tidak mendahului yang menunggu sekarang;
alokasi tetap boleh sebelum tanggal rencana. TT yang penutupannya sudah dijadwalkan (`berlaku_sampai` terisi) tidak bisa
dialokasikan (409 `tempat_tidur_ditutup`) dan `dapat_dipilih` peta false. Asumsi: penutupan terjadwal berapa pun jauhnya
langsung membuat TT tidak bisa dipilih/dialokasikan. `tutup()` menolak TT terisi/dipesan untuk semua tanggal mulai tutup,
karena `PenempatanBelumAda::terisiAtauDipesan()` selalu false sampai 2.29. Asumsi: "eskalasi ke Kepala IGD" (FR-03-07) =
teguran tingkat 2 ke kepala unit teguran jenis tugas (unit IGD); tingkat 3 mengikuti aturan 1.7. Asumsi: perintah
`menunggu_kamar` dari 2.26 yang tercatat sebelum migrasi ini tidak diisi-ulang menjadi permintaan kamar (belum ada data
produksi).
Menyusul (dari tinjauan #200, tidak dikerjakan di 2.28):
- Keunikan permintaan aktif masih per kunjungan, bukan per pasien: pasien yang mendapat SPRI poli lalu disposisi IGD rawat
  inap bisa punya dua permintaan/dua TT dipesan. Endpoint batal permintaan kamar juga belum ada. Untuk 2.29.
- `UbahStatusTempatTidur` memanggil `audit->catat()` di tengah transaksi alokasi/batal (kunci rantai audit dipegang lebih
  lama). Pola diterima; ditinjau ulang bila throughput menjadi masalah.
- 2.29 mengganti `PenempatanBelumAda` dengan pemeriksa penempatan nyata; sesudah itu penolakan `tutup()` berdasarkan status
  sekarang boleh dilonggarkan untuk penutupan bertanggal depan (diperiksa per tanggal).
Asumsi FE #201: aksi Buat SPRI tampil bagi pemegang `rawat_jalan.pemeriksaan.sahkan` (izin sel bawaan poliklinik → rawat
inap) pada kunjungan `dilayani`; dokter poli tidak bisa membaca matriks, jadi sel yang diubah RS ke izin lain tetap diputus
server (403 tampil sebagai pesan). Ringkasan SPRI hanya tampil sesudah simpan di panel itu (belum ada GET permintaan per
kunjungan); membuka ulang lalu menyimpan lagi berujung 409 `permintaan_kamar_sudah_ada` dengan pesan jelas. Pilihan TT
alokasi diambil dari GET `/peta-tempat-tidur` seluruh cakupan (tanpa saring gedung/bangsal), hanya `dapat_dipilih` dan
kosong siap, disaring kelas diminta; bangsal pilihan dokter hanya disebut, tidak menyaring. Akun contoh `admisi` diberi
`rawat_inap.peta_tempat_tidur.lihat` karena pilihan TT berasal dari peta. Antrean dimuat ulang tiap 15 detik seperti daftar
IGD di Disposisi (bukan permintaan latar). TT kosong siap dengan `dapat_dipilih` false dan tidak ditutup dibaca sebagai
penutupan terjadwal (satu-satunya sebab di kontrak v0.36.0) dan diberi keterangan di peta TT. Kolom papan IGD "Disposisi
menunggu" menjadi "Disposisi dan kamar". Tautan tugas `?permintaan=` menyorot baris itu.

### 2.29 · Admisi rawat inap: penempatan TT, pindah ruang, episode rawat inap
Tujuan : pasien tiba di bangsal, menempati TT, dan seluruh kunjungan sebelumnya hari itu tergabung ke episode rawat inap.
FR     : FR-08-06 (pindah ruang/kelas), FR-08-04, FR-00-01 (rawat inap), FR-00-03; 7.00.2 baris "Rawat inap"
Tabel  : `kunjungan.rawat_inap`, `kunjungan.penempatan_tempat_tidur`, `kunjungan.kunjungan` (`jenis_kunjungan` + `rawat_inap`)
API    : `POST /api/v1/permintaan-tempat-tidur/{id}/tempatkan`, `POST /api/v1/kunjungan/{id}/pindah-ruang`
Uji    :
- Penempatan menutup permintaan, membuka penempatan dengan jam server, TT "terisi"; episode rawat inap menggabung kunjungan IGD/poli
  hari yang sama yang mendahului (7.00.2).
- Pindah ruang: penempatan lama ditutup (alasan pindah), TT lama "dibersihkan", TT baru "terisi", serah terima tercatat (FR-00-03).
- Satu pasien tidak bisa menempati dua TT; TT terisi tidak bisa ditempati pasien lain (409).
- Relasi perawatan bangsal memakai `KelolaRelasiPerawatan` (2.6), tidak membuat jalur baru.
Di luar: rawat gabung ibu-bayi dan titip kelas (FR-08-06 bagian lain), asesmen awal RI (FR-08-07, data induk skor belum ada), eMAR.
Asumsi BE #202: DPJP awal = dokter pemberi SPRI/disposisi (`diminta_oleh`), disimpan di `kunjungan.pegawai_id`, dan mendapat
relasi perawatan `dpjp_rawat_inap` tanpa akhir (`berlaku_sampai` kosong hanya untuk peran itu) sampai pulang. Kunjungan RI
(kolom baru `kunjungan.unit_id` = unit bangsal TT) langsung `dilayani`, nomor `RI-YYYYMMDD-NNNN`, cara bayar dan penjamin
disalin dari kunjungan asal. Awal rawat inap = `menunggu_sejak` permintaan; ditempatkan sebelum tanggal rencana → jam
penempatan. Episode: episode aktif rawat jalan harian/IGD > 6 jam dengan kunjungan bertanggal awal RI digabung; kunjungan
IGD asal selalu ikut berapa pun tanggalnya (IGD lewat tengah malam, 7.00.3 "episode rawat inap sejak IGD"). Disposisi IGD
rawat inap menggantikan permintaan aktif lama pasien (batal "Digantikan permintaan IGD", TT dilepas); SPRI poli saat ada
permintaan aktif → 409. Perintah `menunggu_kamar` (hanya ditambah) selesai lewat status permintaan (`ditempatkan`/`batal`);
serah terima saat tiba disimpan di penempatan. Pindah ruang hanya memeriksa cakupan unit bangsal asal (DPJP mengikuti
pasien) dan tidak mengubah relasi perawatan; perintahnya berstatus baru `dilaksanakan`. Batal permintaan tanpa TT = izin di
cakupan mana pun, dengan TT = cakupan unit bangsal TT. Pemeriksa penempatan mengabaikan tanggal (penempatan belum punya
rencana selesai); `tutup()` tetap menolak TT terisi/dipesan. `hari_rawat` = hari ini − tanggal penempatan pertama + 1.
Menyusul (dari tinjauan #202):
- `KelolaKunjungan::episodeHarian` hanya mencari episode jenis harian: pasien rawat inap yang didaftarkan ke poli hari yang
  sama (konsul, 7.00.3 "Rawat inap → Poli lain") mendapat episode RJ baru, bukan episode RI. Untuk tiket konsul/rawat bersama.
- `BentukEpisodeRawatInap` memanggil `audit->catat()` di tengah transaksi penempatan (pola sama `UbahStatusTempatTidur`,
  diterima).
Keputusan pemilik 10 Nov 2026: pindah ruang dibuat di 2.29; peta TT membawa `kunjungan_rawat_inap_id` (kontrak v0.38.0,
field opsional `TempatTidurPeta`) hanya untuk TT berpenempatan aktif yang tidak ditutup dan hanya bagi pemegang
`rawat_inap.pindah_ruang.input` di cakupan yang memuat unit bangsal TT; tanpa izin kunci tidak dikirim (bukan null).
Keputusan pemilik 11 Nov 2026: peta TT juga membawa `pasien_rawat_inap` {nama, no_rm, tanggal_lahir} dengan syarat sama
persis (dikirim bersama id kunjungan, satu cabang kondisi; tidak ke log/audit; kontrak tetap v0.38.0 karena belum rilis).
Dialog pindah ruang menampilkan nama, No. RM, dan tanggal lahir sebelum konfirmasi (PRD 5.1 butir 9); kartu peta tetap tanpa
nama pasien. Asumsi FE #203: izin pemicu kedua field = kode bawaan `rawat_inap.pindah_ruang.input`, bukan izin sel matriks
rawat_inap → rawat_inap yang bisa diubah RS; bila RS mengganti izin sel, POST pindah ruang tetap diputus server. Kedua field juga mensyaratkan lapis 5 izin itu (SIP/SPK
berlaku, jalur `AccessEvaluator::periksa` yang sama dengan POST); gagal lapis 5 → kunci tidak dikirim, peta tetap 200. Pindah ruang 409/404/422 memuat ulang peta dulu; `status_tidak_sesuai`
dan 404 menutup dialog. Tempatkan tampil bagi `rawat_inap.penempatan.input` pada permintaan `dialokasikan`; Batalkan bagi
`pendaftaran.admisi.input` (izin rute kontrak) pada `menunggu`/`dialokasikan`. 409 permintaan basi
(`permintaan_kamar_tidak_dialokasikan`, `permintaan_kamar_tidak_aktif`, 404) menutup dialog dan memuat ulang antrean; 409
lain (`pasien_sudah_ditempatkan`, TT tidak lagi dipesan/ditutup) tampil di dialog sesudah antrean dimuat ulang. Belum ada
TT berstatus melewati rencana lama rawat sampai 2.40; tampilannya diuji unit. E2E: batal lalu SPRI kedua dari kunjungan
poli yang sama, alokasi, tempatkan, lalu `dokter.poli` memindahkan pasien ke CTHG-2-202-4 dari peta; `bersihkanPesertaSintetis` mengakhiri penempatan aktif pasien contoh lewat DB (hanya
lokal/CI) agar spec bisa diulang.

### 2.30 · Lab: daftar kerja dan spesimen
Tujuan : petugas lab menerima order dari semua pos di satu daftar kerja, cito di atas, lalu mencatat pengambilan dan penerimaan sampel.
FR     : FR-19-01, FR-19-02 (order → diterima lab), FR-19-03; 7.00.5 butir 4
Tabel  : `permintaan.permintaan_item` (2.16), `lab.spesimen` (11.2)
API    : `GET /api/v1/lab/daftar-kerja`, `POST /api/v1/permintaan-item/{id}/spesimen`, `POST /api/v1/spesimen/{id}/terima`
Uji    :
- Daftar kerja memuat order lab berstatus aktif dari semua pos, cito di urutan atas, dengan asal order dan lokasi pasien saat ini.
- Barcode spesimen unik; ambil dan terima mencatat jam server dan petugas; order batal (2.16) tidak bisa diambil sampelnya (409).
- Order yang sudah diterima lab tidak bisa dibatalkan pemesan (menutup syarat 2.16 "batal hanya sebelum diterima").
- Daftar kerja lewat `Paginasi::respons`, `throttle:api-layar-kerja`.
Di luar: antarmuka alat (FR-19-04, [Perlu pemilik]), cetak label fisik, FHIR Specimen (Tahap D).

### 2.31 · Lab: hasil, nilai rujukan data induk, validasi, hasil mengikuti pasien
Tujuan : hasil lab diinput, ditandai rendah/tinggi/kritis menurut nilai rujukan RS, divalidasi, lalu sampai ke pemesan dan DPJP saat ini.
FR     : FR-19-02 (diperiksa → divalidasi), FR-19-05, FR-19-06, FR-00-09; 7.00.5 butir 2–3
Tabel  : `lab.hasil` (11.2), data induk nilai rujukan per pemeriksaan × umur × JK (isi dari RS; e2e sintetis)
API    : `POST /api/v1/permintaan-item/{id}/hasil`, `POST /api/v1/hasil-lab/{id}/validasi`, `GET /api/v1/episode/{id}/hasil-lab`
Uji    :
- Penanda l/h/ll/hh/kritis dihitung server dari data induk aktif; tanpa nilai rujukan → hasil tanpa penanda + peringatan, bukan nilai karangan.
- Validasi analis wajib; pemeriksaan bertanda "butuh otorisasi Sp.PK" di data induk wajib otorisasi kedua (FR-19-05).
- Hasil tervalidasi muncul di kotak kerja pemesan **dan** DPJP/ruang pasien saat itu, termasuk bila pasien pindah sesudah order (FR-00-09).
- Hasil tervalidasi tidak bisa diubah; koreksi = adendum bertanda; tampil di linimasa episode.
Di luar: mikrobiologi (FR-19-09), QC (FR-19-10), rujukan lab luar (FR-19-11).

### 2.32 · Lab: pelaporan nilai kritis
Tujuan : nilai kritis dilaporkan ke penanggung jawab saat itu dengan baca ulang dan jam lapor, lalu masuk CPPT.
FR     : FR-19-07
Tabel  : `lab.laporan_kritis` (11.2)
API    : `POST /api/v1/hasil-lab/{id}/laporan-kritis`
Uji    :
- Hasil bertanda kritis tidak dianggap selesai sebelum laporan dicatat (penerima, jam lapor, baca ulang ya/tidak).
- Laporan meneruskan tugas ke DPJP dengan tenggat dari data induk, dan membuat entri CPPT otomatis berlabel sumber lab.
- Selang validasi → lapor tersimpan untuk indikator (FR-19-07); belum dilapor melewati tenggat → eskalasi 7.53.
Di luar: dasbor indikator (Fase 4).

### 2.33 · Radiologi: worklist, skrining, pelaksanaan
Tujuan : radiografer bekerja dari satu worklist order semua pos, dengan skrining keamanan yang lengkap sebelum pemeriksaan.
FR     : FR-20-06, FR-20-07, FR-20-08; 7.00.5 butir 4
Tabel  : `radiologi.pemeriksaan` (11.2), daftar skrining per modalitas = data induk (FR-20-07, isi dari Instalasi Radiologi RS)
API    : `GET /api/v1/radiologi/worklist`, `POST /api/v1/permintaan-item/{id}/pemeriksaan-radiologi`, `POST /api/v1/pemeriksaan-radiologi/{id}/selesai`
Uji    :
- Worklist memuat asal order, prioritas (cito/rutin/terjadwal), dan lokasi pelaksanaan (instalasi/portabel/OK).
- Skrining wajib per modalitas dari data induk; fungsi ginjal terbaru diambil dari hasil lab tervalidasi (2.31), bukan diketik; alergi dari
  `klinis.alergi`.
- Pemeriksaan portabel tercatat pada kunjungan tempat pasien berada (FR-20-08).
- `no_akses` unik; pemeriksaan selesai mencatat radiografer dan jam server.
Di luar: DICOM MWL dan PACS ([Perlu pemilik]), dosis radiasi (FR-20-05), transport pasien (FR-20-09).

### 2.34 · Radiologi: ekspertise ber-TTE, adendum, temuan kritis
Tujuan : Sp.Rad mengesahkan ekspertise dengan TTE, hasil sampai ke pemesan dan DPJP, dan temuan kritis dilaporkan seperti lab.
FR     : FR-20-03, FR-20-10, FR-20-11, FR-00-09
Tabel  : `radiologi.ekspertise` (11.2), objek TTE `ekspertise` (modul Ttd)
API    : `POST /api/v1/pemeriksaan-radiologi/{id}/ekspertise`, `POST /api/v1/ekspertise/{id}/sahkan`, `POST /api/v1/ekspertise/{id}/temuan-kritis`
Uji    :
- Sahkan hanya oleh Sp.Rad pemilik akun dengan PIN T1 (`TtdModal`); sesudah sah tidak bisa diubah, koreksi lewat adendum.
- Ekspertise sah muncul di kotak kerja pemesan dan DPJP/ruang pasien saat itu (FR-00-09); ekspertise > 24 jam belum sah ditandai (FR-20-03).
- Temuan kritis: lapor, baca ulang, jam lapor, masuk CPPT, eskalasi bila tidak dikonfirmasi (pola 2.32).
Di luar: viewer PACS (`url_pacs` kosong sampai adaptor), ImagingStudy SATUSEHAT (Tahap D).

### 2.35 · Farmasi: telaah resep dan kembalikan ke dokter
Tujuan : apoteker menelaah resep di layar dan mengembalikannya ke dokter lewat sistem bila ada masalah.
FR     : FR-22-02 (telaah), FR-22-03, FR-22-04
Tabel  : `farmasi.telaah_resep` (11.2; daftar periksa administratif/farmasetik/klinis sebagai data induk)
API    : `POST /api/v1/resep/{id}/telaah`, `POST /api/v1/resep/{id}/kembalikan`
Uji    :
- Daftar periksa telaah dari data induk; hasil `setuju` memindahkan resep ke tahap disiapkan, `kembali` mengembalikan ke dokter dengan catatan.
- Resep kembali muncul di kotak kerja dokter penulis; dokter membatalkan dan menulis resep baru (resep sah tidak bisa diubah, 2.17).
- Peringatan resep 2.17 (alergi/duplikasi/non-fornas) tampil di layar telaah; interaksi dan dosis ginjal tidak dibuat (data induk belum ada).
- Telaah hanya oleh akun apoteker berizin depo resep itu; waktu tunggu dihitung dari `masuk_pada` (FR-22-01).
Di luar: PTO/visite apoteker (FR-22-11).

### 2.36 · Farmasi: stok depo per batch
Tujuan : depo punya saldo stok per batch dan kedaluwarsa sehingga penyerahan bisa mengurangi stok yang benar.
FR     : FR-22-13 (bagian stok dan mutasi), FR-22-14 (penanda ≤ 90 hari)
Tabel  : `logistik.stok_batch`, `logistik.mutasi_stok` (11.2); gudang/depo dari data induk lokasi
API    : `POST /api/v1/stok/saldo-awal` (impor CSV, pola 2.11), `POST /api/v1/stok/mutasi`, `GET /api/v1/stok?gudang_id=`
Uji    :
- Saldo awal per batch lewat impor bertanggal; mutasi gudang → depo mengurangi asal dan menambah tujuan dalam satu transaksi.
- Stok tidak bisa minus; mutasi melebihi saldo → 422.
- Batch kedaluwarsa ≤ 90 hari ditandai (FR-22-14); stok di bawah minimum data induk → notifikasi (FR-22-13).
- Mutasi hanya ditambah (tanpa hapus); pembatalan = mutasi balik bertanda.
Di luar: pengadaan dan penerimaan pemasok (7.42), stock opname (FR-22-15), narkotika (FR-22-12).

### 2.37 · Farmasi: penyiapan, penyerahan, PIO, kurangi stok
Tujuan : obat disiapkan dari batch yang ada, diserahkan dengan informasi obat, dan stok depo berkurang otomatis.
FR     : FR-22-02 (disiapkan → siap → diserahkan), FR-22-18, FR-22-01 (waktu tunggu)
Tabel  : `farmasi.penyerahan` (11.2), `logistik.mutasi_stok` jenis `pakai`
API    : `POST /api/v1/resep/{id}/siapkan`, `POST /api/v1/resep/{id}/serahkan`
Uji    :
- Penyiapan memilih batch (bawaan kedaluwarsa terdekat); stok kurang → 409 dengan rincian item.
- Penyerahan memerlukan cek dua penanda identitas pasien (PRD 5.1 butir 9) dan PIO dicentang; jam server; antrean `F-` selesai (2.17).
- Kejadian `ObatDiserahkan` terbit untuk tagihan dan SATUSEHAT (Tahap D); waktu tunggu nonracikan/racikan dicatat (FR-22-01).
Di luar: label/etiket cetak, UDD rawat inap (FR-22-10).

### 2.38 · Farmasi: racikan dengan komponen dan BUD
FR     : FR-22-09; `farmasi.racikan` / `racikan_komponen` (11.2)
Uji    : dokter menulis racikan (puyer/kapsul/salep) dengan komponen dan jumlah; BUD dari data induk bentuk sediaan; penyiapan racikan
         mengurangi stok tiap komponen; waktu tunggu racikan dipisah (FR-22-01). Di luar: produksi steril (FR-22-21).

### 2.39 · Farmasi: obat kronis JKN 7 + 23 hari
FR     : FR-22-08; `farmasi.resep_item` (`porsi_paket`, `porsi_kronis`), `farmasi.formularium_item.kronis` (2.11)
Uji    : obat kronis untuk pasien JKN dipisah 7 + 23 hari sejak disiapkan; riwayat 90 hari tampil di telaah; pengambilan sebelum jatah habis
         ditandai dan butuh alasan. Di luar: klaim Apotek Online (FR-22-20, [Perlu pemilik]).

### 2.40 · Pemulangan: rencana pulang, resume medis ber-TTE, daftar periksa
Tujuan : pasien rawat inap pulang dengan resume yang disahkan DPJP dan daftar periksa yang lengkap.
FR     : FR-05-01, FR-05-02, FR-05-03, FR-05-04, FR-05-05
Tabel  : `kunjungan.rencana_pulang`, `klinis.resume_medis` (11.2)
API    : `PUT /api/v1/kunjungan/{id}/rencana-pulang`, `POST /api/v1/kunjungan/{id}/resume-medis`, `POST /api/v1/resume-medis/{id}/sahkan`
Uji    :
- Rencana pulang hari ini tampil sejak pagi di peta TT (status "rencana pulang", 2.27).
- Resume disusun dari CPPT, diagnosis, dan resep episode; DPJP mengesahkan dengan PIN T1; belum sah > 2 × 24 jam → tugas dan eskalasi.
- Pulang lewat matriks 2.26: menutup penempatan, TT "dibersihkan" otomatis (FR-05-05), episode rawat inap selesai.
- Daftar periksa pulang (obat pulang, surat kontrol, tagihan/jaminan) dari data induk; item tagihan menunggu kejadian kasir (Tahap D).
Di luar: kirim resume ke pasien (FR-05-08, kanal menunggu portal 7.48), surat keterangan (FR-05-06), Composition SATUSEHAT (Tahap D).
Catatan wajib dari 2.29: pemulangan memanggil `KelolaRelasiPerawatan::akhiri('kunjungan', <kunjungan RI>)` (relasi
dpjp_rawat_inap tanpa akhir) dan menutup penempatan `pulang` (TT → dibersihkan).
Keputusan pemilik 13 Nov 2026: kontrak v0.39.0 (GET|PUT rencana-pulang, POST pulang, GET|POST resume-medis, POST
resume-medis/{id}/sahkan, data induk daftar-periksa-pulang); tujuan keluar pulang/aps/rujuk_keluar/meninggal lewat sel
matriks baru (izin `rawat_inap.pemulangan.input`, klinis); butir wajib belum selesai boleh dengan alasan per butir; peta TT
membuka id kunjungan + identitas juga bagi `rawat_inap.pemulangan.lihat`; status TT rencana pulang/melewati dihitung saat
peta dibaca; rencana pulang non-klinis; resume = potret sumber yang ada + isian DPJP, disahkan DPJP dengan PIN T1.
Asumsi BE #204: (1) `pemulangan.input` = izin sel matriks (IZIN_KLINIS, syarat matriks `izin_bukan_klinis`) sekaligus izin
membuat rencana pulang yang non-klinis: rencana memakai `AccessEvaluator::periksa(..., tanpaSyaratKlinis: true)`, jadi
syarat SIP/SPK dilewati khusus di situ, lapis 1–3 dan MFA tetap. (2) Peta: tiap izin pembuka (pindah ruang, lihat
pemulangan) lewat `periksa()` miliknya sendiri; `pemulangan.lihat` non-klinis, jadi perawat tanpa SIP menerima kunci, dan
MFA berlaku bila RS menandai izin itu `butuh_mfa`. (3) Pulang tanpa rencana: rencana dibuat otomatis (tanggal = hari
pulang) dengan butir data induk aktif, aturan alasan sama. (4) Kunjungan lain dalam episode (poli asal SPRI) tidak ditutup
dan tidak menahan pulang; episode rawat inap selesai. (5) Tugas `RESUME_BELUM_TTE_48J` objek `kunjungan`, `tenggat_dari`
= `dibuat` (tugas dibuat saat pulang, jadi tenggat = jam pulang + `batas_menit` 2880; `objek` di KotakKerja memakai
tanggal objek apa adanya), pengingat −240, teguran 0, eskalasi 1440 (preseden USULAN_DATA_INDUK); pelaksana akun aktif
DPJP (`kunjungan.pegawai_id`), tanpa akun → unit bangsal. (6) Resume: CPPT berlabel sensitivitas tidak dipotret (hanya
dihitung, `cppt_berlabel`); POST susun ulang memotret ulang sumber, isian yang tidak dikirim tetap; sahkan wajib If-Match
`versi` agar yang disahkan = yang dibaca. GET resume belum disusun → `data` null. Siapa pun pemegang `pemulangan.input`
ber-SIP boleh menyusun; hanya DPJP mengesahkan. (7) Item sumber `kejadian` tidak bisa dicentang (422) sampai kasir Tahap D.
(8) Sub-menu `master_data.daftar_periksa_pulang` dan `rawat_inap.pemulangan` BELUM masuk TERSEDIA (menyimpang dari brief):
e2e `fase1.spec.ts` mewajibkan setiap halaman TERSEDIA punya layar dan uji di LAYAR, jadi PR FE 2.40 menambahkannya ke
TERSEDIA lewat migrasi yang memanggil `MenuBawaan::pasang()` bersama halamannya. Peran contoh perawat tanpa
`pemulangan.sahkan`; modul 05 aktif di data lokal. (9) TT contoh `CTHG-2-202-1` jadi `terisi` (spec peta e2e disesuaikan).
Menyusul (2.40): CPPT, diagnosis, dan resep yang ditulis di kunjungan rawat inap (tatanan `ri`) belum punya layar/endpoint,
jadi resume hanya memotret yang ditulis di kunjungan IGD/poli dalam episode; alur B-05/B-06 rinci untuk APS, melarikan diri,
meninggal (FR-05-07); adendum resume sah; tindakan (modul tindakan); kirim resume ke pasien (FR-05-08).

### 2.41 · Konsul / rujukan internal antar-poli
FR     : FR-04-04, FR-07-25; `kunjungan.rujukan` arah `internal` (11.2)
Uji    : dokter poli membuat konsul ke poli lain dengan alasan; hari yang sama → kunjungan baru di episode yang sama (7.00.3 baris
         Poliklinik); konsul tampil di worklist poli tujuan; jawaban konsul masuk CPPT. Di luar: SISRUTE dan VClaim ([Perlu pemilik]).

### 2.42 · Mode display papan IGD (token perangkat)
Tujuan : papan pasien IGD tampil di monitor dinding tanpa sesi petugas, pola display antrean 2.8 (token perangkat). Keputusan
         pemilik 9 Nov 2026: papan 2.25 hanya untuk layar kerja petugas (sesi petugas, batas diam 15 menit berlaku).
FR     : FR-03-03
Uji    :
- Token perangkat hanya berkemampuan `papan_igd`; token display antrean atau anjungan ditolak.
- Identitas yang boleh tampil di layar umum mengikuti keputusan PDP **[Perlu pemilik]**: nama lengkap, inisial, atau No. RM?
- Tanpa keluhan dan data klinis lain selain triase, area, lama di IGD, dan tanda waktu.
- Polling ETag/If-None-Match → 304 bila tidak berubah.
- Token dicabut saat layar terbuka → layar meminta petugas, token dibuang (pola anjungan 2.20).

### Keluar Tahap C (CI, data sintetis)
- e2e: pasien IGD → triase → papan → order lab cito → hasil kritis dilaporkan → SPRI → alokasi TT → penempatan → resep RI ditelaah dan
  diserahkan (stok berkurang) → resume sah → pulang (TT dibersihkan), @1440 + axe.
- Hasil lab yang keluar sesudah pasien pindah ruang sampai ke DPJP baru (uji FR-00-09).

**[Perlu pemilik] dari perencanaan Tahap C**
- Adaptor tanpa dokumen resmi: antarmuka alat lab (HL7 v2/ASTM, FR-19-04), PACS dan DICOM MWL (FR-20-01/02), SISRUTE (FR-04-01/02),
  VClaim surat kontrol dan SPRI (nomor BPJS), Apotek Online (FR-22-20). Pemilik menyediakan dokumen atau contoh permintaan/respons.
- Reverb untuk papan IGD dan peta TT (K1 menyebut Fase 2): dependensi baru; sampai diputuskan, polling ETag.
- Isi data induk klinis dari RS: nilai rujukan dan batas kritis lab, daftar skrining radiologi per modalitas, instrumen triase, area IGD,
  batas lama menunggu kamar, tenggat laporan kritis, daftar periksa telaah dan pulang, BUD racikan.
- Pasien tanpa identitas di IGD (FR-03-01): pakai alur pasien sementara yang ada atau aturan baru (penomoran, penggabungan kemudian)?
- Nama kode jenis episode/kunjungan baru (`igd`, `rawat_inap`, "IGD > 6 jam") belum ada di PRD 11.2; ditetapkan di PR kontrak 2.23 sebagai
  Asumsi kecuali pemilik menetapkan lain.
- Gelang pasien, verifikasi DPJP, dan TBaK (kerangka C) belum dirinci: gelang butuh keputusan cetak/printer (FR-01-27 juga menunggu),
  verifikasi DPJP/TBaK butuh `dpjp_id`/`pemeriksa_id` di kunjungan (pertanyaan Tahap B). Mode farmasi APM menunggu 2.37.
- Instrumen triase berisi nilai klinis tetapi dikelola peran data induk (2.24). Keputusan pemilik 9 Nov 2026: izin ubah instrumen
  triase diatur lewat peran di RS (komite medik/keperawatan); kode tidak diubah.
- Matriks perpindahan (2.26): isi awal hanya baris IGD dan Poliklinik PRD 7.00.3. Baris Pendaftaran, Penunjang, Kamar operasi,
  ICU/HCU, dan Rawat inap diisi oleh tiket modul masing-masing lewat migrasi (Asumsi) atau oleh RS lewat usulan data induk? Sel
  `igd → icu_hcu/poli_lain/penunjang` aktif tetapi perintahnya lewat modul lain (disposisi menolaknya dengan
  `tujuan_bukan_disposisi`). Izin `igd.disposisi.perbaiki` dan `master_data.matriks_perpindahan.input/perbaiki` belum punya
  pemakai.

Utang 2.24: `jamWib`/`waktuTriase` (FE) mengunci zona Asia/Jakarta dan label "WIB"; produk generik (PRD 3.4) perlu zona dari profil
RS (`instalasi.zona_waktu` sudah ada di backend). Instrumen e2e `E2E-TRIASE-*` nonaktif tertinggal di basis data e2e (sengaja; tidak dihapus).

## Kerangka tahap berikutnya (dirinci kemudian)
- **C — Penunjang, IGD, rawat inap**: dirinci di bagian "Tahap C · tiket" di atas (2.23–2.41); sisa: gelang, verifikasi DPJP dan TBaK,
  mode farmasi APM, Reverb.
- **D — Kasir dan pelaporan**: tagihan otomatis per episode, tindakan ICD-9-CM, pemisahan tanggungan, pembayaran, tutup kasir; SATUSEHAT Encounter/Condition/Observation/ServiceRequest/MedicationRequest via outbox; Aplicares dari peta TT.
