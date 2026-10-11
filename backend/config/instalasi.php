<?php

// Sifat instalasi ini (PRD 3.4). Instalasi demo/pelatihan berisi data sintetis saja dan setiap layar
// diberi pita "DEMO — data fiktif" (FR-54-10). Sengaja tidak diturunkan dari APP_ENV: staging bisa
// berisi uji data nyata yang disamarkan, dan demo publik bisa berjalan dengan APP_ENV=production.
return [
    'demo' => (bool) env('INSTALASI_DEMO', false),
];
