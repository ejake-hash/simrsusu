<?php

// Rantai hash audit dan titik anchor, lihat docs/adr/0003-rantai-hash-audit.md.
return [

    // Zona waktu untuk tanggal --dari/--sampai verifikasi dan batas jam anchor. Partisi tetap per bulan UTC.
    'zona_waktu' => env('AUDIT_ZONA_WAKTU', 'Asia/Jakarta'),

    // Verifikasi rantai diproses per jendela urutan agar tiap query di bawah statement_timeout simrs_app (5 detik)
    // walau tabel audit berisi jutaan baris (uji 1.12: ±700 ribu baris dalam satu query sudah melewati 5 detik).
    'verifikasi_jendela' => (int) env('AUDIT_VERIFIKASI_JENDELA', 50000),

    'partisi' => [
        'bulan_ke_depan' => (int) env('AUDIT_PARTISI_BULAN_KE_DEPAN', 3),
    ],

    // Hash terakhir tiap jam disalin ke luar database, satu berkas JSON Lines per bulan. Di produksi,
    // arahkan disk ke penyimpanan terpisah yang tidak bisa ditimpa (mis. MinIO dengan object lock)
    // dan channel log ke SIEM.
    'anchor' => [
        'disk' => env('AUDIT_ANCHOR_DISK', 'local'),
        'direktori' => env('AUDIT_ANCHOR_DIREKTORI', 'audit/anchor'),
        'channel_log' => 'audit_anchor',
    ],

];
