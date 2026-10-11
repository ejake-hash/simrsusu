<?php

namespace Tests\Feature\Akses;

use App\Modules\Akses\Application\AccessEvaluator;
use App\Modules\Akses\Application\KebijakanMfa;
use App\Modules\Akses\Database\MenuBawaan;
use App\Modules\Akses\Domain\Cakupan;
use App\Modules\Akses\Models\Izin;
use App\Modules\Akses\Models\Menu;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\Akses\Models\PenggunaIzinKhusus;
use App\Modules\Akses\Models\PenggunaPeran;
use App\Modules\Akses\Models\Peran;
use App\Modules\Akses\Models\PeranIzin;
use App\Modules\Akses\Models\PeranMenu;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Opis\JsonSchema\Validator;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\KlienSpa;
use Tests\TestCase;

/** Tiket 1.2a: menu, peran, izin, AccessEvaluator lapis 1–3, GET /api/v1/saya/menu. */
class HakAksesMenuTest extends TestCase
{
    use KlienSpa;

    private const SANDI = 'kuda-lumping-biru-29';

    protected function setUp(): void
    {
        parent::setUp();

        config(['menu.tampilkan_segera_hadir' => true]);
        // Tes ini memakai Pengaturan sebagai contoh sub-menu yang sudah tersedia, bukan menguji izin
        // sensitif; tanpa ini setiap pemegangnya wajib MFA (MenuBawaan::IZIN_SENSITIF, tiket 1.2c).
        Izin::query()->whereIn('kode', MenuBawaan::IZIN_SENSITIF)->update(['sensitif' => false]);
    }

    public function test_akun_tanpa_penugasan_hanya_melihat_beranda_dan_master_data_ditolak(): void
    {
        $pengguna = $this->buatPengguna();

        $menu = $this->masukLaluMenu($pengguna)->assertOk();

        $this->assertSame(['beranda'], $this->kodeMenu($menu));
        $this->assertSame([], $menu->json('data.izin'));
        $this->minta('GET', '/api/v1/peran')->assertForbidden()->assertJsonPath('error.kode', 'akses_ditolak');
    }

    public function test_peran_memberi_sub_menu_dan_izin_dengan_status_dari_modul_aktif(): void
    {
        $pengguna = $this->buatPengguna();
        $this->tugaskan($pengguna, $this->buatPeran(['pengaturan.peran', 'pengaturan.audit', 'igd.papan']));

        $menu = $this->masukLaluMenu($pengguna)->assertOk();

        $this->assertSame(['beranda', 'igd', 'igd.papan', 'pengaturan', 'pengaturan.audit', 'pengaturan.peran'], $this->kodeMenu($menu));
        $this->assertSame('aktif', $this->item($menu, 'pengaturan')['status']);
        $this->assertSame('aktif', $this->item($menu, 'pengaturan.peran')['status']);
        // Modul 54 aktif tetapi halamannya belum dibangun; IGD (modul 03) belum aktif.
        $this->assertSame('segera', $this->item($menu, 'pengaturan.audit')['status']);
        $this->assertSame('segera', $this->item($menu, 'igd')['status']);
        $this->assertSame(2, $this->item($menu, 'igd.papan')['fase']);
        $this->assertContains('pengaturan.peran.lihat', $menu->json('data.izin'));
        $this->assertNotContains('pengaturan.akun.lihat', $menu->json('data.izin'));

        $this->minta('GET', '/api/v1/peran')->assertOk()->assertJsonPath('meta.kursor_berikutnya', null);
    }

    public function test_cabut_per_akun_selalu_menang_atas_peran(): void
    {
        $pengguna = $this->buatPengguna();
        $peran = $this->buatPeran(['pengaturan.peran', 'pengaturan.akun']);
        $peranLain = $this->buatPeran(['pengaturan.peran']);
        $this->tugaskan($pengguna, $peran);
        $this->tugaskan($pengguna, $peranLain);
        $this->khusus($pengguna, 'cabut', menu: 'pengaturan.peran');

        $menu = $this->masukLaluMenu($pengguna)->assertOk();

        $this->assertNotContains('pengaturan.peran', $this->kodeMenu($menu));
        $this->assertContains('pengaturan.akun', $this->kodeMenu($menu));
        $this->assertSame([], array_values(array_filter($menu->json('data.izin'), fn ($i) => str_starts_with($i, 'pengaturan.peran.'))));
        $this->minta('GET', '/api/v1/peran')->assertForbidden();
    }

