<?php

// Rantai hash audit dan titik jangkar, lihat docs/adr/0003-rantai-hash-audit.md.
return [

    // Zona waktu untuk "hari" pada --dari/--sampai dan jangkar harian. Partisi tetap per bulan UTC.
    'zona_waktu' => env('AUDIT_ZONA_WAKTU', 'Asia/Jakarta'),

    'partisi' => [
        'bulan_ke_depan' => (int) env('AUDIT_PARTISI_BULAN_KE_DEPAN', 3),
    ],

    // Hash terakhir per hari disalin ke luar database. Di produksi, arahkan disk ke penyimpanan
    // terpisah yang tidak bisa ditimpa (mis. MinIO dengan object lock) dan channel log ke SIEM.
    'jangkar' => [
        'disk' => env('AUDIT_JANGKAR_DISK', 'local'),
        'berkas' => env('AUDIT_JANGKAR_BERKAS', 'audit/jangkar-rantai.jsonl'),
        'channel_log' => 'audit_jangkar',
    ],

];
