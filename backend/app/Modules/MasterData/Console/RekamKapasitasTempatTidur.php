<?php

namespace App\Modules\MasterData\Console;

use App\Modules\MasterData\Application\KapasitasTempatTidur;
use App\Modules\MasterData\Application\SuntinganBerversi;
use Illuminate\Console\Command;

/** Tugas harian (tiket 1.8b, FR-08-18): rekam kapasitas TT hari ini per bangsal dan kelas. Aman diulang. */
final class RekamKapasitasTempatTidur extends Command
{
    protected $signature = 'master-data:rekam-kapasitas-tt {--tanggal= : Tanggal (Y-m-d), bawaan hari ini zona RS}';

    protected $description = 'Merekam kapasitas tempat tidur harian (penyebut BOR/BTO/TOI)';

    public function handle(KapasitasTempatTidur $kapasitas): int
    {
        $tanggal = (string) ($this->option('tanggal') ?: SuntinganBerversi::hariIni());
        if ($tanggal < SuntinganBerversi::hariIni()) {
            $this->error('Rekaman hari yang sudah lewat tidak diubah.');

            return self::FAILURE;
        }
        $this->info('Baris kapasitas direkam: '.$kapasitas->rekam($tanggal));

        return self::SUCCESS;
    }
}
