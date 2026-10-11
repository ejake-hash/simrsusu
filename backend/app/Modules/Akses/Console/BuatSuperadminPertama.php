<?php

namespace App\Modules\Akses\Console;

use App\Modules\Akses\Application\KebijakanMfa;
use App\Modules\Akses\Application\SuperadminPertama;
use App\Modules\Akses\Domain\Totp;
use App\Modules\Akses\Models\Pengguna;
use Illuminate\Console\Command;
use Illuminate\Contracts\Validation\Validator as ValidatorKontrak;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use RuntimeException;

/**
 * FR-54-08 langkah 1. Sandi hanya lewat prompt tersembunyi (bukan opsi, agar tidak masuk riwayat
 * shell). Rahasia TOTP dan kode cadangan hanya ditulis ke terminal sekali, tidak ke log.
 */
final class BuatSuperadminPertama extends Command
{
    protected $signature = 'akses:superadmin-pertama
        {--nama-pengguna= : Nama pengguna untuk masuk}
        {--nama= : Nama lengkap pegawai (orang, bukan akun bersama)}
        {--nip= : NIP pegawai (opsional)}
        {--email= : Email (opsional)}';

    protected $description = 'Membuat akun superadmin bernama dengan MFA untuk instalasi baru (paling banyak 2)';

    public function handle(SuperadminPertama $superadmin, KebijakanMfa $kebijakan): int
    {
        if ($superadmin->jumlahAktif() >= SuperadminPertama::BATAS) {
            return $this->tolakSudahCukup();
        }

        $isian = [
            'nama_pengguna' => $this->option('nama-pengguna') ?? $this->ask('Nama pengguna'),
            'nama' => $this->option('nama') ?? $this->ask('Nama lengkap pegawai'),
            'nip' => $this->option('nip') ?: null,
            'email' => $this->option('email') ?: null,
            'sandi' => $this->secret('Kata sandi'),
            'sandi_confirmation' => $this->secret('Ulangi kata sandi'),
        ];

        $validator = Validator::make($isian, [
            'nama_pengguna' => ['required', 'string', 'max:150', 'regex:/^[a-z0-9._-]+$/i'],
            'nama' => ['required', 'string', 'max:200'],
            'nip' => ['nullable', 'string', 'max:50', Rule::unique('sdm.pegawai', 'nip')],
            'email' => ['nullable', 'email', 'max:200'],
            'sandi' => ['required', 'string', 'max:1024', 'confirmed', Password::defaults()],
        ], [
            'nama_pengguna.regex' => 'Nama pengguna hanya huruf, angka, titik, garis bawah, dan tanda hubung.',
            'nip.unique' => 'NIP sudah dipakai pegawai lain.',
            'sandi.confirmed' => 'Ulangan kata sandi tidak sama.',
            '*.required' => 'Isian :attribute wajib diisi.',
        ])->after(function (ValidatorKontrak $v) use ($isian) {
            // Unik tanpa membedakan huruf besar/kecil, sama dengan indeks pengguna_nama_pengguna_unik.
            $nama = mb_strtolower(trim((string) $isian['nama_pengguna']));
            if ($nama !== '' && Pengguna::query()->whereRaw('lower(nama_pengguna) = ?', [$nama])->exists()) {
                $v->errors()->add('nama_pengguna', 'Nama pengguna sudah dipakai.');
            }
        });

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $pesan) {
                $this->error($pesan);
            }

            return self::FAILURE;
        }

        try {
            $hasil = $superadmin->buat($isian['nama_pengguna'], $isian['nama'], $isian['nip'], $isian['email'], $isian['sandi']);
        } catch (RuntimeException) {
            return $this->tolakSudahCukup();
        }

        $namaPengguna = $hasil['pengguna']->nama_pengguna;
        $this->info("Superadmin {$namaPengguna} dibuat dengan MFA aktif.");
        $this->newLine();
        $this->warn('Ditampilkan SEKALI. Jangan disalin ke tiket, chat, atau dokumen bersama.');
        $this->line('Tambahkan ke aplikasi autentikator (tempel URI ke pembuat kode QR, atau ketik kunci manual):');
        $this->line('  URI    : '.Totp::uriOtpauth($hasil['rahasia'], (string) config('autentikasi.mfa.penerbit'), $namaPengguna));
        $this->line('  Kunci  : '.trim(chunk_split($hasil['rahasia'], 4, ' ')));
        $this->newLine();
        $this->line('Kode cadangan (masing-masing sekali pakai), simpan di tempat aman:');
        foreach ($hasil['kode_cadangan'] as $kode) {
            $this->line('  '.$kode);
        }
        $this->newLine();
        $sisa = SuperadminPertama::BATAS - $superadmin->jumlahAktif();
        if ($sisa > 0) {
            $this->line("Buat {$sisa} superadmin lagi untuk orang lain (PRD 9.2.4: minimal dua orang).");
        }
        if (! $kebijakan->jaringanRsDiisi()) {
            $this->warn('AUTENTIKASI_MFA_JARINGAN_RS masih kosong: login lewat web ditolak sampai diisi.');
        }

        return self::SUCCESS;
    }

    private function tolakSudahCukup(): int
    {
        $this->error('Sudah ada '.SuperadminPertama::BATAS.' superadmin aktif. Superadmin berikutnya diberikan dari aplikasi oleh superadmin yang ada.');

        return self::FAILURE;
    }
}
