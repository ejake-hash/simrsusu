<?php

namespace App\Modules\MasterData\Application;

use App\Core\Audit\CatatAktivitas;
use App\Core\Http\ApiError;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\MasterData\Models\JadwalPengecualian;
use App\Modules\Sdm\Models\Pegawai;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Cuti dokter dan libur RS (tiket 2.4). Tanpa DELETE: pembatalan = status batal + alasan_batal. Versi = revisi. */
final class KelolaJadwalPengecualian
{
    public function __construct(private readonly CatatAktivitas $audit) {}

    /** @param  array<string, mixed>  $isi */
    public function buat(Pengguna $penyunting, array $isi): JadwalPengecualian
    {
        $pegawaiId = $isi['pegawai_id'] ?? null;
        if (($isi['jenis'] === 'cuti') !== ($pegawaiId !== null)) {
            throw ValidationException::withMessages(['pegawai_id' => $isi['jenis'] === 'cuti' ? 'Cuti wajib menyebut dokter.' : 'Libur RS berlaku untuk semua dokter; kosongkan dokter.']);
        }
        if ($isi['sampai_tanggal'] < $isi['dari_tanggal']) {
            throw ValidationException::withMessages(['sampai_tanggal' => 'Tanggal akhir tidak boleh sebelum tanggal mulai.']);
        }
        if ($pegawaiId !== null && ! Pegawai::query()->whereKey($pegawaiId)->exists()) {
            throw new ApiError(404, 'tidak_ditemukan', 'Pegawai tidak ditemukan.');
        }

        return DB::transaction(function () use ($penyunting, $isi) {
            $p = new JadwalPengecualian(['jenis' => $isi['jenis'], 'pegawai_id' => $isi['pegawai_id'] ?? null, 'dari_tanggal' => $isi['dari_tanggal'],
                'sampai_tanggal' => $isi['sampai_tanggal'], 'alasan' => $isi['alasan'], 'status' => 'aktif']);
            $p->forceFill(['dibuat_oleh' => $penyunting->id, 'diubah_oleh' => $penyunting->id])->save();
            $this->audit->catat('master_data.jadwal_pengecualian_dicatat', $penyunting->id, $p->getTable(), $p->id, $this->isiAudit($p->refresh()));

            return $p;
        });
    }

    /** @param  array<string, mixed>  $isi */
    public function ubah(Pengguna $penyunting, JadwalPengecualian $p, string $versi, array $isi): JadwalPengecualian
    {
        return DB::transaction(function () use ($penyunting, $p, $versi, $isi) {
            SuntinganBerversi::naikkan(JadwalPengecualian::class, $p->id, $versi, $penyunting->id, 'Pengecualian jadwal');
            $p->refresh();
            if ($p->status === 'batal' || $p->sampai_tanggal->toDateString() < SuntinganBerversi::hariIni()) {
                throw ValidationException::withMessages(['status' => 'Pengecualian yang sudah dibatalkan atau sudah lewat tidak dapat diubah.']);
            }
            $sebelum = $this->isiAudit($p);
            $p->fill(array_intersect_key($isi, array_flip(['dari_tanggal', 'sampai_tanggal', 'alasan'])));
            if ($p->sampai_tanggal->lt($p->dari_tanggal)) {
                throw ValidationException::withMessages(['sampai_tanggal' => 'Tanggal akhir tidak boleh sebelum tanggal mulai.']);
            }
            if (($isi['status'] ?? null) === 'batal') {
                $p->forceFill(['status' => 'batal', 'alasan_batal' => $isi['alasan_batal'], 'dibatalkan_oleh' => $penyunting->id, 'dibatalkan_pada' => now()]);
            }
            $p->forceFill(['diubah_oleh' => $penyunting->id])->save();
            $this->audit->catat('master_data.jadwal_pengecualian_diubah', $penyunting->id, $p->getTable(), $p->id, $this->isiAudit($p->refresh()), $sebelum);

            return $p;
        });
    }

    /** @return array<string, mixed> */
    private function isiAudit(JadwalPengecualian $p): array
    {
        return array_diff_key($p->attributesToArray(), array_flip(['id', 'dibuat_pada', 'dibuat_oleh', 'diubah_pada', 'diubah_oleh', 'revisi', 'dibatalkan_oleh', 'dibatalkan_pada']));
    }
}
