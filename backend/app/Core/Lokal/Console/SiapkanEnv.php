<?php

namespace App\Core\Lokal\Console;

use App\Core\Lokal\PerintahLokal;
use App\Modules\Akses\Database\Seeders\AkunSintetisSeeder;
use App\Modules\Akses\Domain\Totp;
use App\Modules\Ttd\Database\Seeders\TtdSintetisSeeder;
use App\Modules\Ttd\Domain\AturanPin;
use Dotenv\Dotenv;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * `php artisan lokal:siapkan-env` (tiket 1.16): menulis backend/.env lokal tanpa menyalin atau membuat kunci dengan tangan.
 * Berkas baru dibuat dari .env.example; berkas lama hanya ditambah kunci yang belum ada atau masih kosong, nilai yang
 * sudah terisi tidak pernah ditimpa. Rahasia hanya ditulis ke .env dan terminal, tidak ke log.
 */
final class SiapkanEnv extends Command
{
    protected $signature = 'lokal:siapkan-env
        {--paket-identitas= : Folder paket identitas RS di luar repo (PAKET_IDENTITAS_CONTOH_DIR)}
        {--kemudahan-uji : Batas diam, MFA ulang, dan umur sesi panjang untuk uji manual (LOKAL)}';

    protected $description = 'Membuat atau melengkapi .env lokal: kunci, rahasia akun sintetis, simulator (bukan untuk production)';

    private const ACAK = ['APP_KEY', 'TTD_KUNCI', 'KREDENSIAL_KUNCI'];

    private const KOMENTAR_KEMUDAHAN = '# LOKAL: kemudahan uji (lokal:siapkan-env --kemudahan-uji); jangan dipakai di server.';

    /** @var list<string> */
    private array $baris = [];

    /** @var list<string> */
    private array $ditambah = [];

    /** @var list<string> */
    private array $dibiarkan = [];

    public function handle(): int
    {
        $berkas = app()->environmentFilePath();
        if (! PerintahLokal::diizinkan(app())) {
            $this->error('Ditolak: APP_ENV=production (lingkungan atau .env). Perintah ini hanya untuk lokal.');

            return self::FAILURE;
        }

        $paket = null;
        if (($opsi = $this->option('paket-identitas')) !== null) {
            $paket = $this->periksaPaket((string) $opsi);
            if ($paket === null) {
                return self::FAILURE;
            }
        }

        $baru = ! is_file($berkas);
        $contoh = (string) file_get_contents(base_path('.env.example'));
        $lama = $baru ? null : (string) file_get_contents($berkas);
        $this->baris = explode("\n", rtrim($lama ?? $contoh, "\n"));

        // kunci => [nilai, timpa nilai contoh, komentar, laporkan bila dibiarkan]
        $isian = [];
        foreach ($this->nilaiOtomatis($contoh) as $kunci => $nilai) {
            // Nilai acak yang sudah terisi memang dibiarkan; yang dilaporkan hanya nilai tetap yang berbeda.
            $isian[$kunci] = [$nilai, $baru, null, ! in_array($kunci, self::ACAK, true) && ! str_starts_with($kunci, 'AKUN_SINTETIS_')];
        }
        if ($paket !== null) {
            $isian['PAKET_IDENTITAS_CONTOH_DIR'] = [$paket, true, null, true];
        }
        if ($this->option('kemudahan-uji')) {
            foreach (['AUTENTIKASI_BATAS_DIAM_MENIT' => '480', 'AUTENTIKASI_MFA_ULANG_MENIT' => '480', 'SESSION_LIFETIME' => '600'] as $kunci => $nilai) {
                $isian[$kunci] = [$nilai, $baru, self::KOMENTAR_KEMUDAHAN, true];
            }
        }

        if (! $baru) {
            // Kunci baru di .env.example yang belum ada di .env lama, dengan nilai contohnya (kecuali yang diisi di bawah).
            foreach ($this->kunciAktif($contoh) as $kunci => $nilai) {
                if (! isset($isian[$kunci]) && $this->cari($kunci) === null) {
                    $this->tambah($kunci, $nilai, null);
                }
            }
        }
        foreach ($isian as $kunci => [$nilai, $timpa, $komentar, $laporkan]) {
            $this->isi($kunci, $nilai, $timpa, $komentar, $laporkan);
        }

        $isi = implode("\n", $this->baris)."\n";
        if ($isi === $lama) {
            $this->info('.env sudah lengkap; tidak ada yang diubah.');
        } else {
            if ($lama !== null) {
                $this->info('Cadangan .env lama: '.$this->cadangkan($lama));
            }
            file_put_contents($berkas, $isi);
            @chmod($berkas, 0600);
            $this->info(($baru ? '.env dibuat dari .env.example' : '.env dilengkapi').': '.implode(', ', array_unique($this->ditambah)).'.');
        }
        if ($this->dibiarkan !== []) {
            $this->warn('Sudah terisi, tidak ditimpa: '.implode(', ', $this->dibiarkan).'.');
        }

        $this->cetakAkun(Dotenv::parse($isi));

        return self::SUCCESS;
    }

