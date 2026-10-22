# Backlog Fase 2 (Alur pasien inti) · Tahap A: dari siap melayani sampai pasien masuk antrean poli

> Sumber tiket aktif. Kebutuhan tetap di `docs/prd.md` (baca per bagian). Cara kerja, format tiket, worktree, Korlap,
> antislop, dan laporan 5 baris: skill `kerja-tiket`. Status diubah lewat PR yang menyelesaikan tiketnya.
> Tahap B–D sengaja hanya kerangka; dirinci perencana setelah Tahap A selesai, supaya tiket tidak basi.

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
| 2.5 | Cari pasien satu kolom + pasien baru | BE+FE | M | 2.3 · **Selesai** (BE PR #127, FE PR #128 dengan data tiruan v0.19.0; integrasi FE menyusul) |
| 2.6 | Kunjungan poli + episode + relasi perawatan | BE+FE | M | 2.4, 2.5 · **BE selesai** (PR #129: kunjungan, episode, relasi perawatan lapis 4, batal otomatis; merge setelah pemilik memeriksa lapis 4); FE belum |
| 2.7 | Kepesertaan, rujukan, dan SEP (simulator VClaim) | BE+FE | M | 2.6 |
| 2.8 | Antrean loket dan poli + display | BE+FE | M | 2.6 |
| 2.9 | Duplikat pasien + usulan gabung RM | BE+FE | M | 2.5 |

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

### 2.9 · Duplikat pasien + usulan gabung RM
Tujuan : duplikat terdeteksi saat input dan digabung lewat usulan berjenjang yang diverifikasi Rekam Medis.
FR     : FR-01-02
Akses  : `pendaftaran.master_pasien` + aksi baru `usul`, `verifikasi` (verifikator = peran Rekam Medis)
Uji    :
- Pasien baru dengan nama mirip + tgl lahir sama memunculkan peringatan sebelum simpan.
- Gabung memindahkan kunjungan ke RM tujuan; RM lama ditandai "digabung ke …", tidak dihapus; bisa ditelusuri.
- Pengusul ≠ verifikator.
Di luar: pemisahan RM (unmerge) — catat sebagai utang bila tidak muat.

### Keluar Tahap A (di staging, data sintetis)
- e2e: pasien baru BPJS → kunjungan poli → SEP (simulator) → antrean → dipanggil → display.
- Penjaga siap melayani terbukti menolak pendaftaran di instalasi yang belum lengkap.
- Backlog Tahap B ditulis rinci (oleh perencana), PRD diselaraskan sekali.

## Kerangka tahap berikutnya (dirinci kemudian)
- **B — Rawat jalan + RME**: formularium (wizard langkah 7, KFA via simulator), CPPT lintas pos (`CpptLinimasa`), asesmen RJ, order penunjang bersama (7.00.5), e-resep → antrean farmasi, Antrean Online BPJS task ID 1–7, APM, akses darurat (K12).
- **C — Penunjang, IGD, rawat inap**: lab/radiologi + hasil mengikuti pasien, telaah resep & depo, IGD + triase + papan (Reverb), rujukan, admisi RI dari peta TT, SPRI, pemulangan, gelang.
- **D — Kasir dan pelaporan**: tagihan otomatis per episode, pemisahan tanggungan, pembayaran, tutup kasir; SATUSEHAT Encounter/Condition/Observation via outbox; Aplicares dari peta TT.
