<?php

return [
    // Kunci khusus kredensial integrasi (K9), `base64:<32 bita>`, dari env/vault; bukan APP_KEY. Kosong = kredensial tidak
    // bisa disimpan atau dibaca (503 kredensial_kunci_belum_dikonfigurasi). Rotasi: kunci lama dipindah ke
    // KREDENSIAL_KUNCI_LAMA (dipisah koma), lalu `php artisan integrasi:rotasi-kunci`.
    'kunci' => env('KREDENSIAL_KUNCI'),
    'kunci_lama' => env('KREDENSIAL_KUNCI_LAMA'),

    // Simulator dalam repo (K8): semua panggilan HTTP ke sistem luar dijawab simulator, tidak ada yang keluar jaringan.
    // Hanya untuk lokal, CI, dan staging tanpa kredensial; selalu mati di produksi.
    'simulator' => (bool) env('INTEGRASI_SIMULATOR', false),

    // Alamat sistem luar per lingkungan. Tidak bisa diubah lewat API (SSRF). Nilai bawaan dari catatan lapangan
    // (docs/integrasi/catatan-lapangan-bridging.md §1.2, §2, §15.1, §24): **perlu verifikasi** ke dokumen resmi
    // BPJS/Kemenkes sebelum produksi.
    'alamat' => [
        'bpjs_vclaim' => [
            'sandbox' => env('INTEGRASI_VCLAIM_SANDBOX', 'https://apijkn-dev.bpjs-kesehatan.go.id/vclaim-rest-dev'),
            'produksi' => env('INTEGRASI_VCLAIM_PRODUKSI', 'https://apijkn.bpjs-kesehatan.go.id/vclaim-rest'),
        ],
        'aplicares' => [
            'sandbox' => env('INTEGRASI_APLICARES_SANDBOX'),
            'produksi' => env('INTEGRASI_APLICARES_PRODUKSI'),
        ],
        'satusehat' => [
            'sandbox' => env('INTEGRASI_SATUSEHAT_SANDBOX', 'https://api-satusehat-stg.dto.kemkes.go.id'),
            'produksi' => env('INTEGRASI_SATUSEHAT_PRODUKSI', 'https://api-satusehat.kemkes.go.id'),
        ],
    ],

    // Batas waktu satu panggilan HTTP (detik). Uji koneksi memakai batas sendiri.
    'batas_waktu' => (int) env('INTEGRASI_BATAS_WAKTU', 20),
    'batas_waktu_uji' => 15,

    // Retry eksponensial: tunggu = dasar × 2^(percobaan-1) menit, paling lama `tunggu_maks`; sesudah `maks_percobaan`
    // kiriman berstatus gagal (kirim ulang manual).
    'maks_percobaan' => (int) env('INTEGRASI_MAKS_PERCOBAAN', 8),
    'tunggu_dasar_menit' => 1,
    'tunggu_maks_menit' => 360,

    // Circuit breaker per sistem: terbuka sesudah `ambang` kegagalan sistem berturut-turut, lalu satu percobaan
    // (setengah terbuka) sesudah `buka_detik`; gagal lagi → durasi berlipat, paling lama `buka_maks_detik`.
    'circuit_breaker' => [
        'ambang' => (int) env('INTEGRASI_CB_AMBANG', 5),
        'buka_detik' => (int) env('INTEGRASI_CB_BUKA_DETIK', 60),
        'buka_maks_detik' => 900,
    ],

    // Kiriman per putaran pekerja per sistem.
    'per_putaran' => 100,

    // Masa simpan log integrasi.log_api (hari); partisi bulanan yang seluruhnya lebih tua dihapus.
    'log_hari' => (int) env('INTEGRASI_LOG_HARI', 90),
    // Isi permintaan/respons per baris log paling banyak (bita), sesudah disamarkan.
    'log_maks_bita' => 16384,
];
