<?php

namespace App\Modules\Sdm\Application;

use App\Core\Audit\CatatAktivitas;
use App\Core\Http\ApiError;
use App\Core\Http\IfMatch;
use App\Modules\Akses\Application\AkunPegawai;
use App\Modules\Akses\Application\DataUnit;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\Sdm\Events\PegawaiKeluar;
use App\Modules\Sdm\Models\Pegawai;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Tambah dan ubah pegawai, termasuk pencatatan keluar (tiket 1.8d; PRD 11.2, 9.2.6). NIK hanya oleh pemegang izin
 * bagian identitas; tidak pernah masuk audit (hanya penanda berubah). Versi = revisi (412 bila berubah).
 */
final class KelolaPegawai
{
    public function __construct(
        private readonly CatatAktivitas $audit,
        private readonly DataUnit $dataUnit,
        private readonly AkunPegawai $akun,
    ) {}

    /** @param  array<string, mixed>  $isi */
    public function buat(Pengguna $penyunting, array $isi, bool $bolehNik): Pegawai
    {
        return $this->tangkapGanda(fn () => DB::transaction(function () use ($penyunting, $isi, $bolehNik) {
            $pegawai = new Pegawai;
            $this->isi($pegawai, $isi, $bolehNik);
            $pegawai->forceFill(['dibuat_oleh' => $penyunting->id, 'diubah_oleh' => $penyunting->id])->save();
            $this->audit->catat('sdm.pegawai_dibuat', $penyunting->id, 'sdm.pegawai', $pegawai->id,
                [...$this->isiAudit($pegawai), 'dasar_perubahan' => $isi['dasar_perubahan']]);

            return $pegawai;
        }));
    }

    /** @param  array<string, mixed>  $isi  isian PATCH (kontrak) */
    public function ubah(Pengguna $penyunting, Pegawai $pegawai, string $versi, array $isi, bool $bolehNik): Pegawai
    {
        return $this->tangkapGanda(fn () => DB::transaction(function () use ($penyunting, $pegawai, $versi, $isi, $bolehNik) {
            $revisi = IfMatch::revisi($versi);
            $naik = $revisi !== null && Pegawai::query()->whereKey($pegawai->id)->where('revisi', $revisi)
                ->update(['revisi' => DB::raw('revisi + 1'), 'diubah_oleh' => $penyunting->id]) === 1;
            if (! $naik) {
                throw new ApiError(412, 'versi_berubah', 'Data pegawai ini diubah orang lain sejak Anda membukanya. Muat ulang untuk melihat perubahan terbaru.');
            }
            $pegawai->refresh();
            $sebelum = $this->isiAudit($pegawai);
            // Superadmin hanya berkurang lewat persetujuan superadmin lain (K4), bukan lewat data SDM.
            if (($isi['tanggal_keluar'] ?? null) !== null && $this->akun->memegangSuperadmin($pegawai->id)) {
                throw new ApiError(409, 'superadmin_aktif', 'Akun pegawai ini memegang peran superadmin. Akhiri penugasan superadminnya lebih dulu.', [
                    ['kolom' => 'tanggal_keluar', 'pesan' => 'Akhiri penugasan superadmin lewat persetujuan superadmin lain.'],
                ]);
            }
            $this->isi($pegawai, $isi, $bolehNik);
            // Rencana keluar dibatalkan: pegawai aktif lagi, tetapi akunnya tetap nonaktif sampai diaktifkan admin.
            if (array_key_exists('tanggal_keluar', $isi) && $isi['tanggal_keluar'] === null) {
                $pegawai->status = 'aktif';
            }
            $pegawai->forceFill(['diubah_oleh' => $penyunting->id])->save();
            $this->prosesKeluarBila($pegawai, $penyunting->id);
            $this->audit->catat('sdm.pegawai_diubah', $penyunting->id, 'sdm.pegawai', $pegawai->id,
                [...$this->isiAudit($pegawai), 'nik_diubah' => $pegawai->wasChanged('nik_sidik'), 'dasar_perubahan' => $isi['dasar_perubahan']], $sebelum);

            return $pegawai;
        }));
    }

