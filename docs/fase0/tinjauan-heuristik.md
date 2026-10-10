# Tinjauan heuristik prototipe — Fase 0 (mode produk)

Tanggal: 10 Oktober 2026 · Bahan: `docs/html/` (20 halaman, data fiktif) · Rujukan: PRD Lampiran E.1 dan 5.1 butir 9–17.

**Metode.** 10 heuristik Nielsen, keselamatan pasien, dan WCAG 2.1 AA. Setiap halaman dirender di 1440×900 dan 390×844, lalu alur utamanya dijalankan dengan skrip. Keparahan: 4 = keselamatan pasien atau kebocoran data, 3 = mayor, 2 = minor, 1 = kosmetik.

**Batas.** Ini tinjauan ahli, bukan uji pengguna. Hasilnya tidak menggantikan uji SUS (PRD E.2). Temuan ditulis apa adanya sesuai pengamatan saat tinjauan dilakukan.

**Status tindak lanjut (setelah tinjauan).**

| Temuan | Status |
|---|---|
| Keparahan 4: RJ-1, IGD-1, IGD-2, RI-1, OK-1, OK-2, F1, F2, PN-1 | Diperbaiki di prototipe dan diuji ulang dengan skrip |
| Lintas halaman: modal tidak menutup dengan Esc, fokus keluar modal | Diperbaiki di komponen bersama (`common.js`) |
| Lintas halaman: tombol *Spek dev* menutupi tombol aksi | Dipindah ke tepi kiri layar |
| Lintas halaman: TTE atas nama orang lain (termasuk ekspertise radiologi di penunjang) | Diperbaiki di `TtdModal`: penanda tangan harus sama dengan akun yang login; selain itu menjadi *minta tanda tangan* |
| Keparahan 3 lainnya | Belum diperbaiki di prototipe; menjadi syarat terima tiket modul terkait |
| Ketidakcocokan data fiktif antarhalaman | Tidak ditindaklanjuti (hanya prototipe) |

---

# Tinjauan Heuristik A: Login, Home, Pendaftaran, Anjungan, Display Antrean

Metode: Playwright Chromium, 1440x900 dan 390x844 (ditambah 1920x1080 untuk display). Alur utama diklik lewat skrip. Screenshot ada di `/tmp/fase0/shots/`. Font Google diblokir proxy sehingga render memakai font cadangan; ini tidak memengaruhi temuan. Panel "Spek dev" tidak dinilai, kecuali kalau menghalangi.
Skala keparahan: 4 = katastrofik/keselamatan pasien, 3 = mayor, 2 = minor, 1 = kosmetik.

## login.html

| ID | Heuristik | Temuan | Bukti | Keparahan | Saran |
|---|---|---|---|---|---|
| L1 | Pencegahan kesalahan / privasi | Ada opsi "Ingat perangkat ini 12 jam" di halaman yang akan dipakai di komputer bangsal/poli bersama. Pengguna berikutnya bisa masuk dengan sesi orang lain. | `#remember`, login_d.png | 2 | Sembunyikan opsi ini untuk perangkat bersama (diatur per perangkat). Tambahkan kunci layar cepat atau ganti pengguna. |
| L2 | Keyboard / fokus (WCAG 2.4.3) | Setelah kredensial dikirim, fokus jatuh ke tombol "Kembali", bukan ke digit OTP pertama. Penyebabnya, `show()` memilih elemen `input, button` pertama menurut urutan DOM. | Hasil skrip: `focus otp: <button class="back" ...>` | 2 | Fokuskan ke `.otp input:first-child`. |
| L3 | Kecocokan sistem–dunia nyata | Pesan informasi ("Atur ulang kata sandi dilakukan oleh Helpdesk TI…", "SSO belum dikonfigurasi…") memakai kotak bahaya merah `#err` berikon galat. Pengguna mengira dirinya salah. | login_forgot.png | 2 | Pakai varian alert info (biru/netral) untuk pesan non-galat. |
| L4 | Visibilitas status | Langkah "Pilih unit kerja" tidak menampilkan siapa yang sedang masuk (di langkah OTP ditampilkan) dan tidak punya tombol kembali/ganti akun. | login_unit.png | 1 | Ulangi kartu identitas (nama/NIP) dan sediakan "Bukan Anda?". |
| L5 | Status hanya warna | Legenda ilustrasi peta TT hanya warna. "Dibersihkan" dan "Kosong" (abu tua vs abu) hampir tak bisa dibedakan. Panelnya `aria-hidden`, jadi dampaknya kecil. | login_d.png, panel kanan | 1 | Kalau pola ini dipakai ulang di modul nyata, tambahkan pola/ikon. |

Sudah baik: pesan gagal menyebut sisa percobaan dan aturan penguncian 5x/15 menit. Ada peringatan Caps Lock dan label/`autocomplete` yang benar. Hasil audit kontras teks: tidak ada pelanggaran.

## home.html

| ID | Heuristik | Temuan | Bukti | Keparahan | Saran |
|---|---|---|---|---|---|
| H1 | Konsistensi / keselamatan | Peta tempat tidur dan tabel "Pasien ruangan" bertentangan untuk TT yang sama. PD-306A di peta "4" hari terisi, di tabel "0 · Masuk 10.45 · Dipesan". PD-312A di peta "3" hijau, di tabel "6 / 5 · Lewat rencana". PD-304B di peta "2", di tabel "4 / 5". Ringkasan menyebut "1 dibersihkan", padahal peta menampilkan 2 tile arsir. | home_beds.png, home_table.png, DOM `#side .bed[title]` | 3 | Dua widget harus membaca satu sumber. Tambahkan uji konsistensi data peragaan. Kalau di produksi juga dua sumber, ini risiko keputusan bed/pulang yang salah. |
| H2 | Keselamatan pasien / prioritas | Di peran DPJP, "Hasil kritis: kalium 2,6 mmol/L" (Tn. Ahmad Fauzi) diberi label "Pengingat" dengan ikon jam yang sama seperti tugas supervisi, dan ada di urutan terakhir dari 5 tugas. | home_role_DPJP.png, `#tasks` | 3 | Buat tingkat "Kritis" tersendiri (ikon + teks + posisi teratas). Sertakan tindak lanjut yang tercatat (bukan hanya "sudah dibaca"). |
| H3 | Identifikasi pasien (SKP 1) | Daftar pasien dan tugas hanya memakai nama + TT, tanpa No. RM/tanggal lahir. Penulisan nama campur: nama lengkap ("Tn. Ahmad Fauzi") dan inisial ("Tn. M.R.", "Ny. S.H.", "Tn. K.L.") di layar staf. | home_table.png; tugas "HCU-2 · Tn. Rudi Hartono" | 3 | Tampilkan minimal 2 identitas (nama lengkap + RM/tgl lahir) di setiap baris pasien. Inisial cukup untuk layar publik, bukan untuk staf. |
| H4 | Visibilitas | Pada 1440 px, kolom "Status" terpotong ("Rencar…", "Lewat r…", "Dipesa…"). Tabel `826px` di dalam `.tbl-wrap` `713px` dengan `overflow:auto` tanpa tanda bisa digulir. | home_table.png, hasil skrip overflow | 2 | Kurangi kolom/lebar, bungkus teks status, atau beri bayangan tepi penanda gulir. |
| H5 | Status hanya warna / aksesibilitas | Tile peta TT hanya berwarna dengan angka hari rawat. Nomor bed hanya ada di `title` (div tidak fokusabel, tidak terbaca keyboard/sentuh). Status "dipesan" disebut di ringkasan tetapi tidak ada di legenda. | home_beds.png, `div.bed[title]` | 2 | Tulis kode bed di tile, buat tile sebagai tombol dengan `aria-label`, dan lengkapi legenda + pola untuk setiap status. |
| H6 | Konsistensi waktu | Header "Sabtu, 10 Oktober 2026", sapaan "Selamat malam" (18.46), tetapi "Dinas hari ini: Jumat, shift pagi 07.00–14.00" dan KPI "Tenaga shift pagi". Panel DPJP "Poliklinik sore 13.00–16.00" masih tampil aktif. | home_d_full.png, home_role_DPJP.png | 2 | Turunkan shift/hari dari jam berjalan. Jangan isi teks tetap. |
| H7 | Umpan balik | Pencarian global "Ahmad" + Enter tidak memberi hasil atau pesan apa pun. Tombol lonceng dan unit hanya memunculkan toast. | home_search.png, home_bell.png | 2 | Minimal tampilkan dropdown hasil atau "tidak ditemukan". Lonceng sebaiknya membuka panel. |
| H8 | Pencegahan kesalahan | Tombol utama "Sahkan" (resume 2 pasien) dan "Konfirmasi" (instruksi lisan "Furosemid 40 mg IV") ada langsung di daftar. Labelnya terbaca sebagai aksi final, padahal yang terjadi membuka layar (toast "membuka layar 'Sahkan'"). | home_dpjp_sahkan.png, home_dpjp_konfirmasi.png | 2 | Ganti label menjadi "Tinjau & sahkan". Konfirmasi instruksi lisan wajib melewati ringkasan baca-ulang (obat, dosis, rute, pasien). |

Sudah baik: Kotak kerja punya tenggat dan tingkat (Pengingat/Teguran/Eskalasi) berupa teks + warna. Isi beranda menyesuaikan peran, dan ada keterangan pembatasan angka rupiah untuk Kepala Unit.

## pendaftaran.html

| ID | Heuristik | Temuan | Bukti | Keparahan | Saran |
|---|---|---|---|---|---|
| P1 | Pencegahan kesalahan | Setelah SEP terbit, "Poliklinik" masih bisa diganti ke Jantung dan dokter dr. Fikri yang kuotanya "terisi 25 (penuh)". "Kirim ke poliklinik" tetap aktif tanpa peringatan. SEP tetap "Terbit" untuk rujukan INT, dan nomor antrean tetap PD-023. | reg_after_sep_poli.png; skrip: `poli disabled? False … send dis: False` | 3 | Kunci field kunjungan setelah SEP terbit. Perubahan harus lewat alur "ubah/batalkan SEP". Blokir dokter berkuota penuh. |
| P2 | Pencegahan kesalahan | Mengganti "Penjamin" ke Umum setelah SEP terbit menyembunyikan kartu SEP, dan pengiriman tetap diizinkan ("Data lengkap. Pasien siap dikirim."). SEP jadi yatim dan berisiko tagihan ganda. | reg_after_sep_umum.png | 3 | Tolak perubahan penjamin selama ada SEP aktif, atau minta pembatalan SEP dengan alasan. |
| P3 | Identifikasi pasien | Saat menekan "Terbitkan SEP" dan "Kirim ke poliklinik", nama/RM pasien tidak terlihat di viewport. Identitas hanya ada di kartu atas. Pasien bisa berganti hanya dengan klik baris antrean. | reg_sep.png, reg_after_sep_poli.png | 3 | Tampilkan banner pasien yang tetap terlihat (nama, RM, tgl lahir). Ulangi nama di dialog konfirmasi SEP/kirim. |
| P4 | Identifikasi / pencarian | Mode "Nama + tgl lahir" menerima "siti" tanpa tanggal lahir, dan hasilnya memuat "Ny. Sri Wahyuni" yang tidak cocok. Instruksi "Pilih dengan mencocokkan tanggal lahir dan nama ibu" tidak didukung: nama ibu tidak tampil di hasil maupun di form pasien baru. | reg_nama_siti.png, reg_newpatient.png | 3 | Wajibkan tanggal lahir pada mode ini. Tampilkan nama ibu/alamat di hasil dan sediakan field nama ibu kandung. |
| P5 | Pesan galat / pencegahan RM ganda | Input "12345" pada mode kartu BPJS (13 digit) menghasilkan "Tidak ditemukan… daftarkan sebagai pasien baru". Tidak ada validasi panjang. Pesan ini mendorong pembuatan RM baru. | reg_invalid.png | 3 | Validasi format per mode ("Nomor kartu harus 13 digit"). Arahkan ke "pasien baru" hanya setelah pencarian valid. |
| P6 | Pesan galat / form | Form "Pasien baru": saat simpan kosong, galat hanya berupa border merah + toast di area gelap di belakang drawer. Tidak ada teks di field, "Jenis kelamin" tidak diberi border, tidak ada penanda wajib, dan Escape tidak menutup drawer. Tanggal lahir native tampil "mm/dd/yyyy" (tergantung locale), berbeda dari format dd-mm-yyyy di layar lain. | reg_newpatient_submit.png; skrip: `after esc drawer visible? True` | 2 | Pesan inline per field dengan `aria-describedby`, ringkasan galat di dalam drawer, tanda wajib (*), Escape untuk menutup, dan input tanggal dd-mm-yyyy. |
| P7 | Aksesibilitas (WCAG 1.3.1 / 2.1.1) | Label "Nomor SEP", "Tanggal", "Jenis pelayanan", "Kelas hak" tidak terhubung ke input (tanpa `for`). Baris antrean diberi `tabindex=0`, tetapi Enter tidak memilih pasien (klik bisa). | Audit: `UNLABELED sepNo, sepDate, sepJenis, sepKelas`; skrip: `kbd queue pick: —` vs `click queue pick: Tn. B.H.` | 2 | Tambahkan `for`/`id`. Tangani Enter/Space pada baris, atau ubah barisnya menjadi tombol. |
| P8 | Beban kognitif / estetika | Navigasi berlapis: sub-menu sidebar (Pendaftaran pasien, Admisi RI, Antrean…) menduplikasi tab di halaman (Pasien, Admisi RI, Antrean, SEP…), dan ada dua kolom cari (global + halaman). Banyak teks terpotong: placeholder "13 digit nomor kar…", select "Penyakit Dalam (INT", diagnosis "E11.9 · Diabetes melitus…". Tombol "Spek dev" menutupi "Simpan & buat No. RM". | pendaftaran_d_full.png, reg_bpjs.png, reg_newpatient.png | 2 | Pilih salah satu: sub-menu atau tab. Lebarkan field kunci, beri tooltip/teks penuh di bawahnya, dan geser tombol dev. |

