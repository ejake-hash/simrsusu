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

    // Per IP dihitung dari IP klien; di belakang reverse proxy, isi PROKSI_TEPERCAYA (config/jaringan.php)
    // agar seluruh RS tidak berbagi satu kuota IP proksi.
    'batas_laju' => [
        'per_ip_per_menit' => (int) env('AUTENTIKASI_BATAS_PER_IP_PER_MENIT', 60),
        'per_nama_pengguna_per_menit' => (int) env('AUTENTIKASI_BATAS_PER_NAMA_PENGGUNA_PER_MENIT', 10),
    ],

    // Batas diam sesi, lihat docs/adr/0002-batas-diam-sesi.md. SESSION_LIFETIME harus
    // lebih besar dari nilai terbesar di sini.
    'sesi' => [
        'batas_diam_menit' => (int) env('AUTENTIKASI_BATAS_DIAM_MENIT', 15),
        'batas_diam_perangkat_terdaftar_menit' => (int) env('AUTENTIKASI_BATAS_DIAM_PERANGKAT_TERDAFTAR_MENIT', 60),
    ],

    // Hanya untuk AkunSintetisSeeder di lokal/staging; kosong = dibuat acak dan dicetak sekali.
    'akun_sintetis_sandi' => env('AKUN_SINTETIS_SANDI'),

];
