<?php

namespace Tests\Feature\Ttd;

use App\Modules\Akses\Database\Seeders\AkunSintetisSeeder;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\Akses\Models\PersetujuanAkses;
use App\Modules\MasterData\Models\UnitOrganisasi;
use App\Modules\Sdm\Models\Pegawai;
use App\Modules\Ttd\Application\AlurPermintaanTte;
use App\Modules\Ttd\Database\Seeders\TtdSintetisSeeder;
use App\Modules\Ttd\Domain\AturanPin;
use App\Modules\Ttd\Domain\KunciTtd;
use App\Modules\Ttd\Infrastructure\SimulatorTte;
use App\Modules\Ttd\Models\Kredensial;
use App\Modules\Ttd\Models\TandaTangan;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\AksesUji;
use Tests\Support\KlienSpa;
use Tests\Support\KontrakApi;
use Tests\TestCase;

/** Tiket 1.6-be: tanda tangan elektronik T1 (kontrak v0.8.0). */
class TtdTest extends TestCase
{
    use AksesUji, KlienSpa, KontrakApi;

    private const PIN = '482916';

    private const DASAR = 'Verifikasi KTP tatap muka 12/10/2026';

    public function test_aturan_pin(): void
    {
        foreach (['123456', '654321', '012345', '987654', '111111', '121212', '123123', '12345', '12a456', '1234567', ''] as $pin) {
            $this->assertTrue(AturanPin::lemah($pin), $pin);
        }
        foreach (['482916', '135790', '120345', '987650'] as $pin) {
            $this->assertFalse(AturanPin::lemah($pin), $pin);
        }
    }

    public function test_aktivasi_dengan_sandi_dan_pin_kuat_lalu_kunci_tidak_terbuka_tanpa_pin_atau_kunci_server(): void
    {
        $akun = $this->buatPengguna();
        $this->masukSebagai($akun);
        $this->minta('GET', '/api/v1/saya')->assertOk()->assertJsonPath('data.ttd.aktif', false)->assertJsonPath('data.ttd.dicabut_pada', null);

        $this->minta('POST', '/api/v1/saya/ttd/aktivasi', ['sandi' => 'sandi-salah-sekali', 'pin' => self::PIN, 'pin_ulang' => self::PIN])
            ->assertStatus(422)->assertJsonPath('error.kode', 'sandi_salah')->assertJsonPath('error.rincian.0.kolom', 'sandi');
        $lemah = $this->minta('POST', '/api/v1/saya/ttd/aktivasi', ['sandi' => self::SANDI_UJI, 'pin' => '123456', 'pin_ulang' => '123456'])
            ->assertStatus(422)->assertJsonPath('error.kode', 'pin_lemah')->assertJsonPath('error.rincian.0.kolom', 'pin');
        $this->assertSesuaiKontrak($lemah, 'POST', '/saya/ttd/aktivasi');
        $this->minta('POST', '/api/v1/saya/ttd/aktivasi', ['sandi' => self::SANDI_UJI, 'pin' => '12a4', 'pin_ulang' => '12a4'])
            ->assertStatus(422)->assertJsonPath('error.kode', 'pin_lemah');
        $this->minta('POST', '/api/v1/saya/ttd/aktivasi', ['sandi' => self::SANDI_UJI, 'pin' => self::PIN, 'pin_ulang' => '482917'])
            ->assertStatus(422)->assertJsonPath('error.kode', 'pin_tidak_sama')->assertJsonPath('error.rincian.0.kolom', 'pin_ulang');

        $aktif = $this->minta('POST', '/api/v1/saya/ttd/aktivasi', ['sandi' => self::SANDI_UJI, 'pin' => self::PIN, 'pin_ulang' => self::PIN])
            ->assertCreated()->assertJsonPath('data.aktif', true)->assertJsonPath('data.sesi_berlaku_sampai', null);
        $this->assertSesuaiKontrak($aktif, 'POST', '/saya/ttd/aktivasi');
        $this->assertStringContainsString('no-store', (string) $aktif->headers->get('Cache-Control'));
        $this->minta('POST', '/api/v1/saya/ttd/aktivasi', ['sandi' => self::SANDI_UJI, 'pin' => self::PIN, 'pin_ulang' => self::PIN])
            ->assertStatus(409)->assertJsonPath('error.kode', 'ttd_sudah_aktif');
        $this->assertSesuaiKontrak($this->minta('GET', '/api/v1/saya')->assertJsonPath('data.ttd.aktif', true), 'GET', '/saya');

        // Isi DB tidak memuat PIN; kunci privat tidak terbuka dengan PIN lain, atau dengan PIN benar tanpa kunci server.
        $k = Kredensial::query()->where('pengguna_id', $akun->id)->sole();
        $baris = json_encode(DB::table('ttd.kredensial')->where('id', $k->id)->first());
        $this->assertStringNotContainsString(self::PIN, (string) $baris);
        $this->assertStringNotContainsString(self::PIN, (string) json_encode(DB::table('audit.log_aktivitas')->where('subjek_id', $k->id)->get()));
        $buka = fn (string $pin, string $lada) => KunciTtd::buka((string) $k->kunci_privat_terbungkus, (string) $k->garam, (array) $k->parameter_kdf, $pin, $lada, $akun->id, (string) $k->kunci_publik);
        $lada = (string) base64_decode(substr((string) config('ttd.kunci'), 7), true);
        $this->assertNull($buka('135790', $lada));
        $this->assertNull($buka(self::PIN, str_repeat("\0", 32)));
        $this->assertNotNull($buka(self::PIN, $lada));
        // Bungkusan tidak bisa dipindah ke akun lain (AD mengikat akun).
        $this->assertNull(KunciTtd::buka((string) $k->kunci_privat_terbungkus, (string) $k->garam, (array) $k->parameter_kdf, self::PIN, $lada, (string) Str::uuid7(), (string) $k->kunci_publik));
    }

