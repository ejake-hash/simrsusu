<?php

namespace Tests\Feature\Akses;

use App\Modules\Akses\Domain\Cakupan;
use App\Modules\Akses\Domain\Totp;
use App\Modules\Akses\Models\Izin;
use App\Modules\Akses\Models\Menu;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\Akses\Models\PenggunaIzinKhusus;
use App\Modules\Akses\Models\PenggunaPeran;
use App\Modules\MasterData\Models\UnitOrganisasi;
use App\Modules\Sdm\Models\Pegawai;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Support\AksesUji;
use Tests\Support\KlienSpa;
use Tests\Support\KontrakApi;
use Tests\TestCase;

/** Tiket 1.2c-be: Pengaturan → Akun & akses (kontrak /akun*). */
class PengaturanAkunTest extends TestCase
{
    use AksesUji, KlienSpa, KontrakApi;

    private const DASAR = 'Surat tugas 7/2026';

    public function test_tanpa_izin_semua_endpoint_akun_ditolak(): void
    {
        $akun = $this->buatPengguna();
        $id = $akun->id;
        $acak = (string) Str::uuid7();
        $this->masukSebagai($this->buatPengguna());

        foreach ([
            ['GET', '/akun', '/akun', []],
            ['GET', "/akun/$id", '/akun/{id}', []],
            ['GET', "/akun/$id/menu", '/akun/{id}/menu', []],
            ['POST', "/akun/$id/penugasan", '/akun/{id}/penugasan', []],
            ['POST', "/akun/$id/penugasan/$acak/batal", '/akun/{id}/penugasan/{penugasanId}/batal', []],
            ['POST', "/akun/$id/izin-khusus", '/akun/{id}/izin-khusus', []],
            ['POST', "/akun/$id/izin-khusus/$acak/batal", '/akun/{id}/izin-khusus/{izinKhususId}/batal', []],
            ['PUT', "/akun/$id/wajib-mfa", '/akun/{id}/wajib-mfa', []],
            ['POST', "/akun/$id/mfa/reset", '/akun/{id}/mfa/reset', []],
        ] as [$metode, $uri, $path, $data]) {
            $this->assertSesuaiKontrak($this->minta($metode, '/api/v1'.$uri, $data)->assertForbidden(), $metode, $path);
        }
    }

    public function test_daftar_akun_tanpa_data_pribadi_dengan_filter_dan_paginasi(): void
    {
        $peran = $this->buatPeran(['igd.papan'], ['nama' => 'Perawat IGD']);
        $siti = $this->buatPengguna(['nama_pengguna' => 'siti.kasir', 'pegawai_id' => Pegawai::query()->create(['nip' => 'UJI-SITI', 'nama' => 'Siti Aminah 100%'])->id]);
        $this->tugaskan($siti, $peran, sampai: now()->addMonth());
        PenggunaIzinKhusus::query()->create([
            'pengguna_id' => $siti->id, 'efek' => 'cabut', 'menu_id' => Menu::query()->where('kode', 'igd.papan')->value('id'),
            'dasar_pemberian' => self::DASAR, 'alasan' => 'Uji',
        ]);
        $terkunci = $this->buatPengguna();
        $terkunci->forceFill(['terkunci_sampai' => now()->addMinutes(10)])->save();
        $mfa = $this->buatPengguna();
        $mfa->forceFill(['mfa_rahasia_enkripsi' => Totp::rahasiaBaru(), 'mfa_aktif_pada' => now()])->save();
        $this->buatPengguna(['status' => 'nonaktif']);
        $pengelola = $this->pengelola();
        $this->masukSebagai($pengelola);

        $semua = $this->assertSesuaiKontrak($this->minta('GET', '/api/v1/akun')->assertOk(), 'GET', '/akun');
        $this->assertStringNotContainsString('"email"', (string) $semua->getContent());
        $this->assertStringNotContainsString('nip', (string) $semua->getContent());
        $this->assertStringNotContainsString('mfa_rahasia', (string) $semua->getContent());

        $cari = $this->minta('GET', '/api/v1/akun?cari=aminah%20100%25')->assertOk();
        $this->assertSame([$siti->id], $cari->json('data.*.id'));
        $cari->assertJsonPath('data.0.nama', 'Siti Aminah 100%')
            ->assertJsonPath('data.0.peran.0.nama', 'Perawat IGD')
            ->assertJsonPath('data.0.pengecualian', ['tambah' => 0, 'cabut' => 1]);
        $this->assertSame([$siti->id], $this->minta('GET', '/api/v1/akun?cari=SITI.KAS')->json('data.*.id'));
        $this->assertSame([$siti->id], $this->minta('GET', "/api/v1/akun?peran_id={$peran->id}")->json('data.*.id'));
        $this->assertSame([$terkunci->id], $this->minta('GET', '/api/v1/akun?status=terkunci')->json('data.*.id'));
        $this->assertEqualsCanonicalizing([$mfa->id, $pengelola->id], $this->minta('GET', '/api/v1/akun?mfa=aktif')->json('data.*.id'));
        $this->assertNotContains($terkunci->id, $this->minta('GET', '/api/v1/akun?status=aktif')->json('data.*.id'));

        $halaman1 = $this->minta('GET', '/api/v1/akun?batas=2')->assertOk();
        $halaman2 = $this->minta('GET', '/api/v1/akun?batas=2&kursor='.$halaman1->json('meta.kursor_berikutnya'))->assertOk();
        $this->assertEmpty(array_intersect($halaman1->json('data.*.id'), $halaman2->json('data.*.id')));
        $this->assertSesuaiKontrak($this->minta('GET', '/api/v1/akun?status=hilang')->assertStatus(422), 'GET', '/akun');
    }

