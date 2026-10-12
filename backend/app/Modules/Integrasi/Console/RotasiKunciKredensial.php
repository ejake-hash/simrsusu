<?php

namespace App\Modules\Integrasi\Console;

use App\Modules\Integrasi\Infrastructure\PenyandiKredensial;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * `php artisan integrasi:rotasi-kunci` (K9): enkripsi ulang semua kredensial dengan KREDENSIAL_KUNCI aktif. Kunci lama
 * harus ada di KREDENSIAL_KUNCI_LAMA selama rotasi; sesudah selesai kunci lama boleh dibuang.
 */
final class RotasiKunciKredensial extends Command
{
    protected $signature = 'integrasi:rotasi-kunci';

    protected $description = 'Enkripsi ulang kredensial integrasi dengan KREDENSIAL_KUNCI aktif (sesudah rotasi kunci)';

    public function handle(PenyandiKredensial $penyandi): int
    {
        if (! $penyandi->siap()) {
            $this->error('KREDENSIAL_KUNCI belum dikonfigurasi.');

            return self::FAILURE;
        }
        $n = 0;
        foreach (DB::table('integrasi.kredensial_api')->whereNotNull('rahasia')->where('id_kunci', '<>', $penyandi->idAktif())->get(['id', 'rahasia', 'id_kunci']) as $b) {
            $sandi = $penyandi->sandikan($penyandi->buka($b->rahasia, $b->id_kunci));
            DB::table('integrasi.kredensial_api')->where('id', $b->id)->update(['rahasia' => $sandi['teks'], 'id_kunci' => $sandi['id_kunci'], 'diubah_pada' => now()]);
            $n++;
        }
        $this->info("$n kredensial dienkripsi ulang.");

        return self::SUCCESS;
    }
}