    public function test_reset_diajukan_sdm_disetujui_atasan_lalu_ditandatangani_dan_diverifikasi(): void
    {
        Queue::fake();
        [$pemilik, $atasan, $sdm, $unit] = $this->organisasi();
        $this->aktifkan($pemilik);
        $this->aktifkan($atasan);

        // SDM mengajukan reset; penyetuju = atasan langsung (kepala unit pemilik).
        $this->masukSebagai($sdm);
        $this->minta('POST', "/api/v1/ttd/{$sdm->id}/reset", ['alasan' => 'Lupa PIN', 'dasar' => self::DASAR])->assertStatus(403)->assertJsonPath('error.kode', 'diri_sendiri');
        $ajukan = $this->minta('POST', "/api/v1/ttd/{$pemilik->id}/reset", ['alasan' => 'Pemilik lupa PIN', 'dasar' => self::DASAR])
            ->assertStatus(202)->assertJsonPath('data.jenis', 'reset_ttd')->assertJsonPath('data.objek.jenis', 'reset_ttd')
            ->assertJsonPath('data.penyetuju_peran', null)->assertJsonPath('data.penyetuju_pengguna.id', $atasan->id)
            ->assertJsonPath('data.objek.akun.id', $pemilik->id);
        $this->assertSesuaiKontrak($ajukan, 'POST', '/ttd/{pengguna}/reset');
        $id = $ajukan->json('data.id');
        $this->minta('POST', "/api/v1/ttd/{$pemilik->id}/reset", ['alasan' => 'Pemilik lupa PIN', 'dasar' => self::DASAR])
            ->assertStatus(409)->assertJsonPath('error.kode', 'persetujuan_ganda');
        $this->minta('POST', "/api/v1/persetujuan-akses/{$id}/setujui")->assertStatus(403)->assertJsonPath('error.kode', 'bukan_penyetuju');

        // Pemilik bukan penyetuju; atasan melihatnya di "perlu saya putuskan" dan di kotak kerjanya.
        $this->masukSebagai($pemilik);
        $this->minta('POST', "/api/v1/persetujuan-akses/{$id}/setujui")->assertStatus(403);
        $this->minta('GET', '/api/v1/saya')->assertJsonPath('data.ttd.reset_menunggu', true);
        $this->masukSebagai($atasan);
        $this->minta('GET', '/api/v1/persetujuan-akses?tab=perlu_saya_putuskan')->assertOk()->assertJsonPath('data.0.id', $id)->assertJsonPath('data.0.dapat_diputuskan', true);
        $this->minta('GET', '/api/v1/saya/tugas?tab=saya')->assertOk()->assertJsonPath('data.0.objek.id', $id);
        $setuju = $this->minta('POST', "/api/v1/persetujuan-akses/{$id}/setujui", ['catatan' => 'Identitas sudah diverifikasi SDM'])
            ->assertOk()->assertJsonPath('data.diterapkan', true);
        $this->assertSesuaiKontrak($setuju, 'POST', '/persetujuan-akses/{id}/setujui');
        $this->assertSame('dicabut', Kredensial::query()->where('pengguna_id', $pemilik->id)->sole()->status);
        $this->assertTrue(DB::table('notifikasi.notifikasi')->where('pengguna_id', $pemilik->id)->where('jenis', 'TTD_DICABUT')->exists());

        // Atasan menandatangani keputusannya; kirim ulang tanpa PIN (sesi) tidak menggandakan.
        $objek = [['jenis' => 'persetujuan_akses', 'id' => $id]];
        $this->minta('POST', '/api/v1/ttd/tandatangani', ['objek' => $objek, 'pernyataan_disetujui' => true])
            ->assertStatus(422)->assertJsonPath('error.kode', 'pin_wajib');
        $ttd = $this->minta('POST', '/api/v1/ttd/tandatangani', ['objek' => $objek, 'pin' => self::PIN, 'pernyataan_disetujui' => true])
            ->assertOk()->assertJsonPath('data.0.baru', true)->assertJsonPath('data.0.penanda_tangan.id', $atasan->id)
            ->assertJsonPath('data.0.verifikasi.status', 'sah');
        $this->assertSesuaiKontrak($ttd, 'POST', '/ttd/tandatangani');
        $this->assertNotNull($ttd->json('meta.sesi_berlaku_sampai'));
        $this->minta('POST', '/api/v1/ttd/tandatangani', ['objek' => $objek, 'pernyataan_disetujui' => true])
            ->assertOk()->assertJsonPath('data.0.baru', false)->assertJsonPath('data.0.id', $ttd->json('data.0.id'));
        $this->assertSame(1, TandaTangan::query()->where('objek_id', $id)->count());

        // Verifikasi: pengaju dan pemilik boleh melihat; akun lain 404. Mengganti label peran tidak mengubah apa pun.
        $verif = $this->minta('GET', "/api/v1/ttd/verifikasi/persetujuan_akses/{$id}")->assertOk()->assertJsonPath('data.0.verifikasi.status', 'sah');
        $this->assertSesuaiKontrak($verif, 'GET', '/ttd/verifikasi/{objek_jenis}/{objek_id}');
        $this->masukSebagai($pemilik);
        $this->minta('GET', "/api/v1/ttd/verifikasi/persetujuan_akses/{$id}")->assertOk()->assertJsonPath('data.0.verifikasi.status', 'sah');
        $this->minta('GET', '/api/v1/saya')->assertJsonPath('data.ttd.aktif', false)->assertJsonPath('data.ttd.reset_menunggu', false);
        $this->assertNotNull($this->minta('GET', '/api/v1/saya')->json('data.ttd.dicabut_pada'));
        $this->masukSebagai($this->buatPengguna());
        $this->minta('GET', "/api/v1/ttd/verifikasi/persetujuan_akses/{$id}")->assertNotFound();
        $this->minta('POST', '/api/v1/ttd/tandatangani', ['objek' => $objek, 'pin' => self::PIN, 'pernyataan_disetujui' => true])
            ->assertStatus(409)->assertJsonPath('error.kode', 'ttd_belum_aktif');

        // Isi diubah sesudah ditandatangani → isi_berubah; baris tanda tangan tidak bisa diubah aplikasi.
        PersetujuanAkses::query()->whereKey($id)->update(['catatan_keputusan' => 'Diubah diam-diam']);
        $this->masukSebagai($atasan);
        $this->minta('GET', "/api/v1/ttd/verifikasi/persetujuan_akses/{$id}")->assertJsonPath('data.0.verifikasi.status', 'isi_berubah');
        try {
            DB::transaction(fn () => DB::table('ttd.tanda_tangan')->where('objek_id', $id)->update(['hash_isi' => str_repeat('0', 64)]));
            $this->fail('simrs_app tidak boleh UPDATE ttd.tanda_tangan');
        } catch (QueryException $e) {
            $this->assertStringContainsString('permission denied', $e->getMessage());
        }

        // Pemilik mengaktifkan ulang dengan pasangan kunci baru; tanda tangan lama tetap memakai kunci lama.
        $this->aktifkan($pemilik);
        $this->assertSame(2, Kredensial::query()->where('pengguna_id', $pemilik->id)->count());
        $this->assertSame($unit->id, Pegawai::query()->find($pemilik->pegawai_id)?->unit_organisasi_id);
    }