    public function test_rincian_akun_dan_akun_sendiri(): void
    {
        $pengelola = $this->pengelola();
        $akun = $this->buatPengguna();
        $lama = $this->tugaskan($akun, $this->buatPeran(['igd.papan']));
        $lama->forceFill(['status' => 'batal', 'alasan_batal' => 'Pindah unit', 'dibatalkan_oleh' => $pengelola->id, 'dibatalkan_pada' => now()])->save();
        $berlaku = $this->tugaskan($akun, $this->buatPeran(['rawat_jalan.worklist']));
        PenggunaIzinKhusus::query()->create([
            'pengguna_id' => $akun->id, 'efek' => 'tambah', 'izin_id' => Izin::query()->where('kode', 'kasir.tagihan.lihat')->value('id'),
            'dasar_pemberian' => self::DASAR, 'alasan' => 'Bantu kasir', 'diberikan_oleh' => $pengelola->id,
        ]);
        $this->masukSebagai($pengelola);

        $rinci = $this->assertSesuaiKontrak($this->minta('GET', "/api/v1/akun/{$akun->id}")->assertOk(), 'GET', '/akun/{id}');
        $rinci->assertJsonPath('data.diri_sendiri', false)->assertJsonPath('data.dapat_diubah', true)->assertJsonPath('data.alasan_terkunci', null)
            ->assertJsonPath('data.penugasan.0.id', $berlaku->id)
            ->assertJsonPath('data.penugasan.0.berlaku_sekarang', true)
            ->assertJsonPath('data.penugasan.1.status', 'batal')
            ->assertJsonPath('data.penugasan.1.dibatalkan_oleh.id', $pengelola->id)
            ->assertJsonPath('data.izin_khusus.0.sasaran_label', 'Kasir → Tagihan pasien → Lihat')
            ->assertJsonPath('data.izin_khusus.0.diberikan_oleh.id', $pengelola->id)
            ->assertJsonPath('data.mfa.aktif', false);

        $this->minta('GET', "/api/v1/akun/{$pengelola->id}")->assertOk()->assertJsonPath('data.diri_sendiri', true)
            ->assertJsonPath('data.dapat_diubah', false)->assertJsonPath('data.alasan_terkunci', 'diri_sendiri')
            ->assertJsonPath('data.mfa.dapat_direset', false);
    }