Sudah baik: deteksi "Calon ganda" beserta peringatan RM ganda, dan pesan "Nomor rekam medis tidak dibuat ulang". NIK/telepon disamarkan. "Terbitkan SEP" baru aktif setelah persetujuan umum + verifikasi, dan dokter dengan SIP habis dinonaktifkan.

## anjungan.html (termasuk #farmasi)

| ID | Heuristik | Temuan | Bukti | Keparahan | Saran |
|---|---|---|---|---|---|
| A1 | Kecocokan / pencegahan kebuntuan | Kode booking berhuruf (contoh "PD023"), tetapi keypad hanya angka 0–9. Teks bantuan "Huruf diketik lewat pemindai QR atau papan ketik layar" menjanjikan papan ketik layar yang tidak ada. Pasien tanpa QR tidak bisa mengetik kodenya. | kiosk_booking_err.png | 3 | Sediakan papan ketik alfanumerik layar sentuh, atau buat kode booking angka saja. |
| A2 | Pencegahan kesalahan | Di daftar dokter, dr. Larasati dengan jam praktik "08.30–11.30" tetap bisa dipilih dengan "Estimasi dilayani 19.28" (jam 18.53). Pendaftaran sampai selesai dengan SEP dan nomor PD-024. dr. Hana (13.00–16.00) estimasinya 23.03. | kiosk_dokter.png, kiosk_ticket.png | 3 | Nonaktifkan dokter yang praktiknya selesai atau estimasinya melewati jam praktik ("Praktik sudah selesai"). |
| A3 | Efisiensi / aksesibilitas lansia | Tanggal lahir diisi lewat 3 `select` native. Default "1 Januari 2026" dan tahun berisi 100 opsi mulai 2026, jadi pasien lahir 1950-an harus menggulir sekitar 70 opsi di dropdown native yang kecil di layar sentuh. | kiosk_dob_err.png | 2 | Pakai keypad DDMMYYYY yang sudah ada, atau pemilih tahun berbentuk grid/dekade. |
| A4 | Privasi / konsistensi | Mode farmasi: pindai QR langsung menampilkan nama tersamar dan nama poli ("Poli Mata", "Poli Bedah") tanpa konfirmasi tanggal lahir. Ini berbeda dari alur pendaftaran (ada konfirmasi tgl lahir) dan dari prinsip nama netral "Klinik 7" di layar jadwal. | kiosk_f_QR-SW.png, kiosk_f_QR-RH.png, kiosk_jadwal.png | 2 | Samarkan nama poli di kiosk publik atau minta konfirmasi tgl lahir sebelum detail tampil. |
| A5 | Konsistensi aturan antrean | Semua pengecualian (pasien tidak terdaftar, rujukan habis, biometrik gagal) mendapat "nomor loket prioritas" P-0xx. Nomor ini masuk ke Loket 1 yang khusus lansia/disabilitas/ibu hamil, sehingga antrean prioritas yang sebenarnya ikut tertahan. | kiosk_unknown.png, kiosk_unknown_ticket.png, `ex()` | 2 | Pisahkan seri "dialihkan" (misalnya B-) dari prioritas klinis/sosial. |
| A6 | Keterbacaan kiosk | Teks bantuan dan galat 15 px berwarna abu `#565C63` (`#bkHelp`, `#nikHelp`, footer). Pesan galat merah kecil di bawah input besar mudah terlewat dari jarak lengan. | kiosk_booking_err.png; ukuran font hasil skrip | 2 | Galat kiosk minimal 20 px, dengan ikon dan blok berlatar, dekat tombol aksi. |
| A7 | Visibilitas status | Alur reservasi (kode → tgl lahir → check-in) menampilkan "Langkah 2 dari 4", padahal hanya 2–3 layar. | kiosk_dob.png | 1 | Hitung langkah per alur. |

Sudah baik: semua tombol utama ≥44 px (tile, keypad sekitar 132x72, Kembali/Lanjut sekitar 180x68). Yang <44 px hanya chip peragaan dan tombol mode (31–33 px). Nama disamarkan ("Sri W•••••••"), ada ringkasan "Periksa sekali lagi" sebelum "Daftar", sesi ditutup otomatis disertai hitung mundur, dan struk tidak memuat nama.

## displayantrean.html (#poli, #farmasi, #loket)

| ID | Heuristik | Temuan | Bukti | Keparahan | Saran |
|---|---|---|---|---|---|
| D1 | Status hanya warna | Chip hijau "PD-019" (pasien kembali membawa hasil, disisipkan setelah PD-024) dan badge "R" (racikan) hanya dijelaskan di ticker berjalan dan di atribut `title` yang tak terlihat di TV. Bagi pasien, urutannya tampak meloncat. | display_d.png, display_farmasi_d.png, `TICK` | 2 | Tambahkan legenda tetap di layar, atau ikon + teks pendek ("Hasil", "Racikan"). |
| D2 | Visibilitas status / pemulihan | Hanya satu nomor "Sedang dipanggil". Tidak ada riwayat panggilan atau daftar "terlewat", padahal loket punya tombol "Lewati". Di farmasi, F-036 diam di "Siap diambil" tanpa keterangan sudah dipanggil kapan. Suara default "mati". | display_d.png, display_farmasi_d.png, `#sound` | 2 | Tampilkan 3–5 panggilan terakhir dan daftar terlewat. Pastikan suara aktif secara default di perangkat produksi. |
| D3 | Kecocokan bahasa | Pil "Dokter"/"Perawat" di setiap kartu ruang tidak dijelaskan (tahap layanan?). Pasien bisa salah paham soal siapa yang memanggil. "K7-004 —" menampilkan tanda pisah tanpa arti. | display_d.png | 1 | Ganti dengan "Diperiksa dokter"/"Pemeriksaan awal perawat". Kosongkan, jangan tampilkan "—". |
| D4 | Privasi | Nama lengkap tidak tampil (hanya inisial), dan itu sesuai. Namun inisial + spesialisasi + nama dokter tampil bersamaan, dan inisial berulang di layar berbeda (N.H. di poli dan farmasi). Panggilan suara hanya menyebut nomor (baik). | display_d.png, display_farmasi_d.png | 1 | Pertimbangkan nama netral untuk spesialisasi sensitif lain (pola "Klinik 7" sudah ada). |
| D5 | Estetika | Teks ticker terpotong oleh area merek "SIMRS Karsitara" ("Nama pasie…"). | disp_foot.png | 1 | Beri batas/gradasi di tepi ticker, atau pindahkan logo. |
| D6 | Responsif | Pada 390 px nomor besar pecah dua baris "PD- / 021". | display_poli_m.png | 1 | `white-space:nowrap` + skala font. Kalau display hanya untuk TV, abaikan. |

Sudah baik: kontras teks lolos audit, dan nomor besar bermonospace terbaca jelas di 1920x1080 (disp_1920.png). Loket tidak menampilkan inisial, dan panggilan suara memakai ejaan per digit.

## Lintas halaman

| ID | Heuristik | Temuan | Bukti | Keparahan | Saran |
|---|---|---|---|---|---|
| X1 | Konsistensi / risiko salah baca | Prefiks "PD-" dipakai untuk kode tempat tidur (PD-304B), nomor antrean poli (PD-023), dan nomor pasien poliklinik di beranda DPJP (PD-031). Di tabel DPJP kolom Ruang terpotong menjadi "PD-/304B". | home_role_DPJP.png, pendaftaran_d_full.png | 2 | Bedakan skema: bed memakai kode ruang/kamar (mis. "PD 304-B"), antrean memakai prefiks lain. Kode jangan dibungkus ke baris baru. |
| X2 | Konsistensi | Verifikasi peserta di loket memakai "Verifikasi wajah", sedangkan di anjungan "sidik jari". | reg_bpjs.png, kiosk_sum.png | 2 | Seragamkan istilah dan metode, atau jelaskan kapan masing-masing dipakai. |
| X3 | Konsistensi | Nama RS: "RS Pendidikan Prof. dr. Chairuddin P. Lubis USU" (login/home) vs "RS Universitas Sumatera Utara" (anjungan/struk). | login_d.png, kiosk_ticket.png | 1 | Satu nama resmi + singkatan baku. |
| X4 | Konsistensi data | Anjungan menerbitkan PD-024, padahal PD-024 sudah ada di antrean pendaftaran ("Belum check-in") dan di display Ruang 3 (B.S.). | kiosk_ticket.png, pendaftaran_d_full.png, display_d.png | 1 | Pakai satu sumber nomor di seluruh peragaan. |

## Ringkasan jumlah temuan

| Keparahan | Login | Home | Pendaftaran | Anjungan | Display | Lintas | Total |
|---|---|---|---|---|---|---|---|
| 4 | 0 | 0 | 0 | 0 | 0 | 0 | **0** |
| 3 | 0 | 3 | 5 | 2 | 0 | 0 | **10** |
| 2 | 3 | 5 | 3 | 4 | 2 | 2 | **19** |
| 1 | 2 | 0 | 0 | 1 | 4 | 2 | **9** |
| Total | 5 | 8 | 8 | 7 | 6 | 4 | **38** |

Tidak ada temuan keparahan 4 yang teramati langsung. Yang paling dekat dengan isu keselamatan pasien adalah H2 (nilai kritis setingkat pengingat), H3/P3/P4 (identifikasi pasien), dan P1/P2 (data kunjungan masih bisa diubah setelah SEP terbit).

---

# Tinjauan Heuristik B — Rawat Jalan, IGD, Rawat Inap, Kamar Operasi, Rekam Medis

Metode: Playwright/Chromium, 1440x900 dan 390x844, semua tab diklik, alur utama dijalankan (TTE PIN, resep, triase, drawer IGD, eMAR, ceklis OK, laporan operasi, buka RM, pelepasan informasi). Screenshot ada di `/tmp/fase0/shotsB/`. Pemindaian kontras otomatis atas semua teks di semua tab kelima halaman: **tidak ada teks di bawah 4,5:1** (3:1 untuk teks besar). Yang gagal adalah kontras non-teks dan penggunaan warna sebagai satu-satunya penanda (lihat temuan).

Keparahan: 4 = katastrofik/keselamatan pasien, 3 = mayor, 2 = minor, 1 = kosmetik.

## rawatjalan.html

