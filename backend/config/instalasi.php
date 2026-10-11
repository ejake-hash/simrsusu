<?php

// Sifat instalasi ini (PRD 3.4). Ditetapkan saat pemasangan di server, bukan dari layar.
return [
    // Instalasi demo/pelatihan berisi data sintetis saja; setiap layar diberi pita "DEMO — data fiktif"
    // (FR-54-10), dikirim GET /profil-rs. Sengaja tidak diturunkan dari APP_ENV.
    'demo' => (bool) env('INSTALASI_DEMO', false),

    // Zona waktu RS untuk tanggal berlaku dan jadwal harian (mis. berlaku_dari unit = hari ini di RS).
    'zona_waktu' => env('INSTALASI_ZONA_WAKTU', 'Asia/Jakarta'),

    // Berkas paket identitas (logo, motif, foto; tiket 1.8a). Produksi: disk S3/MinIO (PRD 10.6); bawaan disk
    // lokal privat. Batas ukuran dalam KB.
    'identitas' => [
        'disk' => env('IDENTITAS_DISK', 'local'),
        'batas_kb' => [
            'logo' => (int) env('IDENTITAS_BATAS_LOGO_KB', 200),
            'motif' => (int) env('IDENTITAS_BATAS_MOTIF_KB', 500),
            'foto' => (int) env('IDENTITAS_BATAS_FOTO_KB', 2048),
        ],
    ],
];
