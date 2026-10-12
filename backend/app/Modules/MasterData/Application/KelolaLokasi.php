<?php

namespace App\Modules\MasterData\Application;

use App\Core\Audit\CatatAktivitas;
use App\Core\Http\ApiError;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\MasterData\Models\Lokasi;
use App\Modules\MasterData\Models\UnitOrganisasi;
use Illuminate\Support\Facades\DB;

/**
 * Tambah, ubah, pindah induk, dan ubah status lokasi fisik (tiket 1.8b, kontrak v0.5.0). Node jenis tempat_tidur
 * dikelola KelolaTempatTidur. Tanpa DELETE. Struktur pohon berurutan lewat PohonLokasi::kunci().
 */
final class KelolaLokasi
{
    public function __construct(
        private readonly CatatAktivitas $audit,
        private readonly PohonLokasi $pohon,
        private readonly CatatVersi $versi,
    ) {}

    /** @param  array{induk_id: string|null, jenis: string, kode: string, nama: string, fungsi?: string|null, unit_organisasi_id?: string|null, dasar_perubahan: string}  $isi */
    public function buat(Pengguna $penyunting, array $isi): Lokasi
    {
        if ($isi['jenis'] === 'tempat_tidur') {
            throw $this->lewatTempatTidur();
        }

        return SuntinganBerversi::tangkapKodeGanda(fn () => DB::transaction(function () use ($penyunting, $isi) {
            $this->pohon->kunci();
            $induk = $isi['induk_id'] === null ? null : Lokasi::query()->findOrFail($isi['induk_id']);
            $this->pastikanInduk($induk, $isi['jenis']);
            $this->pastikanFungsi($isi['jenis'], $isi['fungsi'] ?? null);
            $unitId = $this->unit($isi['unit_organisasi_id'] ?? null) ?? $induk?->unit_organisasi_id;

            $lokasi = new Lokasi([
                'induk_id' => $induk?->id, 'jenis' => $isi['jenis'], 'kode' => $isi['kode'],
                'kode_lengkap' => PohonLokasi::kodeLengkap($induk?->kode_lengkap, $isi['kode']), 'nama' => $isi['nama'],
                'fungsi' => $isi['fungsi'] ?? null, 'unit_organisasi_id' => $unitId, 'status' => 'aktif',
                'status_berlaku_dari' => SuntinganBerversi::hariIni(),
            ]);
            $lokasi->forceFill(['dibuat_oleh' => $penyunting->id, 'diubah_oleh' => $penyunting->id])->save();
            if ($lokasi->jenis === 'ruang') {
                $this->versi->catat('ruang', $lokasi->id, null, $this->isiAudit($lokasi), SuntinganBerversi::hariIni(), $isi['dasar_perubahan'], $penyunting->id);
            }
            $this->audit->catat('master_data.lokasi_dibuat', $penyunting->id, 'inti.lokasi', $lokasi->id,
                [...$this->isiAudit($lokasi), 'dasar_perubahan' => $isi['dasar_perubahan']]);

            return $lokasi;
        }));
    }

    /** @param  array<string, mixed>  $isi  isian PATCH (kontrak) */
    public function ubah(Pengguna $penyunting, Lokasi $lokasi, string $versi, array $isi): Lokasi
    {
        if ($lokasi->jenis === 'tempat_tidur') {
            throw $this->lewatTempatTidur();
        }

        return SuntinganBerversi::tangkapKodeGanda(fn () => DB::transaction(function () use ($penyunting, $lokasi, $versi, $isi) {
            $this->pohon->kunci();
            SuntinganBerversi::naikkan(Lokasi::class, $lokasi->id, $versi, $penyunting->id, 'Lokasi');
            $lokasi->refresh();
            $sebelum = $this->isiAudit($lokasi);

            $pindah = array_key_exists('induk_id', $isi) && $isi['induk_id'] !== $lokasi->induk_id;
            $induk = $lokasi->induk_id === null ? null : Lokasi::query()->find($lokasi->induk_id);
            if ($pindah) {
                if ($isi['induk_id'] !== null && in_array($isi['induk_id'], $this->pohon->subpohon($lokasi->id), true)) {
                    throw new ApiError(422, 'siklus', 'Induk baru tidak boleh lokasi ini sendiri atau turunannya.', [
                        ['kolom' => 'induk_id', 'pesan' => 'Pilih induk di luar cabang lokasi ini.'],
                    ]);
                }
                $induk = $isi['induk_id'] === null ? null : Lokasi::query()->findOrFail($isi['induk_id']);
                $this->pastikanInduk($induk, $lokasi->jenis);
            }
            if (array_key_exists('fungsi', $isi)) {
                $this->pastikanFungsi($lokasi->jenis, $isi['fungsi']);
            }
            if (array_key_exists('unit_organisasi_id', $isi)) {
                $isi['unit_organisasi_id'] = $this->unit($isi['unit_organisasi_id']);
            }
            if (isset($isi['status']) && $isi['status'] !== $lokasi->status) {
                $tanggal = $isi['status_berlaku_dari'] ?? SuntinganBerversi::hariIni();
                if ($tanggal > SuntinganBerversi::hariIni()) {
                    throw new ApiError(422, 'tanggal_tidak_valid', 'Status berlaku paling lambat hari ini; penjadwalan ke depan lewat usulan perubahan.', [
                        ['kolom' => 'status_berlaku_dari', 'pesan' => 'Isi tanggal hari ini atau sebelumnya.'],
                    ]);
                }
                if ($isi['status'] !== 'aktif') {
                    $this->pastikanTidakDipakai($lokasi, $isi['status'] === 'ditutup');
                }
                if ($lokasi->status === 'ditutup' && $induk !== null && ! $pindah) {
                    $this->pastikanInduk($induk, $lokasi->jenis);
                }
                $lokasi->status_berlaku_dari = $tanggal;
            }

            $lokasi->fill(array_intersect_key($isi, array_flip(['induk_id', 'kode', 'nama', 'fungsi', 'unit_organisasi_id', 'status'])));
            $lokasi->kode_lengkap = PohonLokasi::kodeLengkap($induk?->kode_lengkap, $lokasi->kode);
            $lokasi->forceFill(['diubah_oleh' => $penyunting->id])->save();
            if ($lokasi->wasChanged('kode_lengkap')) {
                $this->pohon->hitungUlangTurunan($lokasi->id);
            }
            // Versi data induk ruang (1.8c); lokasi jenis lain tidak lewat usulan.
            if ($lokasi->jenis === 'ruang') {
                $this->versi->catat('ruang', $lokasi->id, $sebelum, $this->isiAudit($lokasi),
                    $lokasi->wasChanged('status') ? $lokasi->status_berlaku_dari->toDateString() : SuntinganBerversi::hariIni(),
                    $isi['dasar_perubahan'], $penyunting->id);
            }
            $this->audit->catat('master_data.lokasi_diubah', $penyunting->id, 'inti.lokasi', $lokasi->id,
                [...$this->isiAudit($lokasi), 'dasar_perubahan' => $isi['dasar_perubahan']], $sebelum);

            return $lokasi;
        }));
    }

