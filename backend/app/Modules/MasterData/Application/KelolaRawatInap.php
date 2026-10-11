<?php

namespace App\Modules\MasterData\Application;

use App\Core\Audit\CatatAktivitas;
use App\Core\Http\ApiError;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\MasterData\Models\Bangsal;
use App\Modules\MasterData\Models\Kamar;
use App\Modules\MasterData\Models\KelasRawat;
use App\Modules\MasterData\Models\Lokasi;
use App\Modules\MasterData\Models\Poliklinik;
use App\Modules\MasterData\Models\TempatTidur;
use App\Modules\MasterData\Models\UnitOrganisasi;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Data induk pelengkap lokasi (tiket 1.8b, kontrak v0.5.0): kelas rawat, bangsal, kamar, poliklinik. Tanpa DELETE
 * (status nonaktif). Versi = revisi (412 bila berubah). Kode Aplicares dan kode poli BPJS diisi RS apa adanya.
 */
final class KelolaRawatInap
{
    public function __construct(private readonly CatatAktivitas $audit) {}

    /** @param  array{kode: string, nama: string, kode_aplicares?: string|null, berlaku_dari?: string|null, dasar_perubahan: string}  $isi */
    public function buatKelas(Pengguna $penyunting, array $isi): KelasRawat
    {
        return $this->simpanBaru($penyunting, new KelasRawat([
            'kode' => $isi['kode'], 'nama' => $isi['nama'], 'kode_aplicares' => $isi['kode_aplicares'] ?? null,
            'berlaku_dari' => $isi['berlaku_dari'] ?? SuntinganBerversi::hariIni(), 'status' => 'aktif',
        ]), 'master_data.kelas_rawat_dibuat', $isi['dasar_perubahan']);
    }

    /** @param  array<string, mixed>  $isi */
    public function ubahKelas(Pengguna $penyunting, KelasRawat $kelas, string $versi, array $isi): KelasRawat
    {
        return $this->simpanUbah($penyunting, $kelas, $versi, $isi, ['kode', 'nama', 'kode_aplicares', 'status'], 'Kelas rawat',
            'master_data.kelas_rawat_diubah', function (KelasRawat $k) use ($isi) {
                if (($isi['status'] ?? null) === 'nonaktif' && $k->status === 'aktif') {
                    $jumlah = $this->ttBelumDitutup()->where('kelas_rawat_id', $k->id)->count();
                    if ($jumlah > 0) {
                        throw new ApiError(409, 'kelas_masih_dipakai', 'Masih ada TT di kelas ini yang belum ditutup.', [['jumlah_tempat_tidur' => $jumlah]]);
                    }
                }
            });
    }

    /** @param  array{kode: string, nama: string, unit_organisasi_id: string, dasar_perubahan: string}  $isi */
    public function buatBangsal(Pengguna $penyunting, array $isi): Bangsal
    {
        $this->pastikanUnit($isi['unit_organisasi_id']);

        return $this->simpanBaru($penyunting, new Bangsal([
            'kode' => $isi['kode'], 'nama' => $isi['nama'], 'unit_organisasi_id' => $isi['unit_organisasi_id'], 'status' => 'aktif',
        ]), 'master_data.bangsal_dibuat', $isi['dasar_perubahan']);
    }

    /** @param  array<string, mixed>  $isi */
    public function ubahBangsal(Pengguna $penyunting, Bangsal $bangsal, string $versi, array $isi): Bangsal
    {
        if (isset($isi['unit_organisasi_id'])) {
            $this->pastikanUnit($isi['unit_organisasi_id']);
        }

        return $this->simpanUbah($penyunting, $bangsal, $versi, $isi, ['kode', 'nama', 'unit_organisasi_id', 'status'], 'Bangsal',
            'master_data.bangsal_diubah', function (Bangsal $b) use ($isi) {
                if (($isi['status'] ?? null) === 'nonaktif' && $b->status === 'aktif') {
                    $jumlah = $this->ttBelumDitutup()->whereIn('kamar_id', Kamar::query()->where('bangsal_id', $b->id)->select('id'))->count();
                    if ($jumlah > 0) {
                        throw new ApiError(409, 'bangsal_masih_dipakai', 'Masih ada TT di bangsal ini yang belum ditutup.', [['jumlah_tempat_tidur' => $jumlah]]);
                    }
                }
            });
    }

