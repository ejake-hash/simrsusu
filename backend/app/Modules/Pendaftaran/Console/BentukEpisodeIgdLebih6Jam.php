<?php

namespace App\Modules\Pendaftaran\Console;

use App\Modules\Pendaftaran\Application\BentukEpisodeIgd;
use Illuminate\Console\Command;

/** Tugas terjadwal (tiket 2.23, 7.00.2 baris 3): pindahkan kunjungan IGD > 6 jam ke episode `igd_lebih_6_jam`. Aman diulang. */
final class BentukEpisodeIgdLebih6Jam extends Command
{
    protected $signature = 'kunjungan:episode-igd';

    protected $description = 'Memindahkan kunjungan IGD aktif yang lewat 6 jam sejak tiba, beserta aktivitasnya, ke episode IGD > 6 jam';

    public function handle(BentukEpisodeIgd $bentuk): int
    {
        $this->info('Kunjungan IGD dipindah: '.$bentuk->jalankan());

        return self::SUCCESS;
    }
}
