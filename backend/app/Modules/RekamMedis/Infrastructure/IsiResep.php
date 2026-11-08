<?php

namespace App\Modules\RekamMedis\Infrastructure;

use App\Modules\Akses\Models\Pengguna;
use App\Modules\RekamMedis\Application\KelolaResep;
use App\Modules\RekamMedis\Models\Resep;
use App\Modules\Ttd\Application\PenyediaIsiTtd;
use Illuminate\Support\Facades\DB;

/** Isi kanonik resep (tiket 2.17): butir + depo + racikan + kunjungan, ditandatangani dokter jadwal sekali saat disahkan. */
final class IsiResep implements PenyediaIsiTtd
{
    public function jenis(): string
    {
        return 'resep';
    }

    public function isi(string $id): ?array
    {
        $r = Resep::query()->find($id);
        if ($r === null) {
            return null;
        }
        $butir = DB::table('farmasi.resep_item')->where('resep_id', $id)->whereNull('diganti_pada')->orderBy('urutan')
            ->get(['barang_id', 'dosis', 'frekuensi_per_hari', 'jumlah_per_pakai', 'hari', 'jumlah', 'rute', 'aturan_pakai', 'catatan'])
            ->map(fn ($b) => (array) $b)->all();

        return ['id' => $r->id, 'no_resep' => $r->no_resep, 'kunjungan_id' => $r->kunjungan_id, 'depo_id' => $r->depo_id,
            'racikan' => $r->racikan, 'catatan' => $r->catatan, 'butir' => $butir];
    }

    public function alasanTidakBoleh(Pengguna $penandaTangan, string $id): ?array
    {
        $r = Resep::query()->find($id);
        if ($r === null || ! KelolaResep::terlihat($penandaTangan, $r)) {
            return ['tidak_ditemukan', 'Resep tidak ditemukan.'];
        }
        if (! KelolaResep::dokterJadwal($penandaTangan, $r->kunjungan_id)) {
            return ['bukan_pemutus', 'Resep hanya bisa ditandatangani dokter jadwal kunjungan.'];
        }

        return $r->tanda_tangan_id === null ? null : ['belum_diputuskan', 'Resep sudah ditandatangani.'];
    }

    public function terlihat(Pengguna $pengguna, string $id): bool
    {
        $r = Resep::query()->find($id);

        return $r !== null && KelolaResep::terlihat($pengguna, $r);
    }
}
