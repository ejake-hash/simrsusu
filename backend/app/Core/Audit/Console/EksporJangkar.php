<?php

namespace App\Core\Audit\Console;

use App\Core\Audit\BerkasJangkar;
use App\Core\Audit\RantaiAudit;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

final class EksporJangkar extends Command
{
    protected $signature = 'audit:ekspor-jangkar {--tanggal= : Hari yang dijangkar (Y-m-d), bawaan kemarin}';

    protected $description = 'Menyalin hash terakhir rantai audit per hari ke berkas dan log terpisah di luar database';

    public function handle(RantaiAudit $rantai, BerkasJangkar $berkas): int
    {
        $zona = config('audit.zona_waktu');
        $tanggal = $this->option('tanggal')
            ? CarbonImmutable::parse($this->option('tanggal'), $zona)->startOfDay()
            : CarbonImmutable::now($zona)->subDay()->startOfDay();

        $kepala = $rantai->kepalaSebelum($tanggal->addDay());
        if ($kepala === null) {
            $this->info("Belum ada baris audit sampai akhir {$tanggal->toDateString()}.");

            return self::SUCCESS;
        }

        $jangkar = [
            'tanggal' => $tanggal->toDateString(),
            'zona_waktu' => $zona,
            'urutan' => (int) $kepala->urutan,
            'id' => $kepala->id,
            'terjadi_pada' => CarbonImmutable::parse($kepala->terjadi_pada)->utc()->format('Y-m-d\TH:i:s.u\Z'),
            'hash_rantai' => trim($kepala->hash_rantai),
        ];

        foreach ($berkas->semua() as $ada) {
            if (array_intersect_key($ada, $jangkar) == $jangkar) {
                $this->info("Jangkar {$jangkar['tanggal']} sudah ada (urutan {$jangkar['urutan']}).");

                return self::SUCCESS;
            }
        }

        $jangkar['diekspor_pada'] = CarbonImmutable::now()->utc()->format('Y-m-d\TH:i:s\Z');
        $berkas->tambah($jangkar);
        Log::channel(config('audit.jangkar.channel_log'))->info('jangkar_rantai_audit', $jangkar);

        $this->info("Jangkar {$jangkar['tanggal']}: urutan {$jangkar['urutan']}, hash {$jangkar['hash_rantai']}.");

        return self::SUCCESS;
    }
}