| ID | Heuristik | Temuan | Bukti | Keparahan | Saran |
|---|---|---|---|---|---|
| RJ-1 | Keselamatan pasien; H1 Visibilitas status | Dialog TTE tidak menampilkan identitas pasien. Kotak "Dokumen" hanya berisi teks "Sahkan". Saat dialog muncul, banner pasien sudah tergulung keluar layar, jadi dokter menandatangani tanpa melihat nama atau No RM pasien. | `rj_pin_modal.png`; teks modal `#tteModal`: "Dokumen / Sahkan / Saya, dr. Larasati, Sp.PD, menyatakan…" | 4 | Tampilkan di dialog: nama lengkap, No RM, tanggal lahir, alergi, dan ringkasan isi (jumlah diagnosis, obat, order). Nama dokumen harus jelas, misalnya "Pemeriksaan RJ 10-10-2026". |
| RJ-2 | Keselamatan pasien (identifikasi) | Banner pasien tidak memuat tanggal lahir, hanya "58 tahun". Banner juga tidak sticky, jadi hilang saat menggulir ke bagian resep atau pengesahan. Kata "lahir" tidak ada di seluruh halaman. | `rj_scrolled_sahkan.png`, `rj_pin_modal.png`; CSS hanya `.rme-left,.rme-right{position:sticky}`; grep "lahir" = 0 | 3 | Buat banner identitas sticky berisi nama lengkap, No RM, tanggal lahir (dd-mm-yyyy), jenis kelamin, alergi, dan penjamin. |
| RJ-3 | H1; Estetika/tata letak | Kolom kiri yang sticky (Ringkasan klinis, Catatan peserta didik) menimpa panel "Pemeriksaan otomatis". Panel itu justru berisi alasan hard-stop alergi dan duplikasi, sehingga teksnya terpotong ("…Hapus atau ganti obat."). | `rj_resep_after.png`; `.rme-left{position:sticky;top:76px}` | 3 | Batasi tinggi kolom sticky atau matikan sticky saat kolom lebih tinggi dari viewport. Tampilkan alasan blokir langsung di bawah tombol "Sahkan dan kirim". |
| RJ-4 | H4 Konsistensi; keselamatan | Data pasien yang sama berbeda antar tab. HbA1c tertulis 7,6% (Juli 2026) di Ringkasan, tetapi 9,1% (hari ini 17.54) di CPPT. TD 142/86 vs 148/92, BB 72 vs 71, kreatinin 0,9 vs 1,0. Perawat tertulis Ns. Rina 09.12 vs Ns. Fitri 17.36. Keluhan "dua minggu" vs "2 bulan". | `rawatjalan_tab_periksa.png` vs `rawatjalan_tab_cppt.png` | 3 | Ambil data dari satu sumber (observasi/hasil lab terakhir) dan tampilkan waktu pengambilan. Untuk prototipe, samakan data fiktifnya supaya pengujian dengan pengguna tidak salah arah. |
| RJ-5 | Keselamatan pasien (identifikasi, alergi) | Nama pasien disingkat ("Ny. H.Y.", "Tn. W.S.", "Ny. K.R.") di worklist dan di banner pemeriksaan, padahal pasien pertama tampil dengan nama lengkap. Banner pasien kedua menulis "Tidak ada penanda keselamatan". Status alergi tidak disebut, jadi tidak jelas apakah "tidak ada alergi" atau "belum dikaji". | `rj_periksa_pasien2.png` | 3 | Di layar klinis, selalu tampilkan nama lengkap. Status alergi wajib eksplisit: "Alergi: tidak ada (dikaji …)" atau "Alergi: belum dikaji". |
| RJ-6 | H9 Pesan error; H1 | Obat alergen di tabel resep hanya ditandai latar merah muda pada barisnya, tanpa ikon atau teks. Peringatan lain hanya toast yang hilang dalam 2,6 detik. Saat simvastatin ditambahkan (duplikasi statin), toast yang muncul tetap "Peringatan: pasien alergi amoksisilin." dan duplikasinya tidak disebut. | `rj_resep_after.png` (baris Amoksisilin); toast yang tercatat | 2 | Tambahkan label di baris, misalnya "⚠ Alergi: amoksisilin (ruam)" dan "Duplikasi kelas: statin". Pesan harus spesifik per masalah dan menetap sampai diselesaikan. |
| RJ-7 | Nilai kritis/abnormal | Nilai di luar target tidak ditandai: TD 142/86 (pasien 1), TD 150/90 dan eGFR 38 (pasien 2), HbA1c 9,1% "(target < 7%)". Semuanya tampil sebagai teks biasa. | `rawatjalan_tab_periksa.png`, `rj_periksa_pasien2.png`, `rawatjalan_tab_cppt.png` | 2 | Beri penanda tinggi/rendah berupa ikon plus teks ("↑ tinggi"), bukan warna saja, berdasarkan rentang dari master data. |
| RJ-8 | Aksesibilitas (WCAG 2.1.2, 2.4.3) | Modal TTE tidak menutup dengan Esc dan fokus tidak terkurung. Tab berpindah ke "Spek dev", lalu BODY, lalu "Beranda" di sidebar di belakang modal. | Uji Playwright: setelah Escape `#tteModal` masih `.show`; urutan Tab: Digit 1–6, Batal, Tanda tangani, `#dsBtn`, BODY, Beranda | 2 | Kurung fokus di dalam modal, buat Esc = Batal, dan kembalikan fokus ke tombol pemicu. |

Sudah baik: hard-stop berfungsi. "Sahkan dan kirim" nonaktif dengan pesan "Belum dapat disahkan: alergi, duplikasi.". Setelah disahkan, field SOAP menjadi `disabled` dan perubahan hanya lewat "Tambah adendum". PIN salah memberi umpan balik "PIN salah. Sisa 4 percobaan.".

## igd.html

| ID | Heuristik | Temuan | Bukti | Keparahan | Saran |
|---|---|---|---|---|---|
| IGD-1 | Keselamatan pasien (alergi); H2 Kesesuaian | Drawer pasien menampilkan chip merah "Alergi" untuk pasien yang datanya "Alergi: tidak ada". Penyebabnya, string flag `'bad:Alergi: tidak ada'` dipecah dengan `split(':')` sehingga hanya kata "Alergi" yang tampil. Maknanya jadi terbalik. | `igd_drawer_mati.png` (Tn. H.S.: "Triase Merah · Alergi"); kode `sel.flags.map(f=>{const [k,v]=f.split(':')…})` | 4 | Simpan alergi sebagai field terstruktur (status + daftar zat), jangan string berpemisah ":". Tampilkan "Alergi: tidak ada" dengan gaya netral. |
| IGD-2 | H3 Kontrol & kebebasan; H5 Pencegahan error | Disposisi "Meninggal", "Pulang", atau "Pulang atas permintaan sendiri" lalu "Simpan" langsung mengeluarkan pasien dari papan. Tidak ada konfirmasi, TTE, atau cara membatalkan. Untuk APS, formulir penolakan tertulis "wajib" tetapi tidak dipaksakan. | Uji: baris papan 10 menjadi 9; toast "Tn. H.S.: Meninggal. Lama di IGD 0 j 49 m tercatat." | 4 | Minta konfirmasi berisi identitas pasien dan waktu kematian/pulang, wajib TTE dokter, wajib formulir APS, dan sediakan pembatalan dalam jendela waktu dengan jejak audit. |
| IGD-3 | Keselamatan pasien (identifikasi) | Papan dan drawer hanya menampilkan inisial + umur ("Tn. H.S., 67 th", "Tn. J.S., 60 th", "Tn. S.B., 58 th"). No RM, tanggal lahir, dan status alergi tidak ada. Pasien tanpa flag tidak punya informasi alergi sama sekali. | `igd_tab_papan.png`, `igd_drawer_mati.png`; `flags:[]` pada beberapa pasien | 3 | Tampilkan nama lengkap dan No RM (atau nomor registrasi sementara untuk pasien tanpa identitas) di papan dan drawer. Tampilkan alergi eksplisit di drawer. |
| IGD-4 | H5 Pencegahan error; H9; WCAG 3.3.1 | Form triase menerima nilai mustahil tanpa validasi: TD sistolik 1200, SpO₂ 150, GCS 20, suhu "abc". Data itu tersimpan ("Uji Dua masuk papan IGD · triase Kuning") dan saran sistem menjadi "Kuning · TD 1200/?". Error hanya muncul sebagai toast, tanpa penanda di field (`aria-invalid` = 0). | `igd_triase_invalid.png`; toast yang tercatat | 3 | Validasi rentang fisiologis per field dengan pesan inline. Tandai field yang salah (`aria-invalid` + teks) dan arahkan fokus ke field pertama yang salah. |
| IGD-5 | Responsif; warna sebagai satu-satunya penanda (WCAG 1.4.1) | Pada lebar 390 px, kolom Triase, Tiba, Tanggap, dan Status tergeser keluar layar. Kategori triase hanya tersirat dari garis warna di kiri baris. | `igd_m_papan.png` | 3 | Di layar sempit gunakan tata letak kartu, dengan teks kategori triase di samping nama pasien. |
| IGD-6 | H4 Konsistensi; risiko salah pasien | Panel Eskalasi menulis "Tn. M.R. menunggu Penyakit Dalam · 3 j 10 m", padahal di tabel durasi menunggu 3 j 10 m adalah milik Tn. S.B. (ICU). CPPT Ny. L.H. menulis "IGD Observasi 3 08.02", sedangkan di papan ia tiba pukul 16.49. | `igd_tab_papan.png`, `igd_cppt_tulis.png` | 2 | Turunkan eskalasi dan linimasa dari data episode yang sama. |
| IGD-7 | WCAG 1.4.1; H5 | KPI "Triase hari ini 21 ●3 ●9 ●9 ●0" hanya memakai titik warna tanpa label. "Mode korban massal" aktif dengan satu klik tanpa konfirmasi, padahal langsung memberi tahu tim siaga. | `igd_tab_papan.png`; toast "Mode korban massal diaktifkan. Tim siaga diberi tahu." | 2 | Tambahkan label teks (M/K/H/Hitam). Minta konfirmasi sebelum mengaktifkan mode korban massal dan tampilkan cara menonaktifkannya. |
| IGD-8 | Estetika; aksesibilitas (WCAG 2.4.1) | Tombol "Spek dev" menutupi tombol "Simpan" di drawer (klik Playwright terhalang `#dsBtn`). Halaman tidak punya skip link: perlu 22 kali Tab melewati sidebar sebelum sampai ke konten. | Log Playwright "dsBtn intercepts pointer events"; urutan Tab `igd2.py` | 2 | Pindahkan "Spek dev" ke tempat yang tidak menutupi aksi utama. Tambahkan tautan "Lewati ke konten". |

Sudah baik: triase memberi saran sistem dan mewajibkan alasan bila kategori diubah ("Lengkapi: keluhan utama, alasan perubahan kategori."). Kategori triase selalu disertai teks (Merah/Kuning/Hijau), bukan warna saja. Tanda vital abnormal di drawer diberi penanda. Baris papan fokusable (`tabIndex 0`) dan Enter membuka drawer.

## rawatinap.html

