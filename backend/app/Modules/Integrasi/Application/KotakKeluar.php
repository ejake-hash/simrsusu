<?php

namespace App\Modules\Integrasi\Application;

use App\Core\Audit\CatatAktivitas;
use App\Core\Http\ApiError;
use App\Modules\Integrasi\Domain\SistemIntegrasi;
use App\Modules\Integrasi\Jobs\KirimKiriman;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Outbox `integrasi.kotak_keluar` (PRD 10; kontrak v0.13.0). Modul lain memanggil antrekan() di dalam transaksi
 * bisnisnya; pekerja di queue `integrasi` mengirim sesudah commit. Kiriman terikat ke lingkungan aktif saat dibuat dan
 * tidak pernah berpindah lingkungan secara otomatis.
 */
final class KotakKeluar
{
    /** Penyebab tertahan yang hanya selesai lewat kirim ulang eksplisit (tidak dicek ulang otomatis, FR-49-05). */
    public const TAHAN_MANUAL = ['lingkungan_berubah', 'integrasi_nonaktif'];

    public function __construct(
        private readonly KelolaKredensial $kredensial,
        private readonly CatatAktivitas $audit,
    ) {}

    /**
     * Kiriman baru, atau muatan kiriman yang masih menunggu untuk agregat + jenis yang sama diganti (status terkini
     * menang, mis. ketersediaan TT berubah dua kali sebelum terkirim).
     *
     * @param  array<string, mixed>  $muatan
     */
    public function antrekan(SistemIntegrasi $tujuan, string $jenisResource, string $agregatJenis, string $agregatId, array $muatan = [], ?string $pemilikUnitId = null): string
    {
        // Hanya kiriman yang belum pernah dicoba dan tidak sedang disewa pekerja yang muatannya boleh diganti: kiriman
        // yang sedang dikirim atau sudah pernah dicoba (kunci idempotensinya sudah dipakai) tidak disentuh, jadi muatan
        // baru menjadi kiriman baru. Syarat dicek ulang di UPDATE (READ COMMITTED mengevaluasi ulang sesudah kunci baris).
        $ada = DB::table('integrasi.kotak_keluar')->where('agregat_jenis', $agregatJenis)->where('agregat_id', $agregatId)
            ->where('tujuan', $tujuan->value)->where('jenis_resource', $jenisResource)->where('status', 'menunggu')
            ->where('percobaan', 0)->where('coba_lagi_pada', '<=', now())->first(['id', 'dibuat_pada']);
        if ($ada !== null && DB::table('integrasi.kotak_keluar')->where('id', $ada->id)->where('dibuat_pada', $ada->dibuat_pada)
            ->where('status', 'menunggu')->where('percobaan', 0)->where('coba_lagi_pada', '<=', now())
            ->update(['muatan' => json_encode((object) $muatan), 'pemilik_unit_id' => $pemilikUnitId, 'diubah_pada' => now()]) === 1) {
            return $ada->id;
        }
        $lingkungan = $this->kredensial->lingkunganAktif($tujuan);
        $id = (string) Str::uuid7();
        DB::table('integrasi.kotak_keluar')->insert([
            'id' => $id, 'tujuan' => $tujuan->value, 'lingkungan' => $lingkungan, 'jenis_resource' => $jenisResource,
            'agregat_jenis' => $agregatJenis, 'agregat_id' => $agregatId, 'muatan' => json_encode((object) $muatan),
            'kunci_idempotensi' => (string) Str::uuid7(), 'pemilik_unit_id' => $pemilikUnitId,
            ...($lingkungan === null
                ? ['status' => 'tertahan', 'penyebab_tertahan_kode' => 'integrasi_nonaktif', 'penyebab_tertahan_pesan' => 'Integrasi '.$tujuan->nama().' belum diaktifkan.']
                : ['status' => 'menunggu', 'coba_lagi_pada' => now()]),
        ]);
        if ($lingkungan !== null) {
            KirimKiriman::dispatch($id);
        }

        return $id;
    }

    /**
     * POST /integrasi/kotak-keluar/{id}/kirim-ulang. Idempotency key tetap; kiriman berlingkungan null diikat ke
     * lingkungan aktif (aksi eksplisit pengguna).
     */
    public function kirimUlang(object $baris, string $olehId): void
    {
        if (! in_array($baris->status, ['gagal', 'tertahan'], true)) {
            throw new ApiError(409, 'kiriman_tidak_bisa_dikirim_ulang', 'Kiriman ini sedang menunggu atau sudah terkirim.');
        }
        $tujuan = SistemIntegrasi::from($baris->tujuan);
        $aktif = $this->kredensial->lingkunganAktif($tujuan);
        if ($aktif === null || ($baris->lingkungan !== null && $baris->lingkungan !== $aktif)) {
            throw new ApiError(409, 'lingkungan_tidak_aktif', $aktif === null
                ? 'Integrasi '.$tujuan->nama().' belum punya lingkungan aktif.'
                : 'Kiriman ini dibuat untuk lingkungan '.$baris->lingkungan.', sedangkan yang aktif '.$aktif.'. Kiriman tidak dipindah lingkungan.');
        }
        DB::transaction(function () use ($baris, $aktif, $olehId) {
            $diubah = DB::table('integrasi.kotak_keluar')->where('id', $baris->id)->where('dibuat_pada', $baris->dibuat_pada)
                ->whereIn('status', ['gagal', 'tertahan'])->update([
                    'status' => 'menunggu', 'lingkungan' => $aktif, 'percobaan' => 0, 'coba_lagi_pada' => now(),
                    'penyebab_tertahan_kode' => null, 'penyebab_tertahan_pesan' => null, 'diubah_pada' => now(),
                ]);
            if ($diubah !== 1) {
                throw new ApiError(409, 'kiriman_tidak_bisa_dikirim_ulang', 'Kiriman ini baru saja berubah status. Muat ulang.');
            }
            $this->audit->catat('integrasi.kirim_ulang', $olehId, 'integrasi.kotak_keluar', $baris->id, [
                'tujuan' => $baris->tujuan, 'jenis_resource' => $baris->jenis_resource, 'status_sebelum' => $baris->status, 'lingkungan' => $aktif,
            ]);
            KirimKiriman::dispatch($baris->id);
        });
    }

    public static function cari(string $id): ?object
    {
        return DB::table('integrasi.kotak_keluar')->where('id', $id)->first();
    }
}