    /** Tugas harian: pegawai yang tanggal keluarnya sudah tercapai. Aman diulang. @return int jumlah diproses */
    public function prosesKeluarJatuhTempo(): int
    {
        $jumlah = 0;
        $hariIni = now(config('instalasi.zona_waktu'))->toDateString();
        foreach (Pegawai::query()->where('status', 'aktif')->where('tanggal_keluar', '<=', $hariIni)->pluck('id') as $id) {
            DB::transaction(function () use ($id, &$jumlah) {
                $pegawai = Pegawai::query()->whereKey($id)->lockForUpdate()->firstOrFail();
                if ($this->akun->memegangSuperadmin($pegawai->id)) {
                    // Ditugaskan superadmin sesudah keluarnya dicatat: ditunda sampai penugasan itu diakhiri lewat K4.
                    $this->audit->catat('sdm.keluar_tertunda_superadmin', null, 'sdm.pegawai', $pegawai->id,
                        ['tanggal_keluar' => $pegawai->tanggal_keluar?->toDateString()]);

                    return;
                }
                if ($this->prosesKeluarBila($pegawai, null)) {
                    $jumlah++;
                    $this->audit->catat('sdm.pegawai_keluar_diproses', null, 'sdm.pegawai', $pegawai->id,
                        ['tanggal_keluar' => $pegawai->tanggal_keluar?->toDateString()]);
                }
            });
        }

        return $jumlah;
    }

    /** Tanggal keluar tercapai dan belum diproses → status nonaktif + akun nonaktif (PegawaiKeluar, sinkron). */
    private function prosesKeluarBila(Pegawai $pegawai, ?string $olehId): bool
    {
        if ($pegawai->status !== 'aktif' || $pegawai->statusKepegawaian() !== 'keluar') {
            return false;
        }
        $pegawai->forceFill(['status' => 'nonaktif'])->save();
        PegawaiKeluar::dispatch($pegawai->id, (string) $pegawai->alasan_keluar, $olehId);

        return true;
    }

    /** @param  array<string, mixed>  $isi */
    private function isi(Pegawai $pegawai, array $isi, bool $bolehNik): void
    {
        if (array_key_exists('nik', $isi)) {
            if (! $bolehNik) {
                throw new ApiError(403, 'akses_ditolak', 'Mengisi NIK butuh izin melihat identitas pribadi pegawai.');
            }
            $pegawai->aturNik($isi['nik']);
        }
        if (($isi['unit_organisasi_id'] ?? null) !== null && ! $this->dataUnit->aktif($isi['unit_organisasi_id'])) {
            throw new ApiError(422, 'unit_tidak_valid', 'Unit tidak ada atau nonaktif.', [['kolom' => 'unit_organisasi_id', 'pesan' => 'Pilih unit yang aktif.']]);
        }
        if (array_key_exists('tanggal_keluar', $isi) && $isi['tanggal_keluar'] !== null && empty($isi['alasan_keluar'])) {
            throw new ApiError(422, 'validasi_gagal', 'Alasan keluar wajib diisi.', [['kolom' => 'alasan_keluar', 'pesan' => 'Isi alasan keluar.']]);
        }
        if (array_key_exists('tanggal_keluar', $isi) && $isi['tanggal_keluar'] === null) {
            $isi['alasan_keluar'] = null;
        }
        $pegawai->fill(array_intersect_key($isi, array_flip(['nip', 'nama', 'jenis_kepegawaian', 'profesi', 'unit_organisasi_id', 'jabatan', 'tmt', 'tanggal_keluar', 'alasan_keluar'])));
    }

    /**
     * @template T
     *
     * @param  callable(): T  $kerja
     * @return T
     */
    private function tangkapGanda(callable $kerja): mixed
    {
        try {
            return $kerja();
        } catch (QueryException $e) {
            if ($e->getCode() !== '23505') {
                throw $e;
            }
            // Pesan basis data bisa memuat nilai kunci; hanya nama constraint yang dipakai.
            [$kode, $kolom] = str_contains($e->getMessage(), 'nik_sidik') ? ['nik_sudah_dipakai', 'nik'] : ['nip_sudah_dipakai', 'nip'];
            throw new ApiError(409, $kode, $kolom === 'nik' ? 'NIK sudah tercatat untuk pegawai lain.' : 'NIP sudah dipakai pegawai lain.', [['kolom' => $kolom, 'pesan' => 'Sudah dipakai.']]);
        }
    }

    /** @return array<string, mixed> tanpa NIK */
    private function isiAudit(Pegawai $pegawai): array
    {
        return [...$pegawai->only(['nip', 'nama', 'jenis_kepegawaian', 'profesi', 'unit_organisasi_id', 'jabatan', 'alasan_keluar', 'status']),
            'tmt' => $pegawai->tmt?->toDateString(), 'tanggal_keluar' => $pegawai->tanggal_keluar?->toDateString()];
    }
}
