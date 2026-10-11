<?php

namespace App\Modules\Akses\Application;

use App\Core\Http\ApiError;
use App\Modules\Akses\Models\Izin;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\Akses\Models\Peran;
use App\Modules\Akses\Models\PersetujuanAkses;
use Illuminate\Support\Facades\DB;

/**
 * Peran penyetuju (pemilik data) izin sensitif (K3; tiket 1.2d). Mengganti pemilik data mengubah siapa yang boleh
 * menyetujui, jadi selalu lewat persetujuan superadmin lain (K13): satu superadmin tidak bisa menunjuk penyetuju
 * lalu menyetujui lewat peran itu. Penyunting tidak boleh menunjuk peran yang ia pegang. Persetujuan yang sudah
 * menunggu tetap memakai penyetuju saat diajukan.
 */
final class PenyetujuIzin
{
    public function __construct(
        private readonly PenjagaPenyunting $penjaga,
        private readonly AlurPersetujuan $alur,
    ) {}

    /** Izin tanpa perubahan bila peran itu sudah penyetujunya; selain itu pengajuan yang menunggu. */
    public function atur(Pengguna $penyunting, Izin $izin, string $peranId, string $dasar): Izin|PersetujuanAkses
    {
        if (! $izin->sensitif) {
            throw new ApiError(422, 'izin_tidak_sensitif', 'Penyetuju hanya untuk izin sensitif.');
        }
        $peran = Peran::query()->whereKey($peranId)->where('status', 'aktif')->first();
        if ($peran === null) {
            throw new ApiError(422, 'peran_tidak_valid', 'Pilih peran yang ada dan aktif.', [['kolom' => 'peran_id', 'pesan' => 'Peran tidak ada atau nonaktif.']]);
        }
        $this->penjaga->pastikanBukanPeranSendiri($penyunting, $peran->id);
        if ($izin->peran_penyetuju_id === $peran->id) {
            return $izin;
        }

        return DB::transaction(function () use ($penyunting, $izin, $peran, $dasar) {
            Izin::query()->whereKey($izin->id)->lockForUpdate()->first();
            if (PersetujuanAkses::query()->menunggu()->where('objek_jenis', 'penyetuju_izin')->where('izin_id', $izin->id)->exists()) {
                throw new ApiError(409, 'persetujuan_ganda', 'Penggantian penyetuju izin ini masih menunggu persetujuan.');
            }

            return $this->alur->ajukan($penyunting, 'izin_sensitif', 'penyetuju_izin',
                ['izin_id' => $izin->id, 'peran_id' => $peran->id, 'peran_penyetuju_sebelum_id' => $izin->peran_penyetuju_id],
                [$this->alur->peranSuperadmin()], ['izin_id' => $izin->id, 'peran_id' => $peran->id], $dasar, null)[0];
        });
    }
}
