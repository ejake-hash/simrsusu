<?php

return [
    // Izin praktik "akan habis" bila berlaku_sampai dalam sekian hari (FR-40-01 memberi contoh 30/60; perlu verifikasi
    // kebijakan RS).
    'izin_praktik_hari_peringatan' => (int) env('SDM_IZIN_PRAKTIK_HARI_PERINGATAN', 60),

    // Dokumen izin praktik: disk privat (bukan public), batas ukuran dalam KB.
    'dokumen_disk' => env('SDM_DOKUMEN_DISK', 'local'),
    'dokumen_batas_kb' => (int) env('SDM_DOKUMEN_BATAS_KB', 5120),
];
