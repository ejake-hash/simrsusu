<?php

namespace App\Modules\Ttd\Http\Controllers;

use App\Core\Http\ApiError;
use App\Modules\Akses\Domain\Cakupan;
use App\Modules\Akses\Http\Resources\PersetujuanPresenter;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\Ttd\Application\KelolaTtd;
use App\Modules\Ttd\Application\Penandatangan;
use App\Modules\Ttd\Http\Resources\TandaTanganResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Tanda tangan elektronik T1 (kontrak v0.8.0, tiket 1.6). Penanda tangan selalu akun yang masuk. Respons yang
 * menyangkut PIN tidak disimpan di cache peramban/proksi (no-store).
 */
final class TtdController
{
    private const PIN = ['required', 'string', 'max:20'];

    public function __construct(
        private readonly KelolaTtd $kelola,
        private readonly Penandatangan $penandatangan,
    ) {}

    public function aktivasi(Request $request): JsonResponse
    {
        $isi = $request->validate(['sandi' => ['required', 'string', 'max:200'], 'pin' => self::PIN, 'pin_ulang' => self::PIN]);
        $this->kelola->aktivasi($request, $request->user(), $isi['sandi'], $isi['pin'], $isi['pin_ulang']);

        return $this->tanpaSimpan(new JsonResponse(['data' => $this->kelola->untuk($request, $request->user())], 201));
    }

    public function gantiPin(Request $request): JsonResponse
    {
        $isi = $request->validate(['pin_lama' => self::PIN, 'pin_baru' => self::PIN, 'pin_ulang' => self::PIN]);
        $this->kelola->gantiPin($request, $request->user(), $isi['pin_lama'], $isi['pin_baru'], $isi['pin_ulang']);

        return $this->tanpaSimpan(new JsonResponse(['data' => $this->kelola->untuk($request, $request->user())]));
    }

    public function reset(Request $request, PersetujuanPresenter $presenter, string $pengguna): JsonResponse
    {
        abort_unless(Gate::allows('sdm.kredensial.perbaiki', Cakupan::rs()), 403);
        $isi = $request->validate(['alasan' => ['required', 'string', 'min:5', 'max:500'], 'dasar' => ['required', 'string', 'min:3', 'max:200']]);
        $pemilik = Pengguna::query()->find($pengguna) ?? throw new ApiError(404, 'tidak_ditemukan', 'Data tidak ditemukan.');
        $p = $this->kelola->ajukanReset($request->user(), $pemilik, $isi['alasan'], $isi['dasar']);

        return new JsonResponse(['data' => $presenter->satu($p->refresh(), $request->user())], 202);
    }

    public function tandatangani(Request $request): JsonResponse
    {
        $isi = $request->validate([
            'objek' => ['required', 'array', 'min:1', 'max:50'],
            'objek.*' => ['required', 'array:jenis,id'],
            'objek.*.jenis' => ['required', 'string', 'in:persetujuan_akses'],
            'objek.*.id' => ['required', 'uuid', 'distinct:ignore_case'],
            'pin' => ['nullable', 'string', 'max:20'],
            'pernyataan_disetujui' => ['required', 'boolean', 'accepted'],
        ]);
        $objek = array_map(fn (array $o) => ['jenis' => (string) $o['jenis'], 'id' => strtolower((string) $o['id'])], array_values($isi['objek']));
        [$hasil, $sesiSampai] = $this->penandatangan->tandatangani($request, $request->user(), $objek, $isi['pin'] ?? null);

        return $this->tanpaSimpan(new JsonResponse([
            'data' => TandaTanganResource::daftar($hasil),
            'meta' => ['sesi_berlaku_sampai' => $sesiSampai->toIso8601String()],
        ]));
    }

    public function verifikasi(Request $request, string $objekJenis, string $objekId): JsonResponse
    {
        return new JsonResponse(['data' => TandaTanganResource::daftar($this->penandatangan->verifikasi($request->user(), $objekJenis, $objekId))]);
    }

    private function tanpaSimpan(JsonResponse $respons): JsonResponse
    {
        $respons->headers->set('Cache-Control', 'no-store, private');

        return $respons;
    }
}
