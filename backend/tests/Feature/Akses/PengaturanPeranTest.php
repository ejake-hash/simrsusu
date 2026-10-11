<?php

namespace Tests\Feature\Akses;

use App\Modules\Akses\Database\MenuBawaan;
use App\Modules\Akses\Models\Izin;
use App\Modules\Akses\Models\Menu;
use App\Modules\Akses\Models\PenggunaIzinKhusus;
use App\Modules\Akses\Models\PenggunaPeran;
use App\Modules\Akses\Models\Peran;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\AksesUji;
use Tests\Support\KlienSpa;
use Tests\Support\KontrakApi;
use Tests\TestCase;

/** Tiket 1.2c-be: Pengaturan → Peran & izin (kontrak /menu, /peran*). */
class PengaturanPeranTest extends TestCase
{
    use AksesUji, KlienSpa, KontrakApi;

    private const DASAR = 'SK Direktur 12/2026';

    public function test_tanpa_izin_semua_endpoint_peran_dan_menu_ditolak(): void
    {
        $peran = $this->buatPeran(['igd.papan']);
        $this->masukSebagai($this->buatPengguna());

        foreach ([
            ['GET', '/menu', '/menu', []],
            ['GET', '/peran', '/peran', []],
            ['POST', '/peran', '/peran', ['kode' => 'baru', 'nama' => 'Baru', 'wajib_mfa' => false, 'dasar_perubahan' => self::DASAR]],
            ['GET', "/peran/{$peran->id}", '/peran/{id}', []],
            ['PATCH', "/peran/{$peran->id}", '/peran/{id}', ['nama' => 'Ganti', 'dasar_perubahan' => self::DASAR]],
            ['PUT', "/peran/{$peran->id}/izin", '/peran/{id}/izin', ['izin' => [], 'dasar_perubahan' => self::DASAR]],
        ] as [$metode, $uri, $path, $data]) {
            $respons = $this->minta($metode, '/api/v1'.$uri, $data)->assertForbidden()->assertJsonPath('error.kode', 'akses_ditolak');
            $this->assertSesuaiKontrak($respons, $metode, $path);
        }
    }

    public function test_pohon_menu_memuat_node_aktif_dan_izin_per_node(): void
    {
        $pengguna = $this->buatPengguna();
        // Cukup salah satu izin lihat (peran ATAU akun).
        $peran = $this->buatPeran(['pengaturan.akun']);
        DB::table('akses.peran_izin')->where('peran_id', $peran->id)
            ->whereIn('izin_id', Izin::query()->where('kode', '<>', 'pengaturan.akun.lihat')->select('id'))->update(['status' => 'batal']);
        $this->tugaskan($pengguna, $peran);
        Menu::query()->where('kode', 'igd.triase')->firstOrFail()->forceFill(['aktif' => false])->save();
        $this->masukSebagai($pengguna);

        $respons = $this->assertSesuaiKontrak($this->minta('GET', '/api/v1/menu')->assertOk(), 'GET', '/menu');

        $node = $this->cariNode($respons->json('data.grup'), 'pengaturan.peran');
        $this->assertSame('sub_menu', $node['tingkat']);
        $this->assertTrue($node['tersedia']);
        $this->assertTrue($node['modul_aktif']);
        $this->assertSame(['pengaturan.peran.input', 'pengaturan.peran.lihat', 'pengaturan.peran.perbaiki'], array_column($node['izin'], 'kode'));
        // Modul yang belum tersedia tetap dikirim agar peran bisa disiapkan; node nonaktif tidak.
        $this->assertFalse($this->cariNode($respons->json('data.grup'), 'igd.papan')['tersedia']);
        $this->assertNull($this->cariNode($respons->json('data.grup'), 'igd.triase'));
        $this->assertNull($this->cariNode($respons->json('data.grup'), 'master_data.peran'));
    }