    public function test_cabut_satu_aksi_menahan_api_tetapi_sub_menu_tetap_tampil(): void
    {
        $pengguna = $this->buatPengguna();
        $this->tugaskan($pengguna, $this->buatPeran(['pengaturan.peran']));
        $this->khusus($pengguna, 'cabut', izin: 'pengaturan.peran.lihat');

        $menu = $this->masukLaluMenu($pengguna)->assertOk();

        $this->assertContains('pengaturan.peran', $this->kodeMenu($menu));
        $this->assertNotContains('pengaturan.peran.lihat', $menu->json('data.izin'));
        $this->assertContains('pengaturan.peran.input', $menu->json('data.izin'));
        $this->minta('GET', '/api/v1/peran')->assertForbidden();
    }

    public function test_bagian_dicabut_tidak_boleh_sedangkan_bagian_ikut_induk_ikut_diberikan(): void
    {
        $subMenu = Menu::query()->where('kode', 'pengaturan.akun')->firstOrFail();
        $panelMfa = $this->buatBagian($subMenu, 'pengaturan.akun.mfa');
        $bantuan = $this->buatBagian($subMenu, 'pengaturan.akun.bantuan', ikutInduk: true);
        $peran = $this->buatPeran(['pengaturan.akun']);
        PeranMenu::query()->create(['peran_id' => $peran->id, 'menu_id' => $panelMfa->id]);
        $pengguna = $this->buatPengguna();
        $this->tugaskan($pengguna, $peran);
        $evaluator = app(AccessEvaluator::class);

        $this->assertTrue($evaluator->bolehMenu($pengguna, 'pengaturan.akun.mfa'));
        $this->assertTrue($evaluator->bolehMenu($pengguna, 'pengaturan.akun.bantuan'));

        $this->khusus($pengguna, 'cabut', menu: 'pengaturan.akun.mfa');

        $this->assertFalse($evaluator->bolehMenu($pengguna, 'pengaturan.akun.mfa'));
        $this->assertTrue($evaluator->bolehMenu($pengguna, 'pengaturan.akun'));
        $this->assertTrue($evaluator->bolehMenu($pengguna, $bantuan->kode));
    }

    public function test_pemberian_per_akun_tanpa_peran(): void
    {
        $pengguna = $this->buatPengguna();
        $this->khusus($pengguna, 'tambah', menu: 'pengaturan.peran');
        $this->khusus($pengguna, 'tambah', izin: 'pengaturan.peran.lihat');

        $this->masukLaluMenu($pengguna)->assertOk()->assertJsonPath('data.izin', ['pengaturan.peran.lihat']);
        $this->minta('GET', '/api/v1/peran')->assertOk();
    }

    public function test_penugasan_kedaluwarsa_menghilangkan_akses_tanpa_tindakan_admin(): void
    {
        $pengguna = $this->buatPengguna();
        $this->tugaskan($pengguna, $this->buatPeran(['pengaturan.peran']), sampai: now()->addMinutes(5));
        $this->assertContains('pengaturan.peran', $this->kodeMenu($this->masukLaluMenu($pengguna)));

        // Lebih singkat dari batas cache 15 menit: cache harus ikut berakhir saat penugasan berakhir.
        $this->travel(6)->minutes();

        $this->assertSame(['beranda'], $this->kodeMenu($this->masukLaluMenu($pengguna)));
        $this->minta('GET', '/api/v1/peran')->assertForbidden();
    }

    public function test_penugasan_dibatalkan_atau_belum_mulai_tidak_berlaku(): void
    {
        $pengguna = $this->buatPengguna();
        $peran = $this->buatPeran(['pengaturan.peran']);
        $this->tugaskan($pengguna, $peran, dari: now()->addDay());
        $batal = $this->tugaskan($pengguna, $peran);
        $batal->forceFill(['status' => 'batal', 'alasan_batal' => 'Uji', 'dibatalkan_pada' => now()])->save();

        $this->assertFalse(app(AccessEvaluator::class)->bolehMenu($pengguna, 'pengaturan.peran'));
    }

    public function test_akun_nonaktif_atau_terkunci_tidak_punya_hak(): void
    {
        $pengguna = $this->buatPengguna();
        $this->tugaskan($pengguna, $this->buatPeran(['pengaturan.peran']));
        $evaluator = app(AccessEvaluator::class);
        $this->assertTrue($evaluator->boleh($pengguna, 'pengaturan.peran.lihat'));

        $pengguna->forceFill(['terkunci_sampai' => now()->addMinutes(15)])->save();
        $this->assertFalse($evaluator->boleh($pengguna, 'pengaturan.peran.lihat'));

        $pengguna->forceFill(['terkunci_sampai' => null, 'status' => 'nonaktif'])->save();
        $this->assertFalse($evaluator->boleh($pengguna, 'pengaturan.peran.lihat'));
    }

