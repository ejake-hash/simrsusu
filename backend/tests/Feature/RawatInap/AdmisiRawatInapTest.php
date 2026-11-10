<?php

namespace Tests\Feature\RawatInap;

use App\Core\Http\ApiError;
use App\Core\Http\SuntinganBerversi;
use App\Modules\Akses\Domain\Cakupan;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\MasterData\Application\KelolaTempatTidur;
use App\Modules\MasterData\Domain\PemeriksaPenempatan;
use App\Modules\MasterData\Models\Bangsal;
use App\Modules\MasterData\Models\JadwalPraktik;
use App\Modules\MasterData\Models\Kamar;
use App\Modules\MasterData\Models\KelasRawat;
use App\Modules\MasterData\Models\Lokasi;
use App\Modules\MasterData\Models\TempatTidur;
use App\Modules\MasterData\Models\UnitOrganisasi;
use App\Modules\Pendaftaran\Models\Pasien;
use App\Modules\RawatInap\Application\PemeriksaPenempatanTempatTidur;
use App\Modules\Sdm\Models\Pegawai;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\AksesUji;
use Tests\Support\KlienSpa;
use Tests\Support\KontrakApi;
use Tests\Support\KunjunganUji;
use Tests\TestCase;

/**
 * Tiket 2.29: penempatan TT POST /permintaan-tempat-tidur/{id}/tempatkan, batal permintaan
 * POST /permintaan-tempat-tidur/{id}/batal, pindah ruang POST /kunjungan/{id}/pindah-ruang (kontrak v0.37.0; FR-08-06,
 * FR-08-04, FR-00-01, FR-00-03), episode rawat inap (7.00.2), satu permintaan/penempatan per pasien, pemeriksa penempatan
 * nyata untuk penutupan TT, status TT `melewati_rencana_lama_rawat`, hari rawat di peta. Data sintetis; jam dibekukan
 * Senin 2 Nov 2026 07.00 zona RS (KunjunganUji). Bangsal UJI29-BA (unit ruang RA): TT 101-1, 101-2 kelas K3, 101-3 kelas
 * K1; bangsal UJI29-BB (unit ruang RB): TT 201-1, 201-2 kelas K3.
 */
class AdmisiRawatInapTest extends TestCase
{
    use AksesUji, KlienSpa, KontrakApi, KunjunganUji;

    private const URL = '/api/v1/permintaan-tempat-tidur';

    private Pengguna $dokterIgd;

    private Pegawai $pegawaiIgd;

    private Pengguna $dokterPoli;

    private Pengguna $admisi;

    private Pengguna $dokterRanap;

    /** @var array<string, string> kode => id */
    private array $id = [];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->siapkanKunjungan();
        DB::table('inti.modul_aktif')->insertOrIgnore(['id' => (string) Str::uuid7(), 'modul_no' => 7]);
        $this->siapkanTempatTidur();

