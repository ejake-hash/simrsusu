<?php

namespace App\Modules\Akses\Application;

use App\Core\ProfilRs\ProfilRs;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Cek kontekstual sandi (utang ADR 0001; tiket 1.8d): menolak sandi yang memuat nama pengguna, nama produk, atau
 * nama RS (utuh tanpa spasi, atau kata penting di dalamnya), tanpa membedakan huruf besar.
 */
final class SandiKontekstual implements ValidationRule
{
    /** Kata umum nama RS yang terlalu sering muncul untuk dilarang sendiri-sendiri. */
    /** Nama perangkat lunak (sama untuk semua RS; nama RS dari profil). */
    private const NAMA_PRODUK = 'karsitara';

    private const KATA_UMUM = ['rumah', 'sakit', 'umum', 'daerah', 'pusat', 'khusus', 'universitas', 'provinsi', 'kabupaten', 'kota'];

    public function __construct(private readonly string $namaPengguna) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }
        $sandi = mb_strtolower($value);
        foreach ($this->terlarang() as $kata) {
            if (str_contains($sandi, $kata)) {
                $fail('Sandi tidak boleh memuat nama pengguna, nama rumah sakit, atau nama aplikasi.');

                return;
            }
        }
    }

    /** @return list<string> */
    private function terlarang(): array
    {
        $kata = [mb_strtolower($this->namaPengguna), self::NAMA_PRODUK];
        $namaRs = mb_strtolower((string) ProfilRs::satu()?->nama);
        if ($namaRs !== '') {
            $kata[] = (string) preg_replace('/\s+/u', '', $namaRs);
            foreach (preg_split('/[^\p{L}\p{N}]+/u', $namaRs) ?: [] as $bagian) {
                if (mb_strlen($bagian) >= 4 && ! in_array($bagian, self::KATA_UMUM, true)) {
                    $kata[] = $bagian;
                }
            }
        }

        return array_values(array_unique(array_filter($kata, fn (string $k) => mb_strlen($k) >= 3)));
    }
}
