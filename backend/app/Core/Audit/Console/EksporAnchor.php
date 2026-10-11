<?php

namespace App\Core\Audit\Console;

use App\Core\Audit\BerkasAnchor;
use App\Core\Audit\RantaiAudit;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

final class EksporAnchor extends Command
{
    protected $signature = 'audit:ekspor-anchor
        {--sampai= : Batas akhir eksklusif (tanggal-waktu, zona config audit.zona_waktu); bawaan awal jam berjalan}';

    protected $description = 'Menyalin hash terakhir rantai audit sebelum suatu batas jam ke berkas dan log terpisah di luar database';

    public function handle(RantaiAudit $rantai, BerkasAnchor $berkas): int
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

        $anchor = [
            'batas' => $batas->toIso8601String(),
            'urutan' => (int) $kepala->urutan,
            'id' => $kepala->id,
            'terjadi_pada' => CarbonImmutable::parse($kepala->terjadi_pada)->utc()->format('Y-m-d\TH:i:s.u\Z'),
            'hash_rantai' => trim($kepala->hash_rantai),
        ];

        // Jam tanpa aktivitas menghasilkan pasangan (urutan, hash) yang sama: tidak ditulis ulang.
        foreach ($berkas->semua() as $ada) {
            if ($ada['urutan'] === $anchor['urutan'] && $ada['hash_rantai'] === $anchor['hash_rantai']) {
                $this->info("Anchor urutan {$anchor['urutan']} sudah ada.");

                return self::SUCCESS;
            }
        }

        $anchor['diekspor_pada'] = CarbonImmutable::now()->utc()->format('Y-m-d\TH:i:s\Z');
        $tujuan = $berkas->tambah($anchor, $batas);
        Log::channel(config('audit.anchor.channel_log'))->info('anchor_rantai_audit', $anchor);

        $this->info("Anchor sebelum {$anchor['batas']}: urutan {$anchor['urutan']}, hash {$anchor['hash_rantai']} → {$tujuan}.");

        return self::SUCCESS;
    }
}
