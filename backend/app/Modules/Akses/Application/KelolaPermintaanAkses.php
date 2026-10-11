<?php

namespace App\Modules\Akses\Application;

use App\Core\Audit\CatatAktivitas;
use App\Core\Http\ApiError;
use App\Modules\Akses\Models\Izin;
use App\Modules\Akses\Models\Menu;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\Akses\Models\PermintaanAkses;
use Illuminate\Support\Facades\DB;

/**
 * Permintaan akses dari pengguna (layar "Akses ditolak", PRD 9.3; tiket 1.2d). Ditindaklanjuti pemberi kewenangan
 * dengan penugasan/pengecualian yang membawa permintaan_akses_id (dasar = nomor permintaan), atau ditolak.
 */
final class KelolaPermintaanAkses
{
    public function __construct(
        private readonly CatatAktivitas $audit,
        private readonly AccessEvaluator $evaluator,
        private readonly PenjagaPenyunting $penjaga,
    ) {}

    public function buat(Pengguna $pengguna, string $sasaranJenis, string $sasaranKode, string $alasan): PermintaanAkses
    {
        [$menuId, $izinId] = $sasaranJenis === 'menu'
            ? [Menu::query()->where('kode', $sasaranKode)->whereIn('tingkat', ['sub_menu', 'bagian'])->where('aktif', true)->value('id'), null]
            : [null, Izin::query()->where('kode', $sasaranKode)->value('id')];
        if ($menuId === null && $izinId === null) {
            throw new ApiError(422, 'sasaran_tidak_dikenal', 'Halaman atau aksi yang diminta tidak dikenal.', [
                ['kolom' => 'sasaran_kode', 'pesan' => 'Kode sub-menu, bagian, atau izin tidak ditemukan.'],
            ]);
        }
        $sudah = $sasaranJenis === 'menu' ? $this->evaluator->bolehMenu($pengguna, $sasaranKode) : $this->evaluator->boleh($pengguna, $sasaranKode);
        if ($sudah) {
            throw new ApiError(409, 'sudah_punya_akses', 'Anda sudah punya akses ini. Muat ulang halaman.');
        }

        return DB::transaction(function () use ($pengguna, $menuId, $izinId, $alasan, $sasaranKode) {
            Pengguna::query()->whereKey($pengguna->id)->lockForUpdate()->first();
            $ganda = PermintaanAkses::query()->where('pengguna_id', $pengguna->id)->where('status', 'menunggu')
                ->where($menuId !== null ? 'menu_id' : 'izin_id', $menuId ?? $izinId)->exists();
            if ($ganda) {
                throw new ApiError(409, 'permintaan_ganda', 'Permintaan yang sama masih menunggu.');
            }

            $urut = (int) DB::selectOne("SELECT nextval('akses.permintaan_akses_nomor_seq') AS n")->n;
            $permintaan = new PermintaanAkses([
                'nomor' => sprintf('PMA-%s-%06d', now(config('instalasi.zona_waktu'))->format('Y'), $urut),
                'pengguna_id' => $pengguna->id, 'menu_id' => $menuId, 'izin_id' => $izinId, 'alasan' => $alasan, 'status' => 'menunggu',
                'dibuat_oleh' => $pengguna->id, 'diubah_oleh' => $pengguna->id,
            ]);
            $permintaan->save();
            $this->audit->catat('akses.permintaan_dibuat', $pengguna->id, 'akses.permintaan_akses', $permintaan->id, [
                'nomor' => $permintaan->nomor, 'sasaran' => $sasaranKode, 'alasan' => $alasan,
            ]);

            return $permintaan;
        });
    }

    /** Penjaga sama dengan pemberian: bukan permintaan sendiri, dan akun superadmin hanya oleh superadmin lain. */
    public function tolak(Pengguna $penyunting, PermintaanAkses $permintaan, string $alasan): PermintaanAkses
    {
        $this->penjaga->pastikanBolehMengubahAkun($penyunting, Pengguna::query()->findOrFail($permintaan->pengguna_id));

        return DB::transaction(function () use ($penyunting, $permintaan, $alasan) {
            $permintaan = PermintaanAkses::query()->whereKey($permintaan->id)->lockForUpdate()->firstOrFail();
            $this->pastikanMenunggu($permintaan);
            $permintaan->forceFill(['status' => 'ditolak', 'diputuskan_oleh' => $penyunting->id, 'diputuskan_pada' => now(),
                'catatan_keputusan' => $alasan, 'diubah_oleh' => $penyunting->id])->save();
            $this->audit->catat('akses.permintaan_ditolak', $penyunting->id, 'akses.permintaan_akses', $permintaan->id, [
                'nomor' => $permintaan->nomor, 'alasan' => $alasan,
            ]);

            return $permintaan;
        });
    }

    /**
     * Tandai permintaan ditindaklanjuti (dipanggil dalam transaksi pemberian). Null = tanpa permintaan.
     * Pemanggil sudah memastikan penyunting bukan akun itu sendiri.
     */
    public function tindaklanjuti(?string $permintaanId, Pengguna $akun, Pengguna $penyunting): void
    {
        if ($permintaanId === null) {
            return;
        }
        $permintaan = PermintaanAkses::query()->whereKey($permintaanId)->lockForUpdate()->first();
        if ($permintaan === null || $permintaan->pengguna_id !== $akun->id || $permintaan->status !== 'menunggu') {
            throw new ApiError(422, 'permintaan_tidak_valid', 'Permintaan akses tidak ditemukan untuk akun ini atau sudah diputuskan.', [
                ['kolom' => 'permintaan_akses_id', 'pesan' => 'Pilih permintaan yang masih menunggu milik akun ini.'],
            ]);
        }
        $this->penjaga->pastikanBolehMengubahAkun($penyunting, $akun);
        $permintaan->forceFill(['status' => 'ditindaklanjuti', 'diputuskan_oleh' => $penyunting->id, 'diputuskan_pada' => now(),
            'diubah_oleh' => $penyunting->id])->save();
        $this->audit->catat('akses.permintaan_ditindaklanjuti', $penyunting->id, 'akses.permintaan_akses', $permintaan->id, ['nomor' => $permintaan->nomor]);
    }

    private function pastikanMenunggu(PermintaanAkses $permintaan): void
    {
        if ($permintaan->status !== 'menunggu') {
            throw new ApiError(409, 'sudah_diputuskan', 'Permintaan ini sudah diputuskan.');
        }
    }
}
