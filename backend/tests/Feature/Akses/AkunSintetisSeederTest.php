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

        $akun = Pengguna::query()->where('nama_pengguna', 'like', 'contoh.%')->get();
        $this->assertEqualsCanonicalizing(
            ['contoh.admisi', 'contoh.perawat', 'contoh.dokter', 'contoh.terkunci', 'contoh.mfa', 'contoh.mfa.baru', 'contoh.superadmin', 'contoh.superadmin.dua', 'contoh.tanpa.peran', 'contoh.data.induk', 'contoh.sdm', 'contoh.ttd', 'contoh.ttd.baru',
                'contoh.usulan.pengusul', 'contoh.usulan.verifikator', 'contoh.usulan.penyetuju'],
            $akun->pluck('nama_pengguna')->all(),
        );
        foreach ($akun as $pengguna) {
            $this->assertSame('argon2id', password_get_info($pengguna->sandi_hash)['algoName']);
        }

        // contoh.mfa: wajib MFA dengan rahasia sintetis; contoh.mfa.baru: wajib tetapi belum terdaftar.
        $mfa = $akun->firstWhere('nama_pengguna', 'contoh.mfa');
        $baru = $akun->firstWhere('nama_pengguna', 'contoh.mfa.baru');
        $this->assertTrue($mfa->wajib_mfa && $mfa->mfaAktif());
        $this->assertTrue($baru->wajib_mfa && ! $baru->mfaAktif());
        $this->assertFalse($akun->firstWhere('nama_pengguna', 'contoh.admisi')->wajib_mfa);
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

        $superadmin = $akun('contoh.superadmin');
        $this->assertTrue($superadmin->mfaAktif());
        $this->assertSame('MFRGGZDFMZTWQ2LKNNWG23TPOBYXE43U', $superadmin->mfa_rahasia_enkripsi);
        $this->assertTrue($evaluator->boleh($superadmin, 'pengaturan.akun.perbaiki'));
        $superadminDua = $akun('contoh.superadmin.dua');
        $this->assertSame('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', $superadminDua->mfa_rahasia_enkripsi);
        $this->assertTrue($evaluator->boleh($superadminDua, 'pengaturan.akun.perbaiki'));

        // Izin yang dicabut dari peran contoh lewat layar (status batal) aktif lagi saat seeding ulang.
        $peranDokter = PenggunaPeran::query()->where('pengguna_id', $akun('contoh.dokter')->id)->value('peran_id');
        DB::table('akses.peran_izin')->where('peran_id', $peranDokter)->update(['status' => 'batal']);
        app(CacheHakAkses::class)->lupakanSemua();
        $this->assertFalse($evaluator->boleh($akun('contoh.dokter'), 'rawat_jalan.pemeriksaan.input'));

        $this->seed(AkunSintetisSeeder::class);

        $this->assertTrue($evaluator->boleh($akun('contoh.dokter'), 'rawat_jalan.pemeriksaan.input'));
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
        $akun = Pengguna::query()->where('nama_pengguna', 'contoh.terkunci')->firstOrFail();
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

        $this->assertTrue($evaluator->bolehMenu($akun('contoh.admisi'), 'pendaftaran.pasien'));
        $this->assertFalse($evaluator->bolehMenu($akun('contoh.admisi'), 'igd.papan'));
        $this->assertTrue($evaluator->boleh($akun('contoh.dokter'), 'rawat_jalan.pemeriksaan.input'));
        $this->assertTrue($evaluator->bolehMenu($akun('contoh.perawat'), 'rawat_inap.pemberian_obat'));
        $this->assertFalse($evaluator->bolehMenu($akun('contoh.mfa'), 'igd.papan'));
        $this->assertSame(1, PenggunaPeran::query()->where('pengguna_id', $akun('contoh.dokter')->id)->count());
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
        $sdm = Pengguna::query()->where('nama_pengguna', 'contoh.sdm')->firstOrFail();

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
        $verifikator = Pengguna::query()->where('nama_pengguna', 'contoh.usulan.verifikator')->firstOrFail();
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