| ID | Heuristik | Temuan | Bukti | Keparahan | Saran |
|---|---|---|---|---|---|
| RI-1 | Keselamatan pasien (identifikasi) | Satu bangsal berisi banyak pasien dengan inisial identik, dan daftar, eMAR, serta dropdown pemilih pasien tidak memuat No RM. Contoh: "Ny. S.M." (PD-305B, PD-310A, PD-315B), "Tn. J.P." (PD-311B, PD-313A), "Ny. D.P." (PD-303B, PD-307A), "Ny. W.T.", "Ny. T.A.", "Tn. D.K.". Pembeda satu-satunya adalah nomor tempat tidur, yang bukan identitas sah. | `rawatinap_tab_pasien.png`; opsi dropdown EWS "PD-310A · Ny. S.M.", "PD-315B · Ny. S.M." | 4 | Tampilkan nama lengkap, No RM, dan tanggal lahir di setiap daftar dan pemilih pasien. Beri peringatan "nama mirip" untuk pasien dengan nama/inisial serupa di satu ruang. |
| RI-2 | Keselamatan pasien (identitas, alergi) | Header eMAR dan EWS hanya berisi "Ny. Mariani · PD-312A", termasuk saat memberikan heparin. Banner CPPT Tn. Ahmad Fauzi tidak memuat status alergi sama sekali (chip: Kalium 2,6 kritis, Risiko jatuh sedang, EWS 3, DPJP). | `ri_emar_heparin.png`, `ri_cppt_from_list.png`, `rawatinap_tab_ews.png` | 3 | Pakai banner identitas standar (nama, No RM, tanggal lahir, alergi, BB) di semua tab, terutama eMAR. |
| RI-3 | Keselamatan obat (high-alert) | Cek ganda heparin cukup dengan memilih nama perawat kedua dari dropdown (`#dbl`: Ns. Rina/Fadli/Putri), padahal teks di layar menyatakan "Perawat kedua memverifikasi dengan akunnya sendiri". Pindai gelang dan label berhasil hanya dengan klik, dan tidak ada skenario ketidakcocokan. | `ri_emar_scanned.png`; `<select id="dbl">` | 3 | Wajibkan autentikasi perawat kedua (PIN/badge) di perangkat. Buat juga prototipe status "gelang tidak cocok" dan "obat tidak cocok" sebagai layar blokir. |
| RI-4 | H3, H5 (aksi tak dapat dibatalkan) | "Tunda" dan "Pasien menolak" langsung tercatat dengan satu klik, tanpa konfirmasi dan tanpa input alasan. Alasannya diisi otomatis "Ditunda: pasien puasa pemeriksaan". | Toast "Penolakan pasien tercatat. DPJP menerima pemberitahuan."; kode `#marHold`/`#marRefuse` | 3 | Minta alasan (pilihan + teks bebas) dan konfirmasi, lalu sediakan "Batalkan" beberapa detik dengan jejak audit. |
| RI-5 | WCAG 1.4.1, 1.4.11 | Status tempat tidur di peta hanya dibedakan warna pastel. Latar "terisi" rgb(232,244,236) di atas putih kontrasnya sekitar 1,1:1. Nama aksesibel petak hanya angka hari rawat (mis. "4"), dan `title` "PD-307A · Terisi · Ny. D.P." tidak menyebut status "Lewat rencana". | `rawatinap_tab_peta.png`; atribut `.bd.on.over` | 3 | Tambahkan pola atau ikon per status (mis. "!" untuk lewat rencana, "↗" untuk rencana pulang) dan border berkontras ≥3:1. Isi `aria-label` lengkap. |
| RI-6 | H4 Konsistensi; nilai peringatan | EWS Tn. Ahmad Fauzi berbeda-beda: form menghitung NEWS2 = 4, batang terbaru di Tren 24 jam = 3, daftar pasien dan banner CPPT = "EWS 3". Badge EWS 1–4 tampil abu-abu netral yang sama, jadi EWS 4 (Ny. Mariani) tidak menonjol dibanding EWS 1. | `rawatinap_tab_ews.png`, `rawatinap_tab_pasien.png` | 2 | Satu sumber skor dengan waktu observasi. Warna badge bertingkat sesuai ambang respons NEWS2 (0, 1–4, 5–6, ≥7, atau 3 pada satu parameter), disertai teks risiko. |
| RI-7 | Responsif | Pada lebar 390 px, kolom DPJP, EWS, Risiko jatuh, dan Penanda berada di luar layar. "Kalium 2,6 kritis" tidak terlihat. | `rawatinap_m_pasien.png` | 2 | Gunakan tata letak kartu di layar sempit dan letakkan penanda kritis di samping nama. |
| RI-8 | H4 Konsistensi lintas modul | Di tab Pemulangan, resume Tn. K.L. berstatus "Resume medis disahkan DPJP" dan pasien belum dipulangkan. Di Rekam Medis > Kelengkapan, pasien yang sama tertulis "Pulang Hari ini 10.20" dengan "Resume medis · Belum lengkap · TTD DPJP". | `rawatinap_tab_pulang.png` vs `rekammedis_tab_lengkap.png` | 2 | Samakan status dokumen dari satu sumber. |

Sudah baik: "Pulangkan pasien" nonaktif sampai semua butir ceklis selesai. Heparin ditolak tanpa cek ganda ("Obat kewaspadaan tinggi wajib cek ganda perawat kedua."). Nilai kritis kalium dan instruksi lisan (TBaK) diberi label teks yang jelas beserta tenggat konfirmasi.

## kamaroperasi.html

| ID | Heuristik | Temuan | Bukti | Keparahan | Saran |
|---|---|---|---|---|---|
| OK-1 | Keselamatan/legal (TTE); H2 | Yang login adalah Ns. Arif (Koordinator kamar operasi), tetapi dialog "Sahkan laporan operasi" berbunyi "Saya, dr. Taufik, Sp.B, menyatakan isi di atas benar…" dan bisa disahkan dengan PIN. Pengguna menandatangani atas nama orang lain. | `ok_laporan_sign.png`; header "Ns. Arif · Koordinator kamar operasi" | 4 | TTE hanya untuk pemilik akun. Bila pengguna bukan operator, ganti tombol dengan "Kirim ke operator untuk disahkan". |
| OK-2 | Keselamatan pasien (WHO Surgical Safety) | Ceklis Sign in/Time out/Sign out tidak memuat identitas lengkap. Header hanya "Hernioplasti inguinal kanan · Tn. A.R., 47 th", tanpa No RM, tanggal lahir, alergi, atau penandaan sisi. Pemilih kasus hanya berisi "OK 3 · 07.00 · Seksio sesarea elektif" tanpa nama pasien, padahal ada 3 entri SC. Butir "Alergi diketahui" dicentang tanpa menampilkan alerginya. | `kamaroperasi_tab_ceklis.png`; daftar opsi dropdown ceklis | 4 | Tampilkan banner identitas lengkap plus alergi, sisi operasi, dan status persetujuan. Cantumkan nama dan No RM di opsi pemilih kasus. Tampilkan data alergi di samping butir "Alergi diketahui". |
| OK-3 | H1 Visibilitas status | Ada dua "waktu sekarang" yang bertentangan: garis waktu "Posisi peragaan pukul 10.30 WIB" dan jam di footer "18.47 WIB". Status "Berlangsung/Terjadwal" bisa salah dibaca. | `kamaroperasi_tab_jadwal.png` | 2 | Pakai satu jam acuan. Bila sedang mode peragaan, tampilkan banner yang jelas. |
| OK-4 | H6 Rekognisi; beban kognitif | Label blok jadwal terpotong: "Debridem…", "Seksio se…", "Ny. M.S. · d…", "Cito · Apende…". Di layar mobile, lini masa terpotong setelah 09.00. | `kamaroperasi_tab_jadwal.png`, `kamaroperasi_m_jadwal.png` | 2 | Tampilkan tooltip/fokus dengan teks lengkap. Di layar sempit, ganti dengan daftar per kamar. |
| OK-5 | H5; affordance | "Konfirmasi sign out" tampil sebagai tombol primer aktif meski baru 0/5 butir tercentang, lalu ditolak lewat toast. Begitu juga "Selesai operasi" ("Lengkapi sign out sebelum operasi dinyatakan selesai."). | `ok_signout_empty.png` | 2 | Nonaktifkan tombol dengan keterangan sisa butir ("3 butir lagi"), konsisten dengan tombol "Pulangkan pasien" di rawat inap. |
| OK-6 | Estetika | Tombol "Spek dev" menutupi sebagian tombol "Sahkan laporan operasi" dan kartu waktu "Keluar". | `kamaroperasi_tab_laporan.png`, `kamaroperasi_tab_jadwal.png` | 2 | Pindahkan atau buat "Spek dev" bisa diciutkan. |
| OK-7 | Aksesibilitas | Modal TTE memakai komponen yang sama dengan rawat jalan: Esc tidak menutup dan fokus tidak terkurung. | Perilaku `#tteModal` sama dengan RJ-8 | 2 | Perbaiki di komponen bersama (`ds-js`). |

Sudah baik: urutan ceklis terkunci dan operasi tidak bisa dinyatakan selesai sebelum sign out. Operasi cito ditandai teks "Cito ·" dan border merah, operasi ditunda dicoret dengan arsir dan alasannya dicatat, jadi tidak bergantung pada warna saja. Jam antibiotik profilaksis dihitung otomatis ("45 menit sebelum insisi · sesuai").

## rekammedis.html

| ID | Heuristik | Temuan | Bukti | Keparahan | Saran |
|---|---|---|---|---|---|
| RM-1 | H4 Konsistensi; makna warna | "Alergi: tidak ada" tampil dengan pill merah (`bad`), sama dengan gaya "Alergi: amoksisilin". Di IGD CPPT, status yang sama tampil kuning (`warn`). Merah kehilangan makna bahaya. | `rm_opened.png`; data `flags:[['bad','Alergi: tidak ada'],…]`, IGD `['warn','Alergi: tidak ada']` | 3 | Buat satu komponen status alergi untuk semua modul: merah hanya bila ada alergi, netral untuk "tidak ada", kuning untuk "belum dikaji". |
| RM-2 | Privasi (Permenkes 24/2022 Ps. 26) | Tahap "Verifikasi identitas pemohon" untuk permintaan dari asuransi selesai hanya dengan satu klik "Lanjut ke tahap 3", tanpa isian atau unggahan bukti identitas atau surat kuasa. | Toast "Tahap 2 selesai." | 3 | Wajibkan isian jenis dan nomor identitas, lampiran surat kuasa/perjanjian kerja sama, dan nama verifikator sebelum tahap bisa ditutup. |
| RM-3 | Keselamatan (identifikasi) | Banner rekam medis yang dibuka tidak memuat tanggal lahir ("Tn. Ahmad Fauzi RM 00-31-77-58 · BPJS kelas 2"), padahal tabel pencarian di atasnya memuatnya. | `rm_opened.png` | 2 | Tambahkan tanggal lahir dan jenis kelamin di banner. |
| RM-4 | Tata letak | Tabel pencarian terpotong di 1440 px: "Dirawat sejak 7 Okt", "Rawat jalan 2 Okt 20…". | `rekammedis_tab_cari.png` | 2 | Kurangi lebar kolom atau bungkus teks. Jangan andalkan gulir horizontal di desktop. |
| RM-5 | H5; affordance | Setelah diblokir, "Lanjut ke tahap 4" tetap tampil sebagai tombol primer aktif. Tiga kali klik menghasilkan toast yang sama. | Toast "Belum ada persetujuan pasien atau dasar hukum." (berulang) | 2 | Nonaktifkan tombol dan jadikan "Minta persetujuan pasien" sebagai aksi utama. |
| RM-6 | H8 Estetika minimalis | Anotasi internal bocor ke UI di kartu "Aturan retensi": "[High confidence]", "[I]", "[Medium]". | `rekammedis_tab_arsip.png` | 2 | Hapus anotasi atau pindahkan ke panel Spek dev. |
| RM-7 | H1; konsistensi waktu | Tenggat kelengkapan tidak sesuai jam 18.47 tanggal 10 Okt: "Ny. M.S. · Kemarin 14.05 · sisa 3 jam" (seharusnya sudah lewat), "Tn. B.K. · 8 Okt 16.40 · lewat 22 jam" (seharusnya sekitar 26 jam lewat). | `rekammedis_tab_lengkap.png` | 2 | Hitung tenggat dari jam yang sama dengan header/footer. |
| RM-8 | Aksesibilitas; H4 | Modal "Tujuan membuka rekam medis" tidak menutup dengan Esc. Nama tenaga kesehatan tidak konsisten antar modul ("Ns. Dewi Anggraini" vs "Ns. Dewi", "dr. Rizal Hasibuan, Sp.PD" vs "dr. Rizal, Sp.PD"). | Uji Escape: `whyModal` tetap terbuka; `rm_opened.png` vs `rawatinap_*.png` | 2 | Tambahkan Esc dan perangkap fokus. Pakai format nama dari master SDM secara seragam. |

Sudah baik: membuka rekam medis wajib menyebut tujuan ("Pilih tujuan membuka RM.") dan langsung masuk jejak akses. Pasien berprivasi tinggi mendapat peringatan bahwa pembukaan dilaporkan ke PMIK/DPO. Pelepasan ke pihak ketiga diblokir tanpa persetujuan, disertai dasar hukum. Dokumen berlabel psikiatri hanya terlihat keberadaannya, bukan isinya.

## Catatan lintas halaman
- Tanggal lahir tidak muncul di empat halaman klinis (grep "lahir" = 0 di rawatjalan, igd, rawatinap, kamaroperasi). Tidak ada banner identitas sticky yang seragam.
- Komponen status alergi tidak konsisten (merah, kuning, tidak ada sama sekali, bahkan berkebalikan di IGD-1).
- Modal TTE dan modal lain tidak menutup dengan Esc dan tidak mengurung fokus. Drawer IGD sudah menutup dengan Esc. Tidak ada skip link di semua halaman.
- Kontras teks lolos AA di semua tab (pemindaian otomatis). Masalah kontras ada pada elemen non-teks (petak TT) dan status yang hanya ditandai warna.
- Tombol "Spek dev" beberapa kali menutupi aksi utama (Simpan di drawer IGD, Sahkan laporan operasi).

## Ringkasan jumlah temuan

| Halaman | Kep. 4 | Kep. 3 | Kep. 2 | Kep. 1 | Total |
|---|---|---|---|---|---|
| rawatjalan | 1 | 4 | 3 | 0 | 8 |
| igd | 2 | 3 | 3 | 0 | 8 |
| rawatinap | 1 | 4 | 3 | 0 | 8 |
| kamaroperasi | 2 | 0 | 5 | 0 | 7 |
| rekammedis | 0 | 2 | 6 | 0 | 8 |
| **Total** | **6** | **13** | **20** | **0** | **39** |

---

# Tinjauan Heuristik C — Farmasi, Penunjang, Kasir, Casemix & Klaim, Integrasi