    public function test_daftar_peran_dengan_ringkasan_filter_dan_paginasi(): void
    {
        $pemegang = $this->buatPengguna();
        $peran = $this->buatPeran(['igd.papan'], ['nama' => 'Perawat IGD Malam']);
        $this->tugaskan($pemegang, $peran);
        PenggunaPeran::query()->create([
            'pengguna_id' => $this->buatPengguna()->id, 'peran_id' => $peran->id, 'dasar_pemberian' => 'SK lama',
            'berlaku_dari' => now()->subDays(2), 'berlaku_sampai' => now()->subDay(),
        ]);
        $sensitif = $this->buatPeran(['kasir.tagihan'], ['nama' => 'Kasir Sensitif', 'status' => 'nonaktif']);
        Izin::query()->where('kode', 'kasir.tagihan.perbaiki')->update(['sensitif' => true]);
        $this->masukSebagai($this->pengelola());

        $respons = $this->assertSesuaiKontrak($this->minta('GET', '/api/v1/peran?cari=igd%20malam')->assertOk(), 'GET', '/peran');
        $this->assertSame([$peran->id], $respons->json('data.*.id'));
        $this->assertSame(1, $respons->json('data.0.jumlah_akun'), 'Hanya penugasan yang berlaku dihitung.');
        $this->assertFalse($respons->json('data.0.memuat_izin_sensitif'));

        $nonaktif = $this->minta('GET', '/api/v1/peran?status=nonaktif')->assertOk();
        $this->assertSame([$sensitif->id], $nonaktif->json('data.*.id'));
        $this->assertTrue($nonaktif->json('data.0.memuat_izin_sensitif'));

        // Wildcard LIKE di kata cari dibaca sebagai huruf biasa.
        $this->assertSame([], $this->minta('GET', '/api/v1/peran?cari=%25')->assertOk()->json('data'));

        $halaman1 = $this->minta('GET', '/api/v1/peran?batas=2')->assertOk();
        $this->assertCount(2, $halaman1->json('data'));
        $kursor = $halaman1->json('meta.kursor_berikutnya');
        $halaman2 = $this->minta('GET', '/api/v1/peran?batas=2&kursor='.$kursor)->assertOk();
        $this->assertEmpty(array_intersect($halaman1->json('data.*.id'), $halaman2->json('data.*.id')));
        $this->assertSesuaiKontrak($this->minta('GET', '/api/v1/peran?batas=201')->assertStatus(422), 'GET', '/peran');
    }

    public function test_buat_peran_kosong_dan_salinan_tanpa_izin_sensitif(): void
    {
        $sumber = $this->buatPeran(['kasir.tagihan', 'igd.papan']);
        Izin::query()->where('kode', 'kasir.tagihan.perbaiki')->update(['sensitif' => true]);
        $pengelola = $this->pengelola();
        $this->masukSebagai($pengelola);

        $kosong = $this->minta('POST', '/api/v1/peran', [
            'kode' => 'admisi_malam', 'nama' => 'Admisi malam', 'wajib_mfa' => false, 'dasar_perubahan' => self::DASAR,
        ])->assertCreated();
        $this->assertSesuaiKontrak($kosong, 'POST', '/peran');
        $this->assertSame([], $kosong->json('data.izin'));
        $this->assertSame('"'.$kosong->json('data.versi').'"', $kosong->headers->get('ETag'));
        $this->assertTrue($kosong->json('data.dapat_disunting'));

        $salinan = $this->minta('POST', '/api/v1/peran', [
            'kode' => 'kasir_salinan', 'nama' => 'Kasir salinan', 'wajib_mfa' => true, 'salin_dari_id' => $sumber->id, 'dasar_perubahan' => self::DASAR,
        ])->assertCreated();
        $this->assertContains('kasir.tagihan.lihat', $salinan->json('data.izin'));
        $this->assertContains('igd.papan.input', $salinan->json('data.izin'));
        $this->assertNotContains('kasir.tagihan.perbaiki', $salinan->json('data.izin'));
        $this->assertFalse($salinan->json('data.memuat_izin_sensitif'));
        // peran_menu diturunkan dari izin lihat.
        $this->assertEqualsCanonicalizing(['kasir.tagihan', 'igd.papan'], Menu::query()
            ->whereIn('id', DB::table('akses.peran_menu')->where('peran_id', $salinan->json('data.id'))->select('menu_id'))->pluck('kode')->all());

        $audit = $this->assertAuditTerakhir('akses.peran_dibuat', $salinan->json('data.id'));
        $this->assertSame($pengelola->id, $audit->pengguna_id);
        $this->assertSame(self::DASAR, json_decode($audit->sesudah, true)['dasar_perubahan']);

        $ganda = $this->minta('POST', '/api/v1/peran', [
            'kode' => 'admisi_malam', 'nama' => 'Lagi', 'wajib_mfa' => false, 'dasar_perubahan' => self::DASAR,
        ])->assertStatus(409)->assertJsonPath('error.kode', 'kode_sudah_dipakai');
        $this->assertSesuaiKontrak($ganda, 'POST', '/peran');
        $salah = $this->minta('POST', '/api/v1/peran', ['kode' => 'Huruf Besar', 'nama' => 'x', 'wajib_mfa' => 'ya'])->assertStatus(422);
        $this->assertSesuaiKontrak($salah, 'POST', '/peran');
        $this->assertEqualsCanonicalizing(['kode', 'nama', 'wajib_mfa', 'dasar_perubahan'], array_column($salah->json('error.rincian'), 'kolom'));
    }

