<?php

namespace App\Modules\Akses\Database\Seeders;

use App\Modules\Akses\Models\Pengguna;
use App\Modules\Sdm\Models\Pegawai;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use RuntimeException;

/** Akun sintetis untuk lokal/staging (PRD 3.4, FR-54-10). Ditolak di production. */
final class AkunSintetisSeeder extends Seeder
{
    private const AKUN = [
        'contoh.admisi' => 'Contoh Petugas Admisi',
        'contoh.perawat' => 'Contoh Perawat',
        'contoh.dokter' => 'Contoh Dokter',
    ];

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('AkunSintetisSeeder ditolak berjalan di production.');
        }

        $sandi = (string) config('autentikasi.akun_sintetis_sandi');
        $dibuatAcak = $sandi === '';
        if ($dibuatAcak) {
            $sandi = Str::password(20);
        }
        Validator::make(['sandi' => $sandi], ['sandi' => ['required', Password::defaults()]])->validate();

        $urutan = 0;
        foreach (self::AKUN as $namaPengguna => $nama) {
            $pegawai = Pegawai::query()->updateOrCreate(
                ['nip' => sprintf('SINTETIS-%03d', ++$urutan)],
                ['nama' => $nama],
            );
            Pengguna::query()->firstOrNew(['nama_pengguna' => $namaPengguna])->forceFill([
                'pegawai_id' => $pegawai->id,
                'surel' => $namaPengguna.'@contoh.invalid',
                'sandi_hash' => Hash::make($sandi),
                'status' => 'aktif',
            ])->save();
        }

        $this->command?->info('Akun sintetis: '.implode(', ', array_keys(self::AKUN)).'.');
        if ($dibuatAcak) {
            $this->command?->warn("Sandi acak (hanya ditampilkan sekali): {$sandi}");
        }
    }
}