    /** @return array<string, string> */
    private function nilaiOtomatis(string $contoh): array
    {
        $nilai = [
            'APP_KEY' => self::kunciAcak(),
            'TTD_KUNCI' => self::kunciAcak(),
            'KREDENSIAL_KUNCI' => self::kunciAcak(),
            'AKUN_SINTETIS_SANDI' => Str::password(24, symbols: false),
            'AKUN_SINTETIS_TTD_PIN' => self::pinAcak(),
            'AUTENTIKASI_MFA_JARINGAN_RS' => '127.0.0.1/32,::1/128',
            'INSTALASI_DEMO' => 'true',
            'INTEGRASI_SIMULATOR' => 'true',
        ];
        // Variabel .env = kunci config autentikasi dalam huruf besar (AkunSintetisSeeder::RAHASIA_MFA).
        foreach (AkunSintetisSeeder::RAHASIA_MFA as $kunciConfig) {
            $nilai[strtoupper($kunciConfig)] = Totp::rahasiaBaru();
        }
        // Rahasia TOTP akun sintetis yang ditambahkan ke .env.example kemudian ikut terisi.
        foreach (array_keys($this->kunciAktif($contoh)) as $kunci) {
            if (preg_match('/^AKUN_SINTETIS_\w*MFA_RAHASIA$/', $kunci) === 1) {
                $nilai[$kunci] ??= Totp::rahasiaBaru();
            }
        }

        return $nilai;
    }

    /** Isi kunci yang belum ada atau masih kosong; nilai terisi hanya diganti pada .env yang baru dibuat dari contoh. */
    private function isi(string $kunci, string $nilai, bool $timpa, ?string $komentar = null, bool $laporkan = true): void
    {
        $i = $this->cari($kunci);
        if ($i === null) {
            $this->tambah($kunci, $nilai, $komentar);

            return;
        }
        $sekarang = $this->kunciAktif($this->baris[$i])[$kunci] ?? '';
        if ($sekarang === $nilai) {
            return;
        }
        if ($sekarang !== '' && ! $timpa) {
            if ($laporkan) {
                $this->dibiarkan[] = $kunci;
            }

            return;
        }
        $this->baris[$i] = $kunci.'='.self::kutip($nilai);
        if ($komentar !== null) {
            array_splice($this->baris, $i, 0, [$komentar]);
        }
        $this->ditambah[] = $kunci;
    }

    /** Baris baru tepat di bawah contoh yang dikomentari (`# KUNCI=`) bila ada, selain itu di akhir berkas. */
    private function tambah(string $kunci, string $nilai, ?string $komentar): void
    {
        $sisip = [...($komentar !== null ? [$komentar] : []), $kunci.'='.self::kutip($nilai)];
        $posisi = null;
        foreach ($this->baris as $i => $b) {
            if (preg_match('/^#\s*'.preg_quote($kunci, '/').'=/', $b) === 1) {
                $posisi = $i + 1;
            }
        }
        if ($posisi === null) {
            if (! in_array('# Ditambahkan oleh lokal:siapkan-env', $this->baris, true)) {
                $this->baris = [...$this->baris, '', '# Ditambahkan oleh lokal:siapkan-env'];
            }
            $posisi = count($this->baris);
        }
        array_splice($this->baris, $posisi, 0, $sisip);
        $this->ditambah[] = $kunci;
    }