    /** @param  array{bangsal_id: string, lokasi_id: string, kapasitas_maksimum?: int|null, atribut_standar?: array<string, mixed>|null, dasar_perubahan: string}  $isi */
    public function buatKamar(Pengguna $penyunting, array $isi): Kamar
    {
        $this->pastikanBangsal($isi['bangsal_id']);

        return DB::transaction(function () use ($penyunting, $isi) {
            $ruang = Lokasi::query()->whereKey($isi['lokasi_id'])->lockForUpdate()->first();
            if ($ruang === null || $ruang->jenis !== 'ruang' || $ruang->status !== 'aktif') {
                throw new ApiError(422, 'bukan_ruang', 'Kamar harus berupa ruang yang aktif.', [['kolom' => 'lokasi_id', 'pesan' => 'Pilih ruang yang aktif.']]);
            }
            if (Kamar::query()->where('lokasi_id', $ruang->id)->exists()) {
                throw new ApiError(409, 'ruang_sudah_kamar', 'Ruang ini sudah menjadi kamar.');
            }
            $kamar = new Kamar([
                'bangsal_id' => $isi['bangsal_id'], 'lokasi_id' => $ruang->id, 'kapasitas_maksimum' => $isi['kapasitas_maksimum'] ?? null,
                'atribut_standar' => $isi['atribut_standar'] ?? [],
            ]);
            $kamar->forceFill(['dibuat_oleh' => $penyunting->id, 'diubah_oleh' => $penyunting->id])->save();
            $this->audit->catat('master_data.kamar_dibuat', $penyunting->id, 'inti.kamar', $kamar->id,
                [...$this->isiAudit($kamar), 'dasar_perubahan' => $isi['dasar_perubahan']]);

            return $kamar;
        });
    }

    /** @param  array<string, mixed>  $isi */
    public function ubahKamar(Pengguna $penyunting, Kamar $kamar, string $versi, array $isi): Kamar
    {
        if (isset($isi['bangsal_id'])) {
            $this->pastikanBangsal($isi['bangsal_id']);
        }

        return $this->simpanUbah($penyunting, $kamar, $versi, $isi, ['bangsal_id', 'kapasitas_maksimum', 'atribut_standar'], 'Kamar', 'master_data.kamar_diubah');
    }

    /** @param  array{kode: string, nama: string, unit_organisasi_id: string, lokasi_id?: string|null, kode_poli_bpjs?: string|null, dasar_perubahan: string}  $isi */
    public function buatPoliklinik(Pengguna $penyunting, array $isi): Poliklinik
    {
        $this->pastikanUnit($isi['unit_organisasi_id']);
        $this->pastikanRuang($isi['lokasi_id'] ?? null);

        return $this->simpanBaru($penyunting, new Poliklinik([
            'kode' => $isi['kode'], 'nama' => $isi['nama'], 'unit_organisasi_id' => $isi['unit_organisasi_id'],
            'lokasi_id' => $isi['lokasi_id'] ?? null, 'kode_poli_bpjs' => $isi['kode_poli_bpjs'] ?? null, 'status' => 'aktif',
        ]), 'master_data.poliklinik_dibuat', $isi['dasar_perubahan']);
    }

    /** @param  array<string, mixed>  $isi */
    public function ubahPoliklinik(Pengguna $penyunting, Poliklinik $poli, string $versi, array $isi): Poliklinik
    {
        if (isset($isi['unit_organisasi_id'])) {
            $this->pastikanUnit($isi['unit_organisasi_id']);
        }
        if (array_key_exists('lokasi_id', $isi)) {
            $this->pastikanRuang($isi['lokasi_id']);
        }

        return $this->simpanUbah($penyunting, $poli, $versi, $isi, ['kode', 'nama', 'unit_organisasi_id', 'lokasi_id', 'kode_poli_bpjs', 'status'],
            'Poliklinik', 'master_data.poliklinik_diubah');
    }