    public function test_hanya_pemutus_yang_menandatangani_dan_massal_satu_baris_per_objek(): void
    {
        [, $atasan, $sdm] = $this->organisasi();
        $this->aktifkan($atasan);
        $this->aktifkan($sdm);
        $milikAtasan = array_map(fn () => $this->persetujuanDiputuskan($atasan, $sdm), range(1, 12));
        $menunggu = $this->persetujuanDiputuskan($atasan, $sdm, 'menunggu');
        $milikLain = $this->persetujuanDiputuskan($sdm, $atasan);

        $this->masukSebagai($atasan);
        $gagal = $this->minta('POST', '/api/v1/ttd/tandatangani', ['pin' => self::PIN, 'pernyataan_disetujui' => true, 'objek' => [
            ['jenis' => 'persetujuan_akses', 'id' => $milikAtasan[0]],
            ['jenis' => 'persetujuan_akses', 'id' => $milikLain],
            ['jenis' => 'persetujuan_akses', 'id' => $menunggu],
            ['jenis' => 'persetujuan_akses', 'id' => (string) Str::uuid7()],
        ]])->assertStatus(422)->assertJsonPath('error.kode', 'objek_tidak_bisa_ditandatangani');
        $this->assertSame([['objek.1', 'bukan_pemutus'], ['objek.2', 'belum_diputuskan'], ['objek.3', 'tidak_ditemukan']],
            array_map(fn (array $r) => [$r['kolom'], $r['kode']], $gagal->json('error.rincian')));
        $this->assertSame(0, TandaTangan::query()->count());
        $this->minta('POST', '/api/v1/ttd/tandatangani', ['pin' => self::PIN, 'pernyataan_disetujui' => true, 'objek' => [
            ['jenis' => 'persetujuan_akses', 'id' => $milikAtasan[0]], ['jenis' => 'persetujuan_akses', 'id' => $milikAtasan[0]],
        ]])->assertStatus(422)->assertJsonPath('error.kode', 'validasi_gagal');
        $this->minta('POST', '/api/v1/ttd/tandatangani', ['pin' => self::PIN, 'pernyataan_disetujui' => false, 'objek' => [
            ['jenis' => 'persetujuan_akses', 'id' => $milikAtasan[0]],
        ]])->assertStatus(422)->assertJsonPath('error.kode', 'validasi_gagal');

        $massal = $this->minta('POST', '/api/v1/ttd/tandatangani', ['pin' => self::PIN, 'pernyataan_disetujui' => true,
            'objek' => array_map(fn (string $id) => ['jenis' => 'persetujuan_akses', 'id' => $id], $milikAtasan)])->assertOk()->assertJsonCount(12, 'data');
        $this->assertSame($milikAtasan, array_column($massal->json('data'), 'objek_id'));
        $baris = TandaTangan::query()->whereIn('objek_id', $milikAtasan)->get();
        $this->assertCount(12, $baris);
        $this->assertCount(12, $baris->pluck('hash_isi')->unique());
        $this->assertSame(12, DB::table('audit.log_aktivitas')->where('aksi', 'ttd.ditandatangani')->where('pengguna_id', $atasan->id)->count());
    }