        $this->pegawaiIgd = $this->dokter();
        $this->dokterIgd = $this->akunDokter($this->pegawaiIgd);
        $this->tugaskan($this->dokterIgd, $this->buatPeran(['igd.pendaftaran', 'igd.disposisi', 'igd.papan']));
        $pegawaiPoli = $this->dokter();
        $this->id['jadwal'] = $this->jadwal($pegawaiPoli)->id;
        $this->dokterPoli = $this->akunDokter($pegawaiPoli);
        $this->tugaskan($this->dokterPoli, $this->buatPeran(['rawat_jalan.pemeriksaan']));
        $this->admisi = $this->buatPengguna();
        $this->tugaskan($this->admisi, $this->buatPeran(['pendaftaran.admisi', 'rawat_inap.peta_tempat_tidur', 'rawat_inap.penempatan']));
        $this->dokterRanap = $this->akunDokter($this->dokter());
        $this->tugaskan($this->dokterRanap, $this->buatPeran(['rawat_inap.pindah_ruang', 'igd.disposisi', 'rawat_inap.peta_tempat_tidur']));
    }

    public function test_penempatan_menutup_permintaan_tt_terisi_dan_episode_menggabung_igd_poli_hari_sama(): void
    {
        $pasien = $this->pasien();
        $poli = $this->kunjunganPoli($pasien);
        $this->masukSebagai($this->dokterIgd);
        $igd = (string) $this->minta('POST', '/api/v1/kunjungan-igd', ['pasien_id' => $pasien->id, 'cara_bayar' => 'non_jkn'])->assertCreated()->json('data.id');
        $episodePoli = (string) DB::table('kunjungan.kunjungan')->where('id', $poli)->value('episode_id');

        // IGD lewat 6 jam tanpa disposisi: episode IGD > 6 jam terpisah dari episode rawat jalan harian poli.
        $this->travelTo(Carbon::parse(self::SENIN.' 13:30', (string) config('instalasi.zona_waktu')));
        $this->artisan('kunjungan:episode-igd')->assertSuccessful();
        $episodeIgd = (string) DB::table('kunjungan.kunjungan')->where('id', $igd)->value('episode_id');
        $this->assertNotSame($episodePoli, $episodeIgd);
        $this->assertSame('igd_lebih_6_jam', DB::table('kunjungan.episode')->where('id', $episodeIgd)->value('jenis'));

        $this->disposisiRawatInap($igd);
        $jamSpri = now()->copy();
        $permintaan = $this->permintaanDari($igd);
        $this->travel(20)->minutes();
        $this->masukSebagai($this->admisi);
        $this->alokasi($permintaan, $this->id['UJI29-101-1'])->assertOk();
        $this->travel(10)->minutes();
        $respons = $this->tempatkan($permintaan, ['serah_terima' => 'Infus terpasang, GCS 15 (uji sintetis)'])->assertCreated()
            ->assertJsonPath('data.episode.id', $episodeIgd)->assertJsonPath('data.episode.jenis', 'rawat_inap')
            ->assertJsonPath('data.penempatan.tempat_tidur.kode', 'UJI29-101-1')->assertJsonPath('data.penempatan.tempat_tidur.bangsal.kode', 'UJI29-BA')
            ->assertJsonPath('data.penempatan.ditempatkan_oleh.id', $this->admisi->id)->assertJsonPath('data.kelas_dirawat.kode', 'UJI29-K3')
            ->assertJsonPath('data.asal', 'igd')->assertJsonPath('data.permintaan_tempat_tidur_id', $permintaan)
            ->assertJsonPath('data.kunjungan.status', 'dilayani')->assertJsonPath('data.hari_rawat', 1)->assertJsonPath('data.pasien.id', $pasien->id);
        $this->assertSesuaiKontrak($respons, 'POST', '/permintaan-tempat-tidur/{id}/tempatkan');
        $this->assertStringContainsString('no-store', (string) $respons->headers->get('Cache-Control'));
        $this->assertStringStartsWith('RI-20261102-', (string) $respons->json('data.kunjungan.nomor'));
        // Awal rawat inap = jam SPRI (disposisi), penempatan = jam server.
        $this->assertTrue(Carbon::parse((string) $respons->json('data.mulai_pada'))->equalTo($jamSpri));
        $this->assertTrue(Carbon::parse((string) $respons->json('data.penempatan.mulai_pada'))->equalTo(now()));
        $ri = (string) $respons->json('data.kunjungan.id');

        // Permintaan ditempatkan (keluar antrean), TT terisi, riwayat status sumber penempatan.
        $this->assertSame('ditempatkan', DB::table('kunjungan.permintaan_tempat_tidur')->where('id', $permintaan)->value('status'));
        $this->assertSame('terisi', DB::table('inti.tempat_tidur')->where('id', $this->id['UJI29-101-1'])->value('status_saat_ini'));
        $riwayat = DB::table('kunjungan.riwayat_status_tempat_tidur')->where('tempat_tidur_id', $this->id['UJI29-101-1'])->where('sumber_kejadian', 'penempatan')->first();
        $this->assertNotNull($riwayat);
        $this->assertSame(['dipesan', 'terisi', $this->admisi->id], [$riwayat->status_sebelum, $riwayat->status, $riwayat->oleh]);
        $this->assertFalse(collect($this->minta('GET', self::URL)->assertOk()->json('data'))->contains('id', $permintaan));

        // Kunjungan bangsal: unit = unit bangsal TT, DPJP awal = dokter pemberi perintah, relasi perawatan tanpa akhir.
        $k = DB::table('kunjungan.kunjungan')->where('id', $ri)->first();
        $this->assertSame(['rawat_inap', $this->id['UJI29-RA'], $this->pegawaiIgd->id, $episodeIgd], [$k->jenis_kunjungan, $k->unit_id, $k->pegawai_id, $k->episode_id]);
        $relasi = DB::table('akses.relasi_perawatan')->where('sumber_id', $ri)->first();
        $this->assertSame(['dpjp_rawat_inap', $this->pegawaiIgd->id, 'aktif', null], [$relasi->peran, $relasi->pegawai_id, $relasi->status, $relasi->berlaku_sampai]);
        $penempatan = DB::table('kunjungan.penempatan_tempat_tidur')->where('kunjungan_id', $ri)->first();
        $this->assertSame([$pasien->id, null], [$penempatan->pasien_id, $penempatan->selesai_pada]);
        $this->assertNotNull($penempatan->perpindahan_pos_id);

        // Episode (7.00.2): episode IGD > 6 jam (episode kunjungan asal) menjadi rawat inap, kunjungan poli hari itu ikut
        // pindah, episode poli yang kosong batal. Kunjungan IGD selesai, keluar dari papan IGD.
        $this->assertSame(['rawat_inap', 'aktif'], [DB::table('kunjungan.episode')->where('id', $episodeIgd)->value('jenis'), DB::table('kunjungan.episode')->where('id', $episodeIgd)->value('status')]);
        $this->assertSame($episodeIgd, DB::table('kunjungan.kunjungan')->where('id', $poli)->value('episode_id'));
        $lama = DB::table('kunjungan.episode')->where('id', $episodePoli)->first();
        $this->assertSame(['batal', 'Digabung ke episode rawat inap'], [$lama->status, $lama->alasan_batal]);
        $this->assertSame('selesai', DB::table('kunjungan.kunjungan')->where('id', $igd)->value('status'));
        $audit = (string) $this->assertAuditTerakhir('rawat_inap.pasien_ditempatkan', (string) $penempatan->id)->sesudah;
        $this->assertStringNotContainsString('Infus', $audit);
        $this->assertStringNotContainsString($pasien->nama, $audit);
        $this->assertAuditTerakhir('episode.rawat_inap_dibentuk', $episodeIgd);
        $this->masukSebagai($this->dokterIgd);
        $this->minta('GET', '/api/v1/igd/papan')->assertOk()->assertJsonCount(0, 'data');

        // Penempatan kedua → 409; peta TT: terisi dengan hari rawat dihitung dari tanggal penempatan pertama.
        $this->masukSebagai($this->admisi);
        $ulang = $this->tempatkan($permintaan)->assertStatus(409)->assertJsonPath('error.kode', 'permintaan_kamar_tidak_dialokasikan')
            ->assertJsonPath('error.rincian.0.status', 'ditempatkan');
        $this->assertSesuaiKontrak($ulang, 'POST', '/permintaan-tempat-tidur/{id}/tempatkan');
        $this->minta('GET', '/api/v1/peta-tempat-tidur?bangsal_id='.$this->id['UJI29-BA'])->assertOk()
            ->assertJsonPath('data.0.status', 'terisi')->assertJsonPath('data.0.hari_rawat', 1)->assertJsonPath('data.1.hari_rawat', null);
        $this->travelTo(Carbon::parse('2026-11-04 09:00', (string) config('instalasi.zona_waktu')));
        $this->masukSebagai($this->admisi);
        $peta = $this->minta('GET', '/api/v1/peta-tempat-tidur?bangsal_id='.$this->id['UJI29-BA'])->assertOk()->assertJsonPath('data.0.hari_rawat', 3);
        $this->assertSesuaiKontrak($peta, 'GET', '/peta-tempat-tidur');
    }

    public function test_igd_lewat_tengah_malam_tetap_tergabung_ke_episode_rawat_inap(): void
    {
        // IGD tiba Senin 23.00, disposisi rawat inap Selasa 01.00: episode IGD (Senin) menjadi episode rawat inap (7.00.3
        // "episode rawat inap sejak IGD"), bukan episode baru bertanggal Selasa.
        $zona = (string) config('instalasi.zona_waktu');
        $pasien = $this->pasien();
        $this->travelTo(Carbon::parse(self::SENIN.' 23:00', $zona));
        $this->masukSebagai($this->dokterIgd);
        $igd = (string) $this->minta('POST', '/api/v1/kunjungan-igd', ['pasien_id' => $pasien->id, 'cara_bayar' => 'non_jkn'])->assertCreated()->json('data.id');
        $episodeIgd = (string) DB::table('kunjungan.kunjungan')->where('id', $igd)->value('episode_id');

        $this->travelTo(Carbon::parse('2026-11-03 01:00', $zona));
        $this->disposisiRawatInap($igd);
        $permintaan = $this->permintaanDari($igd);
        $this->masukSebagai($this->admisi);
        $this->alokasi($permintaan, $this->id['UJI29-101-1'])->assertOk();
        $respons = $this->tempatkan($permintaan)->assertCreated()->assertJsonPath('data.episode.id', $episodeIgd)
            ->assertJsonPath('data.episode.jenis', 'rawat_inap')->assertJsonPath('data.episode.mulai_tanggal', self::SENIN);
        $this->assertSesuaiKontrak($respons, 'POST', '/permintaan-tempat-tidur/{id}/tempatkan');
        $this->assertSame($episodeIgd, DB::table('kunjungan.kunjungan')->where('id', $igd)->value('episode_id'));
        $this->assertSame(['rawat_inap', 'aktif'], [DB::table('kunjungan.episode')->where('id', $episodeIgd)->value('jenis'),
            DB::table('kunjungan.episode')->where('id', $episodeIgd)->value('status')]);
        $this->assertSame(1, DB::table('kunjungan.episode')->where('pasien_id', $pasien->id)->count());
    }

    public function test_spri_tanggal_lain_membentuk_episode_rawat_inap_baru(): void
    {
        $pasien = $this->pasien();
        $poli = $this->kunjunganPoli($pasien);
        $episodePoli = (string) DB::table('kunjungan.kunjungan')->where('id', $poli)->value('episode_id');
        $this->masukSebagai($this->dokterPoli);
        $permintaan = (string) $this->spri($poli, ['tanggal_rencana' => '2026-11-04'])->assertCreated()->json('data.id');
        $this->masukSebagai($this->admisi);
        $this->alokasi($permintaan, $this->id['UJI29-101-2'])->assertOk();

        $this->travelTo(Carbon::parse('2026-11-04 08:00', (string) config('instalasi.zona_waktu')));
        $this->masukSebagai($this->admisi);
        $respons = $this->tempatkan($permintaan)->assertCreated()->assertJsonPath('data.episode.jenis', 'rawat_inap')
            ->assertJsonPath('data.episode.mulai_tanggal', '2026-11-04')->assertJsonPath('data.asal', 'poliklinik');
        $this->assertNotSame($episodePoli, $respons->json('data.episode.id'));
        // Awal rawat inap SPRI terencana = awal tanggal rencana (zona RS).
        $this->assertTrue(Carbon::parse((string) $respons->json('data.mulai_pada'))->equalTo(Carbon::parse('2026-11-04 00:00', (string) config('instalasi.zona_waktu'))));
        // Episode poli kemarin lusa tidak berubah; kunjungan poli tetap di episodenya.
        $this->assertSame(['rawat_jalan_harian', 'aktif'], [DB::table('kunjungan.episode')->where('id', $episodePoli)->value('jenis'),
            DB::table('kunjungan.episode')->where('id', $episodePoli)->value('status')]);
        $this->assertSame($episodePoli, DB::table('kunjungan.kunjungan')->where('id', $poli)->value('episode_id'));
        // Relasi DPJP poli (pemberi SPRI).
        $this->assertSame('dpjp_rawat_inap', DB::table('akses.relasi_perawatan')->where('sumber_id', $respons->json('data.kunjungan.id'))->value('peran'));
    }

    public function test_pindah_ruang_menutup_penempatan_lama_tt_dibersihkan_serah_terima_tercatat(): void
    {
        $pasien = $this->pasien();
        [$ri] = $this->rawatInapPoli($pasien, 'UJI29-101-1');
        $lama = (string) DB::table('kunjungan.penempatan_tempat_tidur')->where('kunjungan_id', $ri)->value('id');
        // Pasien lain di TT 201-2 dan kunjungan poli biasa (jadwal poli hanya Senin).
        [, $lain] = $this->rawatInapPoli($this->pasien(), 'UJI29-201-2');
        $poli = $this->kunjunganPoli($this->pasien());

        $this->travel(1)->days();
        $this->masukSebagai($this->dokterRanap);
        $respons = $this->pindah($ri, $this->id['UJI29-201-1'])->assertOk()
            ->assertJsonPath('data.penempatan.tempat_tidur.kode', 'UJI29-201-1')->assertJsonPath('data.penempatan.tempat_tidur.bangsal.kode', 'UJI29-BB')
            ->assertJsonPath('data.kelas_dirawat.kode', 'UJI29-K3')->assertJsonPath('data.hari_rawat', 2);
        $this->assertSesuaiKontrak($respons, 'POST', '/kunjungan/{id}/pindah-ruang');
        $this->assertStringContainsString('no-store', (string) $respons->headers->get('Cache-Control'));

        // Penempatan lama selesai (pindah), TT lama dibersihkan, TT baru terisi; unit kunjungan ikut bangsal baru.
        $p = DB::table('kunjungan.penempatan_tempat_tidur')->where('id', $lama)->first();
        $this->assertSame(['pindah', $this->dokterRanap->id], [$p->alasan_selesai, $p->diakhiri_oleh]);
        $this->assertTrue(Carbon::parse((string) $p->selesai_pada)->equalTo(now()));
        $this->assertSame('dibersihkan', DB::table('inti.tempat_tidur')->where('id', $this->id['UJI29-101-1'])->value('status_saat_ini'));
        $this->assertSame('terisi', DB::table('inti.tempat_tidur')->where('id', $this->id['UJI29-201-1'])->value('status_saat_ini'));
        $this->assertSame(['pindah_ruang', 'pindah_ruang'], DB::table('kunjungan.riwayat_status_tempat_tidur')
            ->whereIn('tempat_tidur_id', [$this->id['UJI29-101-1'], $this->id['UJI29-201-1']])->where('sumber_kejadian', 'pindah_ruang')
            ->orderBy('tempat_tidur_id')->pluck('sumber_kejadian')->all());
        $this->assertSame($this->id['UJI29-RB'], DB::table('kunjungan.kunjungan')->where('id', $ri)->value('unit_id'));

        // Serah terima tercatat sebagai perintah rawat_inap → rawat_inap (linimasa FR-00-03); alasan tidak ke audit.
        $perintah = DB::table('kunjungan.perpindahan_pos')->where('dari_kunjungan_id', $ri)->first();
        $this->assertSame(['rawat_inap', 'rawat_inap', 'dilaksanakan', 'Pindah ke ruang isolasi (uji sintetis)', 'rawat_inap.pindah_ruang.input', 'rawat_inap'],
            [$perintah->pos_asal, $perintah->pos_tujuan, $perintah->status, $perintah->serah_terima, $perintah->izin_perintah, $perintah->episode_jenis]);
        $this->assertSame($perintah->id, DB::table('kunjungan.penempatan_tempat_tidur')->where('kunjungan_id', $ri)->whereNull('selesai_pada')->value('perpindahan_pos_id'));
        $riwayat = $this->minta('GET', "/api/v1/kunjungan/$ri/disposisi")->assertOk()->assertJsonPath('data.0.status', 'dilaksanakan')
            ->assertJsonPath('data.0.tujuan', 'rawat_inap')->assertJsonPath('data.0.episode.jenis', 'rawat_inap');
        $this->assertSesuaiKontrak($riwayat, 'GET', '/kunjungan/{id}/disposisi');
        $audit = (string) $this->assertAuditTerakhir('rawat_inap.pindah_ruang', (string) $perintah->id)->sesudah;
        $this->assertStringNotContainsString('isolasi', $audit);

        // Peta: hari rawat di TT baru tetap dihitung dari penempatan pertama.
        $this->minta('GET', '/api/v1/peta-tempat-tidur?bangsal_id='.$this->id['UJI29-BB'])->assertOk()
            ->assertJsonPath('data.0.kode', 'UJI29-201-1')->assertJsonPath('data.0.hari_rawat', 2);

        // Pindah kelas boleh (titip/naik kelas Tahap D): kelas dirawat ikut TT baru.
        $this->pindah($ri, $this->id['UJI29-101-3'])->assertOk()->assertJsonPath('data.kelas_dirawat.kode', 'UJI29-K1');
        $this->assertSame($this->id['UJI29-K1'], DB::table('kunjungan.rawat_inap')->where('kunjungan_id', $ri)->value('kelas_dirawat_id'));
        $this->assertSame(1, DB::table('kunjungan.penempatan_tempat_tidur')->where('pasien_id', $pasien->id)->whereNull('selesai_pada')->count());

        // TT sama, TT tidak ada, isian kurang → 422; TT terisi pasien lain, dibersihkan, ditutup → 409.
        foreach ([['tempat_tidur_id' => $this->id['UJI29-101-3']], ['tempat_tidur_id' => 'ngawur'], ['tempat_tidur_id' => (string) Str::uuid7()],
            ['serah_terima' => null], ['alasan' => 'abc']] as $salah) {
            $this->assertSesuaiKontrak($this->pindah($ri, $this->id['UJI29-201-2'], $salah)->assertStatus(422), 'POST', '/kunjungan/{id}/pindah-ruang');
        }
        $terisi = $this->pindah($ri, $lain)->assertStatus(409)->assertJsonPath('error.kode', 'tempat_tidur_tidak_tersedia')
            ->assertJsonPath('error.rincian.0.status_sekarang', 'terisi');
        $this->assertSesuaiKontrak($terisi, 'POST', '/kunjungan/{id}/pindah-ruang');
        $this->pindah($ri, $this->id['UJI29-101-1'])->assertStatus(409)->assertJsonPath('error.rincian.0.status_sekarang', 'dibersihkan');
        DB::table('inti.tempat_tidur')->where('id', $this->id['UJI29-101-2'])->update(['berlaku_sampai' => '2026-12-31']);
        $this->pindah($ri, $this->id['UJI29-101-2'])->assertStatus(409)->assertJsonPath('error.kode', 'tempat_tidur_ditutup');

        // Bukan kunjungan rawat inap → 409; tidak ada / bukan UUID → 404.
        $bukan = $this->pindah($poli, $this->id['UJI29-201-1'])->assertStatus(409)->assertJsonPath('error.kode', 'status_tidak_sesuai')
            ->assertJsonPath('error.rincian.0.jenis_kunjungan', 'poliklinik');
        $this->assertSesuaiKontrak($bukan, 'POST', '/kunjungan/{id}/pindah-ruang');
        $this->assertSesuaiKontrak($this->pindah((string) Str::uuid7(), $this->id['UJI29-201-1'])->assertNotFound(), 'POST', '/kunjungan/{id}/pindah-ruang');
        $this->pindah('bukan-uuid', $this->id['UJI29-201-1'])->assertNotFound();
    }

    public function test_izin_dan_cakupan_pindah_ruang_dan_penempatan(): void
    {
        [$ri] = $this->rawatInapPoli($this->pasien(), 'UJI29-101-1');
        $url = "/api/v1/kunjungan/$ri/pindah-ruang";

        // Tanpa izin sel matriks: 403 sebelum keberadaan kunjungan dibaca.
        $this->masukSebagai($this->buatPengguna());
        $this->assertSesuaiKontrak($this->minta('POST', $url, [])->assertForbidden(), 'POST', '/kunjungan/{id}/pindah-ruang');
        $this->minta('POST', '/api/v1/kunjungan/'.Str::uuid7().'/pindah-ruang', [])->assertForbidden();
        $this->minta('POST', self::URL.'/'.Str::uuid7().'/tempatkan', [])->assertForbidden();
        $this->minta('POST', self::URL.'/'.Str::uuid7().'/batal', [])->assertForbidden();

        // Izin pindah ruang klinis (SIP/SPK berlaku) → tanpa SIP 403.
        $this->assertTrue((bool) DB::table('akses.izin')->where('kode', 'rawat_inap.pindah_ruang.input')->value('butuh_kewenangan_klinis'));
        $this->assertFalse((bool) DB::table('akses.izin')->where('kode', 'rawat_inap.penempatan.input')->value('butuh_kewenangan_klinis'));
        $this->assertFalse((bool) DB::table('akses.izin')->where('kode', 'rawat_inap.pindah_ruang.input')->value('sensitif'));
        /** @var Pegawai $perawat */
        $perawat = Pegawai::factory()->create(['profesi' => 'perawat']);
        $tanpaSip = $this->buatPengguna(['pegawai_id' => $perawat->id]);
        $this->tugaskan($tanpaSip, $this->buatPeran(['rawat_inap.pindah_ruang']));
        $this->masukSebagai($tanpaSip);
        $this->pindah($ri, $this->id['UJI29-201-1'])->assertForbidden()->assertJsonPath('error.kode', 'izin_praktik_tidak_berlaku');

        // Cakupan unit bangsal asal: dokter ruang RB tidak bisa memindahkan pasien di bangsal BA.
        $ruangB = $this->akunDokter($this->dokter());
        $this->tugaskan($ruangB, $this->buatPeran(['rawat_inap.pindah_ruang']), cakupan: new Cakupan('unit', $this->id['UJI29-RB']));
        $this->masukSebagai($ruangB);
        $this->pindah($ri, $this->id['UJI29-201-1'])->assertForbidden();
        $this->pindah((string) Str::uuid7(), $this->id['UJI29-201-1'])->assertNotFound();
        $ruangA = $this->akunDokter($this->dokter());
        $this->tugaskan($ruangA, $this->buatPeran(['rawat_inap.pindah_ruang']), cakupan: new Cakupan('unit', $this->id['UJI29-RA']));
        $this->masukSebagai($ruangA);
        $this->pindah($ri, $this->id['UJI29-201-1'])->assertOk();

        // Sel matriks rawat_inap → rawat_inap nonaktif → 422.
        DB::table('kunjungan.aturan_perpindahan')->where('pos_asal', 'rawat_inap')->where('pos_tujuan', 'rawat_inap')->update(['status' => 'nonaktif']);
        $this->masukSebagai($this->dokterRanap);
        $this->pindah($ri, $this->id['UJI29-201-2'])->assertStatus(422)->assertJsonPath('error.kode', 'perpindahan_di_luar_matriks');

        // Penempatan: cakupan unit bangsal TT pesanan; id tidak ada / bukan UUID → 404; serah terima terlalu pendek → 422.
        $permintaan = $this->permintaanPoli($this->pasien());
        $this->masukSebagai($this->admisi);
        $this->alokasi($permintaan, $this->id['UJI29-101-2'])->assertOk();
        $admisiB = $this->buatPengguna();
        $this->tugaskan($admisiB, $this->buatPeran(['rawat_inap.penempatan']), cakupan: new Cakupan('unit', $this->id['UJI29-RB']));
        $this->masukSebagai($admisiB);
        $this->assertSesuaiKontrak($this->tempatkan($permintaan)->assertForbidden(), 'POST', '/permintaan-tempat-tidur/{id}/tempatkan');
        $this->assertSesuaiKontrak($this->tempatkan((string) Str::uuid7())->assertNotFound(), 'POST', '/permintaan-tempat-tidur/{id}/tempatkan');
        $this->tempatkan('bukan-uuid')->assertNotFound();
        $this->masukSebagai($this->admisi);
        $this->assertSesuaiKontrak($this->tempatkan($permintaan, ['serah_terima' => 'abc'])->assertStatus(422), 'POST', '/permintaan-tempat-tidur/{id}/tempatkan');
    }

    public function test_satu_pasien_satu_tt_dan_tt_terisi_tidak_bisa_ditempati_pasien_lain(): void
    {
        $pasien = $this->pasien();
        [$ri, $tt, $poli] = $this->rawatInapPoli($pasien, 'UJI29-101-1');

        // Pasien sedang dirawat: SPRI poli dan disposisi IGD rawat inap → 409 pasien_sedang_dirawat.
        $this->masukSebagai($this->dokterPoli);
        $spri = $this->spri($poli)->assertStatus(409)->assertJsonPath('error.kode', 'pasien_sedang_dirawat');
        $this->assertSesuaiKontrak($spri, 'POST', '/kunjungan/{id}/spri');
        $this->masukSebagai($this->dokterIgd);
        $igd = (string) $this->minta('POST', '/api/v1/kunjungan-igd', ['pasien_id' => $pasien->id, 'cara_bayar' => 'non_jkn'])->assertCreated()->json('data.id');
        $disposisi = $this->minta('POST', "/api/v1/kunjungan/$igd/disposisi", ['tujuan' => 'rawat_inap', 'alasan' => 'Perlu rawat inap (uji sintetis)',
            'serah_terima' => 'Infus terpasang (uji sintetis)'])->assertStatus(409)->assertJsonPath('error.kode', 'pasien_sedang_dirawat');
        $this->assertSesuaiKontrak($disposisi, 'POST', '/kunjungan/{id}/disposisi');
        $this->assertFalse(DB::table('kunjungan.perpindahan_pos')->where('dari_kunjungan_id', $igd)->exists());

        // Permintaan kedua pasien yang sama (dibentuk langsung, melewati penjaga SPRI) → 409 pasien_sudah_ditempatkan.
        $asal = DB::table('kunjungan.permintaan_tempat_tidur')->where('id', DB::table('kunjungan.rawat_inap')->where('kunjungan_id', $ri)->value('permintaan_tempat_tidur_id'))->first();
        $kedua = (string) Str::uuid7();
        DB::table('kunjungan.permintaan_tempat_tidur')->insert([
            'id' => $kedua, 'kunjungan_id' => $poli, 'pasien_id' => $pasien->id, 'surat_kontrol_id' => $asal->surat_kontrol_id, 'perpindahan_pos_id' => $asal->perpindahan_pos_id,
            'asal' => 'poliklinik', 'diminta_oleh' => $this->dokterPoli->id, 'diminta_pada' => now(), 'menunggu_sejak' => now(), 'status' => 'dialokasikan',
            'tempat_tidur_id' => $this->id['UJI29-101-2'], 'dialokasikan_pada' => now(),
        ]);
        DB::table('inti.tempat_tidur')->where('id', $this->id['UJI29-101-2'])->update(['status_saat_ini' => 'dipesan']);
        $this->masukSebagai($this->admisi);
        $dua = $this->tempatkan($kedua)->assertStatus(409)->assertJsonPath('error.kode', 'pasien_sudah_ditempatkan');
        $this->assertSesuaiKontrak($dua, 'POST', '/permintaan-tempat-tidur/{id}/tempatkan');
        // Penjaga terakhir: indeks unik parsial satu penempatan aktif per pasien dan per TT.
        $this->assertDitolakBasisData('23505', fn () => DB::table('kunjungan.penempatan_tempat_tidur')->insert($this->barisPenempatan($ri, $pasien->id, $this->id['UJI29-101-2'])));
        $this->assertDitolakBasisData('23505', fn () => DB::table('kunjungan.penempatan_tempat_tidur')->insert($this->barisPenempatan($ri, $this->pasien()->id, $tt)));
        $this->assertDitolakBasisData('23514', fn () => DB::table('kunjungan.penempatan_tempat_tidur')->insert(
            [...$this->barisPenempatan($ri, $this->pasien()->id, $this->id['UJI29-201-2']), 'alasan_selesai' => 'pulang']));

        // TT pesanan pasien lain yang kini terisi (peta berubah di luar alur) → 409, permintaan tetap dialokasikan.
        $lain = $this->permintaanPoli($this->pasien());
        $this->masukSebagai($this->admisi);
        $this->alokasi($lain, $this->id['UJI29-201-1'])->assertOk();
        DB::table('inti.tempat_tidur')->where('id', $this->id['UJI29-201-1'])->update(['status_saat_ini' => 'terisi']);
        $terisi = $this->tempatkan($lain)->assertStatus(409)->assertJsonPath('error.kode', 'tempat_tidur_tidak_tersedia')
            ->assertJsonPath('error.rincian.0.status_sekarang', 'terisi');
        $this->assertSesuaiKontrak($terisi, 'POST', '/permintaan-tempat-tidur/{id}/tempatkan');
        $this->assertSame('dialokasikan', DB::table('kunjungan.permintaan_tempat_tidur')->where('id', $lain)->value('status'));
        // Permintaan masih menunggu (belum ada TT) → 409.
        $menunggu = $this->permintaanPoli($this->pasien());
        $this->masukSebagai($this->admisi);
        $this->tempatkan($menunggu)->assertStatus(409)->assertJsonPath('error.kode', 'permintaan_kamar_tidak_dialokasikan')->assertJsonPath('error.rincian.0.status', 'menunggu');
    }

    public function test_batal_permintaan_dan_satu_permintaan_aktif_per_pasien(): void
    {
        // Pasien P di poli lalu IGD: permintaan IGD aktif → SPRI dari kunjungan poli → 409 (per pasien, bukan per kunjungan).
        $p = $this->pasien();
        $poli = $this->kunjunganPoli($p);
        $igd = $this->igdMenungguKamar($p);
        $permintaanIgd = $this->permintaanDari($igd);
        $this->masukSebagai($this->dokterPoli);
        $ganda = $this->spri($poli)->assertStatus(409)->assertJsonPath('error.kode', 'permintaan_kamar_sudah_ada')
            ->assertJsonPath('error.rincian.0.permintaan_tempat_tidur_id', $permintaanIgd);
        $this->assertSesuaiKontrak($ganda, 'POST', '/kunjungan/{id}/spri');

        // Pasien Q dengan SPRI poli (TT dipesan) lalu disposisi IGD rawat inap: permintaan poli digantikan, TT dilepas.
        $q = $this->pasien();
        $spriQ = $this->permintaanPoli($q);
        $this->masukSebagai($this->admisi);
        $this->alokasi($spriQ, $this->id['UJI29-101-2'])->assertOk();
        $igdQ = $this->igdMenungguKamar($q);
        $lama = DB::table('kunjungan.permintaan_tempat_tidur')->where('id', $spriQ)->first();
        $this->assertSame(['batal', 'Digantikan permintaan IGD', $this->dokterIgd->id], [$lama->status, $lama->alasan_batal, $lama->dibatalkan_oleh]);
        $this->assertSame('kosong_siap', DB::table('inti.tempat_tidur')->where('id', $this->id['UJI29-101-2'])->value('status_saat_ini'));
        $baruQ = $this->permintaanDari($igdQ);
        $this->assertSame('menunggu', DB::table('kunjungan.permintaan_tempat_tidur')->where('id', $baruQ)->value('status'));

        // Batal oleh admisi: TT pesanan kembali kosong siap, SPRI internal batal, alasan dan pelaku tercatat.
        $this->masukSebagai($this->admisi);
        $this->alokasi($baruQ, $this->id['UJI29-101-1'])->assertOk();
        $batal = $this->minta('POST', self::URL."/$baruQ/batal", ['alasan' => 'Keluarga memilih RS lain (uji sintetis)'])->assertOk()
            ->assertJsonPath('data.status', 'batal')->assertJsonPath('data.id', $baruQ);
        $this->assertSesuaiKontrak($batal, 'POST', '/permintaan-tempat-tidur/{id}/batal');
        $this->assertStringContainsString('no-store', (string) $batal->headers->get('Cache-Control'));
        $baris = DB::table('kunjungan.permintaan_tempat_tidur')->where('id', $baruQ)->first();
        $this->assertSame(['Keluarga memilih RS lain (uji sintetis)', $this->admisi->id], [$baris->alasan_batal, $baris->dibatalkan_oleh]);
        $this->assertSame('batal', DB::table('kunjungan.surat_kontrol')->where('id', $baris->surat_kontrol_id)->value('status'));
        $this->assertSame('kosong_siap', DB::table('inti.tempat_tidur')->where('id', $this->id['UJI29-101-1'])->value('status_saat_ini'));
        $this->assertTrue(DB::table('kunjungan.riwayat_status_tempat_tidur')->where('tempat_tidur_id', $this->id['UJI29-101-1'])
            ->where('sumber_kejadian', 'alokasi_batal')->where('oleh', $this->admisi->id)->exists());
        $this->assertAuditTerakhir('rawat_inap.permintaan_kamar_dibatalkan', $baruQ);

        // Perintah IGD menunggu kamar yang permintaannya batal tidak lagi menahan: papan tanpa disposisi menunggu, dokter
        // IGD bisa memberi disposisi rawat inap lagi.
        $this->masukSebagai($this->dokterIgd);
        $baris = collect($this->minta('GET', '/api/v1/igd/papan')->assertOk()->json('data'))->firstWhere('kunjungan_id', $igdQ);
        $this->assertNull($baris['disposisi_menunggu']);
        $this->assertNull($baris['permintaan_kamar']);
        $this->minta('POST', "/api/v1/kunjungan/$igdQ/disposisi", ['tujuan' => 'rawat_inap', 'alasan' => 'Perlu rawat inap (uji sintetis)',
            'serah_terima' => 'Infus terpasang (uji sintetis)'])->assertCreated();

        // Batal ulang → 409; alasan kosong → 422; tidak ada / bukan UUID → 404; cakupan unit TT pesanan → 403.
        $this->masukSebagai($this->admisi);
        $ulang = $this->minta('POST', self::URL."/$baruQ/batal", ['alasan' => 'Batal lagi (uji)'])->assertStatus(409)
            ->assertJsonPath('error.kode', 'permintaan_kamar_tidak_aktif')->assertJsonPath('error.rincian.0.status', 'batal');
        $this->assertSesuaiKontrak($ulang, 'POST', '/permintaan-tempat-tidur/{id}/batal');
        $this->assertSesuaiKontrak($this->minta('POST', self::URL."/$permintaanIgd/batal", [])->assertStatus(422), 'POST', '/permintaan-tempat-tidur/{id}/batal');
        $this->assertSesuaiKontrak($this->minta('POST', self::URL.'/'.Str::uuid7().'/batal', ['alasan' => 'Tidak ada (uji)'])->assertNotFound(),
            'POST', '/permintaan-tempat-tidur/{id}/batal');
        $this->minta('POST', self::URL.'/bukan-uuid/batal', ['alasan' => 'Bukan UUID (uji)'])->assertNotFound();
        $this->alokasi($permintaanIgd, $this->id['UJI29-101-3'])->assertOk();
        $ruangB = $this->buatPengguna();
        $this->tugaskan($ruangB, $this->buatPeran(['pendaftaran.admisi']), cakupan: new Cakupan('unit', $this->id['UJI29-RB']));
        $this->masukSebagai($ruangB);
        $this->minta('POST', self::URL."/$permintaanIgd/batal", ['alasan' => 'Di luar cakupan (uji)'])->assertForbidden();
        $this->assertSame('dialokasikan', DB::table('kunjungan.permintaan_tempat_tidur')->where('id', $permintaanIgd)->value('status'));
    }

    public function test_tutup_tt_memakai_pemeriksa_penempatan_nyata_dan_status_melewati_rencana_lama_rawat(): void
    {
        $this->assertInstanceOf(PemeriksaPenempatanTempatTidur::class, app(PemeriksaPenempatan::class));
        [, $tt] = $this->rawatInapPoli($this->pasien(), 'UJI29-101-1');
        $pemeriksa = app(PemeriksaPenempatan::class);
        $this->assertTrue($pemeriksa->terisiAtauDipesan($tt, '2026-11-10'));
        $this->assertFalse($pemeriksa->terisiAtauDipesan($this->id['UJI29-101-2'], '2026-11-10'));
        $dipesan = $this->permintaanPoli($this->pasien());
        $this->masukSebagai($this->admisi);
        $this->alokasi($dipesan, $this->id['UJI29-101-2'])->assertOk();
        $this->assertTrue($pemeriksa->terisiAtauDipesan($this->id['UJI29-101-2'], '2026-11-10'));

        // Status tersimpan dipalsukan kosong siap: penempatan aktif tetap menolak penutupan, juga penutupan bertanggal depan.
        DB::table('inti.tempat_tidur')->where('id', $tt)->update(['status_saat_ini' => 'kosong_siap']);
        $kelola = app(KelolaTempatTidur::class);
        foreach ([SuntinganBerversi::hariIni(), '2026-12-01'] as $mulai) {
            try {
                $kelola->tutup($this->buatPengguna(), TempatTidur::query()->findOrFail($tt), '0', $mulai, 'Renovasi (uji)', 'SK uji');
                $this->fail('Penutupan TT dengan penempatan aktif seharusnya ditolak.');
            } catch (ApiError $e) {
                $this->assertSame([409, 'tempat_tidur_terisi'], [$e->getStatusCode(), $e->kode]);
            }
        }
        $this->assertNull(TempatTidur::query()->findOrFail($tt)->berlaku_sampai);

        // Status baru FR-08-01 diterima CHECK TT dan riwayat; nilai lain ditolak; peta menampilkannya.
        DB::table('inti.tempat_tidur')->where('id', $tt)->update(['status_saat_ini' => 'melewati_rencana_lama_rawat']);
        DB::table('kunjungan.riwayat_status_tempat_tidur')->insert(['id' => (string) Str::uuid7(), 'tempat_tidur_id' => $tt, 'status_sebelum' => 'terisi',
            'status' => 'melewati_rencana_lama_rawat', 'terjadi_pada' => now(), 'oleh' => null, 'sumber_kejadian' => 'petugas']);
        $this->assertDitolakBasisData('23514', fn () => DB::table('inti.tempat_tidur')->where('id', $tt)->update(['status_saat_ini' => 'ngawur']));
        $peta = $this->minta('GET', '/api/v1/peta-tempat-tidur?bangsal_id='.$this->id['UJI29-BA'])->assertOk()
            ->assertJsonPath('data.0.status', 'melewati_rencana_lama_rawat')->assertJsonPath('data.0.hari_rawat', 1);
        $this->assertSesuaiKontrak($peta, 'GET', '/peta-tempat-tidur');
    }

    private function siapkanTempatTidur(): void
    {
        $irna = UnitOrganisasi::query()->create(['kode' => 'UJI29-IRNA', 'nama' => 'Instalasi Rawat Inap (uji)', 'jenis' => 'instalasi', 'berlaku_dari' => '2026-01-01']);
        foreach (['UJI29-RA', 'UJI29-RB'] as $kode) {
            $this->id[$kode] = UnitOrganisasi::query()->create(['kode' => $kode, 'nama' => "Ruang $kode", 'jenis' => 'ruang', 'induk_id' => $irna->id, 'berlaku_dari' => '2026-01-01'])->id;
        }
        foreach (['UJI29-K3', 'UJI29-K1'] as $kode) {
            $this->id[$kode] = KelasRawat::query()->create(['kode' => $kode, 'nama' => "Kelas $kode", 'berlaku_dari' => '2026-01-01'])->id;
        }
        foreach (['UJI29-BA' => 'UJI29-RA', 'UJI29-BB' => 'UJI29-RB'] as $kode => $unit) {
            $this->id[$kode] = Bangsal::query()->create(['kode' => $kode, 'nama' => "Bangsal $kode", 'unit_organisasi_id' => $this->id[$unit], 'status' => 'aktif'])->id;
        }
        $gedung = $this->lokasi(null, 'gedung', 'UJI29', 'UJI29');
        $this->tt($gedung, '101', 'UJI29-BA', ['1' => 'UJI29-K3', '2' => 'UJI29-K3', '3' => 'UJI29-K1']);
        $this->tt($gedung, '201', 'UJI29-BB', ['1' => 'UJI29-K3', '2' => 'UJI29-K3']);
    }

    private function lokasi(?string $induk, string $jenis, string $kode, string $kodeLengkap, ?string $fungsi = null): string
    {
        return Lokasi::query()->create([
            'induk_id' => $induk, 'jenis' => $jenis, 'kode' => $kode, 'kode_lengkap' => $kodeLengkap, 'nama' => "Lokasi $kodeLengkap",
            'fungsi' => $fungsi, 'status' => 'aktif', 'status_berlaku_dari' => '2026-01-01',
        ])->id;
    }

    /** @param  array<int|string, string>  $kelas  kode TT => kode kelas */
    private function tt(string $gedung, string $kodeRuang, string $bangsal, array $kelas): void
    {
        $ruang = $this->lokasi($gedung, 'ruang', $kodeRuang, "UJI29-$kodeRuang", 'rawat_inap');
        $kamar = Kamar::query()->create(['bangsal_id' => $this->id[$bangsal], 'lokasi_id' => $ruang])->id;
        foreach ($kelas as $kode => $kodeKelas) {
            $kodeTt = "UJI29-$kodeRuang-$kode";
            $this->id[$kodeTt] = TempatTidur::query()->create([
                'kamar_id' => $kamar, 'lokasi_id' => $this->lokasi($ruang, 'tempat_tidur', (string) $kode, $kodeTt), 'kode' => $kodeTt,
                'kelas_rawat_id' => $this->id[$kodeKelas], 'berlaku_dari' => '2026-01-01',
            ])->id;
        }
    }

    /** Kunjungan poli pasien itu yang sudah mulai diperiksa (`dilayani`, syarat SPRI). */
    private function kunjunganPoli(Pasien $pasien): string
    {
        $this->masukSebagai($this->petugasPendaftaran());
        $kunjungan = (string) $this->daftarKunjungan($pasien, JadwalPraktik::query()->findOrFail($this->id['jadwal']))->assertCreated()->json('data.id');
        $this->masukSebagai($this->dokterPoli);
        $this->minta('POST', "/api/v1/kunjungan/$kunjungan/mulai-periksa")->assertOk();

        return $kunjungan;
    }

    /** @return string id permintaan kamar poli (kelas K3) */
    private function permintaanPoli(Pasien $pasien): string
    {
        $kunjungan = $this->kunjunganPoli($pasien);
        $this->masukSebagai($this->dokterPoli);

        return (string) $this->spri($kunjungan)->assertCreated()->json('data.id');
    }

    /**
     * Pasien dirawat inap dari SPRI poli di TT itu.
     *
     * @return array{0: string, 1: string, 2: string} id kunjungan rawat inap, id TT, id kunjungan poli asal
     */
    private function rawatInapPoli(Pasien $pasien, string $kodeTt): array
    {
        $poli = $this->kunjunganPoli($pasien);
        $this->masukSebagai($this->dokterPoli);
        $permintaan = (string) $this->spri($poli)->assertCreated()->json('data.id');
        $this->masukSebagai($this->admisi);
        $this->alokasi($permintaan, $this->id[$kodeTt])->assertOk();

        return [(string) $this->tempatkan($permintaan)->assertCreated()->json('data.kunjungan.id'), $this->id[$kodeTt], $poli];
    }

    /** @return string id kunjungan IGD pasien itu yang baru diberi disposisi rawat inap */
    private function igdMenungguKamar(Pasien $pasien): string
    {
        $this->masukSebagai($this->dokterIgd);
        $kunjungan = (string) $this->minta('POST', '/api/v1/kunjungan-igd', ['pasien_id' => $pasien->id, 'cara_bayar' => 'non_jkn'])->assertCreated()->json('data.id');
        $this->disposisiRawatInap($kunjungan);

        return $kunjungan;
    }

    private function disposisiRawatInap(string $kunjungan): void
    {
        $this->masukSebagai($this->dokterIgd);
        $this->minta('POST', "/api/v1/kunjungan/$kunjungan/disposisi", ['tujuan' => 'rawat_inap', 'alasan' => 'Perlu observasi rawat inap (uji sintetis)',
            'serah_terima' => 'Infus terpasang (uji sintetis)'])->assertCreated();
    }

    private function permintaanDari(string $kunjungan): string
    {
        return (string) DB::table('kunjungan.permintaan_tempat_tidur')->where('kunjungan_id', $kunjungan)->whereIn('status', ['menunggu', 'dialokasikan'])->value('id');
    }

    /** @param  array<string, mixed>  $ganti */
    private function spri(string $kunjungan, array $ganti = []): TestResponse
    {
        return $this->minta('POST', "/api/v1/kunjungan/$kunjungan/spri", [
            'alasan' => 'Observasi rawat inap (uji sintetis)', 'kelas_rawat_id' => $this->id['UJI29-K3'], ...$ganti,
        ]);
    }

    private function alokasi(string $permintaan, string $tempatTidur): TestResponse
    {
        return $this->minta('POST', self::URL."/$permintaan/alokasi", ['tempat_tidur_id' => $tempatTidur]);
    }

    /** @param  array<string, mixed>  $isi */
    private function tempatkan(string $permintaan, array $isi = []): TestResponse
    {
        return $this->minta('POST', self::URL."/$permintaan/tempatkan", $isi);
    }

    /** @param  array<string, mixed>  $ganti */
    private function pindah(string $kunjungan, string $tempatTidur, array $ganti = []): TestResponse
    {
        return $this->minta('POST', "/api/v1/kunjungan/$kunjungan/pindah-ruang", [
            'tempat_tidur_id' => $tempatTidur, 'alasan' => 'Butuh ruang isolasi (uji sintetis)', 'serah_terima' => 'Pindah ke ruang isolasi (uji sintetis)', ...$ganti,
        ]);
    }

    /** @return array<string, mixed> */
    private function barisPenempatan(string $kunjungan, string $pasien, string $tempatTidur): array
    {
        return ['id' => (string) Str::uuid7(), 'tempat_tidur_id' => $tempatTidur, 'kunjungan_id' => $kunjungan, 'pasien_id' => $pasien,
            'mulai_pada' => now(), 'ditempatkan_oleh' => $this->admisi->id];
    }

    /** Pernyataan yang melanggar batasan dijalankan di savepoint, jadi transaksi uji tetap bisa dipakai. */
    private function assertDitolakBasisData(string $kode, callable $aksi): void
    {
        try {
            DB::transaction(fn () => $aksi());
            $this->fail("Basis data seharusnya menolak ($kode).");
        } catch (QueryException $e) {
            $this->assertSame($kode, $e->getCode());
        }
    }
}