    public function test_rincian_peran_menyebut_alasan_terkunci(): void
    {
        $pengelola = $this->pengelola();
        $milikSendiri = PenggunaPeran::query()->where('pengguna_id', $pengelola->id)->value('peran_id');
        $this->masukSebagai($pengelola);

        $superadmin = $this->minta('GET', "/api/v1/peran/{$this->superadmin()->id}")->assertOk();
        $this->assertSesuaiKontrak($superadmin, 'GET', '/peran/{id}');
        $superadmin->assertJsonPath('data.alasan_terkunci', 'peran_bawaan_terkunci')->assertJsonPath('data.dapat_disunting', false)
            ->assertJsonPath('data.wajib_mfa_terkunci', true);
        $this->assertContains('pengaturan.akun.perbaiki', $superadmin->json('data.izin'));

        $this->minta('GET', "/api/v1/peran/$milikSendiri")->assertOk()
            ->assertJsonPath('data.alasan_terkunci', 'peran_sendiri')->assertJsonPath('data.dapat_disunting', false);

        $this->assertSesuaiKontrak($this->minta('GET', '/api/v1/peran/'.Str::uuid7())->assertNotFound(), 'GET', '/peran/{id}');
        $this->minta('GET', '/api/v1/peran/bukan-uuid')->assertNotFound()->assertJsonPath('error.kode', 'tidak_ditemukan');
    }

    public function test_simpan_matriks_mengganti_izin_dan_akun_terdampak_langsung_melihat_menu_baru(): void
    {
        $peran = $this->buatPeran(['igd.papan']);
        $pemegang = $this->buatPengguna();
        $this->tugaskan($pemegang, $peran);
        $this->masukSebagai($pemegang);
        $perambanPemegang = $this->peramban();
        $menuLama = $this->minta('GET', '/api/v1/saya/menu')->assertOk();
        $this->assertContains('igd.papan.input', $menuLama->json('data.izin'));

        $pengelola = $this->pengelola();
        $this->masukSebagai($pengelola);
        $versi = $this->minta('GET', "/api/v1/peran/{$peran->id}")->assertOk()->json('data.versi');
        $baru = ['igd.papan.lihat', 'rawat_jalan.worklist.lihat', 'rawat_jalan.worklist.input'];
        $respons = $this->mintaDenganVersi('PUT', "/api/v1/peran/{$peran->id}/izin", $versi, [
            'izin' => $baru, 'dasar_perubahan' => self::DASAR, 'alasan' => 'Rotasi poli',
        ])->assertOk();

        $this->assertSesuaiKontrak($respons, 'PUT', '/peran/{id}/izin');
        $respons->assertJsonPath('perubahan.ditambah', ['rawat_jalan.worklist.input', 'rawat_jalan.worklist.lihat'])
            ->assertJsonPath('perubahan.dicabut', ['igd.papan.input', 'igd.papan.perbaiki'])
            ->assertJsonPath('perubahan.akun_terdampak', 1);
        $this->assertNotSame($versi, $respons->json('data.versi'));
        $this->assertSame('"'.$respons->json('data.versi').'"', $respons->headers->get('ETag'));
        $this->assertEqualsCanonicalizing($baru, $respons->json('data.izin'));

        // Dicabut = status batal dengan alasan, bukan dihapus.
        $batal = DB::table('akses.peran_izin')->where('peran_id', $peran->id)->where('status', 'batal')->get();
        $this->assertCount(2, $batal);
        $this->assertSame(self::DASAR, $batal[0]->alasan_batal);
        $this->assertSame($pengelola->id, $batal[0]->dibatalkan_oleh);
        $audit = $this->assertAuditTerakhir('akses.izin_diganti', $peran->id);
        $this->assertSame(['igd.papan.input', 'igd.papan.lihat', 'igd.papan.perbaiki'], json_decode($audit->sebelum, true)['izin']);

        $this->pakaiPeramban($perambanPemegang);
        $menuBaru = $this->minta('GET', '/api/v1/saya/menu')->assertOk();
        $this->assertNotContains('igd.papan.input', $menuBaru->json('data.izin'));
        $this->assertContains('rawat_jalan.worklist.input', $menuBaru->json('data.izin'));

        // Diberikan lagi = baris lama aktif kembali, tidak ada baris ganda.
        $this->masukSebagai($pengelola);
        $this->mintaDenganVersi('PUT', "/api/v1/peran/{$peran->id}/izin", $respons->json('data.versi'), [
            'izin' => ['igd.papan.lihat', 'igd.papan.input'], 'dasar_perubahan' => self::DASAR,
        ])->assertOk()->assertJsonPath('perubahan.ditambah', ['igd.papan.input']);
        $this->assertSame(3, DB::table('akses.peran_izin')->where('peran_id', $peran->id)->whereIn('izin_id',
            Izin::query()->where('kode', 'like', 'igd.papan.%')->select('id'))->count());
    }

