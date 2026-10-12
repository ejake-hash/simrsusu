<?php

namespace Tests\Feature\Integrasi;

use App\Modules\Integrasi\Application\KelolaKredensial;
use App\Modules\Integrasi\Application\KotakKeluar;
use App\Modules\Integrasi\Application\LayananSatusehat;
use App\Modules\Integrasi\Application\PengirimKiriman;
use App\Modules\Integrasi\Domain\SistemIntegrasi;
use App\Modules\Integrasi\Infrastructure\KlienSatusehat;
use App\Modules\Integrasi\Infrastructure\SimulatorIntegrasi;
use App\Modules\Integrasi\Jobs\KirimKiriman;
use App\Modules\Integrasi\Jobs\ProsesKotakKeluar;
use App\Modules\MasterData\Models\UnitOrganisasi;
use App\Modules\Sdm\Models\Pegawai;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Testing\Fakes\QueueFake;
use InvalidArgumentException;
use Tests\Support\AksesUji;
use Tests\Support\KlienSpa;
use Tests\TestCase;

/**
 * Tiket 1.9 butir 4 (catatan lapangan §15–§19, terhadap simulator K8): Location berjenjang gedung → lantai → ruang dari
 * 1.8b, Organization dari unit 1.8a, IHS Practitioner dari NIK pegawai, duplikat 20002, token kedaluwarsa, IHS pasien,
 * KFA, circuit breaker baca sinkron, dan log tanpa NIK/nama. Data sintetis.
 */
class SatusehatTest extends TestCase
{
    use AksesUji, KlienSpa;

    private const DASAR = 'SK Direktur 4/2026';

    private const ORG = 'ORGSIM001';

