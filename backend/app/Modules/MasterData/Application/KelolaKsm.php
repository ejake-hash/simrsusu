<?php

namespace App\Modules\MasterData\Application;

use App\Core\Audit\CatatAktivitas;
use App\Core\Http\ApiError;
use App\Core\Http\IfMatch;
use App\Modules\Akses\Application\PemakaianCakupan;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\MasterData\Models\Ksm;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/** Tambah, ubah, dan nonaktifkan KSM (tiket 1.8a). Data seluruh RS: izin dengan cakupan `rs`. Tanpa DELETE. */
final class KelolaKsm
{
    public function __construct(
        private readonly CatatAktivitas $audit,
        private readonly PemakaianCakupan $pemakaian,
    ) {}

    /** @param  array{kode: string, nama: string, dasar_perubahan: string}  $isi */
    public function buat(Pengguna $penyunting, array $isi): Ksm
    {
        return $this->tangkapKodeGanda(fn () => DB::transaction(function () use ($penyunting, $isi) {
            $ksm = new Ksm(['kode' => $isi['kode'], 'nama' => $isi['nama']]);
            $ksm->forceFill(['dibuat_oleh' => $penyunting->id, 'diubah_oleh' => $penyunting->id])->save();
            $this->audit->catat('master_data.ksm_dibuat', $penyunting->id, 'inti.ksm', $ksm->id,
                [...$ksm->only(['kode', 'nama', 'status']), 'dasar_perubahan' => $isi['dasar_perubahan']]);

            return $ksm;
        }));
    }

    /** @param  array<string, mixed>  $isi */
    public function ubah(Pengguna $penyunting, Ksm $ksm, string $versi, array $isi): Ksm
    {
        return $this->tangkapKodeGanda(fn () => DB::transaction(function () use ($penyunting, $ksm, $versi, $isi) {
            $revisi = IfMatch::revisi($versi);
            $naik = $revisi !== null && Ksm::query()->whereKey($ksm->id)->where('revisi', $revisi)
                ->update(['revisi' => DB::raw('revisi + 1'), 'diubah_oleh' => $penyunting->id]) === 1;
            if (! $naik) {
                throw new ApiError(412, 'versi_berubah', 'KSM ini diubah orang lain sejak Anda membukanya. Muat ulang untuk melihat perubahan terbaru.');
            }
            $ksm->refresh();
            $sebelum = $ksm->only(['kode', 'nama', 'status', 'ketua_pegawai_id']);

            if (($isi['status'] ?? null) === 'nonaktif' && $ksm->status === 'aktif'
                && ($jumlah = $this->pemakaian->jumlahPenugasan('ksm', $ksm->id)) > 0) {
                throw new ApiError(409, 'ksm_masih_dipakai', "KSM masih menjadi cakupan {$jumlah} penugasan.", [['jumlah_penugasan' => $jumlah]]);
            }

            if (($isi['ketua_pegawai_id'] ?? null) !== null) {
                PegawaiPimpinan::pastikan($isi['ketua_pegawai_id'], 'ketua_pegawai_id');
            }
            $ksm->fill(array_intersect_key($isi, array_flip(['kode', 'nama', 'status', 'ketua_pegawai_id'])));
            $ksm->forceFill(['diubah_oleh' => $penyunting->id])->save();
            $this->audit->catat('master_data.ksm_diubah', $penyunting->id, 'inti.ksm', $ksm->id,
                [...$ksm->only(['kode', 'nama', 'status', 'ketua_pegawai_id']), 'dasar_perubahan' => $isi['dasar_perubahan']], $sebelum);

            return $ksm;
        }));
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
                ? new ApiError(409, 'kode_sudah_dipakai', 'Kode KSM sudah dipakai.', [['kolom' => 'kode', 'pesan' => 'Kode KSM sudah dipakai.']])
                : $e;
        }
    }
}
