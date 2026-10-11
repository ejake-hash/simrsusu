<?php

namespace App\Modules\MasterData\Application;

use App\Core\Audit\CatatAktivitas;
use App\Core\Http\ApiError;
use App\Core\Http\IfMatch;
use App\Modules\Akses\Application\PemakaianCakupan;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\MasterData\Models\UnitOrganisasi;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Tambah, ubah, pindah induk, dan nonaktifkan unit organisasi (tiket 1.8a). Tanpa DELETE. Versi = revisi
 * (compare-and-set). Lingkup lapis 3 diperiksa pemanggil sebelum masuk sini kecuali induk baru (di sini).
 */
final class KelolaUnit
{
    public const IZIN_INPUT = 'master_data.organisasi.input';

    public const IZIN_PERBAIKI = 'master_data.organisasi.perbaiki';

    public function __construct(
        private readonly CatatAktivitas $audit,
        private readonly PohonUnit $pohon,
        private readonly LingkupUnit $lingkup,
        private readonly PemakaianCakupan $pemakaian,
    ) {}

    /** @param  array{induk_id: string|null, kode: string, nama: string, jenis: string, berlaku_dari?: string|null, dasar_perubahan: string}  $isi */
    public function buat(Pengguna $penyunting, array $isi): UnitOrganisasi
    {
        if (! $this->lingkup->bolehDi($penyunting, self::IZIN_INPUT, $isi['induk_id'])) {
            throw new ApiError(403, 'akses_ditolak', 'Anda tidak punya izin menambah unit di sini.');
        }

        return $this->tangkapKodeGanda(fn () => DB::transaction(function () use ($penyunting, $isi) {
            $this->kunciPohon();
            $this->pastikanIndukAktif($isi['induk_id']);
            $unit = new UnitOrganisasi([
                'induk_id' => $isi['induk_id'], 'kode' => $isi['kode'], 'nama' => $isi['nama'], 'jenis' => $isi['jenis'],
                'berlaku_dari' => $isi['berlaku_dari'] ?? now(config('instalasi.zona_waktu'))->toDateString(),
            ]);
            $unit->forceFill(['dibuat_oleh' => $penyunting->id, 'diubah_oleh' => $penyunting->id])->save();
            $this->audit->catat('master_data.unit_dibuat', $penyunting->id, 'inti.unit_organisasi', $unit->id,
                [...$this->isiAudit($unit), 'dasar_perubahan' => $isi['dasar_perubahan']]);

            return $unit;
        }));
    }

    /** @param  array<string, mixed>  $isi  isian PATCH (kontrak) */
    public function ubah(Pengguna $penyunting, UnitOrganisasi $unit, string $versi, array $isi): UnitOrganisasi
    {
        return $this->tangkapKodeGanda(fn () => DB::transaction(function () use ($penyunting, $unit, $versi, $isi) {
            // Lapis 3 diperiksa sesudah kunci: cabang yang dipindah bersamaan tidak bisa meloloskan suntingan di
            // unit yang sudah keluar dari cakupan penyunting.
            $this->kunciPohon();
            if (! $this->lingkup->bolehDi($penyunting, self::IZIN_PERBAIKI, $unit->id)) {
                throw new ApiError(403, 'akses_ditolak', 'Anda tidak punya izin mengubah unit ini.');
            }
            $pindah = array_key_exists('induk_id', $isi) && $isi['induk_id'] !== $unit->induk_id;
            if ($pindah && ! $this->lingkup->bolehDi($penyunting, self::IZIN_PERBAIKI, $isi['induk_id'])) {
                throw new ApiError(403, 'akses_ditolak', 'Anda tidak punya izin memindahkan unit ke induk itu.');
            }
            $revisi = IfMatch::revisi($versi);
            $naik = $revisi !== null && UnitOrganisasi::query()->whereKey($unit->id)->where('revisi', $revisi)
                ->update(['revisi' => DB::raw('revisi + 1'), 'diubah_oleh' => $penyunting->id]) === 1;
            if (! $naik) {
                throw new ApiError(412, 'versi_berubah', 'Unit ini diubah orang lain sejak Anda membukanya. Muat ulang untuk melihat perubahan terbaru.');
            }
            $unit->refresh();
            $sebelum = $this->isiAudit($unit);

            if ($pindah && $isi['induk_id'] !== null) {
                if (in_array($isi['induk_id'], $this->pohon->subpohon([$unit->id]), true)) {
                    throw new ApiError(422, 'siklus', 'Induk baru tidak boleh unit ini sendiri atau turunannya.', [
                        ['kolom' => 'induk_id', 'pesan' => 'Pilih induk di luar cabang unit ini.'],
                    ]);
                }
                $this->pastikanIndukAktif($isi['induk_id']);
            }
            if (($isi['status'] ?? null) === 'nonaktif' && $unit->status === 'aktif') {
                $this->pastikanTidakDipakai($unit);
            }

            $unit->fill(array_intersect_key($isi, array_flip(['induk_id', 'kode', 'nama', 'jenis', 'status', 'berlaku_dari'])));
            $unit->forceFill(['diubah_oleh' => $penyunting->id])->save();
            $this->audit->catat('master_data.unit_diubah', $penyunting->id, 'inti.unit_organisasi', $unit->id,
                [...$this->isiAudit($unit), 'dasar_perubahan' => $isi['dasar_perubahan']], $sebelum);

            return $unit;
        }));
    }

    /**
     * Perubahan struktur pohon berurutan sampai commit: dua pindah induk bersamaan tidak bisa saling lolos cek
     * siklus, dan cek induk aktif / masih dipakai tidak bisa didahului perubahan lain. Pohon unit kecil dan jarang
     * diubah, jadi satu kunci untuk seluruh pohon cukup.
     */
    private function kunciPohon(): void
    {
        DB::select("SELECT pg_advisory_xact_lock(hashtext('inti.unit_organisasi.pohon'))");
    }

    private function pastikanIndukAktif(?string $indukId): void
    {
        if ($indukId !== null && ! UnitOrganisasi::query()->whereKey($indukId)->where('status', 'aktif')->exists()) {
            throw new ApiError(422, 'induk_tidak_aktif', 'Induk harus unit yang ada dan aktif.', [
                ['kolom' => 'induk_id', 'pesan' => 'Pilih induk yang aktif.'],
            ]);
        }
    }

    private function pastikanTidakDipakai(UnitOrganisasi $unit): void
    {
        $rincian = [
            'jumlah_anak_aktif' => UnitOrganisasi::query()->where('induk_id', $unit->id)->where('status', 'aktif')->count(),
            'jumlah_penugasan' => $this->pemakaian->jumlahPenugasan('unit', $unit->id),
            'jumlah_modul_aktif' => DB::table('inti.modul_aktif')->where('unit_organisasi_id', $unit->id)->count(),
        ];
        if (array_sum($rincian) > 0) {
            throw new ApiError(409, 'unit_masih_dipakai', 'Unit masih dipakai: nonaktifkan anaknya, akhiri penugasannya, atau matikan modulnya lebih dulu.', [$rincian]);
        }
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
                ? new ApiError(409, 'kode_sudah_dipakai', 'Kode unit sudah dipakai.', [['kolom' => 'kode', 'pesan' => 'Kode unit sudah dipakai.']])
                : $e;
        }
    }

    /** @return array<string, mixed> */
    private function isiAudit(UnitOrganisasi $unit): array
    {
        return [...$unit->only(['induk_id', 'kode', 'nama', 'jenis', 'status']), 'berlaku_dari' => $unit->berlaku_dari->toDateString()];
    }
}
