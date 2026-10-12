<?php

namespace App\Modules\Integrasi\Application;

use App\Modules\Integrasi\Domain\SistemIntegrasi;
use App\Modules\MasterData\Application\DataIntegrasi;
use App\Modules\MasterData\Application\SuntinganBerversi;
use App\Modules\MasterData\Events\KapasitasTempatTidurBerubah;

/**
 * Pemicu kiriman Aplicares (tiket 1.9 butir 5). Aplicares melaporkan keadaan, bukan riwayat, jadi tidak ada kiriman
 * selama integrasi belum aktif. Keadaan disusulkan oleh sinkron menyeluruh: harian (juga menangkap perubahan TT
 * bertanggal ke depan) atau `php artisan integrasi:sinkron-aplicares` sesudah Aplicares diaktifkan.
 */
final class SinkronAplicares
{
    public function __construct(
        private readonly KotakKeluar $kotakKeluar,
        private readonly KelolaKredensial $kredensial,
        private readonly DataIntegrasi $data,
    ) {}

    /** Kejadian sesudah commit dari modul MasterData. */
    public function kapasitasBerubah(KapasitasTempatTidurBerubah $kejadian): void
    {
        if ($kejadian->mulaiTanggal <= SuntinganBerversi::hariIni() && $this->aktif()) {
            $this->antrekan($kejadian->bangsalId);
        }
    }

    /** @return int jumlah bangsal yang diantrekan */
    public function semua(): int
    {
        if (! $this->aktif()) {
            return 0;
        }
        $bangsal = $this->data->bangsalBerTempatTidur();
        foreach ($bangsal as $id) {
            $this->antrekan($id);
        }

        return count($bangsal);
    }

    private function aktif(): bool
    {
        return $this->kredensial->lingkunganAktif(SistemIntegrasi::Aplicares) !== null;
    }

    private function antrekan(string $bangsalId): void
    {
        $this->kotakKeluar->antrekan(SistemIntegrasi::Aplicares, PenanganAplicares::JENIS, 'bangsal', $bangsalId);
    }
}
