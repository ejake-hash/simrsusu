<?php

namespace App\Core\Instalasi;

/**
 * Penjaga `instalasi.siap_melayani` (tiket 1.8f, FR-54-08): true sesudah langkah penyiapan wajib lengkap. Dipakai modul
 * lain untuk aturan "sesudah melayani pasien" (mis. perubahan ruang/TT harus lewat usulan, 1.8c).
 */
interface StatusInstalasi
{
    public function siapMelayani(): bool;
}
