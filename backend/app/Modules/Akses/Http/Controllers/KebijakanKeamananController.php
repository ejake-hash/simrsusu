<?php

namespace App\Modules\Akses\Http\Controllers;

use App\Core\Audit\CatatAktivitas;
use App\Core\Http\ApiError;
use App\Core\Http\IfMatch;
use App\Modules\Akses\Application\KelolaPerangkat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Pengaturan → Keamanan (tiket 1.13): masa percaya perangkat bawaan instalasi. Batas atas dari konfigurasi server, tidak
 * bisa dinaikkan dari layar. Versi = kolom revisi (If-Match).
 */
final class KebijakanKeamananController
{
    public function show(): JsonResponse
    {
        return $this->respons(DB::table('akses.kebijakan_keamanan')->first());
    }

    public function update(Request $request, CatatAktivitas $audit): JsonResponse
    {
        $isi = $request->validate([
            'perangkat_tepercaya_hari' => ['required', 'integer', 'min:0', 'max:32767'],
            'dasar_perubahan' => ['required', 'string', 'min:3', 'max:200'],
        ]);
        $versi = IfMatch::versi($request);
        $maks = KelolaPerangkat::batas()['maks_hari'];
        if ($isi['perangkat_tepercaya_hari'] > $maks) {
            throw new ApiError(422, 'masa_percaya_melebihi_batas', "Masa percaya paling lama {$maks} hari (batas konfigurasi server).", [
                ['kolom' => 'perangkat_tepercaya_hari', 'pesan' => "Paling lama {$maks} hari."],
            ]);
        }

        $baris = DB::transaction(function () use ($request, $isi, $versi, $audit) {
            $lama = DB::table('akses.kebijakan_keamanan')->lockForUpdate()->first();
            if ((string) $lama->revisi !== $versi) {
                throw new ApiError(412, 'versi_berubah', 'Kebijakan sudah diubah orang lain. Muat ulang lalu ulangi.');
            }
            DB::table('akses.kebijakan_keamanan')->where('id', $lama->id)->update([
                'perangkat_tepercaya_hari' => $isi['perangkat_tepercaya_hari'], 'revisi' => $lama->revisi + 1,
                'diubah_pada' => now(), 'diubah_oleh' => $request->user()->id,
            ]);
            $audit->catat('akses.kebijakan_keamanan_diubah', $request->user()->id, 'akses.kebijakan_keamanan', $lama->id,
                ['perangkat_tepercaya_hari' => $isi['perangkat_tepercaya_hari'], 'dasar_perubahan' => $isi['dasar_perubahan']],
                ['perangkat_tepercaya_hari' => $lama->perangkat_tepercaya_hari]);

            return DB::table('akses.kebijakan_keamanan')->where('id', $lama->id)->first();
        });

        return $this->respons($baris);
    }

    private function respons(object $baris): JsonResponse
    {
        $versi = (string) $baris->revisi;

        return (new JsonResponse(['data' => [
            'versi' => $versi,
            'perangkat_tepercaya_hari' => (int) $baris->perangkat_tepercaya_hari,
            'batas' => KelolaPerangkat::batas(),
            // Hanya bila pernah diubah dari layar; nilai awal dari migrasi tidak punya waktu ubah yang berarti.
            'diubah_pada' => (int) $baris->revisi > 0 ? Carbon::parse($baris->diubah_pada)->toIso8601String() : null,
        ]]))->setEtag($versi);
    }
}
