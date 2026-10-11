<?php

namespace App\Core\Audit\Console;

use App\Core\Audit\BerkasAnchor;
use App\Core\Audit\RantaiAudit;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

final class VerifikasiRantai extends Command
{
    protected $signature = 'audit:verifikasi-rantai
        {--dari= : Tanggal awal (Y-m-d, zona config audit.zona_waktu)}
        {--sampai= : Tanggal akhir, ikut diperiksa (Y-m-d)}';

    protected $description = 'Memeriksa rantai hash audit.log_aktivitas dan anchor per jam; melaporkan baris pertama yang putus';

    public function handle(RantaiAudit $rantai, BerkasAnchor $berkas): int
    {
        $zona = config('audit.zona_waktu');
        $dari = $this->option('dari') ? CarbonImmutable::parse($this->option('dari'), $zona)->startOfDay() : null;
        $sampai = $this->option('sampai') ? CarbonImmutable::parse($this->option('sampai'), $zona)->addDay()->startOfDay() : null;

        $batas = $rantai->batasUrutan($dari, $sampai);
        if ($batas === null) {
            $this->info('Tidak ada baris audit pada rentang ini.');

            return $this->periksaAnchor($rantai, $berkas, null, $sampai === null) ? self::SUCCESS : self::FAILURE;
        }

        [$awal, $akhir] = $batas;
        $putus = $rantai->barisPutusPertama($awal, $akhir);
        if ($putus !== null) {
            // Hanya penanda baris; isi baris tidak dicetak karena bisa memuat data pribadi.
            $this->error("Rantai PUTUS pada urutan {$putus->urutan} (id {$putus->id}, terjadi_pada {$putus->terjadi_pada}).");

            return self::FAILURE;
        }

        $this->info("Rantai utuh: {$rantai->jumlahBaris($awal, $akhir)} baris, urutan {$awal}–{$akhir}.");

        return $this->periksaAnchor($rantai, $berkas, $batas, $sampai === null) ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Anchor menangkap hal yang tidak terlihat dari rantai saja: baris ekor yang dihapus atau
     * rantai yang dihitung ulang seluruhnya.
     *
     * @param  array{0: int, 1: int}|null  $batas
     */
    private function periksaAnchor(RantaiAudit $rantai, BerkasAnchor $berkas, ?array $batas, bool $sampaiEkor): bool
    {
        $diperiksa = 0;
        foreach ($berkas->semua() as $anchor) {
            $urutan = (int) $anchor['urutan'];
            if ($batas !== null && ($urutan < $batas[0] || (! $sampaiEkor && $urutan > $batas[1]))) {
                continue;
            }
            if ($batas === null && ! $sampaiEkor) {
                continue;
            }

            $baris = $rantai->barisDenganUrutan($urutan);
            if ($baris === null || $baris->id !== $anchor['id'] || trim($baris->hash_rantai) !== $anchor['hash_rantai']) {
                $this->error("Anchor {$anchor['batas']} TIDAK COCOK: urutan {$urutan} ".($baris === null ? 'hilang' : 'berbeda').'.');

                return false;
            }
            $diperiksa++;
        }

        $this->info("Anchor cocok: {$diperiksa}.");

        return true;
    }
}