    public function test_lihat_sebagai_memakai_evaluator_yang_sama_dan_tercatat(): void
    {
        $akun = $this->buatPengguna();
        $this->tugaskan($akun, $this->buatPeran(['igd.papan']));
        $pengelola = $this->pengelola();
        $this->masukSebagai($pengelola);

        $menu = $this->assertSesuaiKontrak($this->minta('GET', "/api/v1/akun/{$akun->id}/menu")->assertOk(), 'GET', '/akun/{id}/menu');

        $this->assertContains('igd.papan.lihat', $menu->json('data.izin'));
        $this->assertNotContains('pengaturan.akun.lihat', $menu->json('data.izin'));
        $this->assertSame($pengelola->id, $this->assertAuditTerakhir('akses.lihat_sebagai', $akun->id)->pengguna_id);
    }

    public function test_tugaskan_peran_lalu_akhiri_dan_akses_akun_ikut_berubah(): void
    {
        $akun = $this->buatPengguna();
        $peran = $this->buatPeran(['igd.papan']);
        $this->masukSebagai($akun);
        $perambanAkun = $this->peramban();
        $this->assertSame([], $this->minta('GET', '/api/v1/saya/menu')->json('data.izin'));

        $pengelola = $this->pengelola();
        $this->masukSebagai($pengelola);
        $tugas = $this->minta('POST', "/api/v1/akun/{$akun->id}/penugasan", [
            'peran_id' => $peran->id, 'cakupan_jenis' => 'rs', 'berlaku_sampai' => now()->addMonths(6)->toIso8601String(),
            'dasar_pemberian' => self::DASAR, 'alasan' => 'Rotasi IGD',
        ])->assertCreated();
        $this->assertSesuaiKontrak($tugas, 'POST', '/akun/{id}/penugasan');
        $tugas->assertJsonPath('data.diberikan_oleh.id', $pengelola->id)->assertJsonPath('data.berlaku_sekarang', true);
        $this->assertAuditTerakhir('akses.penugasan_dibuat', $akun->id);

        $this->pakaiPeramban($perambanAkun);
        $this->assertContains('igd.papan.lihat', $this->minta('GET', '/api/v1/saya/menu')->json('data.izin'));

        $this->masukSebagai($pengelola);
        $ganda = $this->minta('POST', "/api/v1/akun/{$akun->id}/penugasan", [
            'peran_id' => $peran->id, 'cakupan_jenis' => 'rs', 'dasar_pemberian' => self::DASAR,
        ])->assertStatus(409)->assertJsonPath('error.kode', 'penugasan_ganda');
        $this->assertSesuaiKontrak($ganda, 'POST', '/akun/{id}/penugasan');

        $uri = "/api/v1/akun/{$akun->id}/penugasan/{$tugas->json('data.id')}/batal";
        $this->assertSesuaiKontrak($this->minta('POST', $uri, ['alasan_batal' => 'x'])->assertStatus(422), 'POST', '/akun/{id}/penugasan/{penugasanId}/batal');
        $batal = $this->minta('POST', $uri, ['alasan_batal' => 'Rotasi selesai'])->assertOk();
        $this->assertSesuaiKontrak($batal, 'POST', '/akun/{id}/penugasan/{penugasanId}/batal');
        $batal->assertJsonPath('data.status', 'batal')->assertJsonPath('data.alasan_batal', 'Rotasi selesai')
            ->assertJsonPath('data.dibatalkan_oleh.id', $pengelola->id)->assertJsonPath('data.berlaku_sekarang', false);
        $this->minta('POST', $uri, ['alasan_batal' => 'Rotasi selesai'])->assertStatus(409)->assertJsonPath('error.kode', 'sudah_batal');
        $this->assertSame(1, PenggunaPeran::query()->where('pengguna_id', $akun->id)->count(), 'Baris tidak dihapus.');

        $this->pakaiPeramban($perambanAkun);
        $this->assertSame([], $this->minta('GET', '/api/v1/saya/menu')->json('data.izin'));
    }

