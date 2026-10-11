<?php

namespace App\Modules\Notifikasi\Http\Controllers;

use App\Core\Audit\CatatAktivitas;
use App\Core\Http\ApiError;
use App\Core\Http\IfMatch;
use App\Core\Http\Paginasi;
use App\Modules\Akses\Domain\Cakupan;
use App\Modules\Notifikasi\Http\Resources\NotifikasiResource;
use App\Modules\Notifikasi\Models\JenisTugas;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * /api/v1/jenis-tugas (kontrak v0.7.0, tiket 1.7, FR-53-04). Tanpa POST: jenis dipasang migrasi modul pembuatnya.
 * Izin master_data.jenis_tugas.* cakupan rs. Perubahan berlaku untuk tugas yang dibuat sesudahnya.
 */
final class JenisTugasController
{
    public function index(Request $request): JsonResponse
    {
        self::izin('lihat');
        $filter = $request->validate(['aktif' => ['nullable', Rule::in(['true', 'false', '1', '0'])]]);
        $query = JenisTugas::query()->when(isset($filter['aktif']), fn ($q) => $q->where('aktif', in_array($filter['aktif'], ['true', '1'], true)));

        return Paginasi::respons($query, $request, 'kode', arah: 'asc',
            bentuk: fn ($baris) => $baris->map(fn (JenisTugas $j) => NotifikasiResource::jenisTugas($j))->values()->all());
    }

    public function show(JenisTugas $jenisTugas): JsonResponse
    {
        self::izin('lihat');

        return $this->satu($jenisTugas);
    }

    public function update(Request $request, JenisTugas $jenisTugas, CatatAktivitas $audit): JsonResponse
    {
        self::izin('perbaiki');
        $isi = $request->validate([
            'nama' => ['sometimes', 'string', 'min:3', 'max:150'],
            'batas_menit' => ['sometimes', 'integer', 'min:1', 'max:525600'],
            'unit_teguran_id' => ['sometimes', 'nullable', 'uuid'],
            'unit_eskalasi_id' => ['sometimes', 'nullable', 'uuid'],
            'eskalasi' => ['sometimes', 'array'],
            'eskalasi.pengingat_menit' => ['required_with:eskalasi', 'integer', 'min:-525600', 'max:-1'],
            'eskalasi.teguran_menit' => ['required_with:eskalasi', 'integer', 'min:0', 'max:525600'],
            'eskalasi.eskalasi_menit' => ['required_with:eskalasi', 'integer', 'min:0', 'max:525600'],
            'kritis' => ['sometimes', 'boolean'],
            'aktif' => ['sometimes', 'boolean'],
            'dasar_perubahan' => ['required', 'string', 'min:3', 'max:200'],
        ]);
        if (count($isi) < 2) {
            throw ValidationException::withMessages(['dasar_perubahan' => 'Isi minimal satu perubahan.']);
        }
        if (isset($isi['eskalasi']) && $isi['eskalasi']['eskalasi_menit'] < $isi['eskalasi']['teguran_menit']) {
            throw new ApiError(422, 'eskalasi_tidak_urut', 'Eskalasi (tingkat 3) tidak boleh sebelum teguran (tingkat 2).', [['kolom' => 'eskalasi', 'pesan' => 'Urutkan waktu eskalasi.']]);
        }
        foreach (['unit_teguran_id', 'unit_eskalasi_id'] as $kolom) {
            if (($isi[$kolom] ?? null) !== null && ! DB::table('inti.unit_organisasi')->where('id', $isi[$kolom])->where('status', 'aktif')->exists()) {
                throw new ApiError(422, 'unit_tidak_valid', 'Unit tidak ada atau nonaktif.', [['kolom' => $kolom, 'pesan' => 'Pilih unit yang aktif.']]);
            }
        }
        $versi = IfMatch::versi($request);

        DB::transaction(function () use ($request, $jenisTugas, $isi, $versi, $audit) {
            $revisi = IfMatch::revisi($versi);
            $naik = $revisi !== null && JenisTugas::query()->whereKey($jenisTugas->id)->where('revisi', $revisi)
                ->update(['revisi' => DB::raw('revisi + 1'), 'diubah_oleh' => $request->user()->id]) === 1;
            if (! $naik) {
                throw new ApiError(412, 'versi_berubah', 'Jenis tugas ini diubah orang lain sejak Anda membukanya. Muat ulang untuk melihat perubahan terbaru.');
            }
            $jenisTugas->refresh();
            $sebelum = NotifikasiResource::jenisTugas($jenisTugas);
            $jenisTugas->fill([...array_intersect_key($isi, array_flip(['nama', 'batas_menit', 'unit_teguran_id', 'unit_eskalasi_id', 'kritis', 'aktif'])),
                ...($isi['eskalasi'] ?? [])]);
            $jenisTugas->forceFill(['diubah_oleh' => $request->user()->id])->save();
            $audit->catat('notifikasi.jenis_tugas_diubah', $request->user()->id, 'inti.jenis_tugas', $jenisTugas->id,
                [...NotifikasiResource::jenisTugas($jenisTugas), 'dasar_perubahan' => $isi['dasar_perubahan']], $sebelum);
        });

        return $this->satu($jenisTugas);
    }

    private static function izin(string $aksi): void
    {
        abort_unless(Gate::allows("master_data.jenis_tugas.$aksi", Cakupan::rs()), 403);
    }

    private function satu(JenisTugas $jenis): JsonResponse
    {
        $jenis->refresh();

        return (new JsonResponse(['data' => NotifikasiResource::jenisTugas($jenis)]))->setEtag((string) $jenis->revisi)->setPrivate();
    }
}