    public function test_lima_pin_salah_mengunci_dan_memberi_tahu_pemilik_serta_atasan(): void
    {
        Queue::fake();
        [$pemilik, $atasan, $sdm] = $this->organisasi();
        $this->aktifkan($pemilik);
        $id = $this->persetujuanDiputuskan($pemilik, $sdm);
        $this->masukSebagai($pemilik);
        $objek = [['jenis' => 'persetujuan_akses', 'id' => $id]];
        foreach ([4, 3, 2, 1] as $sisa) {
            $salah = $this->minta('POST', '/api/v1/ttd/tandatangani', ['objek' => $objek, 'pin' => '135790', 'pernyataan_disetujui' => true])
                ->assertStatus(422)->assertJsonPath('error.kode', 'pin_salah')->assertJsonPath('error.rincian.0.sisa_percobaan', $sisa);
        }
        $this->assertSesuaiKontrak($salah, 'POST', '/ttd/tandatangani');
        $kunci = $this->minta('POST', '/api/v1/ttd/tandatangani', ['objek' => $objek, 'pin' => '135790', 'pernyataan_disetujui' => true])
            ->assertStatus(403)->assertJsonPath('error.kode', 'ttd_terkunci');
        $this->assertGreaterThan(0, (int) $kunci->headers->get('Retry-After'));
        // PIN benar pun ditolak selama terkunci; ganti PIN juga.
        $this->minta('POST', '/api/v1/ttd/tandatangani', ['objek' => $objek, 'pin' => self::PIN, 'pernyataan_disetujui' => true])->assertStatus(403);
        $this->minta('PUT', '/api/v1/saya/ttd/pin', ['pin_lama' => self::PIN, 'pin_baru' => '135790', 'pin_ulang' => '135790'])->assertStatus(403);
        $this->assertNotNull($this->minta('GET', '/api/v1/saya')->json('data.ttd.terkunci_sampai'));
        foreach ([$pemilik, $atasan] as $penerima) {
            $this->assertTrue(DB::table('notifikasi.notifikasi')->where('pengguna_id', $penerima->id)->where('jenis', 'TTD_TERKUNCI')->where('prioritas', 'kritis')->exists());
        }

        $this->travel(16)->minutes();
        $this->masukSebagai($pemilik);
        $this->minta('POST', '/api/v1/ttd/tandatangani', ['objek' => $objek, 'pin' => self::PIN, 'pernyataan_disetujui' => true])->assertOk();
    }

