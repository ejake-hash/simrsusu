<?php

// Rate limit semua rute api/v1 (PRD 14.1.1 butir 7, 14.2), selain batas masuk di config/autentikasi.php.
// Pengguna yang sudah masuk dihitung per akun, permintaan tanpa sesi per IP. Rute yang wajar dipakai
// cepat (daftar kerja, papan) dilonggarkan dengan:
//   ->withoutMiddleware('throttle:api')->middleware('throttle:api-layar-kerja')
return [
    'api' => [
        'per_pengguna_per_menit' => (int) env('RATE_LIMIT_API_PER_PENGGUNA_PER_MENIT', 120),
        'per_ip_per_menit' => (int) env('RATE_LIMIT_API_PER_IP_PER_MENIT', 120),
    ],

    'layar_kerja' => [
        'per_pengguna_per_menit' => (int) env('RATE_LIMIT_LAYAR_KERJA_PER_PENGGUNA_PER_MENIT', 600),
    ],

    // GET /api/v1/siap untuk penyeimbang beban dan pemantau.
    'siap' => [
        'per_ip_per_menit' => (int) env('RATE_LIMIT_SIAP_PER_IP_PER_MENIT', 60),
    ],
];