    /** @return Builder<TempatTidur> */
    private function ttBelumDitutup(): Builder
    {
        return TempatTidur::query()->where(fn ($q) => $q->whereNull('berlaku_sampai')->orWhere('berlaku_sampai', '>=', SuntinganBerversi::hariIni()));
    }

    /**
     * @template T of Model
     *
     * @param  T  $baris
     * @return T
     */
    private function simpanBaru(Pengguna $penyunting, Model $baris, string $aksi, string $dasar): Model
    {
        return SuntinganBerversi::tangkapKodeGanda(fn () => DB::transaction(function () use ($penyunting, $baris, $aksi, $dasar) {
            $baris->forceFill(['dibuat_oleh' => $penyunting->id, 'diubah_oleh' => $penyunting->id])->save();
            $this->audit->catat($aksi, $penyunting->id, $baris->getTable(), (string) $baris->getKey(), [...$this->isiAudit($baris), 'dasar_perubahan' => $dasar]);

            return $baris;
        }));
    }

    /**
     * @template T of Model
     *
     * @param  T  $baris
     * @param  array<string, mixed>  $isi
     * @param  list<string>  $kolom  kolom yang boleh diubah
     * @param  (callable(T): void)|null  $periksa  dipanggil sesudah versi dikunci, sebelum diubah
     * @return T
     */
    private function simpanUbah(Pengguna $penyunting, Model $baris, string $versi, array $isi, array $kolom, string $label, string $aksi, ?callable $periksa = null): Model
    {
        return SuntinganBerversi::tangkapKodeGanda(fn () => DB::transaction(function () use ($penyunting, $baris, $versi, $isi, $kolom, $label, $aksi, $periksa) {
            SuntinganBerversi::naikkan($baris::class, (string) $baris->getKey(), $versi, $penyunting->id, $label);
            $baris->refresh();
            $sebelum = $this->isiAudit($baris);
            if ($periksa !== null) {
                $periksa($baris);
            }
            $baris->fill(array_intersect_key($isi, array_flip($kolom)));
            $baris->forceFill(['diubah_oleh' => $penyunting->id])->save();
            $this->audit->catat($aksi, $penyunting->id, $baris->getTable(), (string) $baris->getKey(),
                [...$this->isiAudit($baris), 'dasar_perubahan' => $isi['dasar_perubahan']], $sebelum);

            return $baris;
        }));
    }

    private function pastikanUnit(string $unitId): void
    {
        if (! UnitOrganisasi::query()->whereKey($unitId)->where('status', 'aktif')->exists()) {
            throw new ApiError(422, 'unit_tidak_valid', 'Unit tidak ada atau nonaktif.', [['kolom' => 'unit_organisasi_id', 'pesan' => 'Pilih unit yang aktif.']]);
        }
    }

    private function pastikanBangsal(string $bangsalId): void
    {
        if (! Bangsal::query()->whereKey($bangsalId)->where('status', 'aktif')->exists()) {
            throw new ApiError(422, 'bangsal_tidak_valid', 'Bangsal tidak ada atau nonaktif.', [['kolom' => 'bangsal_id', 'pesan' => 'Pilih bangsal yang aktif.']]);
        }
    }

    private function pastikanRuang(?string $lokasiId): void
    {
        if ($lokasiId !== null && ! Lokasi::query()->whereKey($lokasiId)->where('jenis', 'ruang')->where('status', 'aktif')->exists()) {
            throw new ApiError(422, 'bukan_ruang', 'Lokasi harus ruang yang aktif.', [['kolom' => 'lokasi_id', 'pesan' => 'Pilih ruang yang aktif.']]);
        }
    }

    /** @return array<string, mixed> tanpa kolom teknis */
    private function isiAudit(Model $baris): array
    {
        return array_diff_key($baris->attributesToArray(), array_flip(['id', 'dibuat_pada', 'dibuat_oleh', 'diubah_pada', 'diubah_oleh', 'revisi']));
    }
}
