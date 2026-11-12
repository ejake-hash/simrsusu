<?php

namespace Tests\Feature\Akses;

use App\Modules\Akses\Application\AccessEvaluator;
use App\Modules\Akses\Application\CacheHakAkses;
use App\Modules\Akses\Database\Seeders\AkunSintetisSeeder;
use App\Modules\Akses\Models\Menu;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\Akses\Models\PenggunaIzinKhusus;
use App\Modules\Akses\Models\PenggunaPeran;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class AkunSintetisSeederTest extends TestCase
{
    public function test_menolak_berjalan_di_production(): void
    {
        // Dipanggil langsung: db:seed milik Laravel sendiri sudah menolak production tanpa --force,
        // yang diuji di sini adalah guard seeder bila --force tetap dipakai.
        $this->app['env'] = 'production';

        $this->expectException(RuntimeException::class);
        $this->app->make(AkunSintetisSeeder::class)->__invoke();
    }

    public function test_membuat_akun_sintetis_dengan_hash_argon2id(): void
    {
        config(['autentikasi.akun_sintetis_sandi' => 'kuda-lumping-biru-29']);

        $this->seed(AkunSintetisSeeder::class);

        $akun = Pengguna::query()->where('email', 'like', '%@contoh.invalid')->get();
        $this->assertEqualsCanonicalizing(
            ['admisi', 'perawat', 'dokter', 'terkunci', 'mfa', 'mfa.baru', 'superadmin', 'superadmin.dua', 'tanpa.peran', 'data.induk', 'sdm', 'ttd', 'ttd.baru',
                'usulan.pengusul', 'usulan.verifikator', 'usulan.penyetuju', 'integrasi', 'rekam.medis', 'dokter.poli', 'apoteker', 'dokter.darurat'],
            $akun->pluck('nama_pengguna')->all(),
        );
        $this->assertSame(0, Pengguna::query()->where('nama_pengguna', 'like', 'contoh.%')->count(), 'Nama lama berawalan contoh. sudah diganti.');
        foreach ($akun as $pengguna) {
            $this->assertSame('argon2id', password_get_info($pengguna->sandi_hash)['algoName']);
        }

        // mfa: wajib MFA dengan rahasia sintetis; mfa.baru: wajib tetapi belum terdaftar.
        $mfa = $akun->firstWhere('nama_pengguna', 'mfa');
        $baru = $akun->firstWhere('nama_pengguna', 'mfa.baru');
        $this->assertTrue($mfa->wajib_mfa && $mfa->mfaAktif());
        $this->assertTrue($baru->wajib_mfa && ! $baru->mfaAktif());
        $this->assertFalse($akun->firstWhere('nama_pengguna', 'admisi')->wajib_mfa);
        // integrasi (tiket 1.9): MFA aktif karena memegang pengaturan.integrasi.perbaiki (sensitif).
        $this->assertTrue($akun->firstWhere('nama_pengguna', 'integrasi')->mfaAktif());
    }

    public function test_superadmin_sintetis_dengan_mfa_dan_peran_contoh_aktif_lagi_setelah_seeding_ulang(): void
    {
        config([
            'autentikasi.akun_sintetis_sandi' => 'kuda-lumping-biru-29',
            'autentikasi.akun_sintetis_superadmin_mfa_rahasia' => 'MFRGGZDFMZTWQ2LKNNWG23TPOBYXE43U',
            'autentikasi.akun_sintetis_superadmin_dua_mfa_rahasia' => 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ',
        ]);
        $this->seed(AkunSintetisSeeder::class);
        $evaluator = app(AccessEvaluator::class);
        $akun = fn (string $nama) => Pengguna::query()->where('nama_pengguna', $nama)->firstOrFail();

        $superadmin = $akun('superadmin');
        $this->assertTrue($superadmin->mfaAktif());
        $this->assertSame('MFRGGZDFMZTWQ2LKNNWG23TPOBYXE43U', $superadmin->mfa_rahasia_enkripsi);
        $this->assertTrue($evaluator->boleh($superadmin, 'pengaturan.akun.perbaiki'));
        $superadminDua = $akun('superadmin.dua');
        $this->assertSame('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', $superadminDua->mfa_rahasia_enkripsi);
        $this->assertTrue($evaluator->boleh($superadminDua, 'pengaturan.akun.perbaiki'));

        // Izin yang dicabut dari peran contoh lewat layar (status batal) aktif lagi saat seeding ulang.
        $peranDokter = PenggunaPeran::query()->where('pengguna_id', $akun('dokter')->id)->value('peran_id');
        DB::table('akses.peran_izin')->where('peran_id', $peranDokter)->update(['status' => 'batal']);
        app(CacheHakAkses::class)->lupakanSemua();
        $this->assertFalse($evaluator->boleh($akun('dokter'), 'rawat_jalan.pemeriksaan.input'));

        $this->seed(AkunSintetisSeeder::class);

        $this->assertTrue($evaluator->boleh($akun('dokter'), 'rawat_jalan.pemeriksaan.input'));
        $this->assertSame(1, PenggunaPeran::query()->where('pengguna_id', $superadmin->id)->count());

        // Akun tanpa peran kembali tanpa penugasan berlaku walau sempat ditugaskan, dan tidak wajib MFA.
        $tanpaPeran = $akun(AkunSintetisSeeder::TANPA_PERAN);
        PenggunaPeran::query()->create(['pengguna_id' => $tanpaPeran->id, 'peran_id' => $peranDokter, 'dasar_pemberian' => 'Uji e2e']);
        PenggunaIzinKhusus::query()->create([
            'pengguna_id' => $tanpaPeran->id, 'efek' => 'tambah', 'dasar_pemberian' => 'Uji e2e', 'alasan' => 'Uji',
            'menu_id' => Menu::query()->where('kode', 'igd.papan')->value('id'),
        ]);
        $this->seed(AkunSintetisSeeder::class);
        $this->assertFalse(PenggunaPeran::query()->berlaku()->where('pengguna_id', $tanpaPeran->id)->exists());
        $this->assertFalse(PenggunaIzinKhusus::query()->berlaku()->where('pengguna_id', $tanpaPeran->id)->exists());
        $this->assertSame(['beranda'], array_keys(app(AccessEvaluator::class)->hak($tanpaPeran->refresh())->menu));
        $this->assertFalse($tanpaPeran->refresh()->wajib_mfa);
    }

    public function test_seeding_ulang_membuka_kunci_akun_sintetis(): void
    {
        config(['autentikasi.akun_sintetis_sandi' => 'kuda-lumping-biru-29']);
        $this->seed(AkunSintetisSeeder::class);
        $akun = Pengguna::query()->where('nama_pengguna', 'terkunci')->firstOrFail();
        $akun->forceFill(['percobaan_gagal' => 3, 'terkunci_sampai' => now()->addMinutes(15)])->save();

        $this->seed(AkunSintetisSeeder::class);

        $akun->refresh();
        $this->assertFalse($akun->terkunci());
        $this->assertSame(0, $akun->percobaan_gagal);
    }

    public function test_peran_contoh_memberi_menu_berbeda_dan_aman_diulang(): void
    {
        config(['autentikasi.akun_sintetis_sandi' => 'kuda-lumping-biru-29']);
        $this->seed(AkunSintetisSeeder::class);
        $this->seed(AkunSintetisSeeder::class);
        $evaluator = app(AccessEvaluator::class);
        $akun = fn (string $nama) => Pengguna::query()->where('nama_pengguna', $nama)->firstOrFail();

        $this->assertTrue($evaluator->bolehMenu($akun('admisi'), 'pendaftaran.pasien'));
        $this->assertFalse($evaluator->bolehMenu($akun('admisi'), 'igd.papan'));
        // Pilihan poli, jadwal, dan penjamin di layar kunjungan poli: lihat saja, tanpa ubah data induk.
        foreach (['master_data.organisasi.lihat', 'master_data.jadwal_praktik.lihat', 'master_data.penjamin.lihat'] as $kode) {
            $this->assertTrue($evaluator->boleh($akun('admisi'), $kode), $kode);
        }
        $this->assertFalse($evaluator->boleh($akun('admisi'), 'master_data.jadwal_praktik.input'));
        $this->assertTrue($evaluator->boleh($akun('dokter'), 'rawat_jalan.pemeriksaan.input'));
        $this->assertTrue($evaluator->bolehMenu($akun('perawat'), 'rawat_inap.pemberian_obat'));
        // Tiket 2.23: perawat (seluruh menu IGD) mendaftarkan pasien IGD; admisi poli tidak.
        $this->assertTrue($evaluator->boleh($akun('perawat'), 'igd.pendaftaran.input'));
        // Tiket 2.24: perawat mencatat triase dan ulang triase; admisi poli tidak.
        foreach (['igd.triase.lihat', 'igd.triase.input'] as $kode) {
            $this->assertTrue($evaluator->boleh($akun('perawat'), $kode), $kode);
        }
        $this->assertFalse($evaluator->bolehMenu($akun('admisi'), 'igd.triase'));
        // Instrumen triase dikelola admin data induk (seluruh menu master_data), bukan perawat.
        $this->assertTrue($evaluator->boleh($akun('data.induk'), 'master_data.instrumen_triase.perbaiki'));
        $this->assertFalse($evaluator->bolehMenu($akun('perawat'), 'master_data.instrumen_triase'));
        // 2.23-fe: cari pasien dan pilih penjamin dari layar Pendaftaran IGD, tanpa NIK utuh dan tanpa ubah data induk.
        foreach (['pendaftaran.pasien.lihat', 'master_data.penjamin.lihat'] as $kode) {
            $this->assertTrue($evaluator->boleh($akun('perawat'), $kode), $kode);
        }
        $this->assertFalse($evaluator->boleh($akun('perawat'), 'pendaftaran.pasien.identitas.lihat'));
        $this->assertFalse($evaluator->boleh($akun('perawat'), 'pendaftaran.pasien.input'));
        $this->assertFalse($evaluator->bolehMenu($akun('admisi'), 'igd.pendaftaran'));
        $this->assertFalse($evaluator->bolehMenu($akun('mfa'), 'igd.papan'));
        // 2.26-fe: dokter poli contoh (SIP dari lokal:kunjungan-contoh) membuka Disposisi IGD dan daftar pasien IGD; izin
        // input bersyarat SIP (lapis 5), jadi di sini cukup diperiksa terpasang di perannya, tanpa izin sensitif.
        foreach (['igd.pendaftaran.lihat', 'igd.disposisi.lihat'] as $kode) {
            $this->assertTrue($evaluator->boleh($akun('dokter.poli'), $kode), $kode);
        }
        $this->assertTrue(DB::table('akses.peran_izin')->join('akses.peran', 'akses.peran.id', '=', 'akses.peran_izin.peran_id')
            ->join('akses.izin', 'akses.izin.id', '=', 'akses.peran_izin.izin_id')->where('akses.peran.kode', 'contoh_dokter_poli')
            ->where('akses.izin.kode', 'igd.disposisi.input')->where('akses.peran_izin.status', 'aktif')->exists());
        $this->assertFalse((bool) DB::table('akses.izin')->where('kode', 'igd.disposisi.input')->value('sensitif'));
        $this->assertFalse($evaluator->bolehMenu($akun('dokter.poli'), 'igd.triase'));
        // 2.29: pindah ruang = perintah dokter: dokter poli contoh memegangnya, perawat (seluruh menu rawat_inap) tidak;
        // penempatan TT oleh admisi.
        $peranIzin = fn (string $peran, string $izin) => DB::table('akses.peran_izin')->join('akses.peran', 'akses.peran.id', '=', 'akses.peran_izin.peran_id')
            ->join('akses.izin', 'akses.izin.id', '=', 'akses.peran_izin.izin_id')->where('akses.peran.kode', $peran)
            ->where('akses.izin.kode', $izin)->where('akses.peran_izin.status', 'aktif')->exists();
        $this->assertTrue($peranIzin('contoh_dokter_poli', 'rawat_inap.pindah_ruang.input'));
        // 2.29-fe: pindah ruang dari peta TT, jadi dokter poli contoh juga melihat peta TT (bukan izin sensitif).
        $this->assertTrue($evaluator->boleh($akun('dokter.poli'), 'rawat_inap.peta_tempat_tidur.lihat'));
        $this->assertFalse((bool) DB::table('akses.izin')->where('kode', 'rawat_inap.peta_tempat_tidur.lihat')->value('sensitif'));
        $this->assertFalse($peranIzin('contoh_perawat', 'rawat_inap.pindah_ruang.input'));
        // 2.40: resume medis disahkan DPJP; perawat tetap boleh membuat rencana pulang (pemulangan.input).
        $this->assertFalse($peranIzin('contoh_perawat', 'rawat_inap.pemulangan.sahkan'));
        $this->assertTrue($peranIzin('contoh_perawat', 'rawat_inap.pemulangan.input'));
        $this->assertTrue($peranIzin('contoh_perawat', 'rawat_inap.penempatan.input'));
        $this->assertTrue($evaluator->boleh($akun('admisi'), 'rawat_inap.penempatan.input'));
        $this->assertFalse($evaluator->boleh($akun('perawat'), 'rawat_inap.pindah_ruang.input'));
        $this->assertSame(1, PenggunaPeran::query()->where('pengguna_id', $akun('dokter')->id)->count());
    }

    public function test_superadmin_semua_menu_hanya_di_local_dengan_flag_dan_dicabut_saat_flag_mati(): void
    {
        config(['autentikasi.akun_sintetis_sandi' => 'kuda-lumping-biru-29', 'autentikasi.akun_sintetis_superadmin_semua_menu' => true]);
        $evaluator = app(AccessEvaluator::class);
        $superadmin = fn () => Pengguna::query()->where('nama_pengguna', 'superadmin')->firstOrFail();
        // Tanpa lupakanSemua() di sini: seeder sendiri wajib membersihkan cache hak akses.
        $bolehIgd = fn () => $evaluator->bolehMenu($superadmin(), 'igd.papan');

        $this->seed(AkunSintetisSeeder::class);
        $this->assertFalse($bolehIgd(), 'Flag tanpa APP_ENV=local (testing/CI/staging) tidak berlaku: batas PRD 9.2.4.');
        $this->assertSame(0, DB::table('akses.peran')->where('kode', AkunSintetisSeeder::PERAN_SEMUA_MENU)->count(), 'Peran demo tidak dibuat.');

        $this->app['env'] = 'local';
        try {
            $this->seed(AkunSintetisSeeder::class);
            $this->assertTrue($bolehIgd());
            $this->assertTrue($evaluator->bolehMenu($superadmin(), 'pendaftaran.pasien'));
            $this->assertTrue($evaluator->bolehMenu($superadmin(), 'pengaturan.akun'), 'Peran superadmin bawaan tetap.');
            $this->assertFalse($evaluator->bolehMenu(Pengguna::query()->where('nama_pengguna', 'superadmin.dua')->firstOrFail(), 'igd.papan'));

            config(['autentikasi.akun_sintetis_superadmin_semua_menu' => false]);
            $this->seed(AkunSintetisSeeder::class);
            $this->assertFalse($bolehIgd(), 'Flag dimatikan lalu seeding ulang: kembali ke batas PRD 9.2.4.');
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    public function test_admin_sdm_sintetis_dengan_mfa_dan_izin_terpilih(): void
    {
        config([
            'autentikasi.akun_sintetis_sandi' => 'kuda-lumping-biru-29',
            'autentikasi.akun_sintetis_sdm_mfa_rahasia' => 'ONSG2YLEMJUW4U3ENVQWI3LJNZJWI3LB',
        ]);
        $this->seed(AkunSintetisSeeder::class);
        $this->seed(AkunSintetisSeeder::class);
        $evaluator = app(AccessEvaluator::class);
        $sdm = Pengguna::query()->where('nama_pengguna', 'sdm')->firstOrFail();

        $this->assertTrue($sdm->mfaAktif());
        $this->assertSame('ONSG2YLEMJUW4U3ENVQWI3LJNZJWI3LB', $sdm->mfa_rahasia_enkripsi);
        foreach (['sdm.pegawai.perbaiki', 'sdm.kredensial.input', 'master_data.organisasi.lihat', 'pengaturan.akun.input'] as $kode) {
            $this->assertTrue($evaluator->boleh($sdm, $kode), $kode);
        }
        foreach (['sdm.pegawai.identitas.lihat', 'pengaturan.akun.perbaiki', 'pengaturan.peran.lihat', 'master_data.organisasi.input'] as $kode) {
            $this->assertFalse($evaluator->boleh($sdm, $kode), $kode);
        }
        $this->assertTrue($evaluator->bolehMenu($sdm, 'sdm.pegawai'));
        // Akun usulan data induk (1.8c): keempat aksi usulan + lihat organisasi.
        $verifikator = Pengguna::query()->where('nama_pengguna', 'usulan.verifikator')->firstOrFail();
        $this->assertTrue($evaluator->boleh($verifikator, 'master_data.usulan.setujui'));
        $this->assertTrue($evaluator->boleh($verifikator, 'master_data.organisasi.lihat'));
        $this->assertFalse($evaluator->boleh($verifikator, 'master_data.organisasi.perbaiki'));
        $this->assertSame(1, PenggunaPeran::query()->where('pengguna_id', $sdm->id)->count());
    }

    public function test_menolak_sandi_akun_sintetis_yang_lemah(): void
    {
        config(['autentikasi.akun_sintetis_sandi' => 'passwordpassword']);

        $this->expectException(ValidationException::class);
        $this->seed(AkunSintetisSeeder::class);
    }
}
