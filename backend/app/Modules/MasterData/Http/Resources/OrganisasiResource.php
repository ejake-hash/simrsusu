<?php

namespace App\Modules\MasterData\Http\Resources;

use App\Modules\MasterData\Application\PohonUnit;
use App\Modules\MasterData\Models\Ksm;
use App\Modules\MasterData\Models\UnitOrganisasi;
use App\Modules\Sdm\Application\RingkasanPegawai;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Bentuk kontrak UnitOrganisasi dan Ksm; jumlah anak dan jalur dimuat sekaligus per halaman. */
final class OrganisasiResource
{
    public function __construct(private readonly PohonUnit $pohon) {}

    /**
     * @param  Collection<int, UnitOrganisasi>  $unit
     * @return list<array<string, mixed>>
     */
    public function unit(Collection $unit): array
    {
        $id = $unit->pluck('id')->all();
        $anak = $id === [] ? [] : DB::table('inti.unit_organisasi')->whereIn('induk_id', $id)
            ->groupBy('induk_id')->selectRaw('induk_id, count(*) AS jumlah')->pluck('jumlah', 'induk_id')->all();
        $jalur = $this->pohon->jalur($id);
        $kepala = app(RingkasanPegawai::class)->ringkas($unit->pluck('kepala_pegawai_id')->filter());

        return $unit->map(fn (UnitOrganisasi $u) => [
            'id' => $u->id,
            'induk_id' => $u->induk_id,
            'kode' => $u->kode,
            'nama' => $u->nama,
            'jenis' => $u->jenis,
            'status' => $u->status,
            'berlaku_dari' => $u->berlaku_dari->toDateString(),
            'kepala' => $u->kepala_pegawai_id === null ? null : ($kepala[$u->kepala_pegawai_id] ?? null),
            'jumlah_anak' => (int) ($anak[$u->id] ?? 0),
            'jalur' => $jalur[$u->id] ?? [],
            'versi' => (string) $u->revisi,
            'diubah_pada' => $u->diubah_pada->toIso8601String(),
        ])->values()->all();
    }

    /** @return array<string, mixed> */
    public static function ksm(Ksm $k): array
    {
        return [
            'id' => $k->id,
            'kode' => $k->kode,
            'nama' => $k->nama,
            'status' => $k->status,
            'ketua' => ($id = $k->getAttribute('ketua_pegawai_id')) === null ? null
                : (app(RingkasanPegawai::class)->ringkas([$id])[$id] ?? null),
            'versi' => (string) $k->revisi,
            'diubah_pada' => $k->diubah_pada->toIso8601String(),
        ];
    }
}
