<?php

namespace App\Modules\RekamMedis\Application;

use App\Core\Audit\CatatAktivitas;
use App\Core\Http\ApiError;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\RekamMedis\Models\Alergi;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Alergi pasien (tiket 2.13, FR-26-15, kontrak v0.23.0). Tidak dihapus: batal + alasan. Audit hanya id. */
final class KelolaAlergi
{
    public function __construct(private readonly CatatAktivitas $audit) {}

    /** @param  array<string, mixed>  $isi */
    public function catat(Pengguna $oleh, string $pasienId, array $isi): Alergi
    {
        if (($isi['barang_id'] ?? null) !== null && ! DB::table('logistik.barang')->where('id', $isi['barang_id'])->where('status', 'aktif')->exists()) {
            throw ValidationException::withMessages(['barang_id' => 'Barang tidak ditemukan atau nonaktif.']);
        }

        return DB::transaction(function () use ($oleh, $pasienId, $isi) {
            $alergi = new Alergi([
                'pasien_id' => $pasienId, 'zat' => trim($isi['zat']), 'jenis' => $isi['jenis'], 'barang_id' => $isi['barang_id'] ?? null,
                'reaksi' => $isi['reaksi'] ?? null, 'tingkat' => $isi['tingkat'] ?? null, 'dibuat_oleh' => $oleh->id, 'diubah_oleh' => $oleh->id,
            ]);
            try {
                $alergi->save();
            } catch (QueryException $e) {
                throw $e->getCode() === '23505' ? new ApiError(409, 'alergi_sudah_tercatat', 'Alergi zat ini sudah tercatat untuk pasien.') : $e;
            }
            $this->audit->catat('klinis.alergi_dicatat', $oleh->id, $alergi->getTable(), $alergi->id, ['pasien_id' => $pasienId]);

            return $alergi->refresh();
        });
    }

    public function batal(Pengguna $oleh, string $id, string $alasan): Alergi
    {
        return DB::transaction(function () use ($oleh, $id, $alasan) {
            $alergi = Alergi::query()->whereKey($id)->lockForUpdate()->firstOrFail();
            if ($alergi->status === 'batal') {
                throw new ApiError(409, 'sudah_batal', 'Catatan alergi ini sudah dibatalkan.');
            }
            $alergi->forceFill(['status' => 'batal', 'alasan_batal' => $alasan, 'dibatalkan_oleh' => $oleh->id, 'dibatalkan_pada' => now(),
                'diubah_oleh' => $oleh->id, 'revisi' => $alergi->revisi + 1])->save();
            $this->audit->catat('klinis.alergi_dibatalkan', $oleh->id, $alergi->getTable(), $alergi->id, ['pasien_id' => $alergi->pasien_id]);

            return $alergi->refresh();
        });
    }
}