    public function test_ganti_pin_dan_sesi_tanda_tangan_berakhir(): void
    {
        [, $atasan, $sdm] = $this->organisasi();
        $this->aktifkan($sdm);
        [$a, $b] = [$this->persetujuanDiputuskan($sdm, $atasan), $this->persetujuanDiputuskan($sdm, $atasan)];
        $this->masukSebagai($sdm);
        $this->minta('POST', '/api/v1/ttd/tandatangani', ['objek' => [['jenis' => 'persetujuan_akses', 'id' => $a]], 'pin' => self::PIN, 'pernyataan_disetujui' => true])->assertOk();
        $this->assertNotNull($this->minta('GET', '/api/v1/saya')->json('data.ttd.sesi_berlaku_sampai'));

        $this->minta('PUT', '/api/v1/saya/ttd/pin', ['pin_lama' => '135790', 'pin_baru' => '246801', 'pin_ulang' => '246801'])
            ->assertStatus(422)->assertJsonPath('error.kode', 'pin_salah')->assertJsonPath('error.rincian.0.kolom', 'pin_lama');
        $this->minta('PUT', '/api/v1/saya/ttd/pin', ['pin_lama' => self::PIN, 'pin_baru' => '000000', 'pin_ulang' => '000000'])
            ->assertStatus(422)->assertJsonPath('error.rincian.0.kolom', 'pin_baru');
        $ganti = $this->minta('PUT', '/api/v1/saya/ttd/pin', ['pin_lama' => self::PIN, 'pin_baru' => '246801', 'pin_ulang' => '246801'])
            ->assertOk()->assertJsonPath('data.sesi_berlaku_sampai', null);
        $this->assertSesuaiKontrak($ganti, 'PUT', '/saya/ttd/pin');
        $this->minta('POST', '/api/v1/ttd/tandatangani', ['objek' => [['jenis' => 'persetujuan_akses', 'id' => $b]], 'pernyataan_disetujui' => true])
            ->assertStatus(422)->assertJsonPath('error.kode', 'pin_wajib');
        $this->minta('POST', '/api/v1/ttd/tandatangani', ['objek' => [['jenis' => 'persetujuan_akses', 'id' => $b]], 'pin' => self::PIN, 'pernyataan_disetujui' => true])
            ->assertStatus(422)->assertJsonPath('error.kode', 'pin_salah');
        $this->minta('POST', '/api/v1/ttd/tandatangani', ['objek' => [['jenis' => 'persetujuan_akses', 'id' => $b]], 'pin' => '246801', 'pernyataan_disetujui' => true])->assertOk();
        // Tanda tangan lama tetap sah sesudah PIN berganti (pasangan kunci sama).
        $this->minta('GET', "/api/v1/ttd/verifikasi/persetujuan_akses/{$a}")->assertJsonPath('data.0.verifikasi.status', 'sah');

        // Sesi tanda tangan tidak ikut ke sesi login lain, dan berakhir sesudah 15 menit.
        $this->masukSebagai($sdm);
        $this->minta('GET', '/api/v1/saya')->assertJsonPath('data.ttd.sesi_berlaku_sampai', null);
    }

