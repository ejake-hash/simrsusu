<?php

// Menu sidebar (PRD 10.4). Menu yang modulnya belum dibangun/aktif tampil sebagai "Segera hadir"
// hanya di pengembangan dan demo; di produksi disembunyikan.
return [
    'tampilkan_segera_hadir' => (bool) env('MENU_TAMPILKAN_SEGERA_HADIR', env('APP_ENV') === 'local'),
];
