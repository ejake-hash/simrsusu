<?php

namespace App\Modules\MasterData\Application;

use App\Core\Audit\CatatAktivitas;
use App\Core\Http\ApiError;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\MasterData\Models\JadwalPraktik;
use App\Modules\MasterData\Models\Lokasi;
use App\Modules\MasterData\Models\Poliklinik;
use App\Modules\Sdm\Models\IzinPraktik;
use App\Modules\Sdm\Models\Pegawai;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Jadwal praktik dokter (tiket 2.4, kontrak v0.17.0). Tanpa DELETE (status nonaktif). Versi = revisi (412 bila berubah).
 * Penguncian baris pegawai membuat dua penambahan serentak untuk dokter yang sama dicek bergantian (anti tumpang tindih).
 * Utang 2.6: `kuota_di_bawah_terisi` (kontrak PATCH) menunggu tabel kunjungan; sampai saat itu keterisian selalu 0.
 */
final class KelolaJadwalPraktik
{
    private const KOLOM_UBAH = ['ruang_id', 'hari', 'jam_mulai', 'jam_selesai', 'kuota_jkn', 'kuota_non_jkn', 'berlaku_dari', 'berlaku_sampai', 'status'];

    public function __construct(private readonly CatatAktivitas $audit) {}

    /** @param  array<string, mixed>  $isi */
    public function buat(Pengguna $penyunting, array $isi): JadwalPraktik
    {
        return DB::transaction(function () use ($penyunting, $isi) {
            $this->periksaPegawai($isi['pegawai_id']);
            $poli = Poliklinik::query()->whereKey($isi['poli_id'])->first() ?? throw new ApiError(404, 'tidak_ditemukan', 'Poliklinik tidak ditemukan.');
            $jadwal = new JadwalPraktik([...$isi, 'ruang_id' => $isi['ruang_id'] ?? $poli->lokasi_id, 'berlaku_sampai' => $isi['berlaku_sampai'] ?? null, 'status' => 'aktif']);
            $this->periksa($jadwal, true);
            $jadwal->forceFill(['dibuat_oleh' => $penyunting->id, 'diubah_oleh' => $penyunting->id])->save();
            $this->audit->catat('master_data.jadwal_praktik_dibuat', $penyunting->id, $jadwal->getTable(), $jadwal->id,
                [...$this->isiAudit($jadwal->refresh()), 'dasar_perubahan' => $isi['dasar_perubahan']]);

            return $jadwal;
        });
    }

    /** @param  array<string, mixed>  $isi */
    public function ubah(Pengguna $penyunting, JadwalPraktik $jadwal, string $versi, array $isi): JadwalPraktik
    {
        return DB::transaction(function () use ($penyunting, $jadwal, $versi, $isi) {
            SuntinganBerversi::naikkan(JadwalPraktik::class, $jadwal->id, $versi, $penyunting->id, 'Jadwal praktik');
            $this->periksaPegawai($jadwal->pegawai_id);
            $jadwal->refresh();
            $sebelum = $this->isiAudit($jadwal);
            $jadwal->fill(array_intersect_key($isi, array_flip(self::KOLOM_UBAH)));
            $this->periksa($jadwal, isset($isi['berlaku_dari']) || (($isi['status'] ?? null) === 'aktif'));
            $jadwal->forceFill(['diubah_oleh' => $penyunting->id])->save();
            $this->audit->catat('master_data.jadwal_praktik_diubah', $penyunting->id, $jadwal->getTable(), $jadwal->id,
                [...$this->isiAudit($jadwal->refresh()), 'dasar_perubahan' => $isi['dasar_perubahan']], $sebelum);

            return $jadwal;
        });
    }

    /** Kunci baris dokter dan pastikan ia dokter aktif. */
    private function periksaPegawai(string $pegawaiId): void
    {
        $pegawai = Pegawai::query()->whereKey($pegawaiId)->lockForUpdate()->first() ?? throw new ApiError(404, 'tidak_ditemukan', 'Pegawai tidak ditemukan.');
        if (! in_array($pegawai->profesi, ['dokter', 'dokter_gigi'], true) || $pegawai->status !== 'aktif') {
            throw ValidationException::withMessages(['pegawai_id' => 'Pegawai harus dokter yang masih aktif.']);
        }
    }

