<?php

// Angka bawaan dari PRD 14.2. Semua bisa diubah per instalasi lewat .env.
return [

    'sandi' => [
        'panjang_minimal' => (int) env('AUTENTIKASI_SANDI_PANJANG_MINIMAL', 12),

        // Daftar sandi bocor lokal (offline), lihat docs/adr/0001-cek-sandi-bocor-offline.md.
        'daftar_bocor' => env(
            'AUTENTIKASI_SANDI_DAFTAR_BOCOR',
            app_path('Modules/Akses/Infrastructure/data/sandi-bocor.txt.gz'),
        ),

        // Cek tambahan ke Have I Been Pwned (k-anonymity). Mati bawaan: server RS belum tentu
        // boleh atau bisa keluar internet.
        'cek_hibp' => (bool) env('AUTENTIKASI_SANDI_CEK_HIBP', false),
    ],

    'penguncian' => [
        'maks_gagal' => (int) env('AUTENTIKASI_KUNCI_MAKS_GAGAL', 5),
        'durasi_menit' => (int) env('AUTENTIKASI_KUNCI_DURASI_MENIT', 15),
    ],

    // Per IP dihitung dari IP klien; di belakang reverse proxy, isi TRUSTED_PROXIES (config/jaringan.php)
    // agar seluruh RS tidak berbagi satu kuota IP proksi.
    'rate_limit' => [
        'per_ip_per_menit' => (int) env('AUTENTIKASI_BATAS_PER_IP_PER_MENIT', 60),
        'per_nama_pengguna_per_menit' => (int) env('AUTENTIKASI_BATAS_PER_NAMA_PENGGUNA_PER_MENIT', 10),
    ],

    // Batas diam sesi, lihat docs/adr/0002-batas-diam-sesi.md. SESSION_LIFETIME harus
    // lebih besar dari nilai terbesar di sini.
    'sesi' => [
        'batas_diam_menit' => (int) env('AUTENTIKASI_BATAS_DIAM_MENIT', 15),
        'batas_diam_perangkat_terdaftar_menit' => (int) env('AUTENTIKASI_BATAS_DIAM_PERANGKAT_TERDAFTAR_MENIT', 60),
    ],

    // Perangkat tepercaya (tiket 1.13): MFA sekali per perangkat per masa percaya. Batas atas tidak bisa dinaikkan dari layar;
    // nilai instalasi (akses.kebijakan_keamanan) dan per peran hanya bisa di bawahnya.
    'perangkat_tepercaya' => [
        'maks_hari' => (int) env('AUTENTIKASI_PERANGKAT_MAKS_HARI', 30),
        'maks_hari_superadmin' => (int) env('AUTENTIKASI_PERANGKAT_MAKS_HARI_SUPERADMIN', 7),
        'maks_hari_peran_sensitif' => (int) env('AUTENTIKASI_PERANGKAT_MAKS_HARI_PERAN_SENSITIF', 14),
        // Cookie token (HttpOnly, SameSite=Strict, Secure mengikuti session.secure), hanya terkirim ke /api/v1/autentikasi.
        'cookie' => env('AUTENTIKASI_PERANGKAT_COOKIE', 'karsitara_perangkat'),
    ],

    // MFA TOTP (PRD 10.3.2, tiket 1.1b).
    'mfa' => [
        // CIDR jaringan RS, dipisah koma. Akses dari luar daftar ini wajib MFA walau wajib_mfa=false,
        // dan akun yang belum punya MFA tidak bisa mendaftar dari luar (tiket 1.1c).
        // Kosong = instalasi belum disiapkan: login web ditolak 503 konfigurasi_belum_lengkap.
        'jaringan_rs' => array_values(array_filter(array_map('trim', explode(',', (string) env('AUTENTIKASI_MFA_JARINGAN_RS', ''))))),
        'challenge_menit' => (int) env('AUTENTIKASI_MFA_CHALLENGE_MENIT', 5),
        // RFC 6238: kode periode sebelum/sesudah ikut diterima untuk selisih jam ponsel.
        'toleransi_periode' => 1,
        // Nama yang tampil di aplikasi autentikator; isi nama RS di produksi.
        'penerbit' => env('AUTENTIKASI_MFA_PENERBIT', env('APP_NAME', 'SIMRS')),
        // K11 (tiket 1.5): izin butuh_mfa minta MFA ulang bila verifikasi terakhir di sesi lebih lama dari ini.
        'ulang_menit' => (int) env('AUTENTIKASI_MFA_ULANG_MENIT', 15),
    ],

    // Hanya untuk AkunSintetisSeeder di lokal/staging; kosong = dibuat acak dan dicetak sekali.
    'akun_sintetis_sandi' => env('AKUN_SINTETIS_SANDI'),
    // Rahasia TOTP sintetis (Base32) untuk akun contoh.mfa; kosong = dibuat acak dan dicetak sekali.
    'akun_sintetis_mfa_rahasia' => env('AKUN_SINTETIS_MFA_RAHASIA'),
    // Rahasia TOTP sintetis akun contoh.superadmin (tiket 1.2c, e2e Pengaturan); kosong = acak, dicetak sekali.
    'akun_sintetis_superadmin_mfa_rahasia' => env('AKUN_SINTETIS_SUPERADMIN_MFA_RAHASIA'),
    // Superadmin sintetis kedua (tiket 1.2d, e2e kontrol ganda); kosong = acak, dicetak sekali.
    'akun_sintetis_superadmin_dua_mfa_rahasia' => env('AKUN_SINTETIS_SUPERADMIN_DUA_MFA_RAHASIA'),
    // Admin SDM sintetis contoh.sdm (tiket 1.8d, e2e Data pegawai); kosong = acak, dicetak sekali.
    'akun_sintetis_sdm_mfa_rahasia' => env('AKUN_SINTETIS_SDM_MFA_RAHASIA'),
    'akun_sintetis_integrasi_mfa_rahasia' => env('AKUN_SINTETIS_INTEGRASI_MFA_RAHASIA'),

];
