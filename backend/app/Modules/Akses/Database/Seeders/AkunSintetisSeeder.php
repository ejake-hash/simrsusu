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
use Symfony\Component\Console\Formatter\OutputFormatter;

/** Akun sintetis untuk lokal/staging (PRD 3.4, FR-54-10). Ditolak di production. */
final class AkunSintetisSeeder extends Seeder
{
    /** Akun tanpa peran dan pengecualian (Beranda "belum ada modul", tiket 1.2e). */
    public const TANPA_PERAN = 'tanpa.peran';

    public const PERAN_SEMUA_MENU = 'contoh_semua_menu';

    public const AKUN = [
        'admisi' => 'Contoh Petugas Admisi',
        'perawat' => 'Contoh Perawat',
        'dokter' => 'Contoh Dokter',
        // Khusus uji penguncian (e2e): boleh dikunci kapan saja tanpa mengganggu akun lain.
        'terkunci' => 'Contoh Akun Uji Kunci',
        // MFA (tiket 1.1b): wajib MFA dengan rahasia TOTP sintetis yang diketahui e2e.
        'mfa' => 'Contoh Akun MFA',
        // MFA wajib tetapi belum terdaftar: masuk → perlu_daftar_mfa. Seeding ulang mengosongkannya lagi.
        'mfa.baru' => 'Contoh Akun MFA Baru',
        // Superadmin sintetis untuk e2e Pengaturan (tiket 1.2c), MFA aktif dengan rahasia yang diketahui e2e.
        'superadmin' => 'Contoh Superadmin',
        // Superadmin kedua (tiket 1.2d): e2e kontrol ganda "superadmin A mengajukan, superadmin B menyetujui".
        'superadmin.dua' => 'Contoh Superadmin Dua',
        // Tanpa penugasan dan tanpa wajib MFA: Beranda "belum ada modul" (tiket 1.2e). Seeding ulang
        // mengakhiri penugasan yang sempat dibuat uji lain.
        self::TANPA_PERAN => 'Contoh Akun Tanpa Peran',
        // Pemegang Master Data (organisasi, unit, KSM; tiket 1.8a), bukan superadmin (FR-54-01).
        'data.induk' => 'Contoh Admin Data Induk',
        // Admin SDM (tiket 1.8d): data pegawai, kredensial, dan buat akun dari pegawai. pengaturan.akun.input
        // butuh MFA, jadi MFA aktif dengan rahasia sintetis yang diketahui e2e.
        'sdm' => 'Contoh Admin SDM',
        // Admin integrasi (tiket 1.9): konfigurasi, uji koneksi, kotak keluar, log. pengaturan.integrasi.perbaiki sensitif,
        // jadi MFA aktif dengan rahasia sintetis yang diketahui e2e.
        'integrasi' => 'Contoh Admin Integrasi',
        // TTE (tiket 1.6): ttd dengan TTE aktif (PIN sintetis, TtdSintetisSeeder); ttd.baru tanpa TTE (alur aktivasi).
        'ttd' => 'Contoh Akun TTE',
        'ttd.baru' => 'Contoh Akun TTE Baru',
        // Usulan data induk berjenjang (tiket 1.8c): tiga orang berbeda untuk e2e pemisahan tugas (dijaga server).
        'usulan.pengusul' => 'Contoh Pengusul Usulan',
        'usulan.verifikator' => 'Contoh Verifikator Usulan',
        'usulan.penyetuju' => 'Contoh Penyetuju Usulan',
        // Verifikator gabung RM (tiket 2.9): pengusul (admisi) ≠ pemverifikasi, dijaga server.
        'rekam.medis' => 'Contoh Petugas Rekam Medis',
    ];

