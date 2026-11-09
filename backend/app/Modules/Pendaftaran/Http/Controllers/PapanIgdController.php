<?php

namespace App\Modules\Pendaftaran\Http\Controllers;

use App\Core\Http\EtagIsi;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\Pendaftaran\Application\BacaPapanIgd;
use App\Modules\Pendaftaran\Application\TempatkanAreaIgd;
use App\Modules\Sdm\Application\RingkasanPegawai;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /api/v1/igd/papan dan PUT /api/v1/kunjungan/{id}/area-igd (kontrak v0.34.0, tiket 2.25). Izin igd.papan.{lihat,input}
 * cakupan rs diperiksa middleware rute (can.rs) sebelum isian dibaca. Papan dipolling (K1, sampai Reverb): ETag = sidik isi
 * tanpa `meta.waktu_server`, If-None-Match sama → 304; selalu `no-store`.
 */
final class PapanIgdController
{
    public function index(Request $request, BacaPapanIgd $papan): Response
    {
        $filter = $request->validate(['area_id' => ['nullable', 'uuid']]);
        $isi = $papan->baca($filter['area_id'] ?? null);
        $meta = ['area' => $isi['area'], 'aturan' => $isi['aturan'], 'terpotong' => $isi['terpotong']];

        $respons = new JsonResponse(['data' => $isi['baris'], 'meta' => $meta]);
        $hasil = EtagIsi::terapkan($request, $respons);
        if ($hasil === $respons) {
            $respons->setData(['data' => $isi['baris'], 'meta' => [...$meta, 'waktu_server' => now()->toIso8601String()]]);
        }
        $hasil->headers->set('Cache-Control', 'no-store');

        return $hasil;
    }

    public function tempatkan(Request $request, string $kunjungan, TempatkanAreaIgd $kelola, RingkasanPegawai $pegawai): JsonResponse
    {
        $isi = $request->validate(['area_igd_id' => ['required', 'uuid']]);
        /** @var Pengguna $petugas */
        $petugas = $request->user();
        $baris = $kelola->tempatkan($petugas, $kunjungan, $isi['area_igd_id']);

        $area = DB::table('inti.area_igd')->where('id', $baris->area_igd_id)->first(['id', 'kode', 'nama']);
        // Penempatan ke area yang sama tidak mengubah baris: pelaku = penempat terakhir, belum tentu akun ini.
        $pelaku = $baris->diubah_oleh === null ? null : Pengguna::query()->find($baris->diubah_oleh, ['id', 'pegawai_id', 'nama_pengguna']);
        $nama = $pelaku?->pegawai_id === null ? [] : $pegawai->nama([$pelaku->pegawai_id]);

        return (new JsonResponse(['data' => [
            'kunjungan_id' => $baris->kunjungan_id,
            'area' => ['id' => $area->id, 'kode' => $area->kode, 'nama' => $area->nama],
            'ditempatkan_pada' => Carbon::parse($baris->ditempatkan_pada)->toIso8601String(),
            'ditempatkan_oleh' => $pelaku === null ? null : ['id' => $pelaku->id, 'nama' => $nama[$pelaku->pegawai_id] ?? $pelaku->nama_pengguna],
        ]]))->header('Cache-Control', 'no-store');
    }
}
