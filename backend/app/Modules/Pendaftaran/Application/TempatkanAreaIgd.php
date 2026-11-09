<?php

namespace App\Modules\Pendaftaran\Application;

use App\Core\Audit\CatatAktivitas;
use App\Core\Http\ApiError;
use App\Core\Instalasi\StatusInstalasi;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\Pendaftaran\Models\Kunjungan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Penempatan pasien IGD di area (tiket 2.25, kontrak v0.34.0; FR-03-03). Satu baris kunjungan.papan_igd per kunjungan;
 * pindah area mengubah baris itu dan tercatat di audit (area sebelum dan sesudah). Area harus aktif di data induk
 * inti.area_igd. Penempatan ke area yang sama tidak mengubah apa pun.
 */
final class TempatkanAreaIgd
{
    public function __construct(private readonly CatatAktivitas $audit, private readonly StatusInstalasi $instalasi) {}

    /** @return stdClass baris kunjungan.papan_igd sesudah penempatan */
    public function tempatkan(Pengguna $petugas, string $kunjunganId, string $areaId): stdClass
    {
        KelolaPasien::pastikanSiapMelayani($this->instalasi);

        return DB::transaction(function () use ($petugas, $kunjunganId, $areaId) {
            $k = KunciKunjungan::pasienLaluKunjungan($kunjunganId) ?? throw new ApiError(404, 'tidak_ditemukan', 'Kunjungan tidak ditemukan.');
            if ($k->jenis_kunjungan !== Kunjungan::IGD || ! in_array($k->status, Kunjungan::STATUS_AKTIF, true)) {
                throw new ApiError(409, 'status_tidak_sesuai', 'Area hanya untuk kunjungan IGD yang masih terdaftar atau dilayani.',
                    [['jenis_kunjungan' => $k->jenis_kunjungan, 'status' => $k->status]]);
            }
            // FOR SHARE: penonaktifan area yang berjalan bersamaan menunggu sampai penempatan ini commit (atau sebaliknya).
            $area = DB::table('inti.area_igd')->where('id', $areaId)->where('status', 'aktif')->sharedLock()->first(['id', 'kode'])
                ?? throw ValidationException::withMessages(['area_igd_id' => 'Area IGD tidak ditemukan atau tidak aktif.']);

            $lama = DB::table('kunjungan.papan_igd')->where('kunjungan_id', $k->id)->first();
            if ($lama !== null && $lama->area_igd_id === $area->id) {
                return $lama;
            }
            $sekarang = now()->utc()->format('Y-m-d H:i:s.uP');
            if ($lama === null) {
                $id = (string) Str::uuid7();
                DB::table('kunjungan.papan_igd')->insert(['id' => $id, 'kunjungan_id' => $k->id, 'area_igd_id' => $area->id,
                    'ditempatkan_pada' => $sekarang, 'dibuat_oleh' => $petugas->id, 'diubah_oleh' => $petugas->id, 'dibuat_pada' => $sekarang, 'diubah_pada' => $sekarang]);
            } else {
                $id = (string) $lama->id;
                DB::table('kunjungan.papan_igd')->where('id', $id)->update(['area_igd_id' => $area->id, 'ditempatkan_pada' => $sekarang,
                    'diubah_oleh' => $petugas->id, 'diubah_pada' => $sekarang, 'revisi' => DB::raw('revisi + 1')]);
            }

            $this->audit->catat('kunjungan.area_igd_ditempatkan', $petugas->id, 'kunjungan.papan_igd', $id,
                ['kunjungan_id' => $k->id, 'nomor' => $k->nomor, 'area_igd_id' => $area->id, 'area_kode' => $area->kode],
                $lama === null ? null : ['area_igd_id' => $lama->area_igd_id]);

            return DB::table('kunjungan.papan_igd')->where('id', $id)->sole();
        });
    }
}