    public function test_sesi_tanda_tangan_berakhir_di_semua_sesi_saat_ganti_pin_atau_terkunci(): void
    {
        config(['ttd.kunci_menit' => 1]);
        [, $atasan, $sdm] = $this->organisasi();
        $this->aktifkan($sdm);
        $id = fn () => [['jenis' => 'persetujuan_akses', 'id' => $this->persetujuanDiputuskan($sdm, $atasan)]];
        $tanpaPin = fn () => $this->minta('POST', '/api/v1/ttd/tandatangani', ['objek' => $id(), 'pernyataan_disetujui' => true]);

        // Peramban A membuka sesi tanda tangan; peramban B (akun sama) mengganti PIN → sesi A berakhir.
        $this->masukSebagai($sdm);
        $this->minta('POST', '/api/v1/ttd/tandatangani', ['objek' => $id(), 'pin' => self::PIN, 'pernyataan_disetujui' => true])->assertOk();
        $tanpaPin()->assertOk();
        $a = $this->peramban();
        $this->masukSebagai($sdm);
        $this->minta('PUT', '/api/v1/saya/ttd/pin', ['pin_lama' => self::PIN, 'pin_baru' => '246801', 'pin_ulang' => '246801'])->assertOk();
        $this->pakaiPeramban($a);
        $tanpaPin()->assertStatus(422)->assertJsonPath('error.kode', 'pin_wajib');

        // Terkunci → sesi berakhir dan tidak hidup lagi sesudah kunci lepas.
        $this->minta('POST', '/api/v1/ttd/tandatangani', ['objek' => $id(), 'pin' => '246801', 'pernyataan_disetujui' => true])->assertOk();
        foreach (range(1, 5) as $_) {
            $this->minta('POST', '/api/v1/ttd/tandatangani', ['objek' => $id(), 'pin' => '135790', 'pernyataan_disetujui' => true]);
        }
        $this->travel(2)->minutes();
        $this->pakaiPeramban($a);
        $tanpaPin()->assertStatus(422)->assertJsonPath('error.kode', 'pin_wajib');
    }

