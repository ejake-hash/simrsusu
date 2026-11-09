<?php

namespace App\Modules\MasterData\Application;

use App\Core\Audit\CatatAktivitas;
use App\Core\Http\SuntinganBerversi;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\MasterData\Models\AreaIgd;
use App\Modules\MasterData\Models\AturanPapanIgd;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Data induk papan IGD (tiket 2.25): area IGD dan batas waktu papan. Pola instrumen triase (2.24): edit langsung oleh
 * pemegang izin master_data.papan_igd.*, setiap perubahan butuh dasar dan tercatat di audit (sebelum/sesudah). Tanpa
 * DELETE (area nonaktif). Versi = revisi (412 bila berubah). Isinya bukan data pasien, jadi boleh utuh di audit.
 */
final class KelolaDataIndukPapanIgd
{
    private const KOLOM_AREA = ['kode', 'nama', 'urutan', 'status'];

    private const KOLOM_ATURAN = ['batas_tanggap_menit', 'peringatan_lama_igd_menit'];

    public function __construct(private readonly CatatAktivitas $audit) {}

    /** @param  array<string, mixed>  $isi  sudah divalidasi bentuknya */
    public function buatArea(Pengguna $penyunting, array $isi): AreaIgd
    {
        $area = new AreaIgd(['kode' => $isi['kode'], 'nama' => trim($isi['nama']), 'urutan' => $isi['urutan'] ?? 0, 'status' => 'aktif']);

        return $this->tangkapKodeGanda(fn () => DB::transaction(function () use ($penyunting, $area, $isi) {
            $area->forceFill(['dibuat_oleh' => $penyunting->id, 'diubah_oleh' => $penyunting->id])->save();
            $this->audit->catat('master_data.area_igd_dibuat', $penyunting->id, $area->getTable(), $area->id,
                [...$area->refresh()->only(self::KOLOM_AREA), 'dasar_perubahan' => $isi['dasar_perubahan']]);

            return $area;
        }));
    }

    /** @param  array<string, mixed>  $isi  sudah divalidasi bentuknya */
    public function ubahArea(Pengguna $penyunting, AreaIgd $area, string $versi, array $isi): AreaIgd
    {
        return $this->tangkapKodeGanda(fn () => DB::transaction(function () use ($penyunting, $area, $versi, $isi) {
            SuntinganBerversi::naikkan(AreaIgd::class, $area->id, $versi, $penyunting->id, 'Area IGD');
            $sebelum = $area->refresh()->only(self::KOLOM_AREA);
            $ubahan = array_intersect_key($isi, array_flip(self::KOLOM_AREA));
            if (isset($ubahan['nama'])) {
                $ubahan['nama'] = trim($ubahan['nama']);
            }
            $area->fill($ubahan)->forceFill(['diubah_oleh' => $penyunting->id])->save();
            $this->audit->catat('master_data.area_igd_diubah', $penyunting->id, $area->getTable(), $area->id,
                [...$area->refresh()->only(self::KOLOM_AREA), 'dasar_perubahan' => $isi['dasar_perubahan']], $sebelum);

            return $area;
        }));
    }

    /**
     * `peringatan_lama_igd_menit` null = ambang mendekati 6 jam dikosongkan (tanda tidak dipakai).
     *
     * @param  array<string, mixed>  $isi  sudah divalidasi bentuknya
     */
    public function ubahAturan(Pengguna $penyunting, string $versi, array $isi): AturanPapanIgd
    {
        return DB::transaction(function () use ($penyunting, $versi, $isi) {
            $aturan = AturanPapanIgd::query()->lockForUpdate()->firstOrFail();
            SuntinganBerversi::naikkan(AturanPapanIgd::class, $aturan->id, $versi, $penyunting->id, 'Batas papan IGD');
            $sebelum = $aturan->refresh()->only(self::KOLOM_ATURAN);
            $aturan->fill(array_intersect_key($isi, array_flip(self::KOLOM_ATURAN)))->forceFill(['diubah_oleh' => $penyunting->id])->save();
            $this->audit->catat('master_data.aturan_papan_igd_diubah', $penyunting->id, $aturan->getTable(), $aturan->id,
                [...$aturan->refresh()->only(self::KOLOM_ATURAN), 'dasar_perubahan' => $isi['dasar_perubahan']], $sebelum);

            return $aturan;
        });
    }

    /**
     * @template T
     *
     * @param  callable(): T  $kerja
     * @return T
     */
    private function tangkapKodeGanda(callable $kerja): mixed
    {
        try {
            return $kerja();
        } catch (QueryException $e) {
            throw $e->getCode() === '23505'
                ? ValidationException::withMessages(['kode' => 'Kode sudah dipakai area lain.'])
                : $e;
        }
    }
}
