<?php

namespace App\Modules\RawatInap\Http\Controllers;

use App\Core\Http\ApiError;
use App\Core\Http\EtagIsi;
use App\Modules\Akses\Application\AccessEvaluator;
use App\Modules\Akses\Domain\Cakupan;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\MasterData\Application\PohonUnit;
use App\Modules\RawatInap\Application\BacaPetaTempatTidur;
use App\Modules\RawatInap\Application\UbahStatusTempatTidur;
use App\Modules\RawatInap\Domain\StatusTempatTidur;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /api/v1/peta-tempat-tidur dan POST /api/v1/tempat-tidur/{id}/status (kontrak v0.35.0, tiket 2.27). Izin sub-menu
 * rawat_inap.peta_tempat_tidur (lihat / perbaiki) diperiksa middleware rute di cakupan mana pun; cakupan data = unit
 * organisasi bangsal (lapis 3, cakupan unit memuat turunannya): GET menyaring bangsal yang terjangkau, POST menolak TT di
 * bangsal di luar cakupan (403). Peta dipolling: ETag = sidik isi, If-None-Match sama → 304; selalu `no-store`.
 */
final class PetaTempatTidurController
{
    private const IZIN = 'rawat_inap.peta_tempat_tidur';

    private const IZIN_PINDAH_RUANG = 'rawat_inap.pindah_ruang.input';

    public function index(Request $request, BacaPetaTempatTidur $peta, AccessEvaluator $evaluator, PohonUnit $pohon): Response
    {
        $filter = $request->validate(['bangsal_id' => ['nullable', 'uuid'], 'gedung_id' => ['nullable', 'uuid']]);
        /** @var Pengguna $pengguna */
        $pengguna = $request->user();
        $akar = $evaluator->unitTerjangkau($pengguna, self::IZIN.'.lihat');
        // v0.38.0: id kunjungan RI hanya untuk bangsal di cakupan izin pindah ruang (null = seluruh RS, [] = tidak dikirim).
        $akarPindah = $evaluator->unitTerjangkau($pengguna, self::IZIN_PINDAH_RUANG);
        $isi = $peta->baca([
            'bangsal_id' => $filter['bangsal_id'] ?? null, 'gedung_id' => $filter['gedung_id'] ?? null,
        ], $akar === null ? null : $pohon->subpohon($akar), $akarPindah === null ? null : ($akarPindah === [] ? [] : $pohon->subpohon($akarPindah)));

        $hasil = EtagIsi::terapkan($request, new JsonResponse(['data' => $isi['baris'], 'meta' => ['terpotong' => $isi['terpotong']]]));
        $hasil->headers->set('Cache-Control', 'no-store');

        return $hasil;
    }

    public function ubahStatus(Request $request, string $tempatTidur, BacaPetaTempatTidur $peta, UbahStatusTempatTidur $ubah): JsonResponse
    {
        $unit = DB::table('inti.tempat_tidur as tt')->join('inti.kamar as km', 'km.id', '=', 'tt.kamar_id')
            ->join('inti.bangsal as b', 'b.id', '=', 'km.bangsal_id')->where('tt.id', $tempatTidur)->value('b.unit_organisasi_id')
            ?? throw new ApiError(404, 'tidak_ditemukan', 'Tempat tidur tidak ditemukan.');
        Gate::authorize(self::IZIN.'.perbaiki', new Cakupan('unit', (string) $unit));
        $isi = $request->validate(['status' => ['required', 'string', Rule::in(array_keys(StatusTempatTidur::TRANSISI_PETUGAS))]]);

        /** @var Pengguna $petugas */
        $petugas = $request->user();
        $ubah->olehPetugas($tempatTidur, $isi['status'], $petugas->id);
        $baris = $peta->satu($tempatTidur) ?? throw new ApiError(404, 'tidak_ditemukan', 'Tempat tidur tidak ditemukan.');

        return (new JsonResponse(['data' => $baris]))->header('Cache-Control', 'no-store');
    }
}