    public function test_simpan_matriks_menolak_isian_tidak_sah_tanpa_menyimpan_apa_pun(): void
    {
        $peran = $this->buatPeran(['igd.papan']);
        $subMenu = Menu::query()->where('kode', 'igd.papan')->firstOrFail();
        $bagian = Menu::query()->create(['induk_id' => $subMenu->id, 'tingkat' => 'bagian', 'kode' => 'igd.papan.catatan', 'label' => 'Catatan']);
        Izin::query()->create(['kode' => 'igd.papan.catatan.lihat', 'menu_id' => $bagian->id, 'jenis_aksi' => 'lihat']);
        $this->masukSebagai($this->pengelola());
        $versi = (string) $peran->refresh()->revisi;
        $sebelum = DB::table('akses.peran_izin')->where('peran_id', $peran->id)->orderBy('id')->get();

        foreach ([
            [['igd.papan.lihat', 'tidak.ada.lihat'], 'izin_tidak_dikenal', 'tidak.ada.lihat'],
            [['igd.triase.input'], 'lihat_wajib', 'igd.triase.input'],
            [['rawat_jalan.worklist.lihat', 'igd.papan.catatan.lihat'], 'induk_belum_diberikan', 'igd.papan.catatan.lihat'],
        ] as [$izin, $kode, $salah]) {
            $respons = $this->mintaDenganVersi('PUT', "/api/v1/peran/{$peran->id}/izin", $versi, ['izin' => $izin, 'dasar_perubahan' => self::DASAR])
                ->assertStatus(422)->assertJsonPath('error.kode', $kode)->assertJsonPath('error.rincian.0.kode', $salah);
            $this->assertSesuaiKontrak($respons, 'PUT', '/peran/{id}/izin');
        }

        $this->assertEquals($sebelum, DB::table('akses.peran_izin')->where('peran_id', $peran->id)->orderBy('id')->get());
        $this->assertSame((int) $versi, $peran->refresh()->revisi);
        // Bagian dengan induk yang diberi lihat diterima.
        $this->mintaDenganVersi('PUT', "/api/v1/peran/{$peran->id}/izin", $versi, [
            'izin' => ['igd.papan.lihat', 'igd.triase.lihat', 'igd.papan.catatan.lihat'], 'dasar_perubahan' => self::DASAR,
        ])->assertOk();
    }

    public function test_dua_penyunting_dengan_versi_sama_yang_kedua_412_dan_tanpa_if_match_428(): void
    {
        $peran = $this->buatPeran(['igd.papan']);
        $this->masukSebagai($this->pengelola());
        $versi = $this->minta('GET', "/api/v1/peran/{$peran->id}")->json('data.versi');

        $tanpaVersi = $this->minta('PUT', "/api/v1/peran/{$peran->id}/izin", ['izin' => [], 'dasar_perubahan' => self::DASAR])
            ->assertStatus(428)->assertJsonPath('error.kode', 'if_match_wajib');
        $this->assertSesuaiKontrak($tanpaVersi, 'PUT', '/peran/{id}/izin');

        $this->mintaDenganVersi('PATCH', "/api/v1/peran/{$peran->id}", $versi, ['nama' => 'Penyunting pertama', 'dasar_perubahan' => self::DASAR])->assertOk();
        $kedua = $this->mintaDenganVersi('PUT', "/api/v1/peran/{$peran->id}/izin", $versi, ['izin' => [], 'dasar_perubahan' => self::DASAR])
            ->assertStatus(412)->assertJsonPath('error.kode', 'versi_berubah');
        $this->assertSesuaiKontrak($kedua, 'PUT', '/peran/{id}/izin');
        $this->assertSesuaiKontrak($this->mintaDenganVersi('PATCH', "/api/v1/peran/{$peran->id}", $versi, ['nama' => 'Kedua', 'dasar_perubahan' => self::DASAR])
            ->assertStatus(412), 'PATCH', '/peran/{id}');
        $this->assertSame('Penyunting pertama', $peran->refresh()->nama);
        $this->assertSame(3, DB::table('akses.peran_izin')->where('peran_id', $peran->id)->where('status', 'aktif')->count());
    }

