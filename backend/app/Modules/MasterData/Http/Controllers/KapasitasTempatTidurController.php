<?php

namespace App\Modules\MasterData\Http\Controllers;

use App\Core\Http\Paginasi;
use App\Modules\MasterData\Application\KapasitasTempatTidur;
use App\Modules\MasterData\Application\SuntinganBerversi;
use App\Modules\MasterData\Http\Resources\LokasiResource;
use App\Modules\MasterData\Models\Bangsal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/kapasitas-tempat-tidur (kontrak v0.5.0, FR-08-18). Satu baris per bangsal (urut kode). Tanggal lampau
 * dari rekaman harian bila ada; hari ini, mendatang, atau lampau tanpa rekaman dihitung dari masa berlaku TT.
 */
final class KapasitasTempatTidurController
{
    use IzinOrganisasi;

    public function __invoke(Request $request, KapasitasTempatTidur $kapasitas, LokasiResource $resource): JsonResponse
    {
        $this->izin('lihat');
        $filter = $request->validate(['tanggal' => ['nullable', 'date_format:Y-m-d'], 'bangsal_id' => ['nullable', 'uuid']]);
        $tanggal = $filter['tanggal'] ?? SuntinganBerversi::hariIni();
        $query = Bangsal::query()->when($filter['bangsal_id'] ?? null, fn ($q, $id) => $q->whereKey($id));

        return Paginasi::respons($query, $request, 'kode', arah: 'asc', bentuk: function ($baris) use ($kapasitas, $resource, $tanggal) {
            $id = $baris->pluck('id')->all();
            $tersimpan = $tanggal < SuntinganBerversi::hariIni() ? $kapasitas->tersimpan($tanggal, $id) : null;
            $angka = $tersimpan ?? $kapasitas->hitung($tanggal, $id);
            $kelas = $resource->kelasRingkas(collect($angka)->flatMap(fn ($perKelas) => array_keys($perKelas))->unique()->values()->all());

            return $baris->map(fn (Bangsal $b) => [
                'tanggal' => $tanggal,
                'sumber' => $tersimpan === null ? 'dihitung' : 'tersimpan',
                'bangsal' => ['id' => $b->id, 'kode' => $b->kode, 'nama' => $b->nama],
                'kelas' => collect($angka[$b->id] ?? [])->map(fn ($j, $kelasId) => [
                    'kelas_rawat' => $kelas[$kelasId], 'jumlah_tersedia' => $j['tersedia'], 'jumlah_ekstra' => $j['ekstra'],
                ])->sortBy('kelas_rawat.kode')->values()->all(),
            ])->values()->all();
        });
    }
}