    public function test_kirim_ulang_memverifikasi_ulang_tanda_tangan_lama(): void
    {
        [, $atasan, $sdm] = $this->organisasi();
        $this->aktifkan($sdm);
        $id = $this->persetujuanDiputuskan($sdm, $atasan);
        $objek = [['jenis' => 'persetujuan_akses', 'id' => $id]];
        $this->masukSebagai($sdm);
        $this->minta('POST', '/api/v1/ttd/tandatangani', ['objek' => $objek, 'pin' => self::PIN, 'pernyataan_disetujui' => true])->assertOk();
        PersetujuanAkses::query()->whereKey($id)->update(['catatan_keputusan' => 'Diubah sesudah ditandatangani']);
        $this->minta('POST', '/api/v1/ttd/tandatangani', ['objek' => $objek, 'pernyataan_disetujui' => true])
            ->assertOk()->assertJsonPath('data.0.baru', false)->assertJsonPath('data.0.verifikasi.status', 'isi_berubah');
        // Huruf besar id ganda tetap dianggap ganda.
        $this->minta('POST', '/api/v1/ttd/tandatangani', ['pernyataan_disetujui' => true, 'objek' => [
            ['jenis' => 'persetujuan_akses', 'id' => $id], ['jenis' => 'persetujuan_akses', 'id' => strtoupper($id)],
        ]])->assertStatus(422)->assertJsonPath('error.kode', 'validasi_gagal');
    }

    public function test_atasan_naik_ke_unit_induk_dan_puncak_tanpa_atasan(): void
    {
        [$kepalaInduk, $akunInduk] = $this->pegawaiBerakun('dr. Direktur');
        $induk = UnitOrganisasi::query()->create(['kode' => 'DIR', 'nama' => 'Direktorat', 'jenis' => 'bidang', 'berlaku_dari' => '2026-01-01', 'kepala_pegawai_id' => $kepalaInduk->id]);
        [$kepala, $akunKepala] = $this->pegawaiBerakun('dr. Kepala', $induk->id);
        $unit = UnitOrganisasi::query()->create(['kode' => 'IGD', 'nama' => 'IGD', 'jenis' => 'instalasi', 'induk_id' => $induk->id, 'berlaku_dari' => '2026-01-01', 'kepala_pegawai_id' => $kepala->id]);
        $kepala->forceFill(['unit_organisasi_id' => $unit->id])->save();
        $kepalaInduk->forceFill(['unit_organisasi_id' => $induk->id])->save();
        $sdm = $this->buatPengguna();
        $this->tugaskan($sdm, $this->buatPeran(['sdm.kredensial', 'sdm.pegawai']));
        $this->aktifkan($akunKepala);
        $this->aktifkan($akunInduk);

        // Kepala unit sendiri → atasan = kepala unit induk.
        $this->masukSebagai($sdm);
        $this->minta('POST', "/api/v1/ttd/{$akunKepala->id}/reset", ['alasan' => 'Lupa PIN', 'dasar' => self::DASAR])
            ->assertStatus(202)->assertJsonPath('data.penyetuju_pengguna.id', $akunInduk->id);
        // Direktur (unit akar) → tidak ada atasan.
        $this->minta('POST', "/api/v1/ttd/{$akunInduk->id}/reset", ['alasan' => 'Lupa PIN', 'dasar' => self::DASAR])
            ->assertStatus(422)->assertJsonPath('error.kode', 'atasan_belum_ditetapkan');
        // Tanpa TTE aktif; tanpa izin.
        $this->minta('POST', "/api/v1/ttd/{$sdm->id}/reset", ['alasan' => 'Lupa PIN', 'dasar' => self::DASAR])->assertStatus(403);
        $this->masukSebagai($akunKepala);
        $this->minta('POST', "/api/v1/ttd/{$akunInduk->id}/reset", ['alasan' => 'Lupa PIN', 'dasar' => self::DASAR])->assertStatus(403);
    }

    public function test_simulator_t2_alur_asinkron(): void
    {
        [, $atasan, $sdm] = $this->organisasi();
        $id = $this->persetujuanDiputuskan($sdm, $atasan);
        $alur = app(AlurPermintaanTte::class);
        $permintaan = $alur->minta($sdm, 'persetujuan_akses', $id);
        $transaksi = (string) DB::table('ttd.permintaan')->where('id', $permintaan)->value('id_transaksi_penyedia');
        $this->assertSame('terkirim', DB::table('ttd.permintaan')->where('id', $permintaan)->value('status'));
        $this->assertSame('terkirim', $alur->terimaHasil($transaksi));

        app(SimulatorTte::class)->putuskan($transaksi, 'ditandatangani');
        $this->assertSame('ditandatangani', $alur->terimaHasil($transaksi));
        // Webhook ganda aman: tidak membuat tanda tangan kedua.
        $alur->terimaHasil($transaksi);
        $this->assertSame(1, TandaTangan::query()->where('objek_id', $id)->where('tingkat', 'T2')->where('metode', 'psre')->count());
    }

