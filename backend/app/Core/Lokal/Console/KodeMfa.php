<?php

namespace App\Core\Lokal\Console;

use App\Modules\Akses\Database\Seeders\AkunSintetisSeeder;
use App\Modules\Akses\Domain\Totp;
use Illuminate\Console\Command;

/** `php artisan lokal:kode-mfa` (tiket 1.16): kode TOTP saat ini untuk akun sintetis, dari rahasia di .env. Hanya APP_ENV=local. */
final class KodeMfa extends Command
{
    protected $signature = 'lokal:kode-mfa {nama_pengguna? : Satu akun sintetis saja}';

    protected $description = 'Mencetak kode TOTP saat ini untuk akun sintetis (hanya lokal)';

    public function handle(): int
    {
        if (! $this->laravel->environment('local')) {
            $this->error('Ditolak: hanya untuk APP_ENV=local.');

            return self::FAILURE;
        }

        $akun = AkunSintetisSeeder::RAHASIA_MFA;
        if (($nama = $this->argument('nama_pengguna')) !== null) {
            if (! isset($akun[$nama])) {
                $this->error('Bukan akun sintetis ber-MFA. Pilihan: '.implode(', ', array_keys($akun)).'.');

                return self::FAILURE;
            }
            $akun = [$nama => $akun[$nama]];
        }

        $sekarang = now()->getTimestamp();
        $baris = [];
        foreach ($akun as $namaPengguna => $kunciConfig) {
            $rahasia = strtoupper((string) config('autentikasi.'.$kunciConfig));
            $baris[] = [$namaPengguna, $rahasia === '' ? '(rahasia kosong di .env)' : Totp::kode($rahasia, Totp::langkah($sekarang))];
        }
        $this->table(['Nama pengguna', 'Kode'], $baris);
        $this->line('Berlaku '.(Totp::PERIODE - $sekarang % Totp::PERIODE).' detik lagi.');

        return self::SUCCESS;
    }
}
