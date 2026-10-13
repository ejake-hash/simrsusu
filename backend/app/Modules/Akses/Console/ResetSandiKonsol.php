<?php

namespace App\Modules\Akses\Console;

use App\Core\Http\ApiError;
use App\Modules\Akses\Application\ResetSandi;
use App\Modules\Akses\Models\Pengguna;
use Illuminate\Console\Command;

/**
 * Konsol darurat (tiket 1.14): reset sandi bila tidak ada superadmin aktif lain yang bisa mereset lewat layar (ditolak
 * bila masih ada). Butuh akses server; sandi sementara hanya ditulis ke terminal sekali, tidak ke log. Audit
 * `akses.sandi_direset_konsol` dengan pengguna sistem operasi.
 */
final class ResetSandiKonsol extends Command
{
    protected $signature = 'akses:reset-sandi
        {nama_pengguna : Akun yang direset}
        {--alasan= : Alasan reset (mis. lupa sandi, tidak ada superadmin lain)}
        {--dasar= : Nomor memo/berita acara verifikasi identitas}
        {--force : Tanpa pertanyaan konfirmasi}';

    protected $description = 'Reset sandi akun dari server (darurat, bila tidak ada superadmin lain); sandi sementara dicetak sekali';

    public function handle(ResetSandi $reset): int
    {
        $nama = mb_strtolower(trim((string) $this->argument('nama_pengguna')));
        $akun = Pengguna::query()->whereRaw('lower(nama_pengguna) = ?', [$nama])->first();
        if ($akun === null) {
            $this->error('Akun tidak ditemukan.');

            return self::FAILURE;
        }

        if ($reset->adaSuperadminLain($akun)) {
            $this->error('Masih ada superadmin aktif lain: reset sandi lewat Pengaturan → Akun & akses (kontrol ganda).');

            return self::FAILURE;
        }

        $alasan = trim((string) ($this->option('alasan') ?? $this->ask('Alasan reset')));
        $dasar = trim((string) ($this->option('dasar') ?? $this->ask('Dasar (memo/berita acara verifikasi identitas)')));
        if (mb_strlen($alasan) < 5 || mb_strlen($alasan) > 500 || mb_strlen($dasar) < 3 || mb_strlen($dasar) > 200) {
            $this->error('Alasan 5–500 karakter dan dasar 3–200 karakter wajib diisi.');

            return self::FAILURE;
        }
        if (! $this->option('force') && ! $this->confirm("Reset sandi {$akun->nama_pengguna}? Semua sesinya diputus dan sandi lama tidak berlaku.")) {
            $this->warn('Dibatalkan.');

            return self::FAILURE;
        }

        try {
            [$akun, $sandi] = $reset->lewatKonsol($akun, $alasan, $dasar, ...self::penggunaOs());
        } catch (ApiError $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Sandi {$akun->nama_pengguna} direset. Semua sesinya diputus; akun wajib mengganti sandi saat masuk.");
        $this->warn("Sandi sementara (hanya ditampilkan sekali, serahkan langsung ke pemilik akun): {$sandi}");

        return self::SUCCESS;
    }

    /**
     * Orang yang menjalankan (SUDO_USER bila lewat sudo) dan pengguna efektif proses. get_current_user() tidak dipakai:
     * ia mengembalikan pemilik berkas artisan, bukan yang menjalankan.
     *
     * @return array{0: string, 1: string}
     */
    private static function penggunaOs(): array
    {
        $efektif = function_exists('posix_geteuid') && function_exists('posix_getpwuid')
            ? ((posix_getpwuid(posix_geteuid()) ?: [])['name'] ?? (string) posix_geteuid())
            : (string) (getenv('USER') ?: getenv('USERNAME') ?: 'tidak_diketahui');

        return [(string) (getenv('SUDO_USER') ?: $efektif), $efektif];
    }
}
