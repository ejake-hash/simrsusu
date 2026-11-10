<?php

namespace App\Modules\RawatInap\Http\Controllers;

use App\Core\Http\ApiError;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\RawatInap\Application\BacaRawatInap;
use App\Modules\RawatInap\Application\PindahRuang;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Pindah ruang/kelas pasien rawat inap (kontrak v0.37.0, tiket 2.29; FR-08-06): POST /kunjungan/{id}/pindah-ruang. Izin =
 * izin sel matriks rawat_inap → rawat_inap, diperiksa PindahRuang sebelum isian dibaca. Respons `no-store`.
 */
final class RawatInapController
{
    public function pindahRuang(Request $request, string $kunjungan, PindahRuang $layanan, BacaRawatInap $baca): JsonResponse
    {
        /** @var Pengguna $dokter */
        $dokter = $request->user();
        $layanan->izinkan($dokter, $kunjungan);
        $isi = $request->validate([
            'tempat_tidur_id' => ['required', 'string'],
            'alasan' => ['required', 'string', 'min:5', 'max:1000'],
            'serah_terima' => ['required', 'string', 'min:5', 'max:4000'],
        ]);
        // Id dari isian: bukan UUID → 422, bukan error basis data.
        if (! Str::isUuid($isi['tempat_tidur_id'])) {
            throw new ApiError(422, 'validasi_gagal', 'Isian belum benar.', [['kolom' => 'tempat_tidur_id', 'pesan' => 'Pilih tempat tidur dari peta.']]);
        }
        $layanan->pindahkan($dokter, $kunjungan, $isi);

        return (new JsonResponse(['data' => $baca->satu($kunjungan)]))->header('Cache-Control', 'no-store');
    }
}
