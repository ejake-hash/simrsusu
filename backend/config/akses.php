<?php

// Hak akses (PRD 9.2). Lapis 4 (tiket 1.5): izin label sensitivitas per akun selalu berbatas waktu.
return [
    'label_maks_hari' => (int) env('AKSES_LABEL_MAKS_HARI', 365),
];