    private function periksa(JadwalPraktik $jadwal, bool $cekSip): void
    {
        $mulai = substr($jadwal->jam_mulai, 0, 5);
        $selesai = substr($jadwal->jam_selesai, 0, 5);
        if ($selesai <= $mulai) {
            throw ValidationException::withMessages(['jam_selesai' => 'Jam selesai harus sesudah jam mulai.']);
        }
        if ($jadwal->berlaku_sampai !== null && $jadwal->berlaku_sampai->lt($jadwal->berlaku_dari)) {
            throw ValidationException::withMessages(['berlaku_sampai' => 'Tanggal berakhir tidak boleh sebelum tanggal mulai.']);
        }
        if ($jadwal->ruang_id !== null && ($jadwal->isDirty('ruang_id') || ! $jadwal->exists)
            && Lokasi::query()->whereKey($jadwal->ruang_id)->where('jenis', 'ruang')->where('status', 'aktif')->sharedLock()->first() === null) {
            throw new ApiError(422, 'bukan_ruang', 'Lokasi harus ruang yang aktif.', [['kolom' => 'ruang_id', 'pesan' => 'Pilih ruang yang aktif.']]);
        }
        if ($jadwal->status === 'aktif') {
            if ($cekSip) {
                $this->periksaSip($jadwal);
            }
            $this->periksaTumpangTindih($jadwal, $mulai, $selesai);
        }
    }

    private function periksaSip(JadwalPraktik $jadwal): void
    {
        $dari = $jadwal->berlaku_dari->toDateString();
        $ada = IzinPraktik::query()->where('pegawai_id', $jadwal->pegawai_id)->where('jenis', 'sip')->where('status', 'aktif')
            ->where('terbit', '<=', $dari)->where(fn ($q) => $q->whereNull('berlaku_sampai')->orWhere('berlaku_sampai', '>=', $dari))->exists();
        if (! $ada) {
            throw new ApiError(422, 'sip_tidak_aktif', 'Dokter ini tidak memiliki SIP aktif pada tanggal mulai jadwal.', [['kolom' => 'pegawai_id', 'pesan' => 'Catat SIP aktif di data pegawai lebih dulu.']]);
        }
    }

    private function periksaTumpangTindih(JadwalPraktik $jadwal, string $mulai, string $selesai): void
    {
        $sampai = $jadwal->berlaku_sampai?->toDateString();
        $bentrok = JadwalPraktik::query()->where('pegawai_id', $jadwal->pegawai_id)->where('hari', $jadwal->hari)->where('status', 'aktif')
            ->when($jadwal->exists, fn ($q) => $q->whereKeyNot($jadwal->id))
            ->whereRaw('jam_mulai < ?::time AND jam_selesai > ?::time', [$selesai, $mulai])
            ->when($sampai, fn ($q, $s) => $q->where('berlaku_dari', '<=', $s))
            ->where(fn ($q) => $q->whereNull('berlaku_sampai')->orWhere('berlaku_sampai', '>=', $jadwal->berlaku_dari->toDateString()))
            ->value('id');
        if ($bentrok !== null) {
            throw new ApiError(422, 'jadwal_bertumpuk', 'Jam praktik bertumpuk dengan jadwal lain dokter ini di hari yang sama.', [['kolom' => 'jam_mulai', 'pesan' => 'Bertumpuk dengan jadwal aktif.', 'jadwal_id' => $bentrok]]);
        }
    }

    /** @return array<string, mixed> */
    private function isiAudit(JadwalPraktik $jadwal): array
    {
        return array_diff_key($jadwal->attributesToArray(), array_flip(['id', 'dibuat_pada', 'dibuat_oleh', 'diubah_pada', 'diubah_oleh', 'revisi']));
    }
}