    /**
     * Akun sintetis ber-MFA aktif dengan rahasia TOTP yang diketahui e2e: nama pengguna => kunci di config/autentikasi.php.
     * Variabel .env-nya = kunci config dalam huruf besar (dipakai `lokal:siapkan-env` dan `lokal:kode-mfa`, tiket 1.16).
     */
    public const RAHASIA_MFA = [
        'mfa' => 'akun_sintetis_mfa_rahasia',
        'superadmin' => 'akun_sintetis_superadmin_mfa_rahasia',
        'superadmin.dua' => 'akun_sintetis_superadmin_dua_mfa_rahasia',
        'sdm' => 'akun_sintetis_sdm_mfa_rahasia',
        'integrasi' => 'akun_sintetis_integrasi_mfa_rahasia',
    ];

    /** Profesi pegawai sintetis (selain ini non_kesehatan). */
    private const PROFESI = ['perawat' => 'perawat', 'dokter' => 'dokter'];

    /**
     * Peran contoh (tiket 1.2a) agar menu tiap akun sintetis berbeda: kode peran => [nama, akun, menu].
     * Seluruh sub-menu menu itu diberikan beserta aksi dasarnya, cakupan seluruh RS.
     */
    private const PERAN_CONTOH = [
        'contoh_admisi' => ['Contoh Petugas Admisi', 'admisi', ['pendaftaran']],
        'contoh_perawat' => ['Contoh Perawat', 'perawat', ['igd', 'rawat_inap']],
        'contoh_dokter' => ['Contoh Dokter', 'dokter', ['igd', 'rawat_jalan', 'rekam_medis']],
        'contoh_data_induk' => ['Contoh Admin Data Induk', 'data.induk', ['master_data']],
    ];

    /**
     * Izin tambahan per peran contoh di atas. Layar Pendaftaran kunjungan poli memuat pilihan poli, jadwal praktik, dan
     * penjamin dari endpoint data induk, jadi petugas admisi butuh izin lihat ketiganya (RS nyata memberinya lewat Peran & izin).
     * Pemilih dokter di layar Jadwal praktik memakai GET /pegawai (sdm.pegawai.lihat).
     */
    private const IZIN_TAMBAHAN_CONTOH = [
        'contoh_admisi' => ['master_data.organisasi.lihat', 'master_data.jadwal_praktik.lihat', 'master_data.penjamin.lihat'],
        // Tiket 2.15: dokter menyelesaikan kunjungan (aksi tambahan, tidak ikut aksi dasar menu rawat_jalan).
        'contoh_dokter' => ['rawat_jalan.pemeriksaan.sahkan'],
        'contoh_data_induk' => ['sdm.pegawai.lihat', 'farmasi.formularium.lihat', 'farmasi.formularium.input', 'farmasi.formularium.perbaiki'],
        // Tiket 2.13: perawat poli melihat worklist, mengisi asesmen awal dan alergi, dan memanggil nomor antrean (poli dipilih dari data induk).
        'contoh_perawat' => [
            'rawat_jalan.worklist.lihat', 'rawat_jalan.asesmen_perawat.lihat', 'rawat_jalan.asesmen_perawat.input', 'rawat_jalan.asesmen_perawat.perbaiki',
            'pendaftaran.antrean.lihat', 'pendaftaran.antrean.perbaiki', 'master_data.organisasi.lihat',
            // Tiket 2.14: perawat menulis CPPT dan mengesahkannya dengan PIN sendiri.
            'rawat_jalan.cppt.lihat', 'rawat_jalan.cppt.input', 'rawat_jalan.cppt.perbaiki', 'rawat_jalan.cppt.sahkan',
        ],
    ];

