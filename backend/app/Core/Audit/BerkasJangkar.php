<?php

namespace App\Core\Audit;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/** Berkas JSON Lines berisi jangkar harian rantai audit, di luar database (ADR 0003). */
final class BerkasJangkar
{
    /** @return list<array<string, mixed>> */
    public function semua(): array
    {
        if (! $this->disk()->exists($this->berkas())) {
            return [];
        }

        $jangkar = [];
        foreach (preg_split('/\R/', (string) $this->disk()->get($this->berkas()), -1, PREG_SPLIT_NO_EMPTY) as $baris) {
            $jangkar[] = json_decode($baris, true, flags: JSON_THROW_ON_ERROR);
        }

        return $jangkar;
    }

    /** @param  array<string, mixed>  $jangkar */
    public function tambah(array $jangkar): void
    {
        $this->disk()->append($this->berkas(), json_encode($jangkar, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function disk(): Filesystem
    {
        return Storage::disk(config('audit.jangkar.disk'));
    }

    private function berkas(): string
    {
        return config('audit.jangkar.berkas');
    }
}
