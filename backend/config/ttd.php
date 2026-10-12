<?php

return [
    // Kunci server (lada PIN), `base64:<32 bita>`, dari env/vault; tidak pernah di DB. Kosong = TTE tidak bisa dipakai.
    // Rotasi: kunci baru di TTD_KUNCI, kunci lama dipindah ke TTD_KUNCI_LAMA (dipisah koma) sampai semua PIN diganti.
    'kunci' => env('TTD_KUNCI'),
    'kunci_lama' => env('TTD_KUNCI_LAMA'),
    // Biaya Argon2id turunan kunci PIN: `interactive` (bawaan libsodium) atau `minimum` (hanya untuk tes).
    'kdf' => env('TTD_KDF', 'interactive'),

    // Aturan PIN T1 (PRD 7.57.2): angka bawaan dari PRD; perlu verifikasi kebijakan RS.
    'maks_pin_salah' => (int) env('TTD_MAKS_PIN_SALAH', 5),
    'kunci_menit' => (int) env('TTD_KUNCI_MENIT', 15),
    // Sesi tanda tangan: sesudah PIN benar, penandatanganan berikutnya di sesi login yang sama tanpa PIN.
    'sesi_menit' => (int) env('TTD_SESI_MENIT', 15),
    // Hanya untuk TtdSintetisSeeder di lokal/staging/e2e; kosong = PIN acak dicetak sekali.
    'pin_sintetis' => env('AKUN_SINTETIS_TTD_PIN'),
];
