<?php

namespace App\Modules\MasterData\Console;

use App\Core\Http\ApiError;
use App\Modules\MasterData\Application\PemuatReferensi;
use Illuminate\Console\Command;

/** `php artisan referensi:muat <sistem> <berkas>` (tiket 1.8e): aturan dan validasi sama dengan endpoint unggah wizard. */
final class MuatReferensi extends Command
{
    protected $signature = 'referensi:muat {sistem : wilayah|referensi|icd10|icd9cm|loinc|ucum} {berkas : Jalur CSV}
        {--versi= : Versi paket (wajib)} {--sumber= : Asal berkas (wajib)} {--lisensi= : Catatan lisensi/atribusi}
        {--pratinjau : Hanya periksa, tidak menyimpan}';

    protected $description = 'Memuat satu versi paket referensi dari CSV (snapshot; kode lama dinonaktifkan, tidak dihapus)';

    public function handle(PemuatReferensi $pemuat): int
    {
        $sistem = (string) $this->argument('sistem');
        $berkas = (string) $this->argument('berkas');
        $versi = trim((string) $this->option('versi'));
        $sumber = trim((string) $this->option('sumber'));
        if (! in_array($sistem, PemuatReferensi::SISTEM, true) || ! is_file($berkas) || $versi === '' || $sumber === '') {
            $this->error('Sistem tidak dikenal, berkas tidak ada, atau --versi/--sumber kosong.');

            return self::INVALID;
        }
        try {
            $hasil = $pemuat->muat($sistem, (string) file_get_contents($berkas),
                ['versi' => $versi, 'sumber' => $sumber, 'catatan_lisensi' => $this->option('lisensi')], ! $this->option('pratinjau'), null);
        } catch (ApiError $e) {
            $this->error($e->getMessage());
            foreach (array_slice($e->rincian, 0, 20) as $r) {
                $this->line(sprintf('  baris %s %s: %s', $r['baris'] ?? '-', $r['kolom'] ?? '', $r['pesan'] ?? ''));
            }

            return self::FAILURE;
        }
        foreach ($hasil['kesalahan'] as $r) {
            $this->line(sprintf('  baris %d %s: %s', $r['baris'], $r['kolom'] ?? '', $r['pesan']));
        }
        $this->info(sprintf('%s %s: %d baris, %d baru, %d diubah, %d dinonaktifkan, %d diaktifkan lagi%s.', $sistem, $versi, $hasil['jumlah_baris'],
            $hasil['jumlah_baru'], $hasil['jumlah_diubah'], $hasil['jumlah_dinonaktifkan'], $hasil['jumlah_diaktifkan_lagi'], $hasil['diterapkan'] ? '' : ' (belum disimpan)'));

        return $hasil['kesalahan'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
