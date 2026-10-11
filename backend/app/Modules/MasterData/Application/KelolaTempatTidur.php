<?php

namespace App\Modules\MasterData\Application;

use App\Core\Audit\CatatAktivitas;
use App\Core\Http\ApiError;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\MasterData\Domain\PemeriksaPenempatan;
use App\Modules\MasterData\Events\KapasitasTempatTidurBerubah;
use App\Modules\MasterData\Models\Bangsal;
use App\Modules\MasterData\Models\Kamar;
use App\Modules\MasterData\Models\KelasRawat;
use App\Modules\MasterData\Models\Lokasi;
use App\Modules\MasterData\Models\TempatTidur;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Tempat tidur (tiket 1.8b; FR-08-16…18). Setiap TT sekaligus node lokasi jenis tempat_tidur di bawah ruang
 * kamarnya. Kode = kode_lengkap saat dibuat, tidak berubah dan tidak dipakai ulang. Penutupan permanen lewat
 * berlaku_sampai (hari terakhir dihitung); TT terisi/dipesan tidak bisa ditutup (PemeriksaPenempatan).
 */
final class KelolaTempatTidur
{
    public function __construct(
        private readonly CatatAktivitas $audit,
        private readonly PohonLokasi $pohon,
        private readonly PemeriksaPenempatan $penempatan,
    ) {}

    /**
     * @param  array{kamar_id: string, kode: string, kelas_rawat_id: string, aturan_jenis_kelamin?: string|null, isolasi?: bool, ekstra?: bool, berlaku_dari?: string|null, dasar_perubahan: string}  $isi
     * @return array{0: TempatTidur, 1: list<string>} TT dan peringatan
     */
    public function buat(Pengguna $penyunting, array $isi): array
    {
        $berlakuDari = $isi['berlaku_dari'] ?? SuntinganBerversi::hariIni();
        if ($berlakuDari < SuntinganBerversi::hariIni()) {
            throw $this->tanggalTidakValid('berlaku_dari', 'TT baru mulai dihitung paling cepat hari ini.');
        }

        return SuntinganBerversi::tangkapKodeGanda(fn () => DB::transaction(function () use ($penyunting, $isi, $berlakuDari) {
            $this->pohon->kunci();
            $kamar = Kamar::query()->whereKey($isi['kamar_id'])->lockForUpdate()->first();
            $ruang = $kamar === null ? null : Lokasi::query()->find($kamar->lokasi_id);
            $bangsal = $kamar === null ? null : Bangsal::query()->whereKey($kamar->bangsal_id)->sharedLock()->first();
            if ($kamar === null || $ruang === null || $ruang->status !== 'aktif' || $bangsal?->status !== 'aktif') {
                throw new ApiError(422, 'kamar_tidak_valid', 'Kamar tidak ada, ruangnya tidak aktif, atau bangsalnya nonaktif.', [['kolom' => 'kamar_id', 'pesan' => 'Pilih kamar yang aktif.']]);
            }
            $this->pastikanKelas($isi['kelas_rawat_id']);
            $kode = PohonLokasi::kodeLengkap($ruang->kode_lengkap, $isi['kode']);
            if (TempatTidur::query()->where('kode', $kode)->exists()) {
                throw new ApiError(409, 'kode_sudah_dipakai', 'Kode TT ini pernah dipakai dan tidak bisa dipakai ulang.', [['kolom' => 'kode', 'pesan' => 'Pilih kode lain.']]);
            }

            $node = new Lokasi([
                'induk_id' => $ruang->id, 'jenis' => 'tempat_tidur', 'kode' => $isi['kode'], 'kode_lengkap' => $kode, 'nama' => 'TT '.$isi['kode'],
                'unit_organisasi_id' => $ruang->unit_organisasi_id, 'status' => 'aktif', 'status_berlaku_dari' => $berlakuDari,
            ]);
            $node->forceFill(['dibuat_oleh' => $penyunting->id, 'diubah_oleh' => $penyunting->id])->save();
            $tt = new TempatTidur([
                'kamar_id' => $kamar->id, 'lokasi_id' => $node->id, 'kode' => $kode, 'kelas_rawat_id' => $isi['kelas_rawat_id'],
                'aturan_jenis_kelamin' => $isi['aturan_jenis_kelamin'] ?? 'campur', 'isolasi' => $isi['isolasi'] ?? false,
                'ekstra' => $isi['ekstra'] ?? false, 'berlaku_dari' => $berlakuDari,
            ]);
            $tt->forceFill(['dibuat_oleh' => $penyunting->id, 'diubah_oleh' => $penyunting->id])->save();

            // Termasuk TT yang baru mulai dihitung di tanggal mendatang (belum ditutup).
            $belumDitutup = TempatTidur::query()->where('kamar_id', $kamar->id)
                ->where(fn ($q) => $q->whereNull('berlaku_sampai')->orWhere('berlaku_sampai', '>=', SuntinganBerversi::hariIni()))->count();
            $peringatan = $kamar->kapasitas_maksimum !== null && $belumDitutup > $kamar->kapasitas_maksimum
                ? ['melebihi_kapasitas'] : [];
            KapasitasTempatTidurBerubah::dispatch($bangsal->id, $berlakuDari);
            $this->audit->catat('master_data.tempat_tidur_dibuat', $penyunting->id, 'inti.tempat_tidur', $tt->id,
                [...$this->isiAudit($tt), 'peringatan' => $peringatan, 'dasar_perubahan' => $isi['dasar_perubahan']]);

            return [$tt, $peringatan];
        }));
    }

