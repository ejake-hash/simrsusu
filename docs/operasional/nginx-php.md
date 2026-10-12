# nginx dan PHP

Draf, tiket 1.12. Contoh: folder `/srv/karsitara`, domain `simrs.rs-contoh.invalid`, socket
`/run/php/php8.4-fpm-karsitara.sock`. Jalur dan nama paket mengikuti distribusi server.

## 1. Bentuk penyajian

Satu origin untuk SPA dan API (cookie sesi `SameSite=Strict`, `SANCTUM_STATEFUL_DOMAINS` = host ini):

| Jalur | Ditangani | Sumber |
|---|---|---|
| `/` dan rute SPA (`/masuk`, `/pengaturan/...`) | berkas `frontend/dist`, selain berkas statis jatuh ke `index.html` (router memakai `createWebHistory`) | `frontend/src/app/router.ts` |
| `/assets/...` | berkas build Vite berhash, cache panjang | `vite build` |
| `/api/...` | Laravel (`backend/public/index.php`) lewat FastCGI | `frontend/src/shared/api/klien.ts` (`baseURL: '/api/v1'`) |
| `/sanctum/csrf-cookie` | Laravel | `frontend/src/modules/akses/api.ts` |
| `/up` | Laravel, pemeriksaan hidup; batasi ke IP pemantau | `bootstrap/app.php` |
| `/horizon` | **tidak diteruskan**; dasbor ditolak untuk semua akun di luar `local` | `HorizonServiceProvider` |

## 2. Konfigurasi nginx

`/etc/nginx/snippets/karsitara-header.conf` (dipisah karena `add_header` di dalam `location` menghapus warisan
header dari `server`; setiap `location` yang punya `add_header` wajib meng-include berkas ini):

```nginx
add_header Strict-Transport-Security "max-age=31536000" always;
add_header X-Content-Type-Options "nosniff" always;
add_header X-Frame-Options "DENY" always;
add_header Referrer-Policy "same-origin" always;
add_header Permissions-Policy "camera=(), microphone=(), geolocation=()" always;
add_header Content-Security-Policy "default-src 'self'; script-src 'self'; style-src 'self' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data: blob:; connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'" always;
```

`/etc/nginx/snippets/karsitara-laravel.conf`:

```nginx
include fastcgi_params;
fastcgi_param SCRIPT_FILENAME /srv/karsitara/backend/public/index.php;
fastcgi_param SCRIPT_NAME /index.php;
fastcgi_param HTTP_PROXY "";
fastcgi_pass unix:/run/php/php8.4-fpm-karsitara.sock;
fastcgi_read_timeout 60s;
fastcgi_buffers 16 16k;
fastcgi_buffer_size 32k;
```

`/etc/nginx/sites-available/karsitara.conf`:

```nginx
server {
    listen 80;
    server_name simrs.rs-contoh.invalid;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl;
    http2 on;
    server_name simrs.rs-contoh.invalid;

    ssl_certificate     /etc/ssl/karsitara/<sertifikat-lengkap>.pem;
    ssl_certificate_key /etc/ssl/karsitara/<kunci-privat>.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_prefer_server_ciphers off;
    ssl_session_cache shared:SSL:10m;
    ssl_session_tickets off;

    server_tokens off;
    client_max_body_size 12m;          # paket referensi 10 MB, dokumen izin praktik 5 MB, impor 2 MB (backlog 1.8e)
    client_body_timeout 60s;

    gzip on;
    gzip_types text/css application/javascript application/json image/svg+xml;

    include snippets/karsitara-header.conf;

    root /srv/karsitara/frontend/dist;
    index index.html;

    location ^~ /api/ {
        include snippets/karsitara-laravel.conf;
    }

    location ^~ /sanctum/ {
        include snippets/karsitara-laravel.conf;
    }

    location = /up {
        allow <ip-pemantau>;
        deny all;
        include snippets/karsitara-laravel.conf;
    }

    location ^~ /horizon {
        return 404;
    }

    location ^~ /assets/ {
        include snippets/karsitara-header.conf;
        add_header Cache-Control "public, max-age=31536000, immutable" always;
        try_files $uri =404;
    }

    location = /index.html {
        include snippets/karsitara-header.conf;
        add_header Cache-Control "no-cache" always;
    }

    location / {
        try_files $uri $uri/ /index.html;
    }

    location ~ /\. {
        deny all;
    }
}
```