    /**
     * Peran contoh dengan izin terpilih (bukan seluruh menu): kode peran => [nama, akun, kode izin].
     * Bagian data pribadi (sdm.pegawai.identitas.lihat) sengaja tidak diberikan: NIK tampil tersamar.
     */
    private const PERAN_IZIN_CONTOH = [
        'contoh_sdm' => ['Contoh Admin SDM', 'sdm', [
            'sdm.pegawai.lihat', 'sdm.pegawai.input', 'sdm.pegawai.perbaiki',
            'sdm.kredensial.lihat', 'sdm.kredensial.input', 'sdm.kredensial.perbaiki',
            'master_data.organisasi.lihat', 'pengaturan.akun.lihat', 'pengaturan.akun.input',
        ]],
        'contoh_integrasi' => ['Contoh Admin Integrasi', 'integrasi', [
            'pengaturan.integrasi.lihat', 'pengaturan.integrasi.perbaiki', 'integrasi.satusehat.lihat', 'integrasi.satusehat.perbaiki',
            'integrasi.bpjs.lihat', 'integrasi.bpjs.perbaiki', 'integrasi.log.lihat',
        ]],
        'contoh_usulan_pengusul' => ['Contoh Pengusul Usulan', 'usulan.pengusul', self::IZIN_USULAN],
        'contoh_usulan_verifikator' => ['Contoh Verifikator Usulan', 'usulan.verifikator', self::IZIN_USULAN],
        'contoh_usulan_penyetuju' => ['Contoh Penyetuju Usulan', 'usulan.penyetuju', self::IZIN_USULAN],
        'contoh_rekam_medis' => ['Contoh Petugas Rekam Medis', 'rekam.medis', ['pendaftaran.master_pasien.lihat', 'pendaftaran.master_pasien.verifikasi']],
    ];

