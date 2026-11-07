<?php

namespace App\Modules\MasterData\Application;

use App\Core\Audit\CatatAktivitas;
use App\Core\Http\ApiError;
use App\Core\Http\SuntinganBerversi;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\MasterData\Models\JadwalPengecualian;
use App\Modules\MasterData\Models\JadwalPraktik;
use App\Modules\Pendaftaran\Application\KeterisianKunjungan;
use App\Modules\Sdm\Models\Pegawai;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Cuti dokter dan libur RS (tiket 2.4). Tanpa DELETE: pembatalan = status batal + alasan_batal. Versi = revisi. */
final class KelolaJadwalPengecualian
{
    public function __construct(private readonly CatatAktivitas $audit, private readonly KeterisianKunjungan $keterisian) {}

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
            $this->kunciJadwal($isi['pegawai_id'] ?? null);
            $this->periksaKunjungan($isi['pegawai_id'] ?? null, $isi['dari_tanggal'], $isi['sampai_tanggal']);
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
            if (($isi['status'] ?? null) !== 'batal' && $p->isDirty(['dari_tanggal', 'sampai_tanggal'])) {
                $this->kunciJadwal($p->pegawai_id);
                // Hanya tanggal yang baru tertutup: kunjungan di rentang lama sudah ada sebelum pengecualian dicatat.
                $baru = array_diff($this->tanggalTertutup($p->pegawai_id, $p->dari_tanggal->toDateString(), $p->sampai_tanggal->toDateString()),
                    $this->tanggalTertutup($p->pegawai_id, (string) $p->getOriginal('dari_tanggal')?->toDateString(), (string) $p->getOriginal('sampai_tanggal')?->toDateString()));
                if ($baru !== []) {
                    throw KelolaJadwalPraktik::sudahTerisi(array_values($baru));
                }
            }
            if (($isi['status'] ?? null) === 'batal') {
                $p->forceFill(['status' => 'batal', 'alasan_batal' => $isi['alasan_batal'], 'dibatalkan_oleh' => $penyunting->id, 'dibatalkan_pada' => now()]);
            }
            $p->forceFill(['diubah_oleh' => $penyunting->id])->save();
            $this->audit->catat('master_data.jadwal_pengecualian_diubah', $penyunting->id, $p->getTable(), $p->id, $this->isiAudit($p->refresh()), $sebelum);

            return $p;
        });
    }

    /**
     * Kunci baris jadwal yang sama dengan pendaftaran kunjungan (KelolaKunjungan::daftar) sebelum memeriksa kunjungan:
     * yang datang belakangan menunggu, lalu ditolak oleh pemeriksaan masing-masing. Libur RS mengunci semua jadwal.
     */
    private function kunciJadwal(?string $pegawaiId): void
    {
        JadwalPraktik::query()->when($pegawaiId, fn ($q, $id) => $q->where('pegawai_id', $id))->orderBy('id')->lockForUpdate()->pluck('id');
    }

    /** Cuti/libur tidak boleh menutup tanggal yang sudah punya kunjungan terdaftar (batalkan atau pindahkan dulu). */
    private function periksaKunjungan(?string $pegawaiId, string $dari, string $sampai): void
    {
        $tanggal = $this->tanggalTertutup($pegawaiId, $dari, $sampai);
        if ($tanggal !== []) {
            throw KelolaJadwalPraktik::sudahTerisi($tanggal);
        }
    }

    /** @return list<string> tanggal terdaftar (sejak hari ini) yang tertutup cuti dokter ini, atau libur RS bila $pegawaiId null */
    private function tanggalTertutup(?string $pegawaiId, string $dari, string $sampai): array
    {
        return $this->keterisian->tanggalTerdaftar($pegawaiId === null ? [] : ['pegawai_id' => $pegawaiId], max($dari, SuntinganBerversi::hariIni()), $sampai);
    }

    /** @return array<string, mixed> */
    private function isiAudit(JadwalPengecualian $p): array
    {
        return array_diff_key($p->attributesToArray(), array_flip(['id', 'dibuat_pada', 'dibuat_oleh', 'diubah_pada', 'diubah_oleh', 'revisi', 'dibatalkan_oleh', 'dibatalkan_pada']));
    }
}
