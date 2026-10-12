<?php

namespace App\Core\Instalasi;

/**
 * Syarat langkah 8 penyiapan (`kredensial`, kontrak v0.12.0/v0.13.0), diisi modul Integrasi (tiket 1.9): setiap sistem
 * fase 1 punya lingkungan aktif yang lengkap dan uji koneksi terakhir lingkungan itu berhasil.
 */
interface SyaratKredensialIntegrasi
{
    /** @return array{terpenuhi: bool, keterangan: string} */
    public function periksa(): array;
}
