# Paket data referensi (tiket 1.8e, PRD FR-54-09, keputusan K7)

Berkas dimuat dengan `php artisan referensi:muat <sistem> <berkas> --versi=… --sumber=…` atau lewat wizard
(`POST /api/v1/paket-referensi/{sistem}/muat`). Satu pemuatan = satu versi lengkap; kode yang hilang dinonaktifkan,
tidak dihapus. Format CSV per sistem ada di kontrak (`docs/api/openapi.yaml`, v0.11.0).

**Status: berkas resmi belum dikemas.** Isi paket wajib berasal dari sumber resmi di bawah; jangan diisi dari
salinan tidak resmi atau dikarang. Sampai berkas tersedia, folder ini hanya berisi catatan sumber.

| Sistem | Isi | Sumber resmi | Lisensi/catatan | Status |
|---|---|---|---|---|
| `wilayah` | Provinsi s.d. kelurahan, kode Kemendagri bertitik | Keputusan/Permendagri kode dan data wilayah administrasi pemerintahan terbaru | Data pemerintah; versi terbaru dicek setiap rilis | Menunggu berkas dari pemilik |
| `ucum` | Satuan ukur | ucum-essence.xml, unitsofmeasure.org (Regenstrief) | Bebas dipakai (lisensi UCUM) | Menunggu konversi ke CSV `kode,tampilan,kode_induk` |
| `loinc` | Subset lab yang dipakai | LOINC, loinc.org (Regenstrief; unduhan butuh akun) | Gratis dengan syarat atribusi; teks atribusi ditampilkan di layar Tentang | Menunggu berkas dan daftar subset dari RS/pemilik |
| `referensi` | Agama, pendidikan, pekerjaan, status kawin, dll. | Kode variabel RME / SATUSEHAT (Kemenkes) | Daftar kode resmi **perlu verifikasi** (PRD) | Menunggu dokumen resmi |
| `icd10`, `icd9cm` | ICD-10 dan ICD-9-CM (versi INA-CBG) | **Tidak dikemas** (K7): dimuat dari berkas yang disediakan RS, mis. ekspor aplikasi E-Klaim | Hak cipta WHO; distribusi dalam produk perlu verifikasi | Per instalasi |

Saat menambah berkas: simpan sebagai `<sistem>/<versi>.csv`, tulis sumber (URL/nomor dokumen, tanggal unduh) dan
lisensi di tabel ini, lalu muat ke instalasi uji dan periksa ringkasan perubahan sebelum rilis.
