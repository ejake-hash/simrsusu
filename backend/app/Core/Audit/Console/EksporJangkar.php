<?php

namespace App\Core\Audit\Console;

use App\Core\Audit\BerkasJangkar;
use App\Core\Audit\RantaiAudit;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

final class EksporJangkar extends Command
{
    protected $signature = 'audit:ekspor-jangkar
        {--sampai= : Batas akhir eksklusif (tanggal-waktu, zona config audit.zona_waktu); bawaan awal jam berjalan}';

    protected $description = 'Menyalin hash terakhir rantai audit sebelum suatu batas jam ke berkas dan log terpisah di luar database';

    public function handle(RantaiAudit $rantai, BerkasJangkar $berkas): int
    {
        $zona = config('audit.zona_waktu');
        $batas = $this->option('sampai')
            ? CarbonImmutable::parse($this->option('sampai'), $zona)
            : CarbonImmutable::now($zona)->startOfHour();

        $kepala = $rantai->kepalaSebelum($batas);
        if ($kepala === null) {
            $this->info("Belum ada baris audit sebelum {$batas->toIso8601String()}.");

            return self::SUCCESS;
        }

        $jangkar = [
            'batas' => $batas->toIso8601String(),
            'urutan' => (int) $kepala->urutan,
            'id' => $kepala->id,
            'terjadi_pada' => CarbonImmutable::parse($kepala->terjadi_pada)->utc()->format('Y-m-d\TH:i:s.u\Z'),
            'hash_rantai' => trim($kepala->hash_rantai),
        ];

        // Jam tanpa aktivitas menghasilkan pasangan (urutan, hash) yang sama: tidak ditulis ulang.
        foreach ($berkas->semua() as $ada) {
            if ($ada['urutan'] === $jangkar['urutan'] && $ada['hash_rantai'] === $jangkar['hash_rantai']) {
                $this->info("Jangkar urutan {$jangkar['urutan']} sudah ada.");

                return self::SUCCESS;
            }
        }

        $jangkar['diekspor_pada'] = CarbonImmutable::now()->utc()->format('Y-m-d\TH:i:s\Z');
        $tujuan = $berkas->tambah($jangkar, $batas);
        Log::channel(config('audit.jangkar.channel_log'))->info('jangkar_rantai_audit', $jangkar);

        $this->info("Jangkar sebelum {$jangkar['batas']}: urutan {$jangkar['urutan']}, hash {$jangkar['hash_rantai']} → {$tujuan}.");

        return self::SUCCESS;
    }
}
