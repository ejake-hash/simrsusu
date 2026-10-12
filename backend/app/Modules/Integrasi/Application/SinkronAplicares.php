<?php

namespace App\Modules\Integrasi\Application;

use App\Modules\Integrasi\Domain\SistemIntegrasi;
use App\Modules\MasterData\Application\DataIntegrasi;
use App\Modules\MasterData\Application\SuntinganBerversi;
use App\Modules\MasterData\Events\KapasitasTempatTidurBerubah;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pemicu kiriman Aplicares (tiket 1.9 butir 5). Aplicares melaporkan keadaan, bukan riwayat: tidak ada kiriman selama
 * integrasi belum aktif, dan bangsal yang sudah punya kiriman tertahan (data induk kurang) tidak diberi kiriman baru
 * karena isi dibaca saat dikirim. Keadaan disusulkan oleh sinkron menyeluruh: harian (juga menangkap perubahan TT
 * bertanggal ke depan dan perubahan yang tidak memicu kejadian) atau `php artisan integrasi:sinkron-aplicares`.
 */
final class SinkronAplicares
{
    public function __construct(
        private readonly KotakKeluar $kotakKeluar,
        private readonly KelolaKredensial $kredensial,
        private readonly DataIntegrasi $data,
    ) {}

    /**
     * Kejadian sesudah commit dari modul MasterData. Kegagalan di sini tidak menggagalkan permintaan pengguna (perubahan TT
     * sudah tersimpan); sinkron harian menyusulkannya.
     */
    public function kapasitasBerubah(KapasitasTempatTidurBerubah $kejadian): void
    {
        try {
            $lingkungan = $this->kredensial->lingkunganAktif(SistemIntegrasi::Aplicares);
            if ($kejadian->mulaiTanggal <= SuntinganBerversi::hariIni() && $lingkungan !== null) {
                $this->antrekan($kejadian->bangsalId, $lingkungan);
            }
        } catch (Throwable $e) {
            Log::warning('Kiriman Aplicares tidak bisa diantrekan', ['bangsal_id' => $kejadian->bangsalId, 'exception' => $e::class]);
        }
    }

    /** @return int jumlah bangsal yang diperiksa */
    public function semua(): int
    {
        $lingkungan = $this->kredensial->lingkunganAktif(SistemIntegrasi::Aplicares);
        if ($lingkungan === null) {
            return 0;
        }
        // Termasuk bangsal yang kini tanpa TT tetapi pernah terkirim dengan kapasitas > 0 (kamar dipindah ke bangsal lain).
        $bangsal = array_values(array_unique([...$this->data->bangsalBerTempatTidur(),
            ...DB::table('integrasi.ruang_aplicares')->where('lingkungan', $lingkungan)->where('kapasitas', '>', 0)->distinct()->pluck('bangsal_id')->all()]));
        foreach ($bangsal as $id) {
            $this->antrekan($id, $lingkungan);
        }

        return count($bangsal);
    }

    private function antrekan(string $bangsalId, string $lingkungan): void
    {
        $tertahan = DB::table('integrasi.kotak_keluar')->where('agregat_jenis', 'bangsal')->where('agregat_id', $bangsalId)
            ->where('tujuan', SistemIntegrasi::Aplicares->value)->where('jenis_resource', PenanganAplicares::JENIS)
            ->where('lingkungan', $lingkungan)->where('status', 'tertahan')->whereNotIn('penyebab_tertahan_kode', KotakKeluar::TAHAN_MANUAL)->exists();
        if (! $tertahan) {
            $this->kotakKeluar->antrekan(SistemIntegrasi::Aplicares, PenanganAplicares::JENIS, 'bangsal', $bangsalId);
        }
    }
}
