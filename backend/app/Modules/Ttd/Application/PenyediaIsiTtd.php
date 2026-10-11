<?php

namespace App\Modules\Ttd\Application;

use App\Modules\Akses\Models\Pengguna;

/**
 * Isi kanonik dan aturan tanda tangan satu jenis objek (tiket 1.6). Isi dihitung server dari objeknya, tidak pernah
 * dari klien. Implementasi didaftarkan di ModuleServiceProvider modul Ttd per `jenis()`.
 */
interface PenyediaIsiTtd
{
    /** Nilai objek_jenis, mis. persetujuan_akses. */
    public function jenis(): string;

    /**
     * Isi yang ditandatangani (diurutkan IsiKanonik); null bila objek tidak ada.
     *
     * @return array<string, mixed>|null
     */
    public function isi(string $id): ?array;

    /**
     * [kode, pesan] bila akun ini tidak boleh menandatangani objek ini sekarang; null = boleh. Kode: tidak_ditemukan
     * (termasuk objek yang tidak boleh dilihat), belum_diputuskan, bukan_pemutus.
     *
     * @return array{0: string, 1: string}|null
     */
    public function alasanTidakBoleh(Pengguna $penandaTangan, string $id): ?array;

    /** Akun ini boleh melihat objek (dan karena itu verifikasinya). */
    public function terlihat(Pengguna $pengguna, string $id): bool;
}