    public function test_penugasan_yang_ditolak(): void
    {
        $akun = $this->buatPengguna();
        $pengelola = $this->pengelola();
        $nonaktif = $this->buatPeran(['igd.papan'], ['status' => 'nonaktif']);
        $sensitif = $this->buatPeran(['kasir.tagihan']);
        Izin::query()->where('kode', 'kasir.tagihan.perbaiki')->update(['sensitif' => true]);
        $biasa = $this->buatPeran(['igd.papan']);
        $this->masukSebagai($pengelola);
        $tugaskan = fn (Pengguna $ke, array $isi) => $this->minta('POST', "/api/v1/akun/{$ke->id}/penugasan",
            [...['cakupan_jenis' => 'rs', 'dasar_pemberian' => self::DASAR], ...$isi]);

        foreach ([
            [$pengelola, ['peran_id' => $biasa->id], 403, 'diri_sendiri'],
            [$akun, ['peran_id' => $nonaktif->id], 409, 'peran_nonaktif'],
            [$akun, ['peran_id' => $sensitif->id, 'berlaku_sampai' => now()->addDay()->toIso8601String()], 422, 'penyetuju_belum_ditetapkan'],
            [$akun, ['peran_id' => $biasa->id, 'cakupan_jenis' => 'poli', 'cakupan_id' => (string) Str::uuid7()], 422, 'cakupan_belum_didukung'],
            [$akun, ['peran_id' => $biasa->id, 'cakupan_jenis' => 'unit', 'cakupan_id' => (string) Str::uuid7()], 422, 'cakupan_tidak_valid'],
            [$akun, ['peran_id' => $biasa->id, 'cakupan_jenis' => 'unit'], 422, 'cakupan_tidak_valid'],
            [$akun, ['peran_id' => $biasa->id, 'berlaku_sampai' => now()->subDay()->toIso8601String()], 422, 'validasi_gagal'],
            [$akun, ['peran_id' => (string) Str::uuid7()], 422, 'validasi_gagal'],
        ] as [$ke, $isi, $status, $kode]) {
            $respons = $tugaskan($ke, $isi)->assertStatus($status)->assertJsonPath('error.kode', $kode);
            $this->assertSesuaiKontrak($respons, 'POST', '/akun/{id}/penugasan');
        }
        $this->assertSame(0, PenggunaPeran::query()->where('pengguna_id', $akun->id)->count());

        // Penugasan akun lain tidak ditemukan lewat akun ini.
        $superadminLain = $this->tugaskan($this->buatPengguna(), $this->superadmin());
        $this->minta('POST', "/api/v1/akun/{$akun->id}/penugasan/{$superadminLain->id}/batal", ['alasan_batal' => 'Salah akun'])
            ->assertNotFound();
    }

    public function test_cabut_per_akun_lalu_akhiri_mengembalikan_akses_sesuai_peran(): void
    {
        $akun = $this->buatPengguna();
        $this->tugaskan($akun, $this->buatPeran(['kasir.tagihan', 'kasir.tutup']));
        $pengelola = $this->pengelola();
        $this->masukSebagai($pengelola);

        $cabut = $this->minta('POST', "/api/v1/akun/{$akun->id}/izin-khusus", [
            'efek' => 'cabut', 'sasaran_jenis' => 'menu', 'sasaran_kode' => 'kasir.tutup',
            'dasar_pemberian' => self::DASAR, 'alasan' => 'Belum pelatihan tutup kasir',
        ])->assertCreated();
        $this->assertSesuaiKontrak($cabut, 'POST', '/akun/{id}/izin-khusus');
        $cabut->assertJsonPath('data.sasaran_label', 'Kasir → Tutup kasir')->assertJsonPath('data.sensitif', false);
        $this->assertNotContains('kasir.tutup.lihat', $this->minta('GET', "/api/v1/akun/{$akun->id}/menu")->json('data.izin'));

        $this->minta('POST', "/api/v1/akun/{$akun->id}/izin-khusus", [
            'efek' => 'cabut', 'sasaran_jenis' => 'menu', 'sasaran_kode' => 'kasir.tutup', 'dasar_pemberian' => self::DASAR, 'alasan' => 'Dobel',
        ])->assertStatus(409)->assertJsonPath('error.kode', 'pengecualian_ganda');

        $batal = $this->minta('POST', "/api/v1/akun/{$akun->id}/izin-khusus/{$cabut->json('data.id')}/batal", ['alasan_batal' => 'Sudah lulus pelatihan'])->assertOk();
        $this->assertSesuaiKontrak($batal, 'POST', '/akun/{id}/izin-khusus/{izinKhususId}/batal');
        $batal->assertJsonPath('data.status', 'batal');
        $this->assertContains('kasir.tutup.lihat', $this->minta('GET', "/api/v1/akun/{$akun->id}/menu")->json('data.izin'));
        $this->assertSame('batal', PenggunaIzinKhusus::query()->findOrFail($cabut->json('data.id'))->status);
        $this->assertAuditTerakhir('akses.izin_khusus_dibatalkan', $akun->id);
    }