    public function test_cakupan_data_lapis_tiga(): void
    {
        $unitA = (string) Str::uuid7();
        $unitB = (string) Str::uuid7();
        $pengguna = $this->buatPengguna();
        $this->tugaskan($pengguna, $this->buatPeran(['pengaturan.akun']), cakupan: new Cakupan('unit', $unitA));
        $this->actingAs($pengguna, 'web');

        $this->assertTrue(Gate::allows('pengaturan.akun.lihat'));
        $this->assertTrue(Gate::allows('pengaturan.akun.lihat', new Cakupan('unit', $unitA)));
        $this->assertFalse(Gate::allows('pengaturan.akun.lihat', new Cakupan('unit', $unitB)));
        $this->assertFalse(Gate::allows('pengaturan.akun.lihat', Cakupan::rs()));
        // Argumen yang belum dikenal (mis. model, sebelum ada pemeriksaan per objek) ditolak.
        $this->assertFalse(Gate::allows('pengaturan.akun.lihat', $pengguna));
        $this->assertEquals([new Cakupan('unit', $unitA)], app(AccessEvaluator::class)->cakupan($pengguna, 'pengaturan.akun.lihat'));

        $this->tugaskan($pengguna, $this->buatPeran(['pengaturan.akun']));
        $this->assertTrue(Gate::allows('pengaturan.akun.lihat', new Cakupan('unit', $unitB)));
    }

    public function test_mengubah_peran_menghapus_cache_menu_akun_terkait(): void
    {
        $pengguna = $this->buatPengguna();
        $peran = $this->buatPeran(['pengaturan.peran']);
        $this->tugaskan($pengguna, $peran);
        $sebelum = $this->masukLaluMenu($pengguna)->assertOk();
        $versi = $sebelum->json('data.versi');
        $this->assertSame('"'.$versi.'"', $sebelum->headers->get('ETag'));

        // ETag yang sama → 304 tanpa isi.
        $this->headerTambahan = ['If-None-Match' => '"'.$versi.'"'];
        $this->minta('GET', '/api/v1/saya/menu')->assertStatus(304)->assertContent('');
        $this->headerTambahan = [];

        $this->beriSubMenu($peran, 'pengaturan.akun');
        $sesudah = $this->minta('GET', '/api/v1/saya/menu')->assertOk();

        $this->assertNotSame($versi, $sesudah->json('data.versi'));
        $this->assertContains('pengaturan.akun', $this->kodeMenu($sesudah));

        // Peran dinonaktifkan → aksesnya hilang untuk semua pemegangnya.
        $peran->forceFill(['status' => 'nonaktif'])->save();
        $this->assertSame(['beranda'], $this->kodeMenu($this->minta('GET', '/api/v1/saya/menu')));
    }

    public function test_penugasan_baru_langsung_terlihat(): void
    {
        $pengguna = $this->buatPengguna();
        $this->assertSame(['beranda'], $this->kodeMenu($this->masukLaluMenu($pengguna)));

        $this->tugaskan($pengguna, $this->buatPeran(['pengaturan.akun']));

        $this->assertContains('pengaturan.akun', $this->kodeMenu($this->minta('GET', '/api/v1/saya/menu')));
    }

    public function test_tanpa_segera_hadir_menu_yang_belum_aktif_tidak_dikirim(): void
    {
        config(['menu.tampilkan_segera_hadir' => false]);
        $pengguna = $this->buatPengguna();
        $this->tugaskan($pengguna, $this->buatPeran(['pengaturan.peran', 'pengaturan.audit', 'igd.papan']));

        $menu = $this->masukLaluMenu($pengguna)->assertOk();

        $this->assertSame(['beranda', 'pengaturan', 'pengaturan.peran'], $this->kodeMenu($menu));
        $this->assertStringNotContainsString('"segera"', (string) $menu->getContent());
    }

    public function test_segera_hadir_bawaan_hanya_di_local(): void
    {
        $this->assertTrue($this->bawaanSegeraHadir('local'));
        $this->assertFalse($this->bawaanSegeraHadir('production'));
        $this->assertFalse($this->bawaanSegeraHadir('staging'));
    }

