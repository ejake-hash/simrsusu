<?php

namespace App\Modules\MasterData\Http\Controllers;

use App\Core\Database\Like;
use App\Core\Http\IfMatch;
use App\Core\Http\Paginasi;
use App\Modules\Akses\Domain\Cakupan;
use App\Modules\MasterData\Application\KelolaKsm;
use App\Modules\MasterData\Http\Resources\OrganisasiResource;
use App\Modules\MasterData\Models\Ksm;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** /api/v1/ksm (kontrak v0.3.0, tiket 1.8a). KSM data seluruh RS: izin dengan cakupan `rs`. */
final class KsmController
{
    private const KODE = ['string', 'regex:/^[A-Z0-9][A-Z0-9._-]{0,29}$/'];

    public function index(Request $request): JsonResponse
    {
        $this->izin('lihat');
        $filter = $request->validate([
            'cari' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['aktif', 'nonaktif'])],
        ]);
        $query = Ksm::query()
            ->when($filter['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filter['cari'] ?? null, fn ($q, $cari) => $q->where(fn ($q) => $q
                ->whereRaw('kode ILIKE ?', [Like::memuat($cari)])->orWhereRaw('nama ILIKE ?', [Like::memuat($cari)])));

        return Paginasi::respons($query, $request, 'kode', arah: 'asc',
            bentuk: fn ($baris) => $baris->map(fn (Ksm $k) => OrganisasiResource::ksm($k))->values()->all());
    }

    public function show(Ksm $ksm): JsonResponse
    {
        $this->izin('lihat');

        return $this->satu($ksm);
    }

    public function store(Request $request, KelolaKsm $kelola): JsonResponse
    {
        $this->izin('input');
        $isi = $request->validate([
            'kode' => ['required', ...self::KODE],
            'nama' => ['required', 'string', 'min:2', 'max:150'],
            'dasar_perubahan' => ['required', 'string', 'min:3', 'max:200'],
        ]);

        return $this->satu($kelola->buat($request->user(), $isi), 201);
    }

    public function update(Request $request, Ksm $ksm, KelolaKsm $kelola): JsonResponse
    {
        $this->izin('perbaiki');
        $isi = $request->validate([
            'kode' => ['sometimes', ...self::KODE],
            'nama' => ['sometimes', 'string', 'min:2', 'max:150'],
            'status' => ['sometimes', Rule::in(['aktif', 'nonaktif'])],
            'ketua_pegawai_id' => ['sometimes', 'nullable', 'uuid'],
            'dasar_perubahan' => ['required', 'string', 'min:3', 'max:200'],
        ]);
        if (count($isi) < 2) {
            throw ValidationException::withMessages(['nama' => 'Isi minimal satu perubahan.']);
        }
        $versi = IfMatch::versi($request);

        return $this->satu($kelola->ubah($request->user(), $ksm, $versi, $isi));
    }

    private function izin(string $aksi): void
    {
        abort_unless(Gate::allows("master_data.organisasi.$aksi", Cakupan::rs()), 403);
    }

    private function satu(Ksm $ksm, int $status = 200): JsonResponse
    {
        $ksm->refresh();

        return (new JsonResponse(['data' => OrganisasiResource::ksm($ksm)], $status))->setEtag((string) $ksm->revisi)->setPrivate();
    }
}