    public function test_izin_sensitif_baru_tanpa_penyetuju_ditolak_utuh_tetapi_yang_sudah_ada_tetap(): void
    {
        $peran = $this->buatPeran(['kasir.tagihan']);
        Izin::query()->whereIn('kode', ['kasir.tagihan.perbaiki', 'kasir.deposit.perbaiki'])->update(['sensitif' => true]);
        $this->masukSebagai($this->pengelola());
        $versi = (string) $peran->refresh()->revisi;

        $ditolak = $this->mintaDenganVersi('PUT', "/api/v1/peran/{$peran->id}/izin", $versi, [
            'izin' => ['kasir.tagihan.lihat', 'kasir.tagihan.perbaiki', 'kasir.deposit.lihat', 'kasir.deposit.perbaiki'], 'dasar_perubahan' => self::DASAR,
        ])->assertStatus(422)->assertJsonPath('error.kode', 'penyetuju_belum_ditetapkan')->assertJsonPath('error.rincian.0.kode', 'kasir.deposit.perbaiki');
        $this->assertSesuaiKontrak($ditolak, 'PUT', '/peran/{id}/izin');
        $this->assertSame(3, DB::table('akses.peran_izin')->where('peran_id', $peran->id)->where('status', 'aktif')->count());

        // Izin sensitif yang sudah ada boleh tetap dipertahankan atau dicabut.
        $this->mintaDenganVersi('PUT', "/api/v1/peran/{$peran->id}/izin", $versi, [
            'izin' => ['kasir.tagihan.lihat', 'kasir.tagihan.perbaiki'], 'dasar_perubahan' => self::DASAR,
        ])->assertOk()->assertJsonPath('perubahan.dicabut', ['kasir.tagihan.input'])->assertJsonPath('data.wajib_mfa_terkunci', true);
    }