    private function cari(string $kunci): ?int
    {
        foreach ($this->baris as $i => $b) {
            if (array_key_exists($kunci, $this->kunciAktif($b))) {
                return $i;
            }
        }

        return null;
    }

    /** @return array<string, string> kunci aktif (bukan komentar) => nilai tanpa kutip */
    private function kunciAktif(string $isi): array
    {
        preg_match_all('/^\s*(?:export\s+)?([A-Z][A-Z0-9_]*)\s*=(.*)$/m', $isi, $cocok, PREG_SET_ORDER);
        $hasil = [];
        foreach ($cocok as [, $kunci, $nilai]) {
            $nilai = trim($nilai);
            if (preg_match('/^(["\'])(.*)\1/', $nilai, $k) === 1) {
                $nilai = $k[2];
            } else {
                $nilai = trim((string) preg_replace('/\s#.*$/', '', $nilai));
            }
            $hasil[$kunci] = $nilai;
        }

        return $hasil;
    }

    private function periksaPaket(string $folder): ?string
    {
        $nyata = realpath($folder);
        if ($nyata === false || ! is_dir($nyata)) {
            $this->error("Folder paket identitas tidak ditemukan: {$folder}");

            return null;
        }
        // Repo publik: paket identitas RS nyata tidak boleh berada di dalam repo.
        $repo = realpath(dirname(base_path()));
        if ($repo !== false && ($nyata === $repo || str_starts_with($nyata, $repo.DIRECTORY_SEPARATOR))) {
            $this->error('Folder paket identitas harus di luar repo (repo publik).');

            return null;
        }

        return $nyata;
    }

    private function cadangkan(string $isi): string
    {
        $folder = rtrim((string) (getenv('HOME') ?: ($_SERVER['HOME'] ?? sys_get_temp_dir())), '/').'/.karsitara/cadangan-env';
        if (! is_dir($folder)) {
            mkdir($folder, 0700, true);
        }
        $nama = Str::slug(basename(dirname(base_path()))).'-'.now()->format('Ymd-His-u').'.env';
        file_put_contents($folder.'/'.$nama, $isi);
        @chmod($folder.'/'.$nama, 0600);

        return $folder.'/'.$nama;
    }

    /** @param  array<string, string|null>  $env */
    private function cetakAkun(array $env): void
    {
        $this->newLine();
        $this->warn('Rahasia akun sintetis (hanya untuk lokal; jangan disalin ke tiket, PR, atau chat):');
        $this->line('Sandi semua akun sintetis: '.($env['AKUN_SINTETIS_SANDI'] ?? '-'));
        $baris = [];
        foreach (array_keys(AkunSintetisSeeder::AKUN) as $nama) {
            $kunciMfa = AkunSintetisSeeder::RAHASIA_MFA[$nama] ?? null;
            $baris[] = [
                $nama,
                $kunciMfa !== null ? ($env[strtoupper($kunciMfa)] ?? '-') : '',
                in_array($nama, TtdSintetisSeeder::AKTIF, true) ? ($env['AKUN_SINTETIS_TTD_PIN'] ?? '-') : '',
            ];
        }
        $this->table(['Nama pengguna', 'Rahasia TOTP', 'PIN TTE'], $baris);
        $this->line('Berikutnya: php artisan migrate --database=pgsql_migrator && php artisan db:seed');
        $this->line('Kode MFA saat ini: php artisan lokal:kode-mfa [nama_pengguna]');
    }

    private static function kunciAcak(): string
    {
        return 'base64:'.base64_encode(random_bytes(32));
    }

    private static function pinAcak(): string
    {
        do {
            $pin = sprintf('%06d', random_int(0, 999999));
        } while (AturanPin::lemah($pin));

        return $pin;
    }

    /** Dikutip ganda bila ada karakter di luar yang aman (mis. spasi di jalur folder); `$` di-escape agar tidak diinterpolasi. */
    private static function kutip(string $nilai): string
    {
        if (preg_match('#^[A-Za-z0-9_./:,+=@-]*$#', $nilai) === 1) {
            return $nilai;
        }

        return '"'.str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $nilai).'"';
    }
}
