<?php

namespace App\Modules\RekamMedis\Infrastructure;

use App\Modules\Akses\Models\Pengguna;
use App\Modules\RekamMedis\Application\AksesCatatan;
use App\Modules\RekamMedis\Models\CatatanKlinis;
use App\Modules\Ttd\Application\PenyediaIsiTtd;
use Illuminate\Support\Facades\DB;

/** Isi kanonik adendum CPPT (tiket 2.14): ditandatangani penulisnya sekali, di dalam transaksi pembuatannya. */
final class IsiAdendumCatatan implements PenyediaIsiTtd
{
    public function __construct(private readonly AksesCatatan $akses) {}

    public function jenis(): string
    {
        return 'catatan_klinis_adendum';
    }

    public function isi(string $id): ?array
    {
        $a = DB::table('klinis.catatan_klinis_adendum')->where('id', $id)->first(['id', 'catatan_klinis_id', 'penulis_id', 'alasan', 'isi']);

        return $a === null ? null : (array) $a;
    }

    public function alasanTidakBoleh(Pengguna $penandaTangan, string $id): ?array
    {
        $a = DB::table('klinis.catatan_klinis_adendum')->where('id', $id)->first(['catatan_klinis_id', 'penulis_id', 'tanda_tangan_id']);
        if ($a === null || ! $this->terlihat($penandaTangan, $id)) {
            return ['tidak_ditemukan', 'Adendum tidak ditemukan.'];
        }
        if ($a->penulis_id !== $penandaTangan->id) {
            return ['bukan_pemutus', 'Adendum hanya bisa ditandatangani penulisnya.'];
        }

        return $a->tanda_tangan_id === null ? null : ['belum_diputuskan', 'Adendum sudah ditandatangani.'];
    }

    public function terlihat(Pengguna $pengguna, string $id): bool
    {
        $c = CatatanKlinis::query()->whereIn('id', DB::table('klinis.catatan_klinis_adendum')->where('id', $id)->select('catatan_klinis_id'))->first();

        return $c !== null && $this->akses->terlihat($pengguna, $c) && $this->akses->terbuka($pengguna, $c);
    }
}