    public function test_izin_pengelola_akses_sensitif_hanya_lewat_persetujuan_superadmin(): void
    {
        $peran = $this->buatPeran(['igd.papan']);
        $akun = $this->buatPengguna();
        $this->masukSebagai($this->pengelola());

        // Lewat matriks peran: izin lain langsung berlaku, izin pengelola menunggu superadmin (K13).
        $matriks = $this->mintaDenganVersi('PUT', "/api/v1/peran/{$peran->id}/izin", (string) $peran->refresh()->revisi, [
            'izin' => ['igd.papan.lihat', 'pengaturan.akun.lihat', 'pengaturan.akun.perbaiki'], 'dasar_perubahan' => self::DASAR,
        ])->assertOk()->assertJsonPath('data.izin', ['igd.papan.lihat', 'pengaturan.akun.lihat'])
            ->assertJsonPath('data.izin_menunggu.0.kode', 'pengaturan.akun.perbaiki')
            ->assertJsonPath('perubahan.menunggu_persetujuan.0.penyetuju_peran.kode', MenuBawaan::PERAN_SUPERADMIN);
        $this->assertSesuaiKontrak($matriks, 'PUT', '/peran/{id}/izin');
        // Lewat salinan peran superadmin: hanya izin lihat yang ikut.
        $salinan = $this->minta('POST', '/api/v1/peran', [
            'kode' => 'salin_superadmin', 'nama' => 'Salinan superadmin', 'wajib_mfa' => false, 'salin_dari_id' => $this->superadmin()->id, 'dasar_perubahan' => self::DASAR,
        ])->assertCreated();
        // Persetujuan akses (1.2d) dan profil RS (1.8a) tidak sensitif, jadi ikut tersalin.
        $this->assertSame(['pengaturan.akun.lihat', 'pengaturan.peran.lihat', 'pengaturan.persetujuan.input', 'pengaturan.persetujuan.lihat', 'pengaturan.persetujuan.perbaiki',
            'pengaturan.profil_rs.input', 'pengaturan.profil_rs.lihat', 'pengaturan.profil_rs.perbaiki'],
            $salinan->json('data.izin'));
        // Lewat pengecualian per akun, dan penugasan peran yang memuatnya: menunggu superadmin, belum berlaku.
        $this->minta('POST', "/api/v1/akun/{$akun->id}/izin-khusus", [
            'efek' => 'tambah', 'sasaran_jenis' => 'izin', 'sasaran_kode' => 'pengaturan.peran.input', 'berlaku_sampai' => now()->addDay()->toIso8601String(),
            'dasar_pemberian' => self::DASAR, 'alasan' => 'Bantu admin',
        ])->assertStatus(202)->assertJsonPath('data.jenis', 'pengecualian')->assertJsonPath('data.penyetuju_peran.kode', MenuBawaan::PERAN_SUPERADMIN);
        $pengelolaLain = Peran::query()->whereIn('id', DB::table('akses.peran_izin')->whereIn('izin_id',
            Izin::query()->where('kode', 'pengaturan.peran.perbaiki')->select('id'))->select('peran_id'))->where('bawaan_sistem', false)->firstOrFail();
        $this->minta('POST', "/api/v1/akun/{$akun->id}/penugasan", ['peran_id' => $pengelolaLain->id, 'cakupan_jenis' => 'rs', 'dasar_pemberian' => self::DASAR])
            ->assertStatus(202)->assertJsonPath('data.objek.jenis', 'penugasan')->assertJsonPath('data.penyetuju_peran.kode', MenuBawaan::PERAN_SUPERADMIN);
        $this->assertSame(0, PenggunaIzinKhusus::query()->where('pengguna_id', $akun->id)->count() + PenggunaPeran::query()->where('pengguna_id', $akun->id)->count());

        // Pemegangnya wajib MFA dan sakelarnya terkunci.
        $this->minta('GET', "/api/v1/peran/{$pengelolaLain->id}")->assertJsonPath('data.wajib_mfa_terkunci', true)->assertJsonPath('data.memuat_izin_sensitif', true);
    }

    public function test_versi_di_luar_rentang_integer_412_bukan_500(): void
    {
        $peran = $this->buatPeran(['igd.papan']);
        $this->masukSebagai($this->pengelola());

        $this->mintaDenganVersi('PATCH', "/api/v1/peran/{$peran->id}", '99999999999999999999', ['nama' => 'Baru', 'dasar_perubahan' => self::DASAR])
            ->assertStatus(412)->assertJsonPath('error.kode', 'versi_berubah');
    }

    public function test_superadmin_terkunci_dan_mfa_tidak_bisa_dimatikan(): void
    {
        $superadmin = $this->superadmin();
        $this->masukSebagai($this->pengelola());
        $versi = (string) $superadmin->revisi;

        $this->assertSesuaiKontrak($this->mintaDenganVersi('PUT', "/api/v1/peran/{$superadmin->id}/izin", $versi, ['izin' => [], 'dasar_perubahan' => self::DASAR])
            ->assertStatus(409)->assertJsonPath('error.kode', 'peran_bawaan_terkunci'), 'PUT', '/peran/{id}/izin');
        $this->assertSesuaiKontrak($this->mintaDenganVersi('PATCH', "/api/v1/peran/{$superadmin->id}", $versi, ['wajib_mfa' => false, 'dasar_perubahan' => self::DASAR])
            ->assertStatus(422)->assertJsonPath('error.kode', 'mfa_tidak_bisa_dimatikan'), 'PATCH', '/peran/{id}');
        $this->mintaDenganVersi('PATCH', "/api/v1/peran/{$superadmin->id}", $versi, ['status' => 'nonaktif', 'dasar_perubahan' => self::DASAR])
            ->assertStatus(409)->assertJsonPath('error.kode', 'peran_bawaan_terkunci');

        $sensitif = $this->buatPeran(['kasir.tagihan'], ['wajib_mfa' => true]);
        Izin::query()->where('kode', 'kasir.tagihan.perbaiki')->update(['sensitif' => true]);
        $this->mintaDenganVersi('PATCH', "/api/v1/peran/{$sensitif->id}", (string) $sensitif->refresh()->revisi, ['wajib_mfa' => false, 'dasar_perubahan' => self::DASAR])
            ->assertStatus(422)->assertJsonPath('error.kode', 'mfa_tidak_bisa_dimatikan');
        $this->assertTrue($superadmin->refresh()->wajib_mfa && $sensitif->refresh()->wajib_mfa);
    }