    public function test_respons_lulus_skema_kontrak_openapi(): void
    {
        $pengguna = $this->buatPengguna();
        $this->tugaskan($pengguna, $this->buatPeran(['pengaturan.peran', 'pengaturan.akun', 'igd.papan', 'igd.triase']));
        $respons = $this->masukLaluMenu($pengguna)->assertOk();

        $kontrak = json_decode((string) json_encode(Yaml::parseFile(base_path('../docs/api/openapi.yaml'))));
        $validator = new Validator;
        $validator->resolver()?->registerRaw($kontrak, 'https://kontrak.karsitara.invalid/openapi.json');
        $hasil = $validator->validate(json_decode((string) $respons->getContent()), 'https://kontrak.karsitara.invalid/openapi.json#/components/schemas/MenuSaya');

        $this->assertTrue($hasil->isValid(), (string) json_encode($hasil->error()?->args()).' '.$hasil->error()?->message());
        $respons->assertJsonPath('data.grup.0.kode', 'utama')->assertJsonPath('data.grup.0.label', null)
            ->assertJsonPath('data.grup.0.menu.0.jumlah_tugas', null);
    }

    public function test_peran_mewajibkan_mfa_dan_superadmin_tidak_bisa_dibebaskan(): void
    {
        $kebijakan = app(KebijakanMfa::class);
        $biasa = $this->buatPengguna();
        $this->tugaskan($biasa, $this->buatPeran(['pengaturan.akun']));
        $this->assertFalse($kebijakan->wajib($biasa, '127.0.0.1'));
        $this->assertTrue($kebijakan->wajib($biasa, '203.0.113.10'), 'Dari luar jaringan RS selalu wajib MFA.');

        $peranWajib = $this->buatPeran(['pengaturan.akun']);
        $peranWajib->forceFill(['wajib_mfa' => true])->save();
        $pemegang = $this->buatPengguna();
        $this->tugaskan($pemegang, $peranWajib);
        $this->assertTrue($kebijakan->wajib($pemegang, '127.0.0.1'));

        $peranSensitif = $this->buatPeran(['pengaturan.audit']);
        Izin::query()->where('kode', 'pengaturan.audit.lihat')->firstOrFail()->forceFill(['sensitif' => true])->save();
        $pemegangSensitif = $this->buatPengguna();
        $this->tugaskan($pemegangSensitif, $peranSensitif);
        $this->assertTrue($kebijakan->wajib($pemegangSensitif, '127.0.0.1'));

        $superadmin = Peran::query()->where('kode', MenuBawaan::PERAN_SUPERADMIN)->firstOrFail();
        $this->expectException(QueryException::class);
        $superadmin->forceFill(['wajib_mfa' => false])->save();
    }

    public function test_superadmin_hanya_mengelola_peran_dan_akun(): void
    {
        $pengguna = $this->buatPengguna();
        $this->tugaskan($pengguna, Peran::query()->where('kode', MenuBawaan::PERAN_SUPERADMIN)->firstOrFail());
        $evaluator = app(AccessEvaluator::class);

        $this->assertTrue($evaluator->boleh($pengguna, 'pengaturan.peran.perbaiki'));
        $this->assertTrue($evaluator->boleh($pengguna, 'pengaturan.akun.input'));
        $this->assertFalse($evaluator->bolehMenu($pengguna, 'rekam_medis.pencarian'));
        $this->assertFalse($evaluator->bolehMenu($pengguna, 'kasir.tagihan'));
        $this->assertTrue(app(KebijakanMfa::class)->wajib($pengguna, '127.0.0.1'));
    }

    public function test_menu_bawaan_aman_dipasang_ulang_tanpa_menimpa_nonaktif_dari_admin(): void
    {
        $jumlah = [Menu::query()->count(), Izin::query()->count(), PeranIzin::query()->count()];
        Menu::query()->where('kode', 'igd.triase')->firstOrFail()->forceFill(['aktif' => false])->save();

        MenuBawaan::pasang();

        $this->assertSame($jumlah, [Menu::query()->count(), Izin::query()->count(), PeranIzin::query()->count()]);
        $this->assertFalse(Menu::query()->where('kode', 'igd.triase')->value('aktif'));
    }

    private function buatPengguna(): Pengguna
    {
        return Pengguna::factory()->denganSandi(self::SANDI)->create();
    }