    private int $diproses = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        SimulatorIntegrasi::atur(SistemIntegrasi::Satusehat, 'normal');
        $pengelola = $this->buatPengguna();
        $this->tugaskan($pengelola, $this->buatPeran(['master_data.organisasi']));
        $this->masukSebagai($pengelola);
    }

    public function test_location_berjenjang_dan_sinkron_ulang_hanya_yang_berubah(): void
    {
        $this->aktifkan();
        [$gedung, $lantai, $ruang] = $this->rantaiLokasi();
        $tutup = $this->lokasi(null, 'gedung', 'C');
        DB::table('inti.lokasi')->where('id', $tutup)->update(['status' => 'ditutup']);

        $this->artisan('integrasi:sinkron-satusehat')->expectsOutput('Organization: 0, Location: 3, Practitioner: 0')->assertSuccessful();
        $this->pekerja();

        $loc = SimulatorIntegrasi::resourceSatusehat('sandbox', 'Location');
        $this->assertCount(3, $loc);
        $id = DB::table('integrasi.id_eksternal')->where('jenis_lokal', 'lokasi')->pluck('id_luar', 'id_lokal');
        $this->assertSame(['bu', 'lvl', 'ro'], [$loc[$id[$gedung]]['physicalType']['coding'][0]['code'], $loc[$id[$lantai]]['physicalType']['coding'][0]['code'],
            $loc[$id[$ruang]]['physicalType']['coding'][0]['code']]);
        $this->assertArrayNotHasKey('partOf', $loc[$id[$gedung]]);
        $this->assertSame('Location/'.$id[$gedung], $loc[$id[$lantai]]['partOf']['reference']);
        $this->assertSame('Location/'.$id[$lantai], $loc[$id[$ruang]]['partOf']['reference']);
        $this->assertSame([['system' => 'http://sys-ids.kemkes.go.id/location/'.self::ORG, 'value' => $ruang]], $loc[$id[$ruang]]['identifier']);
        $this->assertSame(['Organization/'.self::ORG, 'B-3-304', 'active'], [$loc[$id[$ruang]]['managingOrganization']['reference'],
            $loc[$id[$ruang]]['description'], $loc[$id[$ruang]]['status']]);
        // Sandbox: kolom tampilan (id produksi) tidak diisi.
        $this->assertNull(DB::table('inti.lokasi')->where('id', $ruang)->value('satusehat_lokasi_id'));

        $this->artisan('integrasi:sinkron-satusehat')->expectsOutput('Organization: 0, Location: 0, Practitioner: 0')->assertSuccessful();

        // Lokasi diubah sesudah sinkron: dikirim ulang (PUT), id tetap.
        DB::table('inti.lokasi')->where('id', $ruang)->update(['nama' => 'Ruang 304 Baru', 'status' => 'renovasi', 'diubah_pada' => now()->addSecond()]);
        $this->artisan('integrasi:sinkron-satusehat')->expectsOutput('Organization: 0, Location: 1, Practitioner: 0')->assertSuccessful();
        $this->pekerja();
        $loc = SimulatorIntegrasi::resourceSatusehat('sandbox', 'Location');
        $this->assertCount(3, $loc);
        $this->assertSame(['Ruang 304 Baru', 'suspended'], [$loc[$id[$ruang]]['name'], $loc[$id[$ruang]]['status']]);
        $this->assertSame('PUT', DB::table('integrasi.log_api')->where('tujuan', 'satusehat')->orderByDesc('terjadi_pada')->value('metode'));
    }

    public function test_lokasi_tertahan_sampai_induk_terdaftar(): void
    {
        $this->aktifkan();
        [, , $ruang] = $this->rantaiLokasi();
        $kiriman = app(KotakKeluar::class)->antrekan(SistemIntegrasi::Satusehat, 'Location', 'lokasi', $ruang);
        $this->pekerja();
        $k = DB::table('integrasi.kotak_keluar')->where('id', $kiriman)->first();
        $this->assertSame(['tertahan', 'induk_belum_terdaftar'], [$k->status, $k->penyebab_tertahan_kode]);
        $this->assertStringContainsString('B-3', (string) $k->penyebab_tertahan_pesan);

        // Sinkron mendaftarkan induk; kiriman ruang yang tertahan tidak digandakan, lalu dilepas pada putaran berikutnya.
        $this->artisan('integrasi:sinkron-satusehat')->expectsOutput('Organization: 0, Location: 2, Practitioner: 0')->assertSuccessful();
        $this->pekerja();
        $this->assertTrue(now()->gte(DB::table('integrasi.kotak_keluar')->where('id', $kiriman)->value('coba_lagi_pada')));
        (new ProsesKotakKeluar)->handle(app(PengirimKiriman::class));
        $this->pekerja();
        $this->assertSame('terkirim', DB::table('integrasi.kotak_keluar')->where('id', $kiriman)->value('status'));
        $this->assertCount(3, SimulatorIntegrasi::resourceSatusehat('sandbox', 'Location'));
    }

    public function test_duplikat_20002_dicari_lewat_identifier_lalu_diperbarui(): void
    {
        $this->aktifkan();
        $gedung = $this->lokasi(null, 'gedung', 'B');
        // Sudah terdaftar sebelumnya (mis. instalasi lama) dengan identifier yang sama.
        $lama = app(KlienSatusehat::class)->panggil('sandbox', $this->kredensial(), 'POST', KlienSatusehat::PATH_FHIR.'Location', [
            'resourceType' => 'Location', 'identifier' => [['system' => 'http://sys-ids.kemkes.go.id/location/'.self::ORG, 'value' => $gedung]],
            'name' => 'Nama Lama', 'physicalType' => ['coding' => [['code' => 'bu']]], 'managingOrganization' => ['reference' => 'Organization/'.self::ORG],
        ])['json']['id'];
        DB::table('integrasi.log_api')->update(['endpoint' => '/lama']);

        $this->artisan('integrasi:sinkron-satusehat')->assertSuccessful();
        $this->pekerja();
        $this->assertSame($lama, DB::table('integrasi.id_eksternal')->where('id_lokal', $gedung)->value('id_luar'));
        $loc = SimulatorIntegrasi::resourceSatusehat('sandbox', 'Location');
        $this->assertSame(['Uji B'], array_column($loc, 'name'));
        $log = DB::table('integrasi.log_api')->where('endpoint', '<>', '/lama')->orderBy('terjadi_pada')->get();
        $this->assertSame([['POST', 400], ['GET', 200], ['PUT', 200]], $log->map(fn ($l) => [$l->metode, $l->kode_http])->all());
        $this->assertSame('terkirim', DB::table('integrasi.kotak_keluar')->where('agregat_id', $gedung)->value('status'));
    }

    public function test_token_kedaluwarsa_diminta_ulang_sekali(): void
    {
        $this->aktifkan();
        $gedung = $this->lokasi(null, 'gedung', 'B');
        $this->artisan('integrasi:sinkron-satusehat')->assertSuccessful();
        $this->pekerja();
        SimulatorIntegrasi::cabutTokenSatusehat();
        DB::table('inti.lokasi')->where('id', $gedung)->update(['nama' => 'Gedung B Baru', 'diubah_pada' => now()->addSecond()]);
        $sebelum = DB::table('integrasi.log_api')->count();

        $this->artisan('integrasi:sinkron-satusehat')->assertSuccessful();
        $this->pekerja();
        $log = DB::table('integrasi.log_api')->orderBy('terjadi_pada')->offset($sebelum)->limit(10)->get();
        $this->assertSame([['PUT', 401], ['POST', 200], ['PUT', 200]], $log->map(fn ($l) => [$l->metode, $l->kode_http])->all());
        $this->assertSame('/oauth2/v1/accesstoken', $log[1]->endpoint);
        $this->assertSame(['Gedung B Baru'], array_column(SimulatorIntegrasi::resourceSatusehat('sandbox', 'Location'), 'name'));
    }

    public function test_organization_unit_akar_bagian_dari_rs_dan_anak_bagian_dari_induk(): void
    {
        $this->aktifkan();
        $akar = UnitOrganisasi::query()->create(['kode' => 'IRNA', 'nama' => 'Instalasi Rawat Inap', 'jenis' => 'instalasi', 'berlaku_dari' => '2026-01-01']);
        $anak = UnitOrganisasi::query()->create(['kode' => 'MELATI', 'nama' => 'Ruang Melati', 'jenis' => 'ruang', 'induk_id' => $akar->id, 'berlaku_dari' => '2026-01-01']);
        UnitOrganisasi::query()->create(['kode' => 'LAMA', 'nama' => 'Unit Lama', 'jenis' => 'bagian', 'status' => 'nonaktif', 'berlaku_dari' => '2026-01-01']);

        $this->artisan('integrasi:sinkron-satusehat')->expectsOutput('Organization: 2, Location: 0, Practitioner: 0')->assertSuccessful();
        $this->pekerja();
        $org = SimulatorIntegrasi::resourceSatusehat('sandbox', 'Organization');
        $id = DB::table('integrasi.id_eksternal')->where('jenis_lokal', 'unit_organisasi')->pluck('id_luar', 'id_lokal');
        $this->assertSame('Organization/'.self::ORG, $org[$id[$akar->id]]['partOf']['reference']);
        $this->assertSame('Organization/'.$id[$akar->id], $org[$id[$anak->id]]['partOf']['reference']);
        $this->assertSame(['dept', 'http://sys-ids.kemkes.go.id/organization/'.self::ORG, true],
            [$org[$id[$anak->id]]['type'][0]['coding'][0]['code'], $org[$id[$anak->id]]['identifier'][0]['system'], $org[$id[$anak->id]]['active']]);
    }

    public function test_ihs_practitioner_dari_nik_dan_kolom_produksi(): void
    {
        $this->aktifkan('produksi');
        $dokter = $this->pegawai('dokter', '9900000000000002');
        $perawat = $this->pegawai('perawat', null);
        $bidan = $this->pegawai('bidan', '9900000000000099');
        $this->pegawai('non_kesehatan', '9900000000000003');
        $gedung = $this->lokasi(null, 'gedung', 'B');

        $this->artisan('integrasi:sinkron-satusehat')->expectsOutput('Organization: 0, Location: 1, Practitioner: 3')->assertSuccessful();
        $this->pekerja();
        $status = DB::table('integrasi.kotak_keluar')->where('jenis_resource', 'Practitioner')->pluck('status', 'agregat_id');
        $this->assertSame(['terkirim', 'tertahan', 'gagal'], [$status[$dokter], $status[$perawat], $status[$bidan]]);
        $this->assertSame('nik_kosong', DB::table('integrasi.kotak_keluar')->where('agregat_id', $perawat)->value('penyebab_tertahan_kode'));
        $this->assertSame('N99000000002', DB::table('sdm.pegawai')->where('id', $dokter)->value('ihs_practitioner_id'));
        $this->assertNotNull(DB::table('inti.lokasi')->where('id', $gedung)->value('satusehat_lokasi_id'));

        // NIK dan nama tidak masuk log.
        $log = DB::table('integrasi.log_api')->where('endpoint', 'like', '%Practitioner%')->get();
        $this->assertSame(['/fhir-r4/v1/Practitioner'], $log->pluck('endpoint')->unique()->values()->all());
        foreach ($log as $l) {
            foreach (['9900000000000002', '9900000000000099', 'DOKTER SINTETIS'] as $rahasia) {
                $this->assertStringNotContainsString($rahasia, (string) $l->respons.$l->permintaan.$l->pesan);
            }
        }
        // Struktur respons tetap terbaca untuk diagnosis (id berpola nomor ikut disamarkan).
        $this->assertStringContainsString('"resourceType":"Practitioner"', (string) $log->firstWhere('berhasil', true)?->respons);

        // Sinkron berikutnya: yang tertahan dan yang gagal tanpa perubahan tidak diantrekan lagi.
        $this->artisan('integrasi:sinkron-satusehat')->expectsOutput('Organization: 0, Location: 0, Practitioner: 0')->assertSuccessful();
    }

    public function test_ihs_pasien_kfa_dan_circuit_breaker_baca_sinkron(): void
    {
        $this->aktifkan();
        $layanan = app(LayananSatusehat::class);
        $this->assertSame(['berhasil' => true, 'ihs' => 'P99000000001', 'pesan' => null, 'lingkungan' => 'sandbox'], $layanan->ihsPasien('9900000000000001'));
        $this->assertSame([false, null], array_values(array_slice($layanan->ihsPasien('9900000000000077'), 0, 2)));
        $this->assertStringNotContainsString('9900000000000001', DB::table('integrasi.log_api')->get()->toJson());

        $kfa = $layanan->cariKfa('parasetamol');
        $this->assertSame([true, '93000001'], [$kfa['berhasil'], $kfa['data'][0]['kfa_code']]);
        $sebelum = DB::table('integrasi.log_api')->count();
        $this->assertTrue($layanan->cariKfa('Parasetamol')['berhasil']);
        $this->assertSame($sebelum, DB::table('integrasi.log_api')->count(), 'Hasil KFA dari cache.');

        SimulatorIntegrasi::atur(SistemIntegrasi::Satusehat, 'mati');
        $ambang = (int) config('integrasi.circuit_breaker.ambang');
        $hasil = array_map(fn () => $layanan->ihsPasien('9900000000000001')['berhasil'], range(1, $ambang));
        $this->assertSame(array_fill(0, $ambang, false), $hasil);
        $this->assertSame('terbuka', DB::table('integrasi.status_sistem')->where('tujuan', 'satusehat')->value('cb_status'));
        $sebelum = DB::table('integrasi.log_api')->count();
        $this->assertStringContainsString('tidak tersambung', (string) $layanan->ihsPasien('9900000000000001')['pesan']);
        $this->assertSame($sebelum, DB::table('integrasi.log_api')->count(), 'Sirkuit terbuka: gagal cepat tanpa memanggil.');

        foreach (['ab', str_repeat('x', 51), 'obat;drop'] as $kata) {
            try {
                $layanan->cariKfa($kata);
                $this->fail("Kata kunci KFA '$kata' seharusnya ditolak.");
            } catch (InvalidArgumentException) {
            }
        }
        $this->expectException(InvalidArgumentException::class);
        $layanan->ihsPasien('99/../Patient');
    }

    public function test_tt_ditutup_dan_perubahan_tanpa_stempel_waktu_tetap_tersinkron(): void
    {
        $this->aktifkan();
        [, , $ruang] = $this->rantaiLokasi();
        $tt = $this->tempatTidur($ruang);
        $lokasiTt = DB::table('inti.tempat_tidur')->where('id', $tt)->value('lokasi_id');
        $this->artisan('integrasi:sinkron-satusehat')->expectsOutput('Organization: 0, Location: 4, Practitioner: 0')->assertSuccessful();
        $this->pekerja();
        $id = DB::table('integrasi.id_eksternal')->where('id_lokal', $lokasiTt)->value('id_luar');
        $this->assertSame(['bd', 'active'], [SimulatorIntegrasi::resourceSatusehat('sandbox', 'Location')[$id]['physicalType']['coding'][0]['code'],
            SimulatorIntegrasi::resourceSatusehat('sandbox', 'Location')[$id]['status']]);

        // TT ditutup lewat masa berlaku (inti.lokasi tidak berubah sama sekali).
        DB::table('inti.tempat_tidur')->where('id', $tt)->update(['berlaku_dari' => '2026-01-01', 'berlaku_sampai' => '2026-01-02']);
        // Perubahan nama tanpa mengubah diubah_pada (mis. presisi detik atau transaksi yang commit belakangan).
        DB::table('inti.lokasi')->where('id', $ruang)->update(['nama' => 'Ruang Ganti Nama']);
        $this->artisan('integrasi:sinkron-satusehat')->expectsOutput('Organization: 0, Location: 2, Practitioner: 0')->assertSuccessful();
        $this->pekerja();
        $loc = SimulatorIntegrasi::resourceSatusehat('sandbox', 'Location');
        $this->assertSame('inactive', $loc[$id]['status']);
        $this->assertSame('Ruang Ganti Nama', $loc[DB::table('integrasi.id_eksternal')->where('id_lokal', $ruang)->value('id_luar')]['name']);
    }

    public function test_gagal_diulang_sesudah_kredensial_dibetulkan(): void
    {
        $this->aktifkan();
        $gedung = $this->lokasi(null, 'gedung', 'B');
        SimulatorIntegrasi::atur(SistemIntegrasi::Satusehat, 'tolak');
        $this->artisan('integrasi:sinkron-satusehat')->assertSuccessful();
        $this->pekerja();
        $this->assertSame('gagal', DB::table('integrasi.kotak_keluar')->where('agregat_id', $gedung)->value('status'));
        // Tanpa perubahan apa pun: tidak diulang (tidak membanjiri kotak keluar).
        $this->artisan('integrasi:sinkron-satusehat')->expectsOutput('Organization: 0, Location: 0, Practitioner: 0')->assertSuccessful();

        SimulatorIntegrasi::atur(SistemIntegrasi::Satusehat, 'normal');
        $this->travel(1)->seconds();
        $this->aktifkan();
        $this->artisan('integrasi:sinkron-satusehat')->expectsOutput('Organization: 0, Location: 1, Practitioner: 0')->assertSuccessful();
        $this->pekerja();
        $this->assertSame(['gagal', 'terkirim'], DB::table('integrasi.kotak_keluar')->where('agregat_id', $gedung)->orderBy('dibuat_pada')->pluck('status')->all());

        // Gagal yang lebih tua dari 24 jam juga diulang.
        $nakes = $this->pegawai('dokter', '9900000000000099');
        $this->artisan('integrasi:sinkron-satusehat')->assertSuccessful();
        $this->pekerja();
        $this->assertSame('gagal', DB::table('integrasi.kotak_keluar')->where('agregat_id', $nakes)->value('status'));
        $this->travel(25)->hours();
        $this->artisan('integrasi:sinkron-satusehat')->expectsOutput('Organization: 0, Location: 0, Practitioner: 1')->assertSuccessful();
    }

    public function test_organization_tertahan_karena_induk_dan_induk_nonaktif(): void
    {
        $this->aktifkan();
        $akar = UnitOrganisasi::query()->create(['kode' => 'IRNA', 'nama' => 'Instalasi Rawat Inap', 'jenis' => 'instalasi', 'berlaku_dari' => '2026-01-01']);
        $anak = UnitOrganisasi::query()->create(['kode' => 'MELATI', 'nama' => 'Ruang Melati', 'jenis' => 'ruang', 'induk_id' => $akar->id, 'berlaku_dari' => '2026-01-01']);
        $lama = UnitOrganisasi::query()->create(['kode' => 'LAMA', 'nama' => 'Unit Lama', 'jenis' => 'bagian', 'status' => 'nonaktif', 'berlaku_dari' => '2026-01-01']);
        $yatim = UnitOrganisasi::query()->create(['kode' => 'YATIM', 'nama' => 'Unit Yatim', 'jenis' => 'ruang', 'induk_id' => $lama->id, 'berlaku_dari' => '2026-01-01']);

        $kiriman = app(KotakKeluar::class)->antrekan(SistemIntegrasi::Satusehat, 'Organization', 'unit_organisasi', $anak->id);
        $this->pekerja();
        $this->assertSame('induk_belum_terdaftar', DB::table('integrasi.kotak_keluar')->where('id', $kiriman)->value('penyebab_tertahan_kode'));

        $this->artisan('integrasi:sinkron-satusehat')->expectsOutput('Organization: 2, Location: 0, Practitioner: 0')->assertSuccessful();
        $this->pekerja();
        (new ProsesKotakKeluar)->handle(app(PengirimKiriman::class));
        $this->pekerja();
        $this->assertSame('terkirim', DB::table('integrasi.kotak_keluar')->where('id', $kiriman)->value('status'));
        $this->assertSame(['tertahan', 'induk_ditutup'], array_values((array) DB::table('integrasi.kotak_keluar')->where('agregat_id', $yatim->id)
            ->first(['status', 'penyebab_tertahan_kode'])));
        // Anak yang ikut dinonaktifkan sebelum terdaftar: kirimannya selesai tanpa memanggil SATUSEHAT.
        DB::table('inti.unit_organisasi')->where('id', $yatim->id)->update(['status' => 'nonaktif']);
        DB::table('integrasi.kotak_keluar')->where('agregat_id', $yatim->id)->update(['coba_lagi_pada' => now()->subSecond()]);
        $sebelum = DB::table('integrasi.log_api')->count();
        (new ProsesKotakKeluar)->handle(app(PengirimKiriman::class));
        $this->pekerja();
        $this->assertSame('terkirim', DB::table('integrasi.kotak_keluar')->where('agregat_id', $yatim->id)->value('status'));
        $this->assertSame($sebelum, DB::table('integrasi.log_api')->count());
        $this->assertCount(2, SimulatorIntegrasi::resourceSatusehat('sandbox', 'Organization'));
    }

    public function test_pindah_lingkungan_mendaftarkan_ulang_di_produksi(): void
    {
        $this->aktifkan();
        $gedung = $this->lokasi(null, 'gedung', 'B');
        $this->artisan('integrasi:sinkron-satusehat')->assertSuccessful();
        $this->pekerja();
        $this->assertNull(DB::table('inti.lokasi')->where('id', $gedung)->value('satusehat_lokasi_id'));

        $this->aktifkan('produksi');
        $this->artisan('integrasi:sinkron-satusehat')->expectsOutput('Organization: 0, Location: 1, Practitioner: 0')->assertSuccessful();
        $this->pekerja();
        $id = DB::table('integrasi.id_eksternal')->where('id_lokal', $gedung)->pluck('id_luar', 'lingkungan');
        $this->assertNotSame($id['sandbox'], $id['produksi']);
        $this->assertSame($id['produksi'], DB::table('inti.lokasi')->where('id', $gedung)->value('satusehat_lokasi_id'));
        $this->assertCount(1, SimulatorIntegrasi::resourceSatusehat('produksi', 'Location'));
    }

    public function test_gangguan_saat_kirim_ulang_dicoba_lagi_tanpa_kiriman_ganda(): void
    {
        $this->aktifkan();
        $gedung = $this->lokasi(null, 'gedung', 'B');
        $this->artisan('integrasi:sinkron-satusehat')->assertSuccessful();
        $this->pekerja();
        $kiriman = DB::table('integrasi.kotak_keluar')->where('agregat_id', $gedung)->value('id');

        SimulatorIntegrasi::atur(SistemIntegrasi::Satusehat, 'gangguan');
        DB::table('inti.lokasi')->where('id', $gedung)->update(['nama' => 'Gedung B2']);
        $this->artisan('integrasi:sinkron-satusehat')->assertSuccessful();
        $this->pekerja();
        $baru = DB::table('integrasi.kotak_keluar')->where('agregat_id', $gedung)->where('id', '<>', $kiriman)->first();
        $this->assertSame(['menunggu', 1], [$baru->status, (int) $baru->percobaan]);
        $this->artisan('integrasi:sinkron-satusehat')->expectsOutput('Organization: 0, Location: 0, Practitioner: 0')->assertSuccessful();

        SimulatorIntegrasi::atur(SistemIntegrasi::Satusehat, 'normal');
        DB::table('integrasi.status_sistem')->where('tujuan', 'satusehat')->update(['cb_status' => 'tertutup', 'gagal_beruntun' => 0]);
        DB::table('integrasi.kotak_keluar')->where('id', $baru->id)->update(['coba_lagi_pada' => now()->subSecond()]);
        app(PengirimKiriman::class)->proses($baru->id);
        $this->assertSame('terkirim', DB::table('integrasi.kotak_keluar')->where('id', $baru->id)->value('status'));
        $this->assertSame(['Gedung B2'], array_column(SimulatorIntegrasi::resourceSatusehat('sandbox', 'Location'), 'name'));
    }

    private function tempatTidur(string $ruang): string
    {
        $unit = UnitOrganisasi::query()->create(['kode' => 'IRNA', 'nama' => 'Instalasi Rawat Inap', 'jenis' => 'instalasi', 'berlaku_dari' => '2026-01-01']);
        $bangsal = $this->minta('POST', '/api/v1/bangsal', ['kode' => 'MELATI', 'nama' => 'Bangsal Melati', 'unit_organisasi_id' => $unit->id,
            'dasar_perubahan' => self::DASAR])->assertCreated()->json('data.id');
        $kelas = $this->minta('POST', '/api/v1/kelas-rawat', ['kode' => 'III', 'nama' => 'Kelas III', 'dasar_perubahan' => self::DASAR])->assertCreated()->json('data.id');
        $kamar = $this->minta('POST', '/api/v1/kamar', ['bangsal_id' => $bangsal, 'lokasi_id' => $ruang, 'dasar_perubahan' => self::DASAR])->assertCreated()->json('data.id');
        // Unit dibuat hanya untuk bangsal; tidak ikut sinkron Organization di tes ini.
        DB::table('inti.unit_organisasi')->where('id', $unit->id)->update(['status' => 'nonaktif']);

        return $this->minta('POST', '/api/v1/tempat-tidur', ['kamar_id' => $kamar, 'kode' => '1', 'kelas_rawat_id' => $kelas, 'dasar_perubahan' => self::DASAR])
            ->assertCreated()->json('data.id');
    }

    private function aktifkan(string $lingkungan = 'sandbox'): void
    {
        app(KelolaKredensial::class)->simpan(SistemIntegrasi::Satusehat, $lingkungan, $this->kredensial(), true, false, $this->buatPengguna()->id);
    }

    /** @return array<string, string> */
    private function kredensial(): array
    {
        return ['client_id' => 'klien-sintetis', 'client_secret' => 'rahasia-sintetis-panjang', 'organization_id' => self::ORG];
    }

    /** @return array{0: string, 1: string, 2: string} gedung, lantai, ruang */
    private function rantaiLokasi(): array
    {
        $gedung = $this->lokasi(null, 'gedung', 'B');
        $lantai = $this->lokasi($gedung, 'lantai', '3');

        return [$gedung, $lantai, $this->lokasi($lantai, 'ruang', '304', 'rawat_inap')];
    }

    private function lokasi(?string $induk, string $jenis, string $kode, ?string $fungsi = null): string
    {
        return $this->minta('POST', '/api/v1/lokasi', ['induk_id' => $induk, 'jenis' => $jenis, 'kode' => $kode, 'nama' => 'Uji '.$kode,
            'fungsi' => $fungsi, 'dasar_perubahan' => self::DASAR])->assertCreated()->json('data.id');
    }

    private function pegawai(string $profesi, ?string $nik): string
    {
        $pegawai = Pegawai::query()->create(['nama' => 'Pegawai Sintetis '.$profesi, 'profesi' => $profesi]);
        $pegawai->aturNik($nik);
        $pegawai->save();

        return $pegawai->id;
    }

    private function pekerja(): void
    {
        do {
            /** @var QueueFake $antrean */
            $antrean = Queue::getFacadeRoot();
            $jobs = $antrean->pushed(KirimKiriman::class)->values();
            foreach ($jobs->slice($this->diproses) as $job) {
                $job->handle(app(PengirimKiriman::class));
            }
            $baru = $jobs->count() > $this->diproses;
            $this->diproses = $jobs->count();
        } while ($baru);
    }
}
