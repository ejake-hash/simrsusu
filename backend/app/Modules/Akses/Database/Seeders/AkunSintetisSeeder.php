<?php

namespace App\Modules\Akses\Database\Seeders;

use App\Modules\Akses\Application\CacheHakAkses;
use App\Modules\Akses\Database\MenuBawaan;
use App\Modules\Akses\Domain\Totp;
use App\Modules\Akses\Models\Izin;
use App\Modules\Akses\Models\Menu;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\Akses\Models\PenggunaIzinKhusus;
use App\Modules\Akses\Models\PenggunaPeran;
use App\Modules\Akses\Models\Peran;
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
    /** Akun tanpa peran dan pengecualian (Beranda "belum ada modul", tiket 1.2e). */
    public const TANPA_PERAN = 'contoh.tanpa.peran';

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
        // Superadmin sintetis untuk e2e Pengaturan (tiket 1.2c), MFA aktif dengan rahasia yang diketahui e2e.
        'contoh.superadmin' => 'Contoh Superadmin',
        // Superadmin kedua (tiket 1.2d): e2e kontrol ganda "superadmin A mengajukan, superadmin B menyetujui".
        'contoh.superadmin.dua' => 'Contoh Superadmin Dua',
        // Tanpa penugasan dan tanpa wajib MFA: Beranda "belum ada modul" (tiket 1.2e). Seeding ulang
        // mengakhiri penugasan yang sempat dibuat uji lain.
        self::TANPA_PERAN => 'Contoh Akun Tanpa Peran',
        // Pemegang Master Data (organisasi, unit, KSM; tiket 1.8a), bukan superadmin (FR-54-01).
        'contoh.data.induk' => 'Contoh Admin Data Induk',
        // Admin SDM (tiket 1.8d): data pegawai, kredensial, dan buat akun dari pegawai. pengaturan.akun.input
        // butuh MFA, jadi MFA aktif dengan rahasia sintetis yang diketahui e2e.
        'contoh.sdm' => 'Contoh Admin SDM',
        // TTE (tiket 1.6): contoh.ttd dengan TTE aktif (PIN sintetis, TtdSintetisSeeder); contoh.ttd.baru tanpa TTE (alur aktivasi).
        'contoh.ttd' => 'Contoh Akun TTE',
        'contoh.ttd.baru' => 'Contoh Akun TTE Baru',
    ];

    /** Profesi pegawai sintetis (selain ini non_kesehatan). */
    private const PROFESI = ['contoh.perawat' => 'perawat', 'contoh.dokter' => 'dokter'];

    /**
     * Peran contoh (tiket 1.2a) agar menu tiap akun sintetis berbeda: kode peran => [nama, akun, menu].
     * Seluruh sub-menu menu itu diberikan beserta aksi dasarnya, cakupan seluruh RS.
     */
    private const PERAN_CONTOH = [
        'contoh_admisi' => ['Contoh Petugas Admisi', 'contoh.admisi', ['pendaftaran']],
        'contoh_perawat' => ['Contoh Perawat', 'contoh.perawat', ['igd', 'rawat_inap']],
        'contoh_dokter' => ['Contoh Dokter', 'contoh.dokter', ['igd', 'rawat_jalan', 'rekam_medis']],
        'contoh_data_induk' => ['Contoh Admin Data Induk', 'contoh.data.induk', ['master_data']],
    ];

    /**
     * Peran contoh dengan izin terpilih (bukan seluruh menu): kode peran => [nama, akun, kode izin].
     * Bagian data pribadi (sdm.pegawai.identitas.lihat) sengaja tidak diberikan: NIK tampil tersamar.
     */
    private const PERAN_IZIN_CONTOH = [
        'contoh_sdm' => ['Contoh Admin SDM', 'contoh.sdm', [
            'sdm.pegawai.lihat', 'sdm.pegawai.input', 'sdm.pegawai.perbaiki',
            'sdm.kredensial.lihat', 'sdm.kredensial.input', 'sdm.kredensial.perbaiki',
            'master_data.organisasi.lihat', 'pengaturan.akun.lihat', 'pengaturan.akun.input',
        ]],
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

        [$rahasiaMfa, $rahasiaMfaAcak] = $this->rahasiaTotp('autentikasi.akun_sintetis_mfa_rahasia');
        [$rahasiaSuperadmin, $rahasiaSuperadminAcak] = $this->rahasiaTotp('autentikasi.akun_sintetis_superadmin_mfa_rahasia');
        [$rahasiaSuperadminDua, $rahasiaSuperadminDuaAcak] = $this->rahasiaTotp('autentikasi.akun_sintetis_superadmin_dua_mfa_rahasia');
        [$rahasiaSdm, $rahasiaSdmAcak] = $this->rahasiaTotp('autentikasi.akun_sintetis_sdm_mfa_rahasia');
        $rahasia = ['contoh.mfa' => $rahasiaMfa, 'contoh.superadmin' => $rahasiaSuperadmin, 'contoh.superadmin.dua' => $rahasiaSuperadminDua, 'contoh.sdm' => $rahasiaSdm];

        $urutan = 0;
        foreach (self::AKUN as $namaPengguna => $nama) {
            // Pegawai sintetis lengkap (tiket 1.8d); NIK tidak diisi (tidak ada NIK sintetis yang aman dipakai).
            $pegawai = Pegawai::query()->updateOrCreate(
                ['nip' => sprintf('SINTETIS-%03d', ++$urutan)],
                ['nama' => $nama, 'profesi' => self::PROFESI[$namaPengguna] ?? 'non_kesehatan', 'jabatan' => $nama,
                    'jenis_kepegawaian' => 'Sintetis', 'tanggal_keluar' => null, 'alasan_keluar' => null],
            );
            $pegawai->forceFill(['status' => 'aktif'])->save();
            Pengguna::query()->firstOrNew(['nama_pengguna' => $namaPengguna])->forceFill([
                'pegawai_id' => $pegawai->id,
                'email' => $namaPengguna.'@contoh.invalid',
                'sandi_hash' => Hash::make($sandi),
                'status' => 'aktif',
                // Keadaan awal yang sama tiap seeding, agar uji e2e "terkunci" dan MFA bisa diulang.
                'percobaan_gagal' => 0,
                'terkunci_sampai' => null,
                'wajib_mfa' => str_starts_with($namaPengguna, 'contoh.mfa'),
                'mfa_rahasia_enkripsi' => $rahasia[$namaPengguna] ?? null,
                'mfa_aktif_pada' => isset($rahasia[$namaPengguna]) ? now() : null,
                'mfa_langkah_terakhir' => null,
                'sesi_dicabut_pada' => null,
            ])->save();
        }

        // Kode cadangan akun sintetis dibatalkan (tidak dihapus: simrs_app tanpa DELETE di skema akses).
        DB::table('akses.kode_cadangan_mfa')
            ->whereIn('pengguna_id', Pengguna::query()->whereIn('nama_pengguna', array_keys(self::AKUN))->select('id'))
            ->whereNull('dibatalkan_pada')
            ->update(['dibatalkan_pada' => now()]);

        $this->pasangPeranContoh();
        $this->kosongkanAkses(self::TANPA_PERAN);
        $this->tugaskan('contoh.superadmin', Peran::query()->where('kode', MenuBawaan::PERAN_SUPERADMIN)->firstOrFail());
        $this->tugaskan('contoh.superadmin.dua', Peran::query()->where('kode', MenuBawaan::PERAN_SUPERADMIN)->firstOrFail());

        $this->command?->info('Akun sintetis: '.implode(', ', array_keys(self::AKUN)).'.');
        $sekaliTampil = array_filter([
            $dibuatAcak ? "Sandi acak (hanya ditampilkan sekali): {$sandi}" : null,
            $rahasiaMfaAcak ? "Rahasia TOTP contoh.mfa (hanya ditampilkan sekali): {$rahasiaMfa}" : null,
            $rahasiaSuperadminAcak ? "Rahasia TOTP contoh.superadmin (hanya ditampilkan sekali): {$rahasiaSuperadmin}" : null,
            $rahasiaSuperadminDuaAcak ? "Rahasia TOTP contoh.superadmin.dua (hanya ditampilkan sekali): {$rahasiaSuperadminDua}" : null,
            $rahasiaSdmAcak ? "Rahasia TOTP contoh.sdm (hanya ditampilkan sekali): {$rahasiaSdm}" : null,
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
            // Keadaan awal yang sama tiap seeding: izin yang dicabut lewat layar (status batal) aktif lagi.
            foreach ($subMenu as $menuId) {
                $this->aktifkan('akses.peran_menu', ['peran_id' => $peran->id, 'menu_id' => $menuId]);
            }
            foreach (Izin::query()->whereIn('menu_id', $subMenu)->pluck('id') as $izinId) {
                $this->aktifkan('akses.peran_izin', ['peran_id' => $peran->id, 'izin_id' => $izinId]);
            }
            app(CacheHakAkses::class)->lupakanSemua();

            $this->tugaskan($namaPengguna, $peran);
        }

        foreach (self::PERAN_IZIN_CONTOH as $kode => [$nama, $namaPengguna, $daftarIzin]) {
            $peran = Peran::query()->updateOrCreate(['kode' => $kode], ['nama' => $nama, 'uraian' => 'Peran sintetis untuk lokal/staging.']);
            $izin = Izin::query()->whereIn('kode', $daftarIzin)->get(['id', 'menu_id', 'jenis_aksi']);
            if ($izin->count() !== count($daftarIzin)) {
                throw new RuntimeException("Izin peran {$kode} tidak lengkap di akses.izin.");
            }
            // Sama dengan KelolaPeran: peran_menu diturunkan dari izin lihat.
            foreach ($izin->where('jenis_aksi', 'lihat')->pluck('menu_id')->unique() as $menuId) {
                $this->aktifkan('akses.peran_menu', ['peran_id' => $peran->id, 'menu_id' => $menuId]);
            }
            foreach ($izin->pluck('id') as $izinId) {
                $this->aktifkan('akses.peran_izin', ['peran_id' => $peran->id, 'izin_id' => $izinId]);
            }
            app(CacheHakAkses::class)->lupakanSemua();

            $this->tugaskan($namaPengguna, $peran);
        }
    }

    /** Akhiri penugasan dan pengecualian per akun yang sempat dibuat uji lain (status batal, tidak dihapus). */
    private function kosongkanAkses(string $namaPengguna): void
    {
        $penggunaId = Pengguna::query()->where('nama_pengguna', $namaPengguna)->select('id');
        $pemberian = [
            ...PenggunaPeran::query()->where('status', 'aktif')->whereIn('pengguna_id', $penggunaId)->get(),
            ...PenggunaIzinKhusus::query()->where('status', 'aktif')->whereIn('pengguna_id', $penggunaId)->get(),
        ];
        foreach ($pemberian as $p) {
            $p->forceFill(['status' => 'batal', 'alasan_batal' => 'Seeding ulang: akun contoh tanpa peran', 'dibatalkan_pada' => now()])->save();
        }
    }

    private function tugaskan(string $namaPengguna, Peran $peran): void
    {
        $pengguna = Pengguna::query()->where('nama_pengguna', $namaPengguna)->firstOrFail();
        PenggunaPeran::query()->firstOrCreate(
            ['pengguna_id' => $pengguna->id, 'peran_id' => $peran->id, 'status' => 'aktif'],
            ['dasar_pemberian' => 'Data sintetis (AkunSintetisSeeder)'],
        );
    }

    /** @param  array<string, string>  $kunci */
    private function aktifkan(string $tabel, array $kunci): void
    {
        DB::table($tabel)->upsert(
            [['id' => (string) Str::uuid7(), ...$kunci, 'status' => 'aktif', 'alasan_batal' => null, 'dibatalkan_oleh' => null, 'dibatalkan_pada' => null]],
            array_keys($kunci),
            ['status', 'alasan_batal', 'dibatalkan_oleh', 'dibatalkan_pada'],
        );
    }

    /** @return array{0: string, 1: bool} rahasia Base32 dan apakah dibuat acak */
    private function rahasiaTotp(string $kunciConfig): array
    {
        $rahasia = strtoupper((string) config($kunciConfig));
        $acak = $rahasia === '';
        if ($acak) {
            $rahasia = Totp::rahasiaBaru();
        }
        Totp::base32Dekode($rahasia);

        return [$rahasia, $acak];
    }
}