    public function test_pengecualian_per_akun_yang_ditolak(): void
    {
        $akun = $this->buatPengguna();
        $pengelola = $this->pengelola();
        Izin::query()->where('kode', 'kasir.tagihan.batal')->exists() || Izin::query()->create([
            'kode' => 'kasir.tagihan.batal', 'menu_id' => Menu::query()->where('kode', 'kasir.tagihan')->value('id'), 'jenis_aksi' => 'batal',
        ]);
        Izin::query()->where('kode', 'kasir.tagihan.batal')->update(['sensitif' => true]);
        $this->masukSebagai($pengelola);
        $buat = fn (Pengguna $ke, array $isi) => $this->minta('POST', "/api/v1/akun/{$ke->id}/izin-khusus",
            [...['efek' => 'tambah', 'sasaran_jenis' => 'izin', 'dasar_pemberian' => self::DASAR, 'alasan' => 'Kebutuhan tugas'], ...$isi]);

        foreach ([
            [$pengelola, ['sasaran_kode' => 'kasir.tagihan.lihat'], 403, 'diri_sendiri'],
            [$akun, ['sasaran_kode' => 'kasir.tagihan.batal'], 422, 'berlaku_sampai_wajib'],
            [$akun, ['sasaran_kode' => 'kasir.tagihan.batal', 'berlaku_sampai' => now()->addWeek()->toIso8601String()], 422, 'penyetuju_belum_ditetapkan'],
            [$akun, ['sasaran_kode' => 'tidak.ada.lihat'], 422, 'sasaran_tidak_dikenal'],
            [$akun, ['sasaran_jenis' => 'menu', 'sasaran_kode' => 'kasir'], 422, 'sasaran_tidak_dikenal'],
        ] as [$ke, $isi, $status, $kode]) {
            $this->assertSesuaiKontrak($buat($ke, $isi)->assertStatus($status)->assertJsonPath('error.kode', $kode), 'POST', '/akun/{id}/izin-khusus');
        }
        $this->assertSame(0, PenggunaIzinKhusus::query()->where('pengguna_id', $akun->id)->count());

        // Mencabut aksi sensitif tidak butuh persetujuan.
        $buat($akun, ['efek' => 'cabut', 'sasaran_kode' => 'kasir.tagihan.batal'])->assertCreated()->assertJsonPath('data.sensitif', true);
    }

    public function test_wajib_mfa_untuk_akun_tanpa_mfa_memutus_sesinya(): void
    {
        $akun = $this->buatPengguna();
        $this->masukSebagai($akun);
        $perambanAkun = $this->peramban();
        $this->masukSebagai($this->pengelola());

        $respons = $this->minta('PUT', "/api/v1/akun/{$akun->id}/wajib-mfa", ['wajib_mfa' => true, 'alasan' => 'Memegang data keuangan'])->assertOk();
        $this->assertSesuaiKontrak($respons, 'PUT', '/akun/{id}/wajib-mfa');
        $respons->assertJsonPath('data.wajib_akun', true)
            ->assertJsonPath('data.sumber_wajib', [['jenis' => 'akun', 'label' => 'Sakelar akun', 'dapat_dimatikan' => true]]);
        $this->assertTrue(json_decode($this->assertAuditTerakhir('akses.wajib_mfa_diubah', $akun->id)->sesudah, true)['sesi_diputus']);

        $this->pakaiPeramban($perambanAkun);
        $this->minta('GET', '/api/v1/saya')->assertUnauthorized()->assertJsonPath('error.kode', 'sesi_dicabut');

        // Sesi yang dimulai sesudah pencabutan tetap berlaku.
        $akun->refresh()->forceFill(['wajib_mfa' => false])->save();
        $this->masukSebagai($akun);
        $this->minta('GET', '/api/v1/saya')->assertOk();
        $akun->forceFill(['wajib_mfa' => true])->save();

        // Masuk lagi: harus mendaftar MFA dari jaringan RS dulu.
        $this->mulaiSesiSpa();
        $this->minta('POST', '/api/v1/autentikasi/masuk', ['nama_pengguna' => $akun->nama_pengguna, 'sandi' => self::SANDI_UJI])
            ->assertOk()->assertJsonPath('status', 'perlu_daftar_mfa');
    }