```bash
sudo ln -s /etc/nginx/sites-available/karsitara.conf /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

Catatan:

- **`Referrer-Policy` jangan `no-referrer`.** Sanctum mengenali permintaan SPA dari header `Referer`/`Origin`
  (`SANCTUM_STATEFUL_DOMAINS`). Permintaan GET satu origin tidak membawa `Origin`; tanpa `Referer`, sesi tidak dipasang
  dan `GET /api/v1/saya` dibalas 401. `same-origin` mengirim `Referer` hanya ke origin sendiri.
- **HSTS**: `includeSubDomains`/`preload` hanya bila semua subdomain RS sudah HTTPS.
- **CSP**: Google Fonts masih dimuat oleh `frontend/index.html`, jadi `fonts.googleapis.com`/`fonts.gstatic.com`
  diizinkan. PRD 14.1 (pola frontend 4) melarang ini di produksi; setelah font di-host sendiri, hapus kedua domain dari
  CSP. `data:` dibutuhkan gambar QR pendaftaran MFA, `blob:` untuk unduhan berkas. Pasang dulu sebagai
  `Content-Security-Policy-Report-Only` selama uji e2e di staging, periksa konsol peramban, baru ditegakkan.
- Berkas paket identitas (`/api/v1/profil-rs/berkas/...`) sudah membawa `X-Content-Type-Options` dan CSP sendiri
  untuk SVG dari Laravel.
- Batas waktu 60 detik mengikuti backlog 1.8e (pemuatan referensi). PRD 14.1.1 butir 2 menyebut batas permintaan
  HTTP 30 detik; selisih ini dicatat di [README](README.md).

## 3. Batas waktu dan ukuran: harus selaras

| Lapisan | Nilai minimal | Tempat |
|---|---|---|
| nginx unggahan | `client_max_body_size 12m` | blok `server` |
| nginx ke PHP | `fastcgi_read_timeout 60s` (atau `proxy_read_timeout 60s` bila nginx meneruskan lewat HTTP) | snippet Laravel |
| PHP unggahan | `upload_max_filesize = 12M`, `post_max_size = 12M` | php.ini FPM |
| PHP waktu | `max_execution_time = 60` | php.ini FPM |
| php-fpm | `request_terminate_timeout = 65s` | pool |
| PHP memori | `memory_limit = 128M` minimal (FPM); CLI `256M` untuk `referensi:muat` berkas besar | php.ini |
| Proksi/WAF di depan nginx (bila ada) | sama dengan nginx | perangkat proksi |

## 4. IP klien asli dan `TRUSTED_PROXIES`

IP klien menentukan aturan MFA jaringan RS (`AUTENTIKASI_MFA_JARINGAN_RS`), rate limit per IP, dan IP di log audit.
Laravel membaca `REMOTE_ADDR`; header `X-Forwarded-*` hanya dipercaya dari alamat di `TRUSTED_PROXIES`
(`App\Core\Http\TrustedProxies`, `config/jaringan.php`). Pilih **satu** pola:

| Topologi | nginx | `.env` |
|---|---|---|
| A. nginx menerima koneksi langsung dari pengguna (TLS di nginx) | tanpa `realip` | `TRUSTED_PROXIES=` (kosong) |
| B. Ada load balancer/WAF di depan nginx, nginx menerjemahkan IP | `set_real_ip_from <ip-lb>;` `real_ip_header X-Forwarded-For;` `real_ip_recursive on;` | `TRUSTED_PROXIES=` (kosong): `REMOTE_ADDR` sudah IP klien. Pastikan LB meneruskan `X-Forwarded-Proto` atau TLS juga di nginx. |
| C. Ada load balancer di depan, nginx meneruskan header apa adanya | tanpa `realip` | `TRUSTED_PROXIES=<ip-lb>` (IP/CIDR LB saja, bukan `*`) |

Uji: masuk dari komputer di jaringan RS dan dari luar (mis. VPN/seluler); dari luar MFA harus selalu diminta. Lihat
juga baris `ip` aksi masuk terakhir di audit (`audit.log_aktivitas`) — harus IP klien, bukan IP LB.

## 5. php.ini (FPM dan CLI)

`/etc/php/8.4/fpm/conf.d/90-karsitara.ini` (CLI: berkas sama di `cli/conf.d/` dengan `memory_limit = 256M`):

```ini
expose_php = Off
display_errors = Off
log_errors = On
memory_limit = 128M
upload_max_filesize = 12M
post_max_size = 12M
max_execution_time = 60
max_input_time = 60
date.timezone = UTC

opcache.enable = 1
opcache.enable_cli = 0
opcache.memory_consumption = 256
opcache.interned_strings_buffer = 16
opcache.max_accelerated_files = 20000
opcache.validate_timestamps = 0     ; wajib reload php-fpm setiap rilis
```

`zend.exception_ignore_args` diset `1` oleh aplikasi saat boot (jejak tumpukan tanpa argumen; `AppServiceProvider`).
Koneksi basis data memakai zona UTC (`config/database.php`); zona RS diatur `INSTALASI_ZONA_WAKTU`/`AUDIT_ZONA_WAKTU`.

## 6. Pool php-fpm

`/etc/php/8.4/fpm/pool.d/karsitara.conf`:

```ini
[karsitara]
user = www-data
group = www-data
listen = /run/php/php8.4-fpm-karsitara.sock
listen.owner = www-data
listen.group = www-data
listen.mode = 0660

pm = dynamic
pm.max_children = <angka dari uji beban>
pm.start_servers = <...>
pm.min_spare_servers = <...>
pm.max_spare_servers = <...>
pm.max_requests = 1000
request_terminate_timeout = 65s

clear_env = yes
security.limit_extensions = .php
```

`pm.max_children` dibatasi oleh RAM dan `max_connections` PostgreSQL (setiap anak bisa memegang satu koneksi; Horizon
menambah hingga 18 proses pekerja di `production`, lihat [proses-dan-jadwal.md](proses-dan-jadwal.md)). PgBouncer
yang diminta PRD 14.1 belum ada di repo.

<!-- HASIL-UJI-BEBAN: pm.max_children dan max_connections yang dipakai -->

```bash
sudo php-fpm8.4 -t && sudo systemctl reload php8.4-fpm
```

## 7. Pemeriksaan

```bash
curl -sI https://simrs.rs-contoh.invalid/ | grep -iE 'strict-transport|content-security|x-content-type|x-frame|referrer'
curl -sI https://simrs.rs-contoh.invalid/pengaturan/penyiapan | head -1     # 200 (index.html)
curl -fsS https://simrs.rs-contoh.invalid/api/v1/siap                       # {"status":"siap",...}
curl -s -o /dev/null -w '%{http_code}\n' https://simrs.rs-contoh.invalid/horizon   # 404
curl -s -o /dev/null -w '%{http_code}\n' https://simrs.rs-contoh.invalid/.env      # 403 atau 404
openssl s_client -connect simrs.rs-contoh.invalid:443 -tls1_1 </dev/null 2>&1 | grep -i 'alert\|error'   # ditolak
```
