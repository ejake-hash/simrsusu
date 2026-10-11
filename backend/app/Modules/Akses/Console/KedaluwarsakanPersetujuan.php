<?php

namespace App\Modules\Akses\Console;

use App\Modules\Akses\Application\AlurPersetujuan;
use Illuminate\Console\Command;

/**
 * Tugas harian (tiket 1.2d): persetujuan akses yang lewat 7 hari tanpa keputusan menjadi kedaluwarsa. Aman diulang.
 * Keputusan atas pengajuan yang sudah lewat batas tetap ditolak walau tugas ini belum jalan (AlurPersetujuan).
 */
final class KedaluwarsakanPersetujuan extends Command
{
    protected $signature = 'akses:kedaluwarsakan-persetujuan';

    protected $description = 'Menandai persetujuan akses yang lewat batas waktu sebagai kedaluwarsa';

    public function handle(AlurPersetujuan $alur): int
    {
        $this->info('Persetujuan kedaluwarsa: '.$alur->kedaluwarsakan());

        return self::SUCCESS;
    }
}
