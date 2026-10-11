<?php

namespace App\Modules\Akses\Database\Seeders;

use App\Modules\Akses\Domain\Totp;
use App\Modules\Akses\Models\Izin;
use App\Modules\Akses\Models\Menu;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\Akses\Models\PenggunaPeran;
use App\Modules\Akses\Models\Peran;
use App\Modules\Akses\Models\PeranIzin;
use App\Modules\Akses\Models\PeranMenu;
use App\Modules\Sdm\Models\Pegawai;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
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
        // Khusus uji penguncian (e2e): boleh dikunci kapan saja tanpa mengganggu akun lain.
        'contoh.terkunci' => 'Contoh Akun Uji Kunci',
        // MFA (tiket 1.1b): wajib MFA dengan rahasia TOTP sintetis yang diketahui e2e.
        'contoh.mfa' => 'Contoh Akun MFA',
        // MFA wajib tetapi belum terdaftar: masuk → perlu_daftar_mfa. Seeding ulang mengosongkannya lagi.
        'contoh.mfa.baru' => 'Contoh Akun MFA Baru',
    ];

    /**
     * Peran contoh (tiket 1.2a) agar menu tiap akun sintetis berbeda: kode peran => [nama, akun, menu].
     * Seluruh sub-menu menu itu diberikan beserta aksi dasarnya, cakupan seluruh RS.
     */
    private const PERAN_CONTOH = [
        'contoh_admisi' => ['Contoh Petugas Admisi', 'contoh.admisi', ['pendaftaran']],
        'contoh_perawat' => ['Contoh Perawat', 'contoh.perawat', ['igd', 'rawat_inap']],
        'contoh_dokter' => ['Contoh Dokter', 'contoh.dokter', ['igd', 'rawat_jalan', 'rekam_medis']],
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

        $rahasiaMfa = strtoupper((string) config('autentikasi.akun_sintetis_mfa_rahasia'));
        $rahasiaMfaAcak = $rahasiaMfa === '';
        if ($rahasiaMfaAcak) {
            $rahasiaMfa = Totp::rahasiaBaru();
        }
        Totp::base32Dekode($rahasiaMfa);

        $urutan = 0;
        foreach (self::AKUN as $namaPengguna => $nama) {
            $pegawai = Pegawai::query()->updateOrCreate(
                ['nip' => sprintf('SINTETIS-%03d', ++$urutan)],
                ['nama' => $nama],
            );
            Pengguna::query()->firstOrNew(['nama_pengguna' => $namaPengguna])->forceFill([
                'pegawai_id' => $pegawai->id,
                'email' => $namaPengguna.'@contoh.invalid',
                'sandi_hash' => Hash::make($sandi),
                'status' => 'aktif',
                // Keadaan awal yang sama tiap seeding, agar uji e2e "terkunci" dan MFA bisa diulang.
                'percobaan_gagal' => 0,
                'terkunci_sampai' => null,
                'wajib_mfa' => str_starts_with($namaPengguna, 'contoh.mfa'),
                'mfa_rahasia_enkripsi' => $namaPengguna === 'contoh.mfa' ? $rahasiaMfa : null,
                'mfa_aktif_pada' => $namaPengguna === 'contoh.mfa' ? now() : null,
                'mfa_langkah_terakhir' => null,
            ])->save();
        }

        // Kode cadangan akun sintetis dibatalkan (tidak dihapus: simrs_app tanpa DELETE di skema akses).
        DB::table('akses.kode_cadangan_mfa')
            ->whereIn('pengguna_id', Pengguna::query()->whereIn('nama_pengguna', array_keys(self::AKUN))->select('id'))
            ->whereNull('dibatalkan_pada')
            ->update(['dibatalkan_pada' => now()]);

        $this->pasangPeranContoh();

        $this->command?->info('Akun sintetis: '.implode(', ', array_keys(self::AKUN)).'.');
        $sekaliTampil = array_filter([
            $dibuatAcak ? "Sandi acak (hanya ditampilkan sekali): {$sandi}" : null,
            $rahasiaMfaAcak ? "Rahasia TOTP contoh.mfa (hanya ditampilkan sekali): {$rahasiaMfa}" : null,
        ]);
        foreach ($sekaliTampil as $pesan) {
            $this->command?->warn($pesan);
        }
    }

    private function pasangPeranContoh(): void
    {
        foreach (self::PERAN_CONTOH as $kode => [$nama, $namaPengguna, $daftarMenu]) {
            $peran = Peran::query()->updateOrCreate(['kode' => $kode], ['nama' => $nama, 'uraian' => 'Peran sintetis untuk lokal/staging.']);

            $subMenu = Menu::query()->where('tingkat', 'sub_menu')
                ->whereIn('induk_id', Menu::query()->whereIn('kode', $daftarMenu)->select('id'))->pluck('id');
            foreach ($subMenu as $menuId) {
                PeranMenu::query()->firstOrCreate(['peran_id' => $peran->id, 'menu_id' => $menuId]);
            }
            foreach (Izin::query()->whereIn('menu_id', $subMenu)->pluck('id') as $izinId) {
                PeranIzin::query()->firstOrCreate(['peran_id' => $peran->id, 'izin_id' => $izinId]);
            }

            $pengguna = Pengguna::query()->where('nama_pengguna', $namaPengguna)->firstOrFail();
            PenggunaPeran::query()->firstOrCreate(
                ['pengguna_id' => $pengguna->id, 'peran_id' => $peran->id, 'status' => 'aktif'],
                ['dasar_pemberian' => 'Data sintetis (AkunSintetisSeeder)'],
            );
        }
    }
}
