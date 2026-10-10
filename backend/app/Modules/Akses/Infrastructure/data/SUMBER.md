# sandi-bocor.txt.gz

Daftar sandi bocor untuk cek offline (PRD 14.2, `docs/adr/0001-cek-sandi-bocor-offline.md`).

- Sumber: [SecLists](https://github.com/danielmiessler/SecLists) (lisensi MIT), commit `cae9eab995852c183fbe5e03fc92471fcac82f0e`,
  folder `Passwords/Common-Credentials/`:
  `Pwdb_top-1000000.txt`, `xato-net-10-million-passwords-1000000.txt`, `100k-most-used-passwords-NCSC.txt`.
- Olahan: digabung, hanya baris >= 12 karakter (yang lebih pendek sudah ditolak aturan panjang minimal),
  huruf kecil ASCII, unik, terurut. Hasil 73.078 baris.
- Perintah:
  ```
  cat *.txt | tr -d '\r' | LC_ALL=C awk 'length($0) >= 12' | LC_ALL=C tr '[:upper:]' '[:lower:]' \
    | LC_ALL=C sort -u | gzip -9 -n > sandi-bocor.txt.gz
  ```
- Pembaruan: ulangi perintah dengan commit SecLists terbaru dan perbarui baris di atas.