Metode: render Playwright (chromium) 1440x900 dan 390x844, screenshot di `/tmp/fase0/shotsC/`, alur utama diklik lewat skrip (`/tmp/fase0/scriptsC/flow_*.py`), cek kontras/label otomatis (`audit_*.json`). Data halaman fiktif; panel "Spek dev" diabaikan kecuali menutupi UI. Tanggal sistem saat uji: Sabtu 10 Oktober 2026, ±18.50 WIB.
Keparahan: 4 = katastrofik/keselamatan pasien, 3 = mayor, 2 = minor, 1 = kosmetik.

## farmasi.html

| ID | Heuristik | Temuan | Bukti | Keparahan | Saran |
|---|---|---|---|---|---|
| F1 | Keselamatan pasien; Pencegahan kesalahan (N5) | Satu klik "Centang semua sesuai" mencentang ke-9 butir telaah, termasuk butir yang sistem tandai "Perlu perhatian". Setelah itu "Setujui" lolos. Pada R-0919 (An. F.Z., 16 kg, parasetamol 150 mg ≈ 9 mg/kg, di bawah rentang lazim) resep langsung masuk penyiapan. | `farmasi_R0919_telaah.png`. Alur: `[data-rx=R-0919]` lalu `#allRv` lalu `#okBtn`, toast "Telaah disetujui oleh apt. Siti Rahma. Resep masuk penyiapan.", pill "Disiapkan" | 4 | Hapus "centang semua", atau kecualikan butir berperingatan. Butir "Perlu perhatian" hanya bisa disetujui dengan pilihan eksplisit (kembalikan ke dokter / override + alasan wajib), dan alasannya tercatat. |
| F2 | Keselamatan pasien (identifikasi) | Tahap "Penyerahan dan informasi obat" tidak memverifikasi identitas pasien. Isinya hanya 4 centang PIO dan select "Diterima oleh" (Pasien sendiri/Keluarga). Tidak ada pindai gelang/kartu dan tidak ada pencocokan 2 identitas sebelum "Serahkan obat". | `farmasi_penyerahan.png`, teks `#rvBody` | 4 | Tambahkan langkah wajib: pindai nomor antrean/kartu, lalu konfirmasi nama + tanggal lahir/No. RM (2 identitas). Bila diambil keluarga: nama penerima dan hubungannya. |
| F3 | Keselamatan pasien (high-alert, LASA) | Resep, Stok depo, dan Formularium tidak punya penanda obat high-alert maupun LASA. Insulin glargin 100 IU/mL tampil biasa di stok. KCl disebut "kewaspadaan tinggi" hanya di teks bebas PTO. String "LASA" tidak ditemukan di halaman. | `farmasi_stok_d.png`, `farmasi_klinis_d.png`, grep DOM | 3 | Badge "High-alert" dan "LASA" (tall-man lettering, mis. glimePIRID/glipiZIDE) di semua daftar obat. Double check independen wajib untuk high-alert. |
| F4 | Kecocokan sistem-dunia nyata (N2); Pencegahan kesalahan | Logika FEFO terbalik untuk Metformin. Default batch MTF2608A (ED Mar 2028), padahal MTF2603C (ED Nov 2027) kedaluwarsa lebih dulu. Memilih batch yang benar justru memunculkan "Peringatan FEFO: batch MTF2608A kedaluwarsa lebih dulu.", jadi peringatannya salah. | `farmasi_penyiapan.png`; `select[data-batch=0]` index 1, lalu toast | 3 | Urutkan batch menurut ED naik, pilih otomatis ED terdekat, dan beri peringatan hanya bila petugas memilih batch dengan ED lebih jauh. |
| F5 | Visibilitas status (N1); Keselamatan pasien | Alergi tidak tampil di header resep (`#rSub`: "P · 58 th · RM 00-48-21-07 · BPJS kelas 2 · dr. Larasati… · masuk 18.42"). Alergi amoksisilin hanya muncul sebagai teks kecil butir telaah ("Alergi amoksisilin (ruam): tidak terkait") dan hilang di tahap penyiapan/penyerahan. | `farmasi_d_full.png`, `farmasi_penyiapan.png` | 3 | Banner alergi (merah, ikon + teks) tetap tampil di header resep pada semua tahap, juga di antrean. |
| F6 | Konsistensi (N4); Akuntabilitas | Persetujuan telaah, "Diperiksa ulang apoteker", dan "Catat pengeluaran" narkotika tidak meminta TTE/PIN (`#tteModal` tidak muncul). Saksi narkotika cukup dipilih dari dropdown "Pilih apoteker/TTK kedua" tanpa autentikasi saksi. Pemeriksaan ulang dicentang oleh pengguna yang sama (login apt. Siti Rahma, penyiap "Rina, A.Md.Farm"). | Alur `#okBtn`, `#chk`, `#nkOut`; `farmasi_narko_d.png` | 3 | PIN/TTE T1 untuk telaah dan double check. Saksi narkotika memasukkan PIN sendiri. Tolak bila penyiap = pemeriksa. |
| F7 | Aksesibilitas (WCAG 2.1.1, 2.4.3, 3.3.2) | Baris register narkotika `tr[data-nk]` (tabIndex -1) tidak bisa dipilih dengan keyboard. Modal "Kembalikan ke dokter" tidak memindahkan fokus (activeElement tetap `#backBtn`) dan tidak menutup dengan Esc. 5 select di Farmasi klinis (`#fkPick`, select "Keputusan") dan `#depo` tanpa label terprogram. | Skrip `flow_farmasi.py`, `audit_farmasi.json` | 2 | Jadikan baris tombol/link. Modal: fokus ke elemen pertama, perangkap fokus, Esc = Batal. Tambahkan `aria-label`/`<label for>`. |
| F8 | Estetika & desain minimalis (N8) | Mobile: kolom Jumlah dan "Paket + kronis" tabel resep keluar layar ("2 × 1 sesudah mak…"). Tombol mengambang "Spek dev" bertumpuk dengan tombol aksi kanan bawah saat digulir ("Kembalikan ke dokter"/"Setujui"). | `farmasi_m_part0.png`, `farmasi_R0919_telaah.png` | 2 | Tampilan kartu per obat di mobile (nama, aturan, jumlah). Sembunyikan "Spek dev" di build produksi atau beri jarak aman. |

Sudah baik: telaah dibagi administratif/farmasetik/klinis dengan isian otomatis dan peringatan dosis berbasis BB yang terlihat (kotak kuning). "Kembalikan ke dokter" memakai alasan terstruktur dan sudah terisi saran dosis. Waktu tunggu ditampilkan bersama standarnya (≤ 30/≤ 60 menit).

## penunjang.html

| ID | Heuristik | Temuan | Bukti | Keparahan | Saran |
|---|---|---|---|---|---|
| P1 | Keselamatan pasien (nilai kritis); Pencegahan kesalahan | Modal "Laporkan nilai kritis" hanya berisi select peran ("Perawat penanggung jawab · IGD · Observasi 4"), select cara, dan satu checkbox "Penerima membaca ulang…". Nama penerima dan nilai yang dibacakan ulang tidak dicatat. "Catat laporan" langsung menerbitkan hasil dengan status "Divalidasi" tanpa langkah validasi analis/Sp.PK dan tanpa PIN. | `penunjang_critModal.png`; alur `[data-lact=kritis]` lalu `#crOk`, toast "Nilai kritis dilaporkan dan hasil diterbitkan.", pill "Divalidasi" | 3 | Isian wajib: nama penerima (dari daftar petugas jaga), nilai yang dibacakan ulang, dan jam. Pisahkan "lapor kritis" dari "validasi & terbitkan" (yang butuh PIN validator). |
| P2 | Keselamatan pasien (identifikasi) | Daftar kerja lab, panel hasil, modal kritis, dan bank darah mengidentifikasi pasien hanya dengan inisial + umur ("Tn. J.S., 60 th", "Ny. R.A., 33 th · O Rh+"). Tidak ada No. RM/tanggal lahir, padahal ada dua pasien inisial sama di modul lain (Tn. S.B.). Bank darah menyerahkan kantong PRC dengan identitas ini. | `penunjang_lab_d.png`, `penunjang_bdrs_d.png`, `penunjang_critModal.png` | 3 | Tampilkan minimal 2 identitas (nama, tanggal lahir/No. RM) di setiap baris/panel. Serah darah wajib memindai gelang pasien + kantong. |
| P3 | Konsistensi (N4); Akuntabilitas | "Validasi dan terbitkan" hasil lab tanpa TTE/PIN (toast langsung "Hasil L0419 tervalidasi…"), sedangkan ekspertise radiologi meminta TTE T1. TTE ekspertise juga berbunyi "Saya, dr. Dian, Sp.Rad, menyatakan…" padahal pengguna yang login adalah "Andi, A.Md.AK · Analis laboratorium". | `penunjang_tte.png`; `[data-lact=valid]` (tteModal tidak muncul) | 3 | Validasi lab memakai TTE validator. Penanda tangan selalu pengguna yang login. Tombol pengesahan disembunyikan/dinonaktifkan bila peran tidak berwenang. |
| P4 | Visibilitas status (N1); Desain responsif | Di desktop 1440, pill "Kritis" di tabel hasil panel kanan terpotong tepi panel ("…melalui antarmuka alat, ti…" juga terpotong). Pill "Nilai kritis" baris L0420 menempel tepi. Status radiologi terpotong ("Menungg…", "Ekspertis…"). Di mobile, kolom Prioritas/Tahap/"Nilai kritis" daftar kerja berada di luar layar. | `penunjang_lab_d.png`, `penunjang_rad_vp.png`, `penunjang_m_part0.png` | 3 | Penanda kritis di kolom paling kiri/di samping nilai, jangan di kolom terakhir. Panel lebih lebar atau tabel wrap. Mobile: kartu per order dengan badge kritis di atas. |
| P5 | Keselamatan pasien (kontras) | Panel CT abdomen dengan kontras (RAD-26100234) menampilkan peringatan eGFR 42 tetapi tanpa status alergi kontras dan tanpa persetujuan. "Mulai pemeriksaan" dapat langsung diklik. | `penunjang_rad_ctkontras.png` (teks `#rpBody`) | 3 | Checklist pra-kontras wajib (alergi kontras, eGFR, metformin, persetujuan) sebelum tombol aktif. |
| P6 | Aksesibilitas (WCAG 2.1.1) | Baris lab punya `tabindex=0`, tetapi Enter tidak memilih (fokus L0418 + Enter, panel tetap L0420). Baris radiologi/PA/bank darah tidak bisa difokus. Modal TTE tidak menutup dengan Esc. | Skrip `flow_pj.py` | 2 | Tangani Enter/Space di baris, atau pakai `<button>` di sel pertama. Esc = Batal di semua modal. |
| P7 | Aksesibilitas (WCAG 1.4.1, 1.4.3) | Stok darah memberi status hanya lewat warna: 4 (PRC O) kuning, 3/2 (AB) merah, tanpa teks "di bawah minimum" di sel. Label bar waktu tunggu "9 mnt" putih di hijau muda kontrasnya 2,62:1. | `penunjang_bdrs_d.png`, `audit_penunjang.json` | 2 | Tambahkan teks/ikon ("< min") di sel. Gelapkan warna segmen atau taruh label di luar bar. |
| P8 | Konsistensi data | Ny. Sri Wahyuni: HbA1c lab L0421 7,2% (masih "Sampel diambil"), sedangkan Casemix memakai bukti "HbA1c 7,6%" dan menyebut "Pulang 9 Okt". | `penunjang_lab_d.png`, `casemixdanclaim_koding_d.png` | 2 | Satukan sumber data peragaan agar alur lintas modul bisa diuji. |

Sudah baik: stepper per order dengan jam dan selang tiap tahap, dan median waktu tunggu cito per tahap (9+6+14+5 = 34 mnt, cocok dengan KPI). Nilai kritis memblokir penerbitan sampai dilaporkan. Baris kritis diberi latar merah + teks "Kritis", bukan warna saja.

## kasir.html