    public function test_reset_mfa_memutus_sesi_dan_akun_mendaftar_ulang_dari_jaringan_rs(): void
    {
        $rahasia = Totp::rahasiaBaru();
        $akun = $this->buatPengguna(['wajib_mfa' => true]);
        $akun->forceFill(['mfa_rahasia_enkripsi' => $rahasia, 'mfa_aktif_pada' => now()])->save();
        DB::table('akses.kode_cadangan_mfa')->insert(['id' => (string) Str::uuid7(), 'pengguna_id' => $akun->id, 'kode_hash' => Hash::make('AAAA-BBBB')]);
        $this->mulaiSesiSpa();
        $challenge = $this->minta('POST', '/api/v1/autentikasi/masuk', ['nama_pengguna' => $akun->nama_pengguna, 'sandi' => self::SANDI_UJI])
            ->assertOk()->json('challenge_id');
        $this->minta('POST', '/api/v1/autentikasi/mfa', ['challenge_id' => $challenge, 'metode' => 'totp', 'kode' => Totp::kode($rahasia, Totp::langkah(now()->getTimestamp()))])
            ->assertOk()->assertJsonPath('status', 'masuk');
        $perambanAkun = $this->peramban();

        $pengelola = $this->pengelola();
        $this->masukSebagai($pengelola);
        $this->minta('GET', "/api/v1/akun/{$akun->id}")->assertJsonPath('data.mfa.dapat_direset', true);
        $this->assertSesuaiKontrak($this->minta('POST', "/api/v1/akun/{$pengelola->id}/mfa/reset", ['alasan' => 'Ponsel hilang', 'dasar' => self::DASAR])
            ->assertForbidden()->assertJsonPath('error.kode', 'diri_sendiri'), 'POST', '/akun/{id}/mfa/reset');

        $reset = $this->minta('POST', "/api/v1/akun/{$akun->id}/mfa/reset", ['alasan' => 'Ponsel hilang', 'dasar' => self::DASAR])->assertOk();
        $this->assertSesuaiKontrak($reset, 'POST', '/akun/{id}/mfa/reset');
        $reset->assertJsonPath('data.aktif', false)->assertJsonPath('data.aktif_pada', null)->assertJsonPath('data.dapat_direset', false);
        $akun->refresh();
        $this->assertNull($akun->mfa_rahasia_enkripsi);
        $this->assertSame(0, DB::table('akses.kode_cadangan_mfa')->where('pengguna_id', $akun->id)->whereNull('dibatalkan_pada')->count());
        $audit = $this->assertAuditTerakhir('akses.mfa_direset', $akun->id);
        $this->assertStringNotContainsString($rahasia, (string) $audit->sesudah);
        $this->assertEquals(['alasan' => 'Ponsel hilang', 'dasar' => self::DASAR, 'sesi_diputus' => true], json_decode($audit->sesudah, true));

        $this->assertSesuaiKontrak($this->minta('POST', "/api/v1/akun/{$akun->id}/mfa/reset", ['alasan' => 'Ponsel hilang', 'dasar' => self::DASAR])
            ->assertStatus(409)->assertJsonPath('error.kode', 'mfa_belum_aktif'), 'POST', '/akun/{id}/mfa/reset');

        $this->pakaiPeramban($perambanAkun);
        $this->minta('GET', '/api/v1/saya')->assertUnauthorized()->assertJsonPath('error.kode', 'sesi_dicabut');

        $this->mulaiSesiSpa();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10']);
        $this->minta('POST', '/api/v1/autentikasi/masuk', ['nama_pengguna' => $akun->nama_pengguna, 'sandi' => self::SANDI_UJI])
            ->assertForbidden()->assertJsonPath('error.kode', 'mfa_belum_terdaftar');
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1']);
        $this->minta('POST', '/api/v1/autentikasi/masuk', ['nama_pengguna' => $akun->nama_pengguna, 'sandi' => self::SANDI_UJI])
            ->assertOk()->assertJsonPath('status', 'perlu_daftar_mfa');
    }

