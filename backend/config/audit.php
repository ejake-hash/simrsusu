<?php

// Rantai hash audit dan titik jangkar, lihat docs/adr/0003-rantai-hash-audit.md.
return [

    // Zona waktu untuk tanggal --dari/--sampai verifikasi dan batas jam jangkar. Partisi tetap per bulan UTC.
    'zona_waktu' => env('AUDIT_ZONA_WAKTU', 'Asia/Jakarta'),

    'partisi' => [
        'bulan_ke_depan' => (int) env('AUDIT_PARTISI_BULAN_KE_DEPAN', 3),
    ],

    // Hash terakhir tiap jam disalin ke luar database, satu berkas JSON Lines per bulan. Di produksi,
    // arahkan disk ke penyimpanan terpisah yang tidak bisa ditimpa (mis. MinIO dengan object lock)
    // dan channel log ke SIEM.
    'jangkar' => [
        'disk' => env('AUDIT_JANGKAR_DISK', 'local'),
        'direktori' => env('AUDIT_JANGKAR_DIREKTORI', 'audit/jangkar'),
        'channel_log' => 'audit_jangkar',
    ],

];
