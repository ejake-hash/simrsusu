<?php

// Display antrean (tiket 2.8). Aturan panggil/lewati/sisip ada di data induk kunjungan.aturan_antrean, bukan di sini.
return [
    'display' => [
        'interval_detik' => (int) env('ANTREAN_DISPLAY_INTERVAL_DETIK', 5),
        'jumlah_dipanggil' => (int) env('ANTREAN_DISPLAY_JUMLAH_DIPANGGIL', 4),
        'jumlah_berikutnya' => (int) env('ANTREAN_DISPLAY_JUMLAH_BERIKUTNYA', 6),
    ],
];