    /** @param  array<string, mixed>  $isi */
    public function ubah(Pengguna $penyunting, TempatTidur $tt, string $versi, array $isi): TempatTidur
    {
        return DB::transaction(function () use ($penyunting, $tt, $versi, $isi) {
            SuntinganBerversi::naikkan(TempatTidur::class, $tt->id, $versi, $penyunting->id, 'Tempat tidur');
            $tt->refresh();
            $this->pastikanBelumDitutup($tt);
            if (isset($isi['kelas_rawat_id'])) {
                $this->pastikanKelas($isi['kelas_rawat_id']);
            }
            $sebelum = $this->isiAudit($tt);
            $tt->fill(array_intersect_key($isi, array_flip(['kelas_rawat_id', 'aturan_jenis_kelamin', 'isolasi', 'ekstra'])));
            $tt->forceFill(['diubah_oleh' => $penyunting->id])->save();
            if ($tt->wasChanged(['kelas_rawat_id', 'ekstra'])) {
                KapasitasTempatTidurBerubah::dispatch($this->bangsalId($tt), SuntinganBerversi::hariIni());
            }
            $this->audit->catat('master_data.tempat_tidur_diubah', $penyunting->id, 'inti.tempat_tidur', $tt->id,
                [...$this->isiAudit($tt), 'dasar_perubahan' => $isi['dasar_perubahan']], $sebelum);

            return $tt;
        });
    }

    /** $mulai = hari pertama TT tidak dihitung (≥ hari ini). */
    public function tutup(Pengguna $penyunting, TempatTidur $tt, string $versi, string $mulai, string $alasan, string $dasar): TempatTidur
    {
        if ($mulai < SuntinganBerversi::hariIni()) {
            throw $this->tanggalTidakValid('berlaku_dari', 'Penutupan berlaku paling cepat hari ini.');
        }

        return DB::transaction(function () use ($penyunting, $tt, $versi, $mulai, $alasan, $dasar) {
            SuntinganBerversi::naikkan(TempatTidur::class, $tt->id, $versi, $penyunting->id, 'Tempat tidur');
            $tt->refresh();
            $this->pastikanBelumDitutup($tt);
            if ($mulai < $tt->berlaku_dari->toDateString()) {
                throw $this->tanggalTidakValid('berlaku_dari', 'Penutupan tidak bisa sebelum TT mulai berlaku.');
            }
            if ($this->penempatan->terisiAtauDipesan($tt->id, $mulai)) {
                throw new ApiError(409, 'tempat_tidur_terisi', 'TT terisi atau dipesan pada tanggal itu.');
            }
            $sebelum = $this->isiAudit($tt);
            $tt->forceFill([
                'berlaku_sampai' => Carbon::parse($mulai)->subDay()->toDateString(), 'alasan_tutup' => $alasan, 'diubah_oleh' => $penyunting->id,
            ])->save();
            // Status node lokasi TT diturunkan dari masa berlaku TT saat dibaca (LokasiResource), tidak disimpan ganda.
            KapasitasTempatTidurBerubah::dispatch($this->bangsalId($tt), $mulai);
            $this->audit->catat('master_data.tempat_tidur_ditutup', $penyunting->id, 'inti.tempat_tidur', $tt->id,
                [...$this->isiAudit($tt), 'mulai_tidak_dihitung' => $mulai, 'dasar_perubahan' => $dasar], $sebelum);

            return $tt;
        });
    }

    /** TT kamar itu yang dihitung pada tanggal (berlaku_dari ≤ T ≤ berlaku_sampai). */
    public static function jumlahDihitung(string $kamarId, string $tanggal): int
    {
        return TempatTidur::query()->where('kamar_id', $kamarId)->where('berlaku_dari', '<=', $tanggal)
            ->where(fn ($q) => $q->whereNull('berlaku_sampai')->orWhere('berlaku_sampai', '>=', $tanggal))->count();
    }

    private function pastikanBelumDitutup(TempatTidur $tt): void
    {
        if ($tt->berlaku_sampai !== null) {
            throw new ApiError(409, 'sudah_ditutup', 'TT ini sudah ditutup atau dijadwalkan tutup.');
        }
    }

    private function pastikanKelas(string $kelasId): void
    {
        if (KelasRawat::query()->whereKey($kelasId)->where('status', 'aktif')->sharedLock()->first() === null) {
            throw new ApiError(422, 'kelas_rawat_tidak_valid', 'Kelas rawat tidak ada atau nonaktif.', [['kolom' => 'kelas_rawat_id', 'pesan' => 'Pilih kelas yang aktif.']]);
        }
    }

    private function bangsalId(TempatTidur $tt): string
    {
        return (string) Kamar::query()->whereKey($tt->kamar_id)->value('bangsal_id');
    }

    private function tanggalTidakValid(string $kolom, string $pesan): ApiError
    {
        return new ApiError(422, 'tanggal_tidak_valid', $pesan, [['kolom' => $kolom, 'pesan' => $pesan]]);
    }

    /** @return array<string, mixed> */
    private function isiAudit(TempatTidur $tt): array
    {
        return [...$tt->only(['kamar_id', 'kode', 'kelas_rawat_id', 'aturan_jenis_kelamin', 'isolasi', 'ekstra', 'alasan_tutup']),
            'berlaku_dari' => $tt->berlaku_dari->toDateString(), 'berlaku_sampai' => $tt->berlaku_sampai?->toDateString()];
    }
}
