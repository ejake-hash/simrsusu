<?php

namespace App\Modules\MasterData\Application;

use App\Core\Http\ApiError;
use App\Modules\Sdm\Models\Pegawai;

/** Kepala unit / ketua KSM harus pegawai yang ada dan belum keluar (tiket 1.8d). */
final class PegawaiPimpinan
{
    public static function pastikan(string $pegawaiId, string $kolom): void
    {
        $pegawai = Pegawai::query()->find($pegawaiId);
        if ($pegawai === null || $pegawai->statusKepegawaian() === 'keluar') {
            throw new ApiError(422, 'pegawai_tidak_valid', 'Pegawai tidak ada atau sudah keluar.', [['kolom' => $kolom, 'pesan' => 'Pilih pegawai yang masih bekerja.']]);
        }
    }
}