| ID | Heuristik | Temuan | Bukti | Keparahan | Saran |
|---|---|---|---|---|---|
| K1 | Pencegahan kesalahan (N5); Kontrol pengguna (N3) | Dengan metode QRIS, status "Menunggu pasien memindai…" bisa langsung dilewati kasir. Klik "Terima pembayaran Rp 636.400" menandai Lunas dan menerbitkan kuitansi tanpa konfirmasi bank dan tanpa dialog konfirmasi. Pembatalan sesudahnya butuh otorisasi Kepala Kasir. | `kasir_paid.png`; toast "Pembayaran Rp 636.400 diterima. Kuitansi elektronik terbit." | 3 | QRIS/VA: tombol aktif hanya setelah callback bank (atau "Cek status"). Tunai/debit: dialog ringkasan (nama pasien, nominal, metode) sebelum mengunci. |
| K2 | Pencegahan kesalahan; Kecocokan dunia nyata | Surat jaminan GL-2609-1187 "Berlaku 29 September sampai 5 Oktober 2026" sudah lewat (hari ini 10 Okt; subjudul "29 September sampai hari ini") tanpa peringatan. Tagihan tetap dikirim ke Asuransi A ("Terkirim 18.50 (otomatis saat tagihan ditutup)"). Akomodasi hanya "3 hari" untuk rawat 29 Sep sampai hari ini. | `kasir_tagihan_d.png`, `kasir_paid.png` | 3 | Validasi masa berlaku jaminan dan beri peringatan/blokir ("Jaminan kedaluwarsa, minta perpanjangan"). Cocokkan hari akomodasi dengan sensus. |
| K3 | Konsistensi (N4); Rupiah benar | Rupiah tidak konsisten antarhalaman untuk pasien yang sama. An. F.Z.: obat racikan Rp 86.000 (kasir) vs Rp 9.350 (farmasi); total kasir Rp 261.000 vs Casemix "dibayar di kasir Rp 186.000". Ny. Sri Wahyuni: obat paket 7 hari Rp 61.600 (kasir) vs ≈ Rp 21.420 dari harga satuan farmasi; total Rp 934.000 vs Casemix Rp 612.000. Diagnosis kasir E11.4 vs Casemix E11.9. | Data `BILLS` kasir, farmasi R-0919/R-0921, Casemix E2/E3 | 2 | Satu sumber data peragaan. Rincian kasir tertaut ke transaksi unit (resep R-xxxx). |
| K4 | Pencegahan kesalahan; Format Indonesia | Isian "Uang diterima" menampilkan angka tanpa pemisah ribuan ("700000"), sedangkan "Kembalian" berformat "Rp 63.600". Rawan salah jumlah nol. | `kasir_tunai.png` | 2 | Format langsung saat mengetik (700.000) dengan prefiks Rp tetap. |
| K5 | Pencegahan kesalahan; Kontrol | Tutup kasir: "Tunai menurut sistem Rp 2.350.000" sudah terlihat sebelum hitung fisik (bukan blind count). "Tutup shift" mengunci penerimaan tanpa dialog/PIN. Isian denominasi berlabel "100 rb" dengan placeholder 0, sehingga tidak jelas diisi jumlah lembar atau nominal. | `kasir_tutup_after.png` | 2 | Sembunyikan nilai sistem sampai hitung selesai. Label "Jumlah lembar Rp 100.000". Konfirmasi + PIN sebelum mengunci. |
| K6 | Kontrol pengguna (N3) | "Ajukan refund" (Ny. Mariam) langsung membuat pengajuan Rp 2.200.000 dan menghapus baris deposit tanpa konfirmasi nominal, rekening tujuan, atau pembatalan. | Alur `[data-refund=2]`, toast "Refund diajukan…"; `kasir_refund.png` | 2 | Dialog refund: nominal (terhitung), rekening/penerima, alasan, lalu konfirmasi. |
| K7 | Aksesibilitas (WCAG 1.3.1, 3.3.2); Responsif | Label "Terminal EDC" dan "Bank"/"Nomor VA" tidak terhubung ke kontrolnya (`<label>` tanpa `for`). `#blSearch` hanya placeholder. Di mobile, kolom Total/Ditanggung/Tanggungan pasien terpotong ke kanan, dan "Spek dev" menutupi "Kirim pengingat". | `audit_kasir.json`, skrip `flow_ks.py`, `kasir_m_part0.png` | 2 | Hubungkan label. Kartu baris tagihan di mobile dengan nominal pasien ditonjolkan. |
| K8 | Estetika (N8) | Kuitansi pasien memuat "Jasa pelayanan: 12 item terhitung untuk tenaga medis terkait", info internal yang tidak relevan bagi pasien. | `kasir_paid.png` | 1 | Pindahkan ke tampilan internal. |

Sudah baik: aritmetika tagihan benar (total 16.668.400 − penjamin 16.032.000 = 636.400; plafon kamar 3 × 500.000 = 1.500.000), dan format "Rp 1.950.000" konsisten di tabel. Tombol bayar dinonaktifkan dengan alasan eksplisit ("Tidak dapat diproses: masih ada item belum lengkap.").

## casemixdanclaim.html

| ID | Heuristik | Temuan | Bukti | Keparahan | Saran |
|---|---|---|---|---|---|
| C1 | Pencegahan kesalahan (N5); Kontrol (N3) | "Kirim ke E-Klaim" mengirim dan memfinalkan semua berkas siap sekaligus. Tidak ada dialog konfirmasi, daftar berkas yang akan dikirim, atau TTE. Toast: "3 berkas terkirim ke E-Klaim dan difinalkan." | Alur `#bkSend`; `casemix_sent.png` | 3 | Dialog ringkasan (jumlah berkas, total tarif, daftar SEP) + konfirmasi/TTE. Beri jendela batal sebelum final. |
| C2 | Konsistensi; Akuntabilitas | "Finalkan koding" langsung mengunci kode dan membuat berkas siap kirim tanpa PIN/TTE koder dan tanpa ringkasan CBG akhir. Tombol pengesahan lain di sistem memakai TTE. | Alur `#kdFin` (tteModal tidak muncul), `casemix_final.png` | 2 | Ringkasan "Kode final + CBG + tarif" dan PIN koder. |
| C3 | Konsistensi data lintas modul | Ny. Sri Wahyuni dikode E11.9 "tanpa komplikasi", sedangkan kasir "E11.4 … komplikasi neurologis". Ny. L.H. berstatus "Umum" di kasir tetapi punya SEP BPJS di Berkas klaim. Tn. R.S., Ny. M.P., dan An. K.L. muncul sebagai berkas September sekaligus "sedang dirawat" di Simulasi. Tn. H.S. "Pulang 9 Okt" di Koding tetapi "ICU · hari ke-4" di Simulasi. | `casemixdanclaim_koding_d.png`, `_berkas_d.png`, `_simulasi_d.png`, `kasir_tagihan_d.png` | 2 | Rapikan data peragaan agar satu pasien punya satu status/penjamin/diagnosis. |
| C4 | Konsistensi; Angka benar | Verifikasi: Layak 5.512 + Pending 214 + Dispute 18 = 5.744, tetapi Rekonsiliasi Piutang menulis "Cocok 5.511 · Pending atau dispute 231". "95,9%" seharusnya 96,0% (5.512/5.744 = 95,96%). Satuan campur: "Nilai Rp 1.860 jt" vs "Rp 1,34 M". | `casemixdanclaim_verifikasi_d.png`, `_piutang_d.png` | 2 | Satu sumber angka. Pembulatan standar. Satuan konsisten (Rp 1,86 M). |
| C5 | Visibilitas status (N1) | "Tenggat pengajuan 10 Okt · Besok", sementara jam halaman 10 Okt dan Piutang "Per 9 Oktober 2026". Status tenggat ambigu justru di hari H. | `casemixdanclaim_berkas_d.png` | 2 | Hitung tenggat relatif dari tanggal server ("Hari ini, 23.59"). |
| C6 | Kecocokan dunia nyata (N2); Aksesibilitas | Sel "✕" di tabel Berkas adalah tombol aksi (title "Peragaan: pemilik melengkapi…") dengan nama aksesibel "✕". Secara visual terbaca sebagai status "tidak ada" atau "hapus". Checkbox diagnosis sekunder berarti "kodekan" tanpa header kolom terlihat; uncheck langsung menghapus kode dan mengubah CBG tanpa jejak. | `button.ck.n`, `casemixdanclaim_berkas_d.png`, `_koding_d.png` | 2 | Status dan aksi dipisah ("Belum ada · Ingatkan pemilik"). Header "Dikodekan". Riwayat perubahan kode. |
| C7 | Estetika (N8) | Tarif tingkat ditulis "4,1 jt / 6,2 jt / 9,8 jt" dan berkas "0,2 jt", terlalu dibulatkan untuk konteks klaim. | `casemixdanclaim_koding_d.png`, `_berkas_d.png` | 1 | Tampilkan rupiah penuh (Rp 198.500) atau minimal 2 desimal. |

Sudah baik: diagnosis sekunder wajib tertaut bukti. "Finalkan koding" diblokir bila ada kode tanpa bukti (toast "Masih ada diagnosis tanpa bukti…"), dan grouper hanya menaikkan tingkat dari kode berbukti (uji: hapus N17.9 menjadi J-4-16-II). Tabel umur piutang aritmetikanya cocok (total 34.878 jt → Rp 34,9 M; > 90 hari 1.342 jt = 3,8%).

## integrasi.html

| ID | Heuristik | Temuan | Bukti | Keparahan | Saran |
|---|---|---|---|---|---|
| I1 | Aksesibilitas (WCAG 1.4.1); Visibilitas | Status layanan bridging hanya titik warna (hijau/oranye) dan angka berwarna ("2,6 dtk" oranye, "3,1%" merah). Task 5 (94%) dan Task 7 (93%) merah tanpa teks ambang; ambang 95% tidak dijelaskan. | `integrasi_bpjs_d.png` | 2 | Teks status ("Melambat") di samping titik, dan keterangan ambang ("< 95%"). |
| I2 | Aksesibilitas (WCAG 1.1.1, 1.4.3) | Matriks Kepatuhan memakai `<i class="kc">` berisi ✓/!/·/… tanpa `aria-label`/`title`. Legenda ikon tampil kecil/terpotong, dan "·" (bukan periode) kontrasnya 1,75:1. | `integrasi_kepatuhan_d.png`, `audit_integrasi.json` | 2 | `aria-label` per sel ("Sep: tepat waktu"), ikon legenda ukuran penuh, kontras ≥ 3:1. |
| I3 | Pesan error (N9); Visibilitas | "Sudah diperbaiki", "Coba ulang", dan "Coba ulang semua" selalu berhasil (toast "… diterima SATUSEHAT"). Tidak ada jalur gagal/pesan error. "Sudah diperbaiki" bisa diklik koordinator meski pemilik datanya unit lain (mis. "Pemilik: Bagian SDM"), tanpa verifikasi. | Kode handler `#rjList`, `#rtTable`, `#rtAll`; `integrasi_satusehat_d.png` | 2 | Tunjukkan hasil kirim ulang per item (berhasil/gagal + alasan). Tombol "Sudah diperbaiki" hanya untuk pemilik data, atau berubah jadi "Kirim ulang". |
| I4 | Visibilitas status (N1) | Tenggat Indikator Nasional Mutu "10 Oktober 2026" dengan label "1 hari lagi", padahal jam halaman 10 Oktober. | `integrasi_laporan_d.png` | 2 | Hitung dari tanggal server ("Hari ini"). |
| I5 | Kecocokan dunia nyata (N2) | Pratinjau Laporan kustom tidak masuk akal: Penyakit dalam JKN 14 vs Umum 1.370, Bedah Umum 19 vs JKN 902 (kolom tampak tertukar). Select Periode terpotong ("Juli–September 202"). | `integrasi_kustom_d.png` | 2 | Perbaiki data contoh. Lebarkan/wrap select. |
| I6 | Konsistensi (N4) | Kolom "Ditolak" memakai "—" untuk nol (MedicationStatement, AllergyIntolerance), padahal kolom lain berisi angka. Ambigu antara 0 dan tidak dikirim. | `integrasi_satusehat_d.png` | 1 | Tulis "0". |

Sudah baik: setiap penolakan SATUSEHAT dikelompokkan per penyebab dengan resource terdampak, pemilik data, dan tautan "Buka data". Antrean coba ulang memisahkan kegagalan otomatis dari yang perlu petugas dengan instruksi konkret ("Petugas admisi: verifikasi NIK dengan KTP atau KK").

## Catatan lintas halaman
- Semua modal (TTE, kembalikan ke dokter, nilai kritis) tidak menutup dengan Esc. Modal non-TTE tidak memindahkan fokus ke dalam dialog.
- Pola pengesahan tidak konsisten: ekspertise radiologi pakai TTE, sedangkan telaah resep, validasi lab, finalisasi koding, kirim E-Klaim, tutup shift, dan pembayaran tidak.
- Tombol "Spek dev" (fixed kanan bawah) bertumpuk dengan konten/aksi di semua halaman pada desktop dan mobile.
- Fokus terlihat baik: menu samping memakai ring box-shadow 3px, tombol antrean memakai outline auto.

## Ringkasan jumlah temuan

