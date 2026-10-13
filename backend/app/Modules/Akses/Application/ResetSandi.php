<?php

namespace App\Modules\Akses\Application;

use App\Core\Audit\CatatAktivitas;
use App\Core\Http\ApiError;
use App\Modules\Akses\Models\Pengguna;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Reset sandi akun yang lupa sandi (tiket 1.14; PRD 14.2): sandi sementara acak dikembalikan sekali (tidak disimpan
 * terbaca, tidak dicatat), akun wajib ganti sandi saat masuk, semua sesi diputus, penguncian karena sandi salah dibuka.
 * MFA tidak disentuh (autentikator hilang = reset MFA). Lewat layar oleh admin, atau lewat konsol server bila tidak ada
 * superadmin lain.
 */
final class ResetSandi
{
    public const AUDIT = 'akses.sandi_direset';

    public const AUDIT_KONSOL = 'akses.sandi_direset_konsol';

    public function __construct(
        private readonly CatatAktivitas $audit,
        private readonly PenjagaPenyunting $penjaga,
    ) {}

    /**
     * Bukan akun sendiri; akun pemegang superadmin hanya oleh superadmin lain (K4/K13, PenjagaPenyunting).
     *
     * @return array{0: Pengguna, 1: string} akun dan sandi sementara
     */
    public function olehAdmin(Pengguna $penyunting, Pengguna $akun, string $alasan, string $dasar): array
    {
        $this->penjaga->pastikanBolehMengubahAkun($penyunting, $akun);

        return $this->reset($akun, $penyunting->id, self::AUDIT, ['alasan' => $alasan, 'dasar' => $dasar]);
    }

    /**
     * Konsol server (`akses:reset-sandi`): tanpa akun pelaku; yang tercatat pengguna sistem operasi yang menjalankan.
     *
     * @return array{0: Pengguna, 1: string}
     */
    public function lewatKonsol(Pengguna $akun, string $alasan, string $dasar, string $penggunaOs): array
    {
        return $this->reset($akun, null, self::AUDIT_KONSOL, ['alasan' => $alasan, 'dasar' => $dasar, 'pengguna_os' => $penggunaOs]);
    }

    /**
     * @param  array<string, string>  $catatan
     * @return array{0: Pengguna, 1: string}
     */
    private function reset(Pengguna $akun, ?string $pelakuId, string $aksi, array $catatan): array
    {
        return DB::transaction(function () use ($akun, $pelakuId, $aksi, $catatan) {
            $akun = Pengguna::query()->whereKey($akun->id)->lockForUpdate()->firstOrFail();
            if ($akun->status !== 'aktif') {
                throw new ApiError(409, 'akun_nonaktif', 'Akun ini nonaktif. Aktifkan dulu sebelum mereset sandi.');
            }

            // Sama dengan sandi awal akun baru (AkunPegawai): 20 karakter acak, memenuhi aturan panjang PRD 14.2.
            $sandi = Str::password(20);
            $akun->forceFill([
                'sandi_hash' => Hash::make($sandi), 'wajib_ganti_sandi' => true,
                'percobaan_gagal' => 0, 'terkunci_sampai' => null, 'diubah_oleh' => $pelakuId,
            ])->save();
            $akun->cabutSesi();
            // Tanpa nilai sandi (PRD 14.2). catat() di akhir transaksi (ADR 0003).
            $this->audit->catat($aksi, $pelakuId, 'akses.pengguna', $akun->id, [...$catatan, 'sesi_diputus' => true]);

            return [$akun, $sandi];
        });
    }
}