    public function test_sumber_wajib_mfa_superadmin_tidak_bisa_dimatikan_dan_akun_sendiri_tidak_bisa_diubah(): void
    {
        $superadmin = $this->buatPengguna();
        $this->tugaskan($superadmin, $this->superadmin());
        $pengelola = $this->pengelola();
        $this->masukSebagai($pengelola);

        $this->minta('GET', "/api/v1/akun/{$superadmin->id}")->assertOk()
            ->assertJsonPath('data.mfa.sumber_wajib', [['jenis' => 'superadmin', 'label' => 'Peran Superadmin', 'dapat_dimatikan' => false]]);

        $this->assertSesuaiKontrak($this->minta('PUT', "/api/v1/akun/{$pengelola->id}/wajib-mfa", ['wajib_mfa' => false, 'alasan' => 'Matikan sendiri'])
            ->assertForbidden()->assertJsonPath('error.kode', 'diri_sendiri'), 'PUT', '/akun/{id}/wajib-mfa');
        $this->minta('POST', "/api/v1/akun/{$pengelola->id}/izin-khusus", [
            'efek' => 'tambah', 'sasaran_jenis' => 'menu', 'sasaran_kode' => 'kasir.tagihan', 'dasar_pemberian' => self::DASAR, 'alasan' => 'Naikkan diri',
        ])->assertForbidden()->assertJsonPath('error.kode', 'diri_sendiri');
    }

    public function test_akun_superadmin_hanya_diubah_superadmin_lain(): void
    {
        [$superadmin] = $this->superadminBerMfa();
        $penugasanSuperadmin = PenggunaPeran::query()->where('pengguna_id', $superadmin->id)->firstOrFail();
        $peran = $this->buatPeran(['igd.papan']);
        $this->masukSebagai($this->pengelola());
        $id = $superadmin->id;

        $this->assertSesuaiKontrak($this->minta('GET', "/api/v1/akun/$id")->assertOk(), 'GET', '/akun/{id}')
            ->assertJsonPath('data.mfa.dapat_direset', false)->assertJsonPath('data.dapat_diubah', false)
            ->assertJsonPath('data.alasan_terkunci', 'akun_superadmin');
        foreach ([
            ['POST', "/akun/$id/penugasan", '/akun/{id}/penugasan', ['peran_id' => $peran->id, 'cakupan_jenis' => 'rs', 'dasar_pemberian' => self::DASAR]],
            ['POST', "/akun/$id/izin-khusus", '/akun/{id}/izin-khusus', ['efek' => 'cabut', 'sasaran_jenis' => 'menu', 'sasaran_kode' => 'pengaturan.akun', 'dasar_pemberian' => self::DASAR, 'alasan' => 'Kunci superadmin']],
            ['PUT', "/akun/$id/wajib-mfa", '/akun/{id}/wajib-mfa', ['wajib_mfa' => true, 'alasan' => 'Coba ubah']],
            ['POST', "/akun/$id/mfa/reset", '/akun/{id}/mfa/reset', ['alasan' => 'Coba reset', 'dasar' => self::DASAR]],
        ] as [$metode, $uri, $path, $data]) {
            $respons = $this->minta($metode, '/api/v1'.$uri, $data)->assertForbidden()->assertJsonPath('error.kode', 'akun_superadmin');
            $this->assertSesuaiKontrak($respons, $metode, $path);
        }
        $this->assertTrue($superadmin->refresh()->mfaAktif());
        $this->assertSame(0, PenggunaIzinKhusus::query()->where('pengguna_id', $id)->count());

        // Superadmin lain boleh; mengakhiri penugasan superadmin ditolak selama superadmin aktif tinggal 2 (PRD 9.2.4).
        [$lain, $rahasiaLain] = $this->superadminBerMfa();
        $this->masukSebagai($lain, $rahasiaLain);
        $this->minta('GET', "/api/v1/akun/$id")->assertOk()->assertJsonPath('data.mfa.dapat_direset', true)
            ->assertJsonPath('data.dapat_diubah', true);
        $this->minta('POST', "/api/v1/akun/$id/penugasan/{$penugasanSuperadmin->id}/batal", ['alasan_batal' => 'Coba cabut'])
            ->assertStatus(409)->assertJsonPath('error.kode', 'superadmin_minimal');
        $this->minta('POST', "/api/v1/akun/$id/mfa/reset", ['alasan' => 'Ponsel hilang', 'dasar' => self::DASAR])->assertOk();
    }

