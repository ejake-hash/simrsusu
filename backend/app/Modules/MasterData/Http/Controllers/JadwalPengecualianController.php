<?php

namespace App\Modules\MasterData\Http\Controllers;

use App\Core\Http\IfMatch;
use App\Modules\Akses\Domain\Cakupan;
use App\Modules\MasterData\Application\KelolaJadwalPengecualian;
use App\Modules\MasterData\Http\Resources\JadwalPraktikResource;
use App\Modules\MasterData\Models\JadwalPengecualian;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** /api/v1/jadwal-pengecualian (kontrak v0.17.0, tiket 2.4): cuti dokter dan libur RS. Izin master_data.jadwal_praktik.*. */
final class JadwalPengecualianController
{
    public function __construct(private readonly JadwalPraktikResource $resource) {}

    public function store(Request $request, KelolaJadwalPengecualian $kelola): JsonResponse
    {
        abort_unless(Gate::allows('master_data.jadwal_praktik.input', Cakupan::rs()), 403);
        $isi = $request->validate([
            'jenis' => ['required', Rule::in(['cuti', 'libur'])],
            'pegawai_id' => ['nullable', 'uuid'],
            'dari_tanggal' => ['required', 'date_format:Y-m-d'],
            'sampai_tanggal' => ['required', 'date_format:Y-m-d'],
            'alasan' => IsianOrganisasi::DASAR,
        ]);

        return $this->satu($kelola->buat($request->user(), $isi), 201);
    }

    public function update(Request $request, JadwalPengecualian $pengecualian, KelolaJadwalPengecualian $kelola): JsonResponse
    {
        abort_unless(Gate::allows('master_data.jadwal_praktik.perbaiki', Cakupan::rs()), 403);
        $isi = $request->validate([
            'dari_tanggal' => ['sometimes', 'date_format:Y-m-d'],
            'sampai_tanggal' => ['sometimes', 'date_format:Y-m-d'],
            'alasan' => ['sometimes', ...IsianOrganisasi::DASAR],
            'status' => ['sometimes', Rule::in(['batal'])],
            'alasan_batal' => ['required_with:status', 'string', 'min:3', 'max:200'],
        ]);
        if ($isi === []) {
            throw ValidationException::withMessages(['alasan' => 'Isi minimal satu perubahan.']);
        }

        return $this->satu($kelola->ubah($request->user(), $pengecualian, IfMatch::versi($request), $isi));
    }

    private function satu(JadwalPengecualian $p, int $status = 200): JsonResponse
    {
        $p->refresh();

        return (new JsonResponse(['data' => $this->resource->pengecualian($p)], $status))->setEtag((string) $p->revisi)->setPrivate();
    }
}
