<?php

namespace App\Modules\Akses\Infrastructure;

use RuntimeException;

/**
 * Daftar sandi bocor lokal (gzip, satu sandi huruf kecil per baris). Hanya dibaca saat sandi
 * ditetapkan, bukan saat masuk, jadi dimuat utuh ke memori (~1 MB) tanpa indeks.
 */
final class DaftarSandiBocor
{
    private ?string $isi = null;

    public function __construct(private readonly string $berkas) {}

    public function memuat(string $sandi): bool
    {
        return str_contains($this->isi(), "\n".strtolower($sandi)."\n");
    }

    private function isi(): string
    {
        if ($this->isi === null) {
            $mentah = is_readable($this->berkas) ? gzdecode((string) file_get_contents($this->berkas)) : false;
            if ($mentah === false) {
                // Gagal tertutup: lebih baik sandi tidak bisa ditetapkan daripada cek dilewati diam-diam.
                throw new RuntimeException('Daftar sandi bocor tidak bisa dibaca: '.$this->berkas);
            }
            $this->isi = "\n".$mentah."\n";
        }

        return $this->isi;
    }
}
