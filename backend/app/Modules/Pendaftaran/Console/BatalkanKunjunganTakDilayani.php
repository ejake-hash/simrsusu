<?php

namespace App\Modules\Pendaftaran\Console;

use App\Modules\Pendaftaran\Application\KelolaKunjungan;
use Illuminate\Console\Command;

/** Tugas terjadwal (FR-01-21, tiket 2.6): batalkan kunjungan yang belum dilayani sampai jam tutup jadwalnya. Aman diulang. */
final class BatalkanKunjunganTakDilayani extends Command
{
    protected $signature = 'pendaftaran:batalkan-kunjungan-tak-dilayani';

    protected $description = 'Membatalkan kunjungan poli berstatus terdaftar yang melewati jam tutup jadwal praktik';

    public function handle(KelolaKunjungan $kelola): int
    {
        $this->info('Kunjungan dibatalkan: '.$kelola->batalkanTakDilayani());

        return self::SUCCESS;
    }
}