| Halaman | K4 | K3 | K2 | K1 | Total |
|---|---|---|---|---|---|
| farmasi | 2 | 4 | 2 | 0 | 8 |
| penunjang | 0 | 5 | 3 | 0 | 8 |
| kasir | 0 | 2 | 5 | 1 | 8 |
| casemixdanclaim | 0 | 1 | 5 | 1 | 7 |
| integrasi | 0 | 0 | 5 | 1 | 6 |
| **Total** | **2** | **12** | **20** | **3** | **37** |

---

# Tinjauan Heuristik D: Master Data, Mutu, SDM, Logistik, Penelitian

Metode: render Playwright (chromium), desktop 1440x900 dan mobile 390x844. Alur utama diklik dan DOM diperiksa (kontras, label, fokus). Screenshot ada di `/tmp/fase0/shotsD/`. Panel "Spek dev" tidak dinilai sebagai UX. Catatan lintas halaman: tombol mengambang "Spek dev" menutupi konten di kanan bawah semua halaman.

Ditemukan di kelima halaman (dicatat sekali di setiap halaman lewat ID terkait):
- Saat modal dibuka, fokus tetap di tombol pemicu.
- Tombol Esc tidak menutup modal (`#chgModal`, `#ikModal`, `#saeModal`).
- Tab `role="tab"` tidak merespons tombol panah.
- Error form hanya tampil sebagai toast. Field tidak diberi `aria-invalid`, kecuali form pengecualian di Master Data.
- Baris tabel yang bisa dipilih (`tr[data-id]`) tidak bisa difokus dengan keyboard (tabIndex -1).

## masterdata.html

| ID | Heuristik | Temuan | Bukti | Keparahan | Saran |
|---|---|---|---|---|---|
| MD-1 | Pencegahan error; Kecocokan dengan dunia nyata | Tombol "×" di kartu "Dicabut" justru **memulihkan akses** (menghapus pencabutan). Tidak ada konfirmasi, alasan, atau dasar. Simbol "×" terbaca seperti "tutup kartu". Menambah pengecualian wajib alasan + dasar, tetapi menghapus pencabutan tidak wajib apa pun. | Akun & akses > Siti Aminah > Per akun. `[data-ovdel]` aria-label "Hapus". Klik memunculkan toast "Pengecualian dihapus. Akses kembali mengikuti peran." dan kartu "Dicabut · sub-menu Tutup kasir" hilang (md_akun_perakun.png, md_ovdel.png) | 3 | Ganti dengan tombol teks "Pulihkan akses…". Buka dialog yang mewajibkan alasan + dasar. Untuk node sensitif, kirim ke antrean persetujuan pemilik data. |
| MD-2 | Pencegahan error; Visibilitas status | Perubahan matriks peran (berlaku untuk **semua akun** satu peran) tersimpan dengan satu klik. Tidak ada ringkasan perubahan, jumlah akun terdampak, alasan, atau dasar. Toast tetap mengklaim "tercatat … beserta dasar pemberian". | Peran & menu: sel "CPPT & instruksi untuk PRW" diubah ke "Tidak ada", lalu `#pmSave`. Toast "1 perubahan berlaku. Tercatat di jejak audit beserta dasar pemberian." (md_peran_setnone.png, md_peran_save.png) | 3 | Sebelum simpan, tampilkan dialog berisi diff dan jumlah akun terdampak, dengan isian dasar/alasan wajib. Pencabutan massal pada menu klinis perlu persetujuan pemilik data. |
| MD-3 | Kecocokan sistem dan dunia nyata; Konsistensi | Sub-menu "CPPT & instruksi" untuk Perawat bangsal sudah diset "Tidak ada", tetapi baris anaknya tetap menampilkan akses: Tulis CPPT "I", Catatan psikiatri "L", Status HIV "L". Pengguna tidak bisa tahu apakah pencabutan induk ikut berlaku ke anak. | md_peran_setnone.png (kolom Perawat bangsal) | 3 | Tampilkan pewarisan secara eksplisit, misalnya anak menjadi abu-abu "ikut induk: tidak ada". Jika anak tetap aktif, beri peringatan. |
| MD-4 | Kendali pengguna; Keamanan (pemisahan tugas) | Superadmin (Ir. Dimas Prakoso) bisa langsung "Berikan" permintaan yang "diteruskan ke Kepala Bidang Keuangan" atau "Pejabat Pelindungan Data Pribadi". Ia juga bisa "Tinjau" akses darurat yang menurut KPI "Ditinjau Kepala Instalasi RM dan DPO". Semua aksi satu klik tanpa konfirmasi, dan tinjauan tidak punya pilihan "tidak sesuai". Masa berlaku hak baru diketahui setelah klik. Tidak ada label "Peragaan" (berbeda dengan tab Usulan). | md_akun_berikan.png, toast "Diberikan sampai 31 Desember 2026…". md_audit.png: "Tinjau" menghasilkan toast "Akses darurat ditinjau: alasan sesuai." | 3 | Batasi tombol pada pejabat yang dituju, atau beri label peragaan. Tampilkan masa berlaku sebelum memberi. Tinjauan perlu dua pilihan (Sesuai/Tidak sesuai) dan catatan. |
| MD-5 | Kecocokan dunia nyata; Status hanya simbol | Kolom "Per akun" memakai angka bertanda "−2" dan "+1" pada pil kuning yang sama. Beda cabut dan beri hanya tanda minus kecil. | md_akun.png, baris Siti Aminah "−2" dan Budi Kurniawan "+1" | 2 | Tulis "2 dicabut" / "1 diberikan" dengan ikon dan warna berbeda. |
| MD-6 | Pengenalan > ingatan; Aksesibilitas | Matriks memakai kode huruf L/I/P/–. Kolom "Superadmin" tersembunyi di luar area gulir horizontal tanpa petunjuk. aria-label sel memakai singkatan internal ("CPPT & instruksi untuk PRW: Input", "…SADM…"), bukan nama peran. | Lebar tabel 825px dalam kontainer 746px (overflow auto). Header terakhir "Superadmin" tidak terlihat di md_peran.png | 2 | Sematkan kolom pertama, beri bayangan atau petunjuk gulir. Pakai nama peran lengkap di aria-label. Beri tooltip L/I/P. |
| MD-7 | Pesan error; Aksesibilitas | Modal "Usulkan perubahan": error hanya muncul sebagai toast ("Isi nilai baru.", "Tulis alasan perubahan.") tanpa menandai field. Fokus tetap di `#chgBtn` setelah modal dibuka, dan Esc tidak menutup modal. | md_modal_err.png. `[aria-invalid=true]` kosong. activeElement "chgBtn" | 2 | Tampilkan error inline di bawah field dengan `aria-invalid` + `aria-describedby`. Pindahkan fokus ke judul/field pertama. Aktifkan Esc dan perangkap fokus. |
| MD-8 | Aksesibilitas (keyboard) | 12 baris tabel (katalog, akun) hanya bisa dipilih dengan mouse. Tab tidak merespons panah kiri/kanan. | `tr[data-id]` tabIndex -1. Contoh "Tarif layanan", "Siti Aminah" | 2 | Taruh tombol/tautan di sel judul, atau beri `tabindex=0` + Enter/Space. Terapkan pola keyboard tablist. |

Sudah baik: pratinjau "Lihat sebagai" menampilkan item dicabut dengan coret merah dan alasan ("dicabut · tidak tampil", md_akun_lihat.png). Usulan perubahan data induk punya riwayat versi v3/v4/v5, alur 4 tahap, dan aturan "Pengusul tidak dapat memverifikasi usulannya sendiri". Pemberian sensitif tanpa tanggal ditolak.

## mutu.html

| ID | Heuristik | Temuan | Bukti | Keparahan | Saran |
|---|---|---|---|---|---|
| MU-1 | Pencegahan error; Keselamatan | "Tetapkan HAIs" (primer) dan "Bukan HAIs" adalah keputusan klinis satu klik tanpa konfirmasi, dan langsung mengirim notifikasi ke DPJP. Tombol tetap aktif dan sama menonjolnya meski kriteria belum terpenuhi. | PPI > Penanda IPCN: "An. K.L. … ventilator hari ke-2 (belum > 2 hari)". Toast "Kasus HAIs tercatat. DPJP dan kepala ruang menerima pemberitahuan" (mu_ppi.png) | 3 | Tampilkan checklist kriteria surveilans per penanda. Beri peringatan dan konfirmasi bila kriteria tidak terpenuhi. Sediakan pembatalan dalam jangka waktu tertentu. |
| MU-2 | Status hanya warna; Pencegahan error | Sel matriks grading risiko hanya berupa warna tanpa teks. aria-label hanya "Probabilitas 5, dampak 1" tanpa band. Sel terpilih hanya ditandai garis tepi. Grading Merah langsung berlaku (tim RCA dibentuk) tanpa konfirmasi, termasuk untuk kasus "Lecet di siku kanan". | `<button class="g-hijau" … aria-label="Probabilitas 5, dampak 1"></button>`. Toast "Grading merah ditetapkan. Tim RCA dibentuk, tenggat 45 hari." (mu_insiden.png, mu_grade_set.png) | 3 | Isi sel dengan huruf band (B/H/K/M) dan masukkan band ke aria-label. Konfirmasi grading Merah/Kuning dengan ringkasan konsekuensi. |
| MU-3 | Status hanya warna | Keterlambatan hanya ditandai teks merah tanpa label: "52 jam" (kolom Selang lapor insiden), "7 hari" (kolom Umur keluhan). | mu_insiden.png baris IKP-1035; mu_keluhan.png baris KL-0207 | 2 | Tambahkan pil teks "Terlambat" / "Lewat SLA" dengan ikon. |
| MU-4 | Konsistensi; Pencegahan error | Tombol primer "Kirim ke Kemenkes" (pengiriman eksternal) diletakkan sebaris dengan chip filter Semua / Di bawah target / Belum divalidasi, sehingga mirip filter. | mutu_D_full.png, `#inmSend` | 2 | Pindahkan ke header kartu atau area aksi terpisah. Beri dialog ringkasan sebelum kirim. |
| MU-5 | Visibilitas status; Akuntabilitas | "Tandai tervalidasi" cukup satu klik. Tidak ada isian sampel yang dicocokkan, padahal teksnya berbunyi "Validator mencocokkan sampel dengan sumber data". | Toast "Indikator ditandai tervalidasi oleh Komite Mutu." (mu_val.png) | 2 | Minta jumlah sampel/hasil cocok dan catatan. Tampilkan siapa dan kapan memvalidasi. |
| MU-6 | Pesan error; Aksesibilitas; Kecocokan dunia nyata | Modal "Laporkan insiden": error hanya toast ("Pilih jenis insiden."), fokus tetap di `#ikNew`, Esc tidak menutup. "Waktu kejadian" hanya berupa pilihan relatif ("2 jam lalu", "Kemarin, 20 jam lalu", "Tiga hari lalu, 60 jam"), sehingga waktu persis tidak bisa dicatat. | mu_ikmodal_err.png. Teks `#ikModal` | 2 | Gunakan input tanggal-jam dengan default "sekarang". Tampilkan error inline. Perbaiki fokus dan Esc. |
| MU-7 | Aksesibilitas (keyboard) | 13 baris tabel (indikator, insiden, keluhan) tidak bisa difokus. Detail di panel kanan hanya terbuka dengan mouse. | `tr` tabIndex -1, contoh "Kepatuhan kebersihan tangan" | 2 | Lihat MD-8. |
| MU-8 | Konsistensi | Tab sub-menu ditaruh di kanan atas, sedangkan di Master Data tab ada di bawah judul. "Spek dev" menutupi penjelasan grading dan persentase ICU di kepatuhan kebersihan tangan. | mutu_D_full.png, mu_insiden.png, mu_ppi.png | 1 | Seragamkan posisi tab. Jangan biarkan elemen mengambang menutupi konten. |

Sudah baik: status indikator punya label teks (Tercapai / Perlu PDSA / PDSA berjalan), tidak hanya warna. Pengiriman ke Kemenkes diblokir bila ada indikator belum divalidasi. "Tutup tindak lanjut" diblokir selama RCA belum selesai. Laporan insiden menyembunyikan pelapor dan mengarahkan agar kronologi "tanpa menyebut nama".

## sdm.html