    public function test_seeder_tte_sintetis(): void
    {
        config(['autentikasi.akun_sintetis_sandi' => 'kuda-lumping-biru-29', 'ttd.pin_sintetis' => self::PIN]);
        $this->seed(AkunSintetisSeeder::class);
        $this->seed(TtdSintetisSeeder::class);
        $this->seed(TtdSintetisSeeder::class);
        $akun = Pengguna::query()->where('nama_pengguna', 'contoh.ttd')->firstOrFail();
        $k = Kredensial::query()->where('pengguna_id', $akun->id)->where('status', 'aktif')->sole();
        $lada = (string) base64_decode(substr((string) config('ttd.kunci'), 7), true);
        $this->assertNotNull(KunciTtd::buka((string) $k->kunci_privat_terbungkus, (string) $k->garam, (array) $k->parameter_kdf, self::PIN, $lada, $akun->id, (string) $k->kunci_publik));
        $baru = Pengguna::query()->where('nama_pengguna', 'contoh.ttd.baru')->firstOrFail();
        $this->assertFalse(Kredensial::query()->where('pengguna_id', $baru->id)->where('status', 'aktif')->exists());
    }

    /**
     * Unit IGD: kepala = atasan; pemilik pegawai IGD; petugas SDM berizin kredensial.
     *
     * @return array{0: Pengguna, 1: Pengguna, 2: Pengguna, 3: UnitOrganisasi}
     */
    private function organisasi(): array
    {
        [$kepala, $atasan] = $this->pegawaiBerakun('dr. Kepala IGD');
        $unit = UnitOrganisasi::query()->create(['kode' => 'IGD', 'nama' => 'IGD', 'jenis' => 'instalasi', 'berlaku_dari' => '2026-01-01', 'kepala_pegawai_id' => $kepala->id]);
        [, $pemilik] = $this->pegawaiBerakun('dr. Pemilik', $unit->id);
        $sdm = $this->buatPengguna();
        $this->tugaskan($sdm, $this->buatPeran(['sdm.kredensial', 'sdm.pegawai']));

        return [$pemilik, $atasan, $sdm, $unit];
    }

    /** @return array{0: Pegawai, 1: Pengguna} */
    private function pegawaiBerakun(string $nama, ?string $unitId = null): array
    {
        $pegawai = Pegawai::query()->create(['nama' => $nama, 'unit_organisasi_id' => $unitId]);

        return [$pegawai, $this->buatPengguna(['pegawai_id' => $pegawai->id])];
    }

    private function aktifkan(Pengguna $akun): void
    {
        $this->masukSebagai($akun);
        $this->minta('POST', '/api/v1/saya/ttd/aktivasi', ['sandi' => self::SANDI_UJI, 'pin' => self::PIN, 'pin_ulang' => self::PIN])->assertCreated();
    }

    /** Persetujuan izin khusus sintetis yang sudah diputuskan $pemutus (atau menunggu). */
    private function persetujuanDiputuskan(Pengguna $pemutus, Pengguna $pengaju, string $status = 'disetujui'): string
    {
        $p = new PersetujuanAkses;
        $p->forceFill([
            'nomor' => 'PSA-UJI-'.Str::random(8), 'grup_id' => (string) Str::uuid7(), 'jenis' => 'pengecualian', 'objek_jenis' => 'izin_khusus',
            'objek' => ['efek' => 'tambah', 'catatan' => 'uji'], 'penyetuju_pengguna_id' => $pemutus->id, 'status' => $status,
            'diajukan_oleh' => $pengaju->id, 'dasar' => 'SK uji', 'kedaluwarsa_pada' => now()->addDays(7),
            'diputuskan_oleh' => $status === 'menunggu' ? null : $pemutus->id, 'diputuskan_pada' => $status === 'menunggu' ? null : now(),
        ])->save();

        return $p->id;
    }
}
