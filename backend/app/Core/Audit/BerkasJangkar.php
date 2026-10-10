<?php

namespace App\Core\Audit;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * Jangkar rantai audit di luar database (ADR 0003): JSON Lines, satu berkas per bulan
 * (24 baris per hari selama masa retensi RM tidak menumpuk di satu berkas).
 */
final class BerkasJangkar
{
    /** @return list<array<string, mixed>> */
    public function semua(): array
    {
        $berkas = array_filter($this->disk()->files($this->direktori()), fn ($b) => str_ends_with($b, '.jsonl'));
        sort($berkas);

        $jangkar = [];
        foreach ($berkas as $b) {
            foreach (preg_split('/\R/', (string) $this->disk()->get($b), -1, PREG_SPLIT_NO_EMPTY) as $baris) {
                $jangkar[] = json_decode($baris, true, flags: JSON_THROW_ON_ERROR);
            }
        }

        return $jangkar;
    }

    /** @param  array<string, mixed>  $jangkar */
    public function tambah(array $jangkar, CarbonImmutable $batas): string
    {
        $berkas = $this->direktori().'/jangkar-rantai-'.$batas->utc()->format('Y-m').'.jsonl';
        $this->disk()->append($berkas, json_encode($jangkar, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $berkas;
    }

    private function disk(): Filesystem
    {
        return Storage::disk(config('audit.jangkar.disk'));
    }

    private function direktori(): string
    {
        return config('audit.jangkar.direktori');
    }
}
