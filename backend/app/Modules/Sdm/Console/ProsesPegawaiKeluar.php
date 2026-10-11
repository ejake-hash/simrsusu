<?php

namespace App\Modules\Sdm\Console;

use App\Modules\Sdm\Application\KelolaPegawai;
use Illuminate\Console\Command;

/** Tugas harian (tiket 1.8d, PRD 9.2.6): pegawai yang tanggal keluarnya tercapai → akun nonaktif, sesi diputus. */
final class ProsesPegawaiKeluar extends Command
{
    protected $signature = 'sdm:proses-pegawai-keluar';

    protected $description = 'Menonaktifkan akun pegawai yang tanggal keluarnya sudah tercapai';

    public function handle(KelolaPegawai $kelola): int
    {
        $this->info('Pegawai keluar diproses: '.$kelola->prosesKeluarJatuhTempo());

        return self::SUCCESS;
    }
}
