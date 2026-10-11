<?php

namespace App\Modules\Notifikasi\Http\Controllers;

use App\Core\Http\ApiError;
use App\Core\Http\EtagIsi;
use App\Core\Http\Paginasi;
use App\Modules\Notifikasi\Application\KueriKotakKerja;
use App\Modules\Notifikasi\Http\Resources\NotifikasiResource;
use App\Modules\Notifikasi\Models\Tugas;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/** /api/v1/saya/tugas dan /tugas/{id} (kontrak v0.7.0, tiket 1.7). Tanpa izin khusus: hanya tugas yang terkait akun. */
final class TugasController
{
    public function __construct(
        private readonly KueriKotakKerja $kueri,
        private readonly NotifikasiResource $resource,
    ) {}

    public function index(Request $request): Response
    {
        $tab = $request->validate(['tab' => ['required', Rule::in(KueriKotakKerja::TAB)]])['tab'];
        $query = $this->kueri->tab($request->user(), $tab);
        $respons = $tab === 'selesai'
            ? Paginasi::respons($query, $request, 'selesai_pada', bentuk: fn ($baris) => $this->resource->tugas($baris))
            : Paginasi::respons($query, $request, 'tenggat_pada', arah: 'asc', bentuk: fn ($baris) => $this->resource->tugas($baris));

        return EtagIsi::terapkan($request, EtagIsi::tambahMeta($respons, ['jumlah' => $this->kueri->jumlah($request->user())]));
    }

    public function show(Request $request, string $tugas): JsonResponse
    {
        $t = Tugas::query()->find($tugas);
        if ($t === null || ! $this->kueri->terlihat($request->user(), $t)) {
            throw new ApiError(404, 'tidak_ditemukan', 'Data tidak ditemukan.');
        }

        return new JsonResponse(['data' => $this->resource->tugasRinci($t)]);
    }
}