    /** Semua aksi usulan data induk + lihat organisasi (pemilih objek); pemisahan tugas dijaga server (1.8c). */
    private const IZIN_USULAN = [
        'master_data.usulan.lihat', 'master_data.usulan.input', 'master_data.usulan.perbaiki', 'master_data.usulan.verifikasi',
        'master_data.usulan.setujui', 'master_data.organisasi.lihat',
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

        $rahasia = $rahasiaAcak = [];
        foreach (self::RAHASIA_MFA as $namaPengguna => $kunciConfig) {
            [$rahasia[$namaPengguna], $rahasiaAcak[$namaPengguna]] = $this->rahasiaTotp('autentikasi.'.$kunciConfig);
        }

        $urutan = 0;
        foreach (self::AKUN as $namaPengguna => $nama) {
            // Pegawai sintetis lengkap (tiket 1.8d); NIK tidak diisi (tidak ada NIK sintetis yang aman dipakai).
            $pegawai = Pegawai::query()->updateOrCreate(
                ['nip' => sprintf('SINTETIS-%03d', ++$urutan)],
                ['nama' => $nama, 'profesi' => self::PROFESI[$namaPengguna] ?? 'non_kesehatan', 'jabatan' => $nama,
                    'jenis_kepegawaian' => 'Sintetis', 'tanggal_keluar' => null, 'alasan_keluar' => null],
            );
            $pegawai->forceFill(['status' => 'aktif'])->save();
            // shortcut: DB lama masih memakai nama berawalan contoh. (sebelum 8 Nov 2026); hapus setelah semua DB lokal di-seed ulang.
            Pengguna::query()->where('nama_pengguna', 'contoh.'.$namaPengguna)->update(['nama_pengguna' => $namaPengguna]);
            Pengguna::query()->firstOrNew(['nama_pengguna' => $namaPengguna])->forceFill([
                'pegawai_id' => $pegawai->id,
                'email' => $namaPengguna.'@contoh.invalid',
                'sandi_hash' => Hash::make($sandi),
                'status' => 'aktif',
                // Keadaan awal yang sama tiap seeding, agar uji e2e "terkunci" dan MFA bisa diulang.
                'percobaan_gagal' => 0,
                'terkunci_sampai' => null,
                'wajib_mfa' => str_starts_with($namaPengguna, 'mfa'),
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
        $this->tugaskan('superadmin', Peran::query()->where('kode', MenuBawaan::PERAN_SUPERADMIN)->firstOrFail());
        $this->tugaskan('superadmin.dua', Peran::query()->where('kode', MenuBawaan::PERAN_SUPERADMIN)->firstOrFail());
        $this->pasangSemuaMenuSuperadmin();

        $this->command?->info('Akun sintetis: '.implode(', ', array_keys(self::AKUN)).'.');
        $sekaliTampil = array_filter([$dibuatAcak ? 'Sandi acak (hanya ditampilkan sekali): '.OutputFormatter::escape($sandi) : null]);
        foreach (array_keys(array_filter($rahasiaAcak)) as $namaPengguna) {
            $sekaliTampil[] = "Rahasia TOTP {$namaPengguna} (hanya ditampilkan sekali): {$rahasia[$namaPengguna]}";
        }
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
            $this->beriIzin($peran, self::IZIN_TAMBAHAN_CONTOH[$kode]);
            app(CacheHakAkses::class)->lupakanSemua();

            $this->tugaskan($namaPengguna, $peran);
        }

        foreach (self::PERAN_IZIN_CONTOH as $kode => [$nama, $namaPengguna, $daftarIzin]) {
            $peran = Peran::query()->updateOrCreate(['kode' => $kode], ['nama' => $nama, 'uraian' => 'Peran sintetis untuk lokal/staging.']);
            $this->beriIzin($peran, $daftarIzin);
            app(CacheHakAkses::class)->lupakanSemua();

            $this->tugaskan($namaPengguna, $peran);
        }
    }

    /**
     * Demo lokal (config autentikasi.akun_sintetis_superadmin_semua_menu): akun `superadmin` mendapat peran tambahan berisi semua menu
     * dan izin. Bila flag mati, penugasan peran itu dibatalkan agar seeding ulang kembali ke batas PRD 9.2.4.
     */
    private function pasangSemuaMenuSuperadmin(): void
    {
        if (! (app()->environment('local') && config('autentikasi.akun_sintetis_superadmin_semua_menu'))) {
            $peranLama = Peran::query()->where('kode', self::PERAN_SEMUA_MENU)->select('id');
            foreach (PenggunaPeran::query()->where('status', 'aktif')->whereIn('peran_id', $peranLama)->get() as $p) {
                $p->forceFill(['status' => 'batal', 'alasan_batal' => 'Seeding ulang: demo semua menu dimatikan', 'dibatalkan_pada' => now()])->save();
            }
            app(CacheHakAkses::class)->lupakanSemua();

            return;
        }

        $peran = Peran::query()->updateOrCreate(['kode' => self::PERAN_SEMUA_MENU],
            ['nama' => 'Contoh Semua Menu (demo lokal)', 'uraian' => 'Peran sintetis demo lokal: semua menu dan izin.']);

        foreach (Menu::query()->whereIn('tingkat', ['sub_menu', 'bagian'])->pluck('id') as $menuId) {
            $this->aktifkan('akses.peran_menu', ['peran_id' => $peran->id, 'menu_id' => $menuId]);
        }
        foreach (Izin::query()->pluck('id') as $izinId) {
            $this->aktifkan('akses.peran_izin', ['peran_id' => $peran->id, 'izin_id' => $izinId]);
        }
        app(CacheHakAkses::class)->lupakanSemua();
        $this->tugaskan('superadmin', $peran);
    }

    /** @param  list<string>  $daftarIzin */
    private function beriIzin(Peran $peran, array $daftarIzin): void
    {
        $izin = Izin::query()->whereIn('kode', $daftarIzin)->get(['id', 'menu_id', 'jenis_aksi']);
        if ($izin->count() !== count($daftarIzin)) {
            throw new RuntimeException("Izin peran {$peran->kode} tidak lengkap di akses.izin.");
        }
        // Sama dengan KelolaPeran: peran_menu diturunkan dari izin lihat.
        foreach ($izin->where('jenis_aksi', 'lihat')->pluck('menu_id')->unique() as $menuId) {
            $this->aktifkan('akses.peran_menu', ['peran_id' => $peran->id, 'menu_id' => $menuId]);
        }
        foreach ($izin->pluck('id') as $izinId) {
            $this->aktifkan('akses.peran_izin', ['peran_id' => $peran->id, 'izin_id' => $izinId]);
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
