<?php

namespace Tests\Feature\Antrean;

use App\Modules\Antrean\Models\Antrean;
use App\Modules\Antrean\Models\DisplayAntrean;
use App\Modules\MasterData\Models\JadwalPraktik;
use App\Modules\MasterData\Models\Poliklinik;
use App\Modules\MasterData\Models\UnitOrganisasi;
use App\Modules\Pendaftaran\Models\Pasien;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\AksesUji;
use Tests\Support\KlienSpa;
use Tests\Support\KontrakApi;
use Tests\Support\KunjunganUji;
use Tests\TestCase;

/** Tiket 2.8-be: GET /display/{area} dengan token perangkat; hanya nomor + inisial, nama poli netral, suara tanpa nama. */
class DisplayAntreanTest extends TestCase
{
    use AksesUji, KlienSpa, KontrakApi, KunjunganUji;

    private JadwalPraktik $jadwalPagi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanKunjungan();
        $this->jadwalPagi = $this->jadwal($this->dokter());
        $petugas = $this->petugasPendaftaran();
        $this->tugaskan($petugas, $this->buatPeran(['pendaftaran.antrean']));
        $this->masukSebagai($petugas);
    }

    private function antrean(JadwalPraktik $jadwal, array $pasien = []): Antrean
    {
        $p = $this->pasien();
        $p->forceFill($pasien)->save();
        $id = $this->daftarKunjungan($p, $jadwal)->assertCreated()->json('data.id');

        return Antrean::query()->where('kunjungan_id', $id)->sole();
    }

    private function token(string $kode, array $area = []): string
    {
        Artisan::call('antrean:token-display', ['kode' => $kode, '--area' => $area]);

        return trim(explode("\n", Artisan::output())[0]);
    }

    /** Permintaan perangkat: tanpa cookie sesi petugas dan tanpa guard yang tersisa dari permintaan sebelumnya. */
    private function tanpaSesi(string $uri, ?string $token, array $header = []): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $this->app['session']->driver()->flush();
        $this->withCredentials = false;
        $this->unencryptedCookies = $this->defaultHeaders = [];

        return $this->getJson($uri, [...$header, ...($token === null ? [] : ['Authorization' => 'Bearer '.$token])]);
    }

    private function display(string $area, ?string $token, array $header = []): TestResponse
    {
        $respons = $this->tanpaSesi("/api/v1/display/{$area}", $token, $header);
        if ($respons->getStatusCode() !== 304) {
            $this->assertSesuaiKontrak($respons, 'GET', '/display/{area}');
        }

        return $respons;
    }

    public function test_hanya_nomor_dan_inisial_poli_sensitif_netral_suara_tanpa_nama(): void
    {
        $unit = UnitOrganisasi::query()->create(['kode' => 'POLI-SENS', 'nama' => 'Poli Sensitif Uji', 'jenis' => 'poli', 'berlaku_dari' => '2026-01-01']);
        $sensitif = Poliklinik::query()->create(['kode' => 'JIWA', 'nama' => 'Poli Jiwa', 'unit_organisasi_id' => $unit->id]);
        $sensitif->forceFill(['nama_netral' => 'Klinik 7', 'antrean_prefiks' => 'K7', 'antrean_area' => 'uji'])->save();
        $jadwalSensitif = $this->jadwal($this->dokter(), ['poli_id' => $sensitif->id]);

        $biasa = $this->antrean($this->jadwalPagi, ['nama' => 'Siti Wahyuni Lubis']);
        $jiwa = $this->antrean($jadwalSensitif, ['nama' => 'Budi Santoso']);
        $tanpaIdentitas = $this->antrean($this->jadwalPagi, ['nama' => 'Mr. X', 'tanpa_identitas' => true]);
        $this->minta('POST', "/api/v1/antrean/{$jiwa->id}/panggil")->assertOk();
        $this->travel(1)->seconds();
        $this->minta('POST', "/api/v1/antrean/{$biasa->id}/panggil")->assertOk();

        $respons = $this->display('uji', $this->token('tv-uji'))->assertOk()
            ->assertJsonPath('data.area', ['kode' => 'uji', 'nama' => 'Klinik 7, Poli Contoh'])
            ->assertJsonPath('data.dipanggil.0.nomor_tampil', 'UJI-001')->assertJsonPath('data.dipanggil.0.inisial', 'S.W.')
            ->assertJsonPath('data.dipanggil.0.tujuan', 'Poli Contoh')
            ->assertJsonPath('data.dipanggil.0.teks_suara', 'Nomor U J I 0 0 1, silakan ke Poli Contoh')
            ->assertJsonPath('data.dipanggil.1.nomor_tampil', 'K7-001')->assertJsonPath('data.dipanggil.1.tujuan', 'Klinik 7')
            ->assertJsonPath('data.dipanggil.1.teks_suara', 'Nomor K 7 0 0 1, silakan ke Klinik 7')
            ->assertJsonPath('data.berikutnya', [['nomor_tampil' => 'UJI-002', 'inisial' => null]])
            ->assertJsonPath('data.interval_detik', 5);
        $this->assertStringContainsString('no-store', (string) $respons->headers->get('Cache-Control'));

        $isi = (string) $respons->getContent();
        $pasien = Pasien::query()->whereIn('nama', ['Siti Wahyuni Lubis', 'Budi Santoso', 'Mr. X'])->get();
        foreach ([...$pasien->pluck('nama'), ...$pasien->pluck('no_rm'), ...$pasien->pluck('id'), $biasa->id, $biasa->kunjungan_id, 'Jiwa', 'JIWA', '1990-01-01', 'Wahyuni'] as $rahasia) {
            $this->assertStringNotContainsString((string) $rahasia, $isi);
        }
        $this->assertSame($tanpaIdentitas->nomor_tampil, $respons->json('data.berikutnya.0.nomor_tampil'));
    }

    public function test_etag_tanpa_waktu_server_dan_berubah_saat_ada_panggilan(): void
    {
        $antrean = $this->antrean($this->jadwalPagi);
        $token = $this->token('tv-uji', ['uji']);
        $etag = (string) $this->display('uji', $token)->assertOk()->headers->get('ETag');

        $this->travel(7)->seconds();
        $this->display('uji', $token, ['If-None-Match' => $etag])->assertStatus(304);

        $this->minta('POST', "/api/v1/antrean/{$antrean->id}/panggil")->assertOk();
        $baru = $this->display('uji', $token, ['If-None-Match' => $etag])->assertOk()->assertJsonCount(1, 'data.dipanggil');
        $this->assertNotSame($etag, $baru->headers->get('ETag'));
    }

    public function test_token_perangkat_wajib_berkemampuan_display_dan_berarea(): void
    {
        $this->display('uji', null)->assertUnauthorized()->assertJsonPath('error.kode', 'belum_masuk');
        $this->display('uji', 'acak')->assertUnauthorized();
        $this->display('uji', 'bukan-uuid|rahasia')->assertUnauthorized();

        $this->display('uji', $this->token('tv-lain', ['lain']))->assertForbidden();
        $semua = $this->token('tv-semua');
        $this->display('tidak-ada', $semua)->assertNotFound();

        $tanpaKemampuan = DisplayAntrean::query()->where('kode', 'tv-semua')->sole()->createToken('uji', ['lain'])->plainTextToken;
        $this->display('uji', $tanpaKemampuan)->assertForbidden();

        // Token display tidak berlaku di endpoint petugas (hanya sesi cookie).
        $this->tanpaSesi('/api/v1/antrean?tanggal='.self::SENIN.'&poli_id='.$this->poli->id, $semua, ['Origin' => self::ORIGIN])->assertUnauthorized();

        DB::table('kunjungan.display_antrean')->where('kode', 'tv-semua')->update(['status' => 'nonaktif']);
        $this->display('uji', $semua)->assertUnauthorized();

        $cabut = $this->token('tv-cabut');
        $this->display('uji', $cabut)->assertOk();
        $this->artisan('antrean:token-display', ['kode' => 'tv-cabut', '--cabut' => true])->assertSuccessful();
        $this->display('uji', $cabut)->assertUnauthorized();
        $this->assertAuditTerakhir('antrean.token_display_dicabut', DisplayAntrean::query()->where('kode', 'tv-cabut')->value('id'));

        // Token baru mencabut token lama perangkat yang sama.
        $lama = $this->token('tv-ulang');
        $this->token('tv-ulang');
        $this->display('uji', $lama)->assertUnauthorized();
        $this->assertSame(1, DisplayAntrean::query()->where('kode', 'tv-ulang')->sole()->tokens()->count());
    }

    public function test_batas_laju_per_perangkat(): void
    {
        config(['rate_limit.display.per_perangkat_per_menit' => 2]);
        $token = $this->token('tv-uji');
        $this->display('uji', $token)->assertOk();
        $this->display('uji', $token)->assertOk();
        $this->tanpaSesi('/api/v1/display/uji', $token)->assertStatus(429);
        // Perangkat lain di IP yang sama tidak ikut tertahan.
        $this->display('uji', $this->token('tv-lain'))->assertOk();
    }
}
