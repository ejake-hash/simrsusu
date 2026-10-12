# Kebijakan keamanan

Karsitara SIMRS menangani data kesehatan. Bila Anda menemukan celah keamanan, mohon laporkan secara **pribadi** agar
bisa diperbaiki sebelum diketahui umum.

## Cara melapor

- **Jangan** membuka issue, pull request, atau diskusi publik untuk celah keamanan.
- Laporkan lewat **GitHub Security Advisories** repo ini: tab *Security* → *Report a vulnerability* (laporan hanya
  terlihat oleh pengelola).
- Sertakan: bagian yang terdampak (berkas/endpoint), langkah mereproduksi, dampak yang Anda perkirakan, dan versi atau
  commit yang Anda uji.
- Jangan sertakan data pasien atau data pribadi nyata. Pakai data sintetis.

## Yang kami lakukan

- Konfirmasi penerimaan laporan dalam 3 hari kerja.
- Penilaian dan rencana perbaikan dikabarkan kepada pelapor; perbaikan dirilis lebih dulu sebelum rincian celah
  diumumkan.

## Batasan pengujian

Uji hanya terhadap instalasi milik Anda sendiri dengan data sintetis. Jangan menguji instalasi rumah sakit yang sedang
melayani pasien, jangan mengakses atau mengubah data orang lain, dan jangan melakukan serangan penolakan layanan.

Nilai kunci di `backend/phpunit.xml` dan `.github/workflows/ci.yml` adalah nilai uji sintetis yang sengaja publik; itu
bukan celah, dan tidak boleh dipakai di server.