    public function test_penyunting_tidak_bisa_mengubah_peran_yang_ia_pegang(): void
    {
        $pengelola = $this->pengelola();
        $milikSendiri = Peran::query()->whereIn('id', DB::table('akses.pengguna_peran')->where('pengguna_id', $pengelola->id)->select('peran_id'))->firstOrFail();
        $this->masukSebagai($pengelola);
        $versi = (string) $milikSendiri->revisi;

        $this->assertSesuaiKontrak($this->mintaDenganVersi('PUT', "/api/v1/peran/{$milikSendiri->id}/izin", $versi, [
            'izin' => ['pengaturan.peran.lihat', 'pengaturan.peran.input', 'pengaturan.peran.perbaiki', 'kasir.tagihan.lihat'], 'dasar_perubahan' => self::DASAR,
        ])->assertForbidden()->assertJsonPath('error.kode', 'peran_sendiri'), 'PUT', '/peran/{id}/izin');
        $this->mintaDenganVersi('PATCH', "/api/v1/peran/{$milikSendiri->id}", $versi, ['nama' => 'Naikkan', 'dasar_perubahan' => self::DASAR])
            ->assertForbidden()->assertJsonPath('error.kode', 'peran_sendiri');
    }

    public function test_ubah_peran_dan_nonaktifkan_yang_masih_dipakai_ditolak(): void
    {
        $peran = $this->buatPeran(['igd.papan']);
        $penugasan = $this->tugaskan($this->buatPengguna(), $peran);
        $this->masukSebagai($this->pengelola());

        $ubah = $this->mintaDenganVersi('PATCH', "/api/v1/peran/{$peran->id}", (string) $peran->refresh()->revisi, [
            'nama' => 'Perawat IGD', 'uraian' => null, 'wajib_mfa' => true, 'dasar_perubahan' => self::DASAR,
        ])->assertOk();
        $this->assertSesuaiKontrak($ubah, 'PATCH', '/peran/{id}');
        $ubah->assertJsonPath('data.nama', 'Perawat IGD')->assertJsonPath('data.wajib_mfa', true);
        $audit = $this->assertAuditTerakhir('akses.peran_diubah', $peran->id);
        $this->assertFalse(json_decode($audit->sebelum, true)['wajib_mfa']);

        $this->assertSesuaiKontrak($this->mintaDenganVersi('PATCH', "/api/v1/peran/{$peran->id}", $ubah->json('data.versi'), ['dasar_perubahan' => self::DASAR])
            ->assertStatus(422), 'PATCH', '/peran/{id}');
        $dipakai = $this->mintaDenganVersi('PATCH', "/api/v1/peran/{$peran->id}", $ubah->json('data.versi'), ['status' => 'nonaktif', 'dasar_perubahan' => self::DASAR])
            ->assertStatus(409)->assertJsonPath('error.kode', 'peran_masih_dipakai')->assertJsonPath('error.rincian.0.jumlah_akun', 1);
        $this->assertSesuaiKontrak($dipakai, 'PATCH', '/peran/{id}');

        $penugasan->forceFill(['status' => 'batal', 'alasan_batal' => 'Uji', 'dibatalkan_pada' => now()])->save();
        $this->mintaDenganVersi('PATCH', "/api/v1/peran/{$peran->id}", $ubah->json('data.versi'), ['status' => 'nonaktif', 'dasar_perubahan' => self::DASAR])
            ->assertOk()->assertJsonPath('data.status', 'nonaktif');
    }

    /**
     * @param  list<array<string, mixed>>  $grup
     * @return array<string, mixed>|null
     */
    private function cariNode(array $grup, string $kode): ?array
    {
        $cari = function (array $daftar) use (&$cari, $kode): ?array {
            foreach ($daftar as $node) {
                if ($node['kode'] === $kode) {
                    return $node;
                }
                if (($hasil = $cari($node['anak'] ?? [])) !== null) {
                    return $hasil;
                }
            }

            return null;
        };

        foreach ($grup as $g) {
            if (($hasil = $cari($g['menu'])) !== null) {
                return $hasil;
            }
        }

        return null;
    }
}