| ID | Heuristik | Temuan | Bukti | Keparahan | Saran |
|---|---|---|---|---|---|
| SD-1 | Pencegahan error; Konsistensi; Keselamatan | Cuti Nanda Putri menampilkan peringatan merah "Dinas pagi menjadi 2/3 … Cari pengganti sebelum menyetujui", tetapi "Setujui" tetap aktif dan langsung berlaku tanpa konfirmasi. Akibatnya jadwal kembali ke draf. Ini tidak konsisten dengan tukar dinas, di mana Setujui dinonaktifkan bila melanggar aturan. | sd_cuti.png. Toast "Cuti disetujui. Jadwal dinas kembali ke draf dan menandai shift yang kurang." | 3 | Nonaktifkan atau minta konfirmasi dengan opsi "pilih pengganti" bila kebutuhan shift tidak terpenuhi. |
| SD-2 | Pencegahan error; Keselamatan | "Simpan" SIP baru langsung berlaku dan pengingat berhenti, padahal tidak ada unggahan pindaian atau langkah verifikasi (teks: "Pindaian dokumen diunggah dan diverifikasi bagian SDM"). "Berlaku sampai" sudah terisi default "30 Oktober 2031". | sd_kred.png. Toast "SIP baru tersimpan. Pengingat dihentikan." | 3 | Wajibkan unggah dokumen dan isi tanggal dari dokumen (tanpa default). Status "menunggu verifikasi" sampai diperiksa. |
| SD-3 | Pencegahan error; Kendali pengguna | Klik sel jadwal langsung mengganti dinas secara berputar (P→S→M→L). Tidak ada undo, jadi salah klik langsung mengubah jadwal. Pelanggaran jeda hanya ditandai garis merah dan "!", dan aria-label sel tidak menyebut pelanggaran. | Klik `[data-cell="0-0"]` mengubah aria-label menjadi "Ns. Dewi Anggraini, S.Kep Sen 12: S". Sel bermasalah: aria-label "Lina Marpaung, A.Md.Kep Sab 17: P" (sdm_D_full.png) | 2 | Gunakan menu pilihan dinas per sel dengan Batal/undo. Tambahkan "melanggar jeda" ke aria-label. Pakai nama dinas, bukan huruf, untuk pembaca layar. |
| SD-4 | Aksesibilitas (kontras) | Sel "L" (Libur) dan "—" berwarna abu rgb(148,163,184) di atas #EEF2EF, kontras 2,27:1 (<4,5:1). Ada 32 elemen. | Pemeriksaan kontras otomatis. sdm_D_full.png | 2 | Gelapkan teks menjadi minimal #64748B atau sejenisnya. |
| SD-5 | Visibilitas status; Kejujuran sistem | "Tolak" tukar dinas cukup satu klik dan toast mengklaim "ditolak dengan alasan tercatat", padahal tidak ada isian alasan. | Toast "Permintaan tukar dinas ditolak dengan alasan tercatat." | 2 | Minta alasan singkat (pilihan + teks) sebelum menolak. |
| SD-6 | Privasi data | Panel pegawai menampilkan NIP lengkap "19800412 200801 1 003", yang memuat tanggal lahir, tanpa penyamaran. | sd_pegawai.png panel dr. Rizal Hasibuan | 2 | Samarkan sebagian NIP secara default, tampilkan penuh hanya untuk peran berwenang, dan catat di audit. |
| SD-7 | Konsistensi | Posisi tab berpindah di halaman yang sama: di "Jadwal dinas" tab ada di bawah judul, di Pegawai/Izin/Cuti ada di kanan atas. Toast aksi sebelumnya tetap tampil setelah pindah tab. | sdm_D_full.png vs sd_pegawai.png, sd_kred.png, sd_cuti.png | 2 | Satu posisi tab untuk semua view. Hilangkan toast saat berganti view. |
| SD-8 | Status hanya tebal/warna | Kekurangan dinas (malam Jum 16 "1/2") hanya ditebalkan. Header Sab/Min berwarna merah mirip tanda masalah. | sdm_D_full.png baris "Terjadwal / kebutuhan malam" | 1 | Beri latar/ikon peringatan pada sel kurang. Bedakan akhir pekan tanpa merah. |

Sudah baik: "Setujui" tukar dinas dinonaktifkan dengan alasan eksplisit ("Melanggar aturan jeda: … jeda 0 jam"). "Terbitkan jadwal" diblokir dengan ringkasan masalah dan legenda dinas. Izin yang hampir berakhir menampilkan dampaknya ("Jadwal praktik mulai 31 Oktober tidak dapat dibuat").

## logistik.html

| ID | Heuristik | Temuan | Bukti | Keparahan | Saran |
|---|---|---|---|---|---|
| LG-1 | Pencegahan error; Kecocokan dunia nyata | Di form BAST, angka "0" pada field "Diterima" (Reagen ureum) hanya placeholder dengan value kosong, sehingga field tampak sudah terisi. "Terbitkan BAST" lalu ditolak dengan pesan "Isi jumlah diterima…, isi 0 bila belum datang." Ketiga input juga tidak berlabel. | lg_peng.png. DOM: `placeholder="0"`, `value=""`, `aria-label=null` | 3 | Hapus placeholder angka, atau isi value 0 secara eksplisit. Tandai field kosong inline dan beri label "Diterima <barang>". |
| LG-2 | Pencegahan error | Input "Keluarkan" bertipe text. Isian "abc" diterima, dianggap 0, lalu permintaan diproses sebagai "Dikeluarkan sebagian… tunggakan". Isian melebihi stok hanya memunculkan toast, sementara panel tetap bertuliskan "Semua item terpenuhi". | Toast "Dikeluarkan sebagian. Sisa permintaan tercatat sebagai tunggakan untuk Instalasi Laboratorium." (lg_over.png) | 3 | Gunakan `inputmode=numeric` dengan validasi inline per baris. Perbarui ringkasan secara langsung. Konfirmasi bila pengeluaran parsial. |
| LG-3 | Konsistensi; Keselamatan | Mesin anestesi OK 4 berstatus "Lewat 2 hari" pemeliharaan preventif di tab Pemeliharaan, dan KPI menyatakan "Alat ditandai di unit". Namun di register Aset kondisinya "Baik" tanpa penanda apa pun. | lg_ipsrs (teks: "Mesin anestesi MA-OK-04 … Lewat 2 hari") vs lg_aset.png baris "Mesin anestesi … Baik" | 3 | Tampilkan status pemeliharaan/kalibrasi di register aset dan di detail aset. Beri label "Lewat jadwal PM" yang konsisten di semua tab. |
| LG-4 | Pencegahan error | "Pindahkan ke" sudah terisi default "OK 4". Satu klik "Mutasi" langsung memindahkan Ventilator ICU tanpa konfirmasi. | lg_aset.png. Toast "Ventilator ICU dipindahkan ke OK 4…" | 2 | Default "Pilih lokasi". Konfirmasi dengan ringkasan asal → tujuan. |
| LG-5 | Pencegahan error; Visibilitas | "Usulkan pengadaan" langsung membuat usulan 990 buah tanpa menampilkan atau mengizinkan pengeditan jumlah. Tidak ada pengecekan paket serupa yang sedang berjalan ("Kateter urin dan infus set" sudah di tahap Kontrak). | Toast "Usulan pengadaan Kateter urin Foley 16 Fr 990 buah masuk tahap permintaan." (lg_peng.png) | 2 | Tampilkan dialog jumlah yang bisa diedit beserta informasi paket berjalan untuk barang yang sama. |
| LG-6 | Status hanya warna | Stok di bawah minimum hanya ditandai angka merah (210, 6, 42). "11 jam" lewat batas tanggap juga hanya merah. Jumlah diterima parsial hanya berlatar kuning. | logistik_D_full.png, lg_wo.png, lg_peng.png | 2 | Tambahkan pil teks "Di bawah min" / "Lewat batas" / "Parsial". |
| LG-7 | Aksesibilitas | Baris permintaan dan aset (3+) tidak bisa difokus. Input BAST tidak berlabel, sementara input "Keluarkan" berlabel. Modal dan tab mengikuti pola umum di atas. | `tr` tabIndex -1. aria-label null pada input BAST | 2 | Lihat MD-8, beri label konsisten. |
| LG-8 | Estetika/tata letak | "Spek dev" menutupi select "Kondisi" (teks terpotong "Baik, suhu rantai di…") dan nilai belanja modal Alat kedokteran. | lg_peng.png, lg_aset.png | 1 | Jangan biarkan elemen mengambang menutupi konten. |

Sudah baik: pencocokan tiga dokumen menampilkan selisih "Faktur lebih Rp 15.000.000 dari kontrak" dan memblokir pencatatan utang. Pengeluaran barang memprioritaskan ED terdekat, dan ED tampil di daftar stok. Stepper pengadaan 6 tahap berlabel teks.

## penelitian.html

| ID | Heuristik | Temuan | Bukti | Keparahan | Saran |
|---|---|---|---|---|---|
| PN-1 | Keamanan/privasi; Pemisahan tugas | Akun "Sekretaris Komite Etik Penelitian" bisa menekan "Setujui sebagai DPO", lalu "Rilis data" untuk dataset pasien ICU. Persetujuan pejabat lain diberikan dari akun yang salah, tanpa konfirmasi dan tanpa label peragaan. | Data riset > DR-2026-031. Setelah generalisasi: toast "Disetujui DPO…", lalu "Dataset disiapkan di ruang analisis…" (pn_dpo_ok.png) | 4 | Tombol persetujuan hanya muncul untuk peran DPO. Pengguna lain melihat status "Menunggu DPO". Jika peragaan, beri label "Peragaan: bertindak sebagai DPO". |
| PN-2 | Visibilitas status | Setelah DPO menyetujui, pil status di header detail dan di tabel tetap "Telaah DPO". | pn_dpo_ok.png | 2 | Perbarui status secara langsung (misalnya "Siap rilis"). |
| PN-3 | Konsistensi | Penomoran tahap tidak konsisten: KPI memakai 8 tahap ("6. Izin Direktur", termasuk "4. Perbaikan"), sedangkan stepper detail memakai 7 tahap ("5 Izin Direktur"). | penelitian_D_full.png | 2 | Pakai satu model tahap. Perbaikan bisa jadi sub-status Telaah. |
| PN-4 | Pencegahan error | "Terima laporan akhir" cukup satu klik tanpa membuka atau melampirkan dokumen, dan langsung melepas penahanan izin protokol lain. | Toast "Laporan akhir diterima. Penahanan izin KEP-2026-131 dilepas." (pn_final.png) | 2 | Tampilkan dokumen laporan dan checklist telaah sebelum tombol aktif. Beri konfirmasi. |
| PN-5 | Label form; Aksesibilitas | Select "Bentuk rilis" (Tanggal lahir, Wilayah, Tanggal masuk ICU) dan select "Pilih" protokol di Luaran tidak berlabel. Select Tanggal lahir juga terpotong di tepi tabel. | Pemeriksaan DOM: `SELECT` tanpa id/aria-label (5). pn_data.png, pn_luaran.png | 2 | Beri aria-label "Bentuk rilis Tanggal lahir" dan "Protokol untuk <judul>". Perbaiki lebar kolom. |
| PN-6 | Pesan error; Kecocokan dunia nyata | Modal "Laporkan KTD serius": error hanya toast ("Pilih kriteria serius."), fokus tetap di `#saeBtn`, Esc tidak menutup. "Diketahui" berupa pilihan relatif dengan default "1 jam lalu", padahal batas laporan ke sponsor adalah 24 jam. | Teks `#saeModal`, toast | 2 | Gunakan input tanggal-jam tanpa default, beri hitung mundur 24 jam, dan error inline. |
| PN-7 | Status hanya warna | Bar kelengkapan registri dan kepatuhan KSM memakai merah/oranye/hijau tanpa legenda ambang (84% merah, 88% oranye, 91% hijau). | pn_reg.png, pn_luaran.png | 2 | Tampilkan garis target dan label "di bawah 85%" pada baris. |
| PN-8 | Aksesibilitas (keyboard) | 8 baris tabel (protokol, permintaan data, uji klinik) tidak bisa difokus. Detail hanya terbuka dengan mouse. | `tr` tabIndex -1, contoh "Efektivitas edukasi video…" | 2 | Lihat MD-8. |

Sudah baik: pemeriksaan k-anonimitas memblokir persetujuan dan menampilkan nilai "k = 1" dengan saran penyamaran yang jelas. Pengenal langsung otomatis "Dihapus" atau "Diganti kode studi". Izin yang ditahan menyebut alasannya dan menyediakan tautan "Buka KEP-2024-077".

## Ringkasan jumlah temuan

| Halaman | K4 | K3 | K2 | K1 | Total |
|---|---|---|---|---|---|
| masterdata | 0 | 4 | 4 | 0 | 8 |
| mutu | 0 | 2 | 5 | 1 | 8 |
| sdm | 0 | 2 | 5 | 1 | 8 |
| logistik | 0 | 3 | 4 | 1 | 8 |
| penelitian | 1 | 0 | 7 | 0 | 8 |
| **Total** | **1** | **11** | **25** | **3** | **40** |