    private function pastikanInduk(?Lokasi $induk, string $jenis): void
    {
        if (! Lokasi::bolehAnak($induk?->jenis, $jenis)) {
            throw new ApiError(422, 'jenis_induk_tidak_sesuai', 'Jenis lokasi ini tidak bisa berada di bawah induk itu.', [
                ['kolom' => 'induk_id', 'pesan' => $induk === null ? 'Akar hanya situs atau gedung.' : "Di bawah {$induk->jenis} hanya: ".implode(', ', Lokasi::ANAK[$induk->jenis]).'.'],
            ]);
        }
        if ($induk !== null && $induk->status === 'ditutup') {
            throw new ApiError(422, 'induk_tidak_aktif', 'Induk sudah ditutup.', [['kolom' => 'induk_id', 'pesan' => 'Pilih induk yang belum ditutup.']]);
        }
    }

    private function pastikanFungsi(string $jenis, ?string $fungsi): void
    {
        if ($jenis === 'ruang' && $fungsi === null) {
            throw new ApiError(422, 'fungsi_wajib', 'Fungsi ruang wajib diisi.', [['kolom' => 'fungsi', 'pesan' => 'Pilih fungsi ruang.']]);
        }
        if ($jenis !== 'ruang' && $fungsi !== null) {
            throw new ApiError(422, 'fungsi_hanya_ruang', 'Fungsi hanya untuk ruang.', [['kolom' => 'fungsi', 'pesan' => 'Kosongkan fungsi.']]);
        }
    }

    /** Unit pengelola yang ada dan aktif; null = tidak diisi. */
    private function unit(?string $unitId): ?string
    {
        if ($unitId !== null && ! UnitOrganisasi::query()->whereKey($unitId)->where('status', 'aktif')->exists()) {
            throw new ApiError(422, 'unit_tidak_valid', 'Unit pengelola tidak ada atau nonaktif.', [['kolom' => 'unit_organisasi_id', 'pesan' => 'Pilih unit yang aktif.']]);
        }

        return $unitId;
    }

    /** Renovasi: tanpa TT yang belum ditutup dan poliklinik aktif. Ditutup: ditambah semua turunan non-TT sudah ditutup. */
    private function pastikanTidakDipakai(Lokasi $lokasi, bool $tutup): void
    {
        $subpohon = $this->pohon->subpohon($lokasi->id);
        $rincian = [
            'jumlah_anak_aktif' => ! $tutup ? 0 : Lokasi::query()->whereIn('id', $subpohon)->where('id', '!=', $lokasi->id)
                ->where('jenis', '!=', 'tempat_tidur')->where('status', '!=', 'ditutup')->count(),
            'jumlah_tempat_tidur' => DB::table('inti.tempat_tidur')->whereIn('lokasi_id', $subpohon)
                ->where(fn ($q) => $q->whereNull('berlaku_sampai')->orWhere('berlaku_sampai', '>=', SuntinganBerversi::hariIni()))->count(),
            'jumlah_poliklinik' => DB::table('inti.poliklinik')->whereIn('lokasi_id', $subpohon)->where('status', 'aktif')->count(),
        ];
        if (array_sum($rincian) > 0) {
            throw new ApiError(409, 'lokasi_masih_dipakai', 'Lokasi masih dipakai: tutup turunannya, TT-nya, atau pindahkan poliklinik lebih dulu.', [$rincian]);
        }
    }

    private function lewatTempatTidur(): ApiError
    {
        return new ApiError(422, 'kelola_lewat_tempat_tidur', 'Tempat tidur dikelola dari menu tempat tidur.', [['kolom' => 'jenis', 'pesan' => 'Gunakan tambah tempat tidur di kamar.']]);
    }

    /** @return array<string, mixed> */
    private function isiAudit(Lokasi $lokasi): array
    {
        return [...$lokasi->only(['induk_id', 'jenis', 'kode', 'kode_lengkap', 'nama', 'fungsi', 'unit_organisasi_id', 'status']),
            'status_berlaku_dari' => $lokasi->status_berlaku_dari->toDateString()];
    }
}