    /** @param  list<string>  $subMenu  diberikan beserta aksi dasarnya */
    private function buatPeran(array $subMenu): Peran
    {
        $peran = Peran::query()->create(['kode' => 'uji_'.Str::lower(Str::random(8)), 'nama' => 'Peran uji']);
        foreach ($subMenu as $kode) {
            $this->beriSubMenu($peran, $kode);
        }

        return $peran;
    }

    private function beriSubMenu(Peran $peran, string $kode): void
    {
        $menu = Menu::query()->where('kode', $kode)->firstOrFail();
        PeranMenu::query()->create(['peran_id' => $peran->id, 'menu_id' => $menu->id]);
        foreach (Izin::query()->where('menu_id', $menu->id)->pluck('id') as $izinId) {
            PeranIzin::query()->create(['peran_id' => $peran->id, 'izin_id' => $izinId]);
        }
    }

    private function tugaskan(Pengguna $pengguna, Peran $peran, mixed $dari = null, mixed $sampai = null, ?Cakupan $cakupan = null): PenggunaPeran
    {
        $cakupan ??= Cakupan::rs();

        return PenggunaPeran::query()->create(array_filter([
            'pengguna_id' => $pengguna->id, 'peran_id' => $peran->id,
            'cakupan_jenis' => $cakupan->jenis, 'cakupan_id' => $cakupan->id,
            'berlaku_dari' => $dari, 'berlaku_sampai' => $sampai, 'dasar_pemberian' => 'SK uji',
        ], fn ($nilai) => $nilai !== null));
    }

    private function khusus(Pengguna $pengguna, string $efek, ?string $menu = null, ?string $izin = null): void
    {
        PenggunaIzinKhusus::query()->create(array_filter([
            'pengguna_id' => $pengguna->id, 'efek' => $efek, 'dasar_pemberian' => 'Surat uji', 'alasan' => 'Uji',
            'menu_id' => $menu === null ? null : Menu::query()->where('kode', $menu)->value('id'),
            'izin_id' => $izin === null ? null : Izin::query()->where('kode', $izin)->value('id'),
        ], fn ($nilai) => $nilai !== null));
    }

    private function buatBagian(Menu $subMenu, string $kode, bool $ikutInduk = false): Menu
    {
        return Menu::query()->create([
            'induk_id' => $subMenu->id, 'tingkat' => 'bagian', 'kode' => $kode, 'label' => $kode, 'ikut_induk' => $ikutInduk,
        ]);
    }

    private function masukLaluMenu(Pengguna $pengguna): TestResponse
    {
        $this->mulaiSesiSpa();
        $this->minta('POST', '/api/v1/autentikasi/masuk', ['nama_pengguna' => $pengguna->nama_pengguna, 'sandi' => self::SANDI])
            ->assertOk()->assertJsonPath('status', 'masuk');

        return $this->minta('GET', '/api/v1/saya/menu');
    }

    /** @return list<string> kode menu dan sub-menu, urut abjad */
    private function kodeMenu(TestResponse $respons): array
    {
        $kode = [];
        foreach ($respons->json('data.grup') as $grup) {
            foreach ($grup['menu'] as $menu) {
                $kode = [...$kode, $menu['kode'], ...array_column($menu['sub_menu'], 'kode')];
            }
        }
        sort($kode);

        return $kode;
    }

    /** @return array<string, mixed> */
    private function item(TestResponse $respons, string $kode): array
    {
        foreach ($respons->json('data.grup') as $grup) {
            foreach ($grup['menu'] as $menu) {
                foreach ([$menu, ...$menu['sub_menu']] as $item) {
                    if ($item['kode'] === $kode) {
                        return $item;
                    }
                }
            }
        }

        $this->fail("Menu $kode tidak ada di respons.");
    }

    /** Nilai bawaan config/menu.php untuk APP_ENV tertentu (tanpa MENU_TAMPILKAN_SEGERA_HADIR). */
    private function bawaanSegeraHadir(string $lingkungan): bool
    {
        $semula = [$_SERVER['APP_ENV'] ?? null, $_ENV['APP_ENV'] ?? null, getenv('APP_ENV')];
        $_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = $lingkungan;
        putenv("APP_ENV=$lingkungan");

        try {
            return (require config_path('menu.php'))['tampilkan_segera_hadir'];
        } finally {
            [$server, $env, $putenv] = $semula;
            $_SERVER['APP_ENV'] = $server;
            $_ENV['APP_ENV'] = $env;
            putenv($putenv === false ? 'APP_ENV' : "APP_ENV=$putenv");
        }
    }
}