    public function test_daftar_seribu_akun_tetap_cepat(): void
    {
        $sandi = Hash::make(Str::password(24));
        $pegawai = [];
        $akun = [];
        for ($i = 0; $i < 1000; $i++) {
            $pegawai[] = ['id' => $idPegawai = (string) Str::uuid7(), 'nip' => sprintf('BEBAN-%04d', $i), 'nama' => "Pegawai Beban $i"];
            $akun[] = ['id' => (string) Str::uuid7(), 'pegawai_id' => $idPegawai, 'nama_pengguna' => "beban.$i", 'sandi_hash' => $sandi];
        }
        DB::table('sdm.pegawai')->insert($pegawai);
        foreach (array_chunk($akun, 500) as $potongan) {
            DB::table('akses.pengguna')->insert($potongan);
        }
        $this->masukSebagai($this->pengelola());
        $this->minta('GET', '/api/v1/akun')->assertOk();

        $waktu = [];
        $kursor = '';
        for ($i = 0; $i < 20; $i++) {
            $mulai = hrtime(true);
            $respons = $this->minta('GET', '/api/v1/akun?batas=50'.($kursor === '' ? '' : '&kursor='.$kursor))->assertOk();
            $waktu[] = (hrtime(true) - $mulai) / 1e6;
            $kursor = (string) $respons->json('meta.kursor_berikutnya');
        }
        sort($waktu);

        $this->assertLessThan(300, $waktu[(int) ceil(0.95 * count($waktu)) - 1], 'P95 daftar akun (ms): '.json_encode($waktu));
    }

    /**
     * Temuan ASVS 4.1.3 (tiket 1.12): pengelolaan akun dan peran butuh izin bercakupan seluruh RS. Pemegang izin yang
     * sama bercakupan unit tidak boleh melihat atau mengubah akun RS (admin delegasi per unit belum dibangun).
     */
    public function test_pengelola_bercakupan_unit_tidak_bisa_mengelola_akun_dan_peran(): void
    {
        $unit = UnitOrganisasi::query()->create(['kode' => 'UJI-CAKUPAN', 'nama' => 'Unit uji cakupan', 'jenis' => 'instalasi', 'berlaku_dari' => '2026-01-01']);
        $pengelola = $this->buatPengguna();
        $pengelola->forceFill(['mfa_rahasia_enkripsi' => Totp::rahasiaBaru(), 'mfa_aktif_pada' => now()])->save();
        $this->tugaskan($pengelola, $this->buatPeran(['pengaturan.peran', 'pengaturan.akun']), cakupan: new Cakupan('unit', $unit->id));
        $akun = $this->buatPengguna();
        $peran = $this->buatPeran(['beranda']);
        $this->masukSebagai($pengelola);

        foreach ([
            ['GET', '/api/v1/akun', []],
            ['GET', "/api/v1/akun/{$akun->id}", []],
            ['GET', '/api/v1/peran', []],
            ['GET', "/api/v1/peran/{$peran->id}", []],
            ['GET', '/api/v1/menu', []],
            ['POST', "/api/v1/akun/{$akun->id}/penugasan", ['peran_id' => $peran->id, 'cakupan_jenis' => 'rs', 'dasar_pemberian' => 'SK uji', 'alasan' => 'Uji cakupan']],
            ['POST', "/api/v1/akun/{$akun->id}/nonaktifkan", ['alasan' => 'Uji cakupan']],
            ['POST', "/api/v1/akun/{$akun->id}/mfa/reset", ['alasan' => 'Uji cakupan', 'dasar' => 'SK uji']],
        ] as [$metode, $uri, $isi]) {
            $this->minta($metode, $uri, $isi)->assertForbidden()->assertJsonPath('error.kode', 'akses_ditolak');
        }
        $this->assertSame('aktif', $akun->refresh()->status);
        $this->assertFalse(PenggunaPeran::query()->where('pengguna_id', $akun->id)->exists());
    }
}
