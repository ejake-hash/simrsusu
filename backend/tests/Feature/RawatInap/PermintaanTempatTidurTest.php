<?php

namespace Tests\Feature\RawatInap;

use App\Core\Http\ApiError;
use App\Core\Http\SuntinganBerversi;
use App\Modules\Akses\Domain\Cakupan;
use App\Modules\Akses\Models\Izin;
use App\Modules\Akses\Models\Menu;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\Akses\Models\Peran;
use App\Modules\Akses\Models\PeranIzin;
use App\Modules\Akses\Models\PeranMenu;
use App\Modules\MasterData\Application\KelolaTempatTidur;
use App\Modules\MasterData\Models\Bangsal;
use App\Modules\MasterData\Models\JadwalPraktik;
use App\Modules\MasterData\Models\Kamar;
use App\Modules\MasterData\Models\KelasRawat;
use App\Modules\MasterData\Models\Lokasi;
use App\Modules\MasterData\Models\TempatTidur;
use App\Modules\MasterData\Models\UnitOrganisasi;
use App\Modules\Notifikasi\Models\JenisTugas;
use App\Modules\Notifikasi\Models\Tugas;
use App\Modules\Pendaftaran\Models\Kunjungan;
use App\Modules\Sdm\Models\Pegawai;
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
 * Tiket 2.28: SPRI /kunjungan/{id}/spri, antrean /permintaan-tempat-tidur, alokasi /permintaan-tempat-tidur/{id}/alokasi
 * (kontrak v0.36.0; FR-08-03, FR-03-06, FR-03-07), pembatalan permintaan lewat kejadian (tinjauan #194 butir 1), izin sel
 * matriks klinis (#194 butir 2–3), dan penutupan TT terkunci (#198 butir 1). Data sintetis; jam dibekukan Senin
 * 2 Nov 2026 07.00 zona RS (KunjunganUji). Bangsal UJI28-BA (unit ruang RA): TT 101-1, 101-2 kelas K3, 101-3 kelas K1;
 * bangsal UJI28-BB (unit ruang RB): TT 201-1 kelas K3.
 */
class PermintaanTempatTidurTest extends TestCase
{
    use AksesUji, KlienSpa, KontrakApi, KunjunganUji;

    private const URL = '/api/v1/permintaan-tempat-tidur';

    private Pengguna $dokterIgd;

    private Pengguna $dokterPoli;

    private Pengguna $admisi;

    /** @var array<string, string> kode => id */
    private array $id = [];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->siapkanKunjungan();
        // Modul rawat jalan aktif: SPRI poli sesudah mulai periksa (2.15).
        DB::table('inti.modul_aktif')->insertOrIgnore(['id' => (string) Str::uuid7(), 'modul_no' => 7]);
        $this->siapkanTempatTidur();

        $this->dokterIgd = $this->akunDokter($this->dokter());
        $this->tugaskan($this->dokterIgd, $this->buatPeran(['igd.pendaftaran', 'igd.disposisi', 'igd.papan']));
        $pegawaiPoli = $this->dokter();
        $this->id['jadwal'] = $this->jadwal($pegawaiPoli)->id;
        $this->dokterPoli = $this->akunDokter($pegawaiPoli);
        $this->tugaskan($this->dokterPoli, $this->buatPeran(['rawat_jalan.pemeriksaan']));
        $this->admisi = $this->buatPengguna();
        $this->id['peran_admisi'] = $this->buatPeran(['pendaftaran.admisi', 'rawat_inap.peta_tempat_tidur'])->id;
        $this->tugaskan($this->admisi, Peran::query()->findOrFail($this->id['peran_admisi']));
    }

    public function test_spri_poli_dan_disposisi_igd_masuk_satu_antrean_urut_lama_menunggu(): void
    {
        $poli = $this->kunjunganPoli();
        $this->masukSebagai($this->dokterPoli);
        $spri = $this->spri($poli)->assertCreated()->assertJsonPath('data.status', 'menunggu')->assertJsonPath('data.asal', 'poliklinik')
            ->assertJsonPath('data.kelas_diminta.kode', 'UJI28-K3')->assertJsonPath('data.bangsal_pilihan', null)
            ->assertJsonPath('data.spri.no_surat_bpjs', null)->assertJsonPath('data.spri.tanggal_rencana', self::SENIN)
            ->assertJsonPath('data.tempat_tidur', null)->assertJsonPath('data.diminta_oleh.id', $this->dokterPoli->id)
            ->assertJsonPath('data.kunjungan.jenis_kunjungan', 'poliklinik')->assertJsonPath('data.lama_menunggu_menit', 0);
        $this->assertSesuaiKontrak($spri, 'POST', '/kunjungan/{id}/spri');
        $this->assertStringContainsString('no-store', (string) $spri->headers->get('Cache-Control'));
        $idPoli = (string) $spri->json('data.id');
        // Jam SPRI = awal rawat inap untuk 2.29; perintah perpindahan ikut tercatat, alasan klinis tidak ke audit.
        $this->assertTrue(Carbon::parse((string) $spri->json('data.diminta_pada'))->equalTo(now()));
        $baris = DB::table('kunjungan.permintaan_tempat_tidur')->where('id', $idPoli)->first();
        $perintah = DB::table('kunjungan.perpindahan_pos')->where('id', $baris->perpindahan_pos_id)->first();
        $this->assertSame(['poliklinik', 'rawat_inap', 'menunggu_kamar', 'rawat_jalan.pemeriksaan.sahkan', $this->dokterPoli->id],
            [$perintah->pos_asal, $perintah->pos_tujuan, $perintah->status, $perintah->izin_perintah, $perintah->diputuskan_oleh]);
        $this->assertSame(['spri', 'aktif'], [DB::table('kunjungan.surat_kontrol')->where('id', $baris->surat_kontrol_id)->value('jenis'),
            DB::table('kunjungan.surat_kontrol')->where('id', $baris->surat_kontrol_id)->value('status')]);
        $audit = (string) $this->assertAuditTerakhir('rawat_inap.permintaan_kamar_dibuat', $idPoli)->sesudah;
        $this->assertStringNotContainsString('Observasi', $audit);
        // Satu permintaan aktif per kunjungan.
        $this->spri($poli)->assertStatus(409)->assertJsonPath('error.kode', 'permintaan_kamar_sudah_ada');

        // Disposisi IGD → rawat inap memakai layanan yang sama: SPRI internal + permintaan tanpa kelas.
        $this->travel(10)->minutes();
        $igd = $this->kunjunganIgdMenungguKamar();
        $idIgd = (string) DB::table('kunjungan.permintaan_tempat_tidur')->where('kunjungan_id', $igd)->value('id');
        $this->assertSame('igd', DB::table('kunjungan.permintaan_tempat_tidur')->where('id', $idIgd)->value('asal'));
        // Jenis tugas menunggu kamar dipasang nonaktif (keputusan pemilik 10 Nov 2026): belum ada tugas.
        $this->assertFalse(JenisTugas::query()->where('kode', 'PERMINTAAN_KAMAR_MENUNGGU')->value('aktif'));
        $this->assertFalse(Tugas::query()->where('objek_id', $idIgd)->exists());

        // SPRI rawat terencana minggu depan: menunggu sejak awal tanggal rencana, jadi tidak mendahului yang menunggu sekarang.
        $rencana = $this->kunjunganPoli();
        $idRencana = (string) $this->spri($rencana, ['tanggal_rencana' => '2026-11-09'])->assertCreated()
            ->assertJsonPath('data.lama_menunggu_menit', 0)->json('data.id');

        $this->travel(5)->minutes();
        $this->masukSebagai($this->admisi);
        $antrean = $this->minta('GET', self::URL)->assertOk();
        $this->assertSesuaiKontrak($antrean, 'GET', '/permintaan-tempat-tidur');
        $baris = collect($antrean->json('data'))->whereIn('id', [$idPoli, $idIgd, $idRencana])->values();
        // Paling lama menunggu di atas; lama menunggu dihitung server.
        $this->assertSame([$idPoli, $idIgd, $idRencana], $baris->pluck('id')->all());
        $this->assertSame([15, 5, 0], $baris->pluck('lama_menunggu_menit')->all());
        $this->assertTrue(Carbon::parse((string) $baris[2]['menunggu_sejak'])->equalTo(Carbon::parse('2026-11-09 00:00', (string) config('instalasi.zona_waktu'))));
        $this->assertSame('UJI28-K3', $baris[0]['kelas_diminta']['kode']);
        $this->assertNull($baris[1]['kelas_diminta']);
        $this->assertSame('igd', $baris[1]['kunjungan']['jenis_kunjungan']);
        $this->assertArrayNotHasKey('alasan', $baris[0]);
        $this->minta('GET', self::URL.'?status=dialokasikan')->assertOk()->assertJsonCount(0, 'data');
        $this->minta('GET', self::URL.'?status=batal')->assertStatus(422);
        $this->assertStringContainsString('no-store', (string) $antrean->headers->get('Cache-Control'));
    }

    public function test_alokasi_kosong_siap_jadi_dipesan_di_peta_dan_papan_igd_alokasi_kedua_409(): void
    {
        $poli = $this->permintaanPoli();
        $igdKunjungan = $this->kunjunganIgdMenungguKamar();
        $igd = (string) DB::table('kunjungan.permintaan_tempat_tidur')->where('kunjungan_id', $igdKunjungan)->value('id');

        $this->masukSebagai($this->dokterIgd);
        $papanAwal = $this->minta('GET', '/api/v1/igd/papan')->assertOk()->assertJsonPath('data.0.permintaan_kamar.status', 'menunggu')
            ->assertJsonPath('data.0.permintaan_kamar.id', $igd)->assertJsonPath('data.0.permintaan_kamar.tempat_tidur', null);
        $this->assertSesuaiKontrak($papanAwal, 'GET', '/igd/papan');
        $etag = (string) $papanAwal->headers->get('ETag');

        $this->masukSebagai($this->admisi);
        $hasil = $this->alokasi($poli, $this->id['UJI28-101-1'])->assertOk()->assertJsonPath('data.status', 'dialokasikan')
            ->assertJsonPath('data.tempat_tidur.kode', 'UJI28-101-1')->assertJsonPath('data.tempat_tidur.bangsal.kode', 'UJI28-BA');
        $this->assertSesuaiKontrak($hasil, 'POST', '/permintaan-tempat-tidur/{id}/alokasi');
        $this->assertTrue(Carbon::parse((string) $hasil->json('data.dialokasikan_pada'))->equalTo(now()));
        $riwayat = DB::table('kunjungan.riwayat_status_tempat_tidur')->where('tempat_tidur_id', $this->id['UJI28-101-1'])->first();
        $this->assertSame(['kosong_siap', 'dipesan', 'alokasi', $this->admisi->id], [$riwayat->status_sebelum, $riwayat->status, $riwayat->sumber_kejadian, $riwayat->oleh]);
        $this->assertAuditTerakhir('rawat_inap.tempat_tidur_dialokasikan', $poli);
        // Peta TT (2.27) membaca status yang sama.
        $this->minta('GET', '/api/v1/peta-tempat-tidur?bangsal_id='.$this->id['UJI28-BA'])->assertOk()
            ->assertJsonPath('data.0.kode', 'UJI28-101-1')->assertJsonPath('data.0.status', 'dipesan')->assertJsonPath('data.0.dapat_dipilih', false);

        // Alokasi kedua ke TT yang sama (pemenang balapan sudah commit) → 409; permintaan yang sudah dialokasikan → 409.
        $kedua = $this->alokasi($igd, $this->id['UJI28-101-1'])->assertStatus(409)->assertJsonPath('error.kode', 'tempat_tidur_tidak_tersedia')
            ->assertJsonPath('error.rincian.0.status_sekarang', 'dipesan');
        $this->assertSesuaiKontrak($kedua, 'POST', '/permintaan-tempat-tidur/{id}/alokasi');
        $this->alokasi($poli, $this->id['UJI28-101-2'])->assertStatus(409)->assertJsonPath('error.kode', 'permintaan_kamar_tidak_menunggu');
        $this->assertSame('menunggu', DB::table('kunjungan.permintaan_tempat_tidur')->where('id', $igd)->value('status'));

        // TT dibersihkan, ditutup, atau belum berlaku → 409; kelas lain dari kelas diminta → 422.
        DB::table('inti.tempat_tidur')->where('id', $this->id['UJI28-101-2'])->update(['status_saat_ini' => 'dibersihkan']);
        $this->alokasi($igd, $this->id['UJI28-101-2'])->assertStatus(409)->assertJsonPath('error.kode', 'tempat_tidur_tidak_tersedia');
        DB::table('inti.tempat_tidur')->where('id', $this->id['UJI28-101-2'])->update(['status_saat_ini' => 'kosong_siap', 'berlaku_sampai' => '2026-11-01']);
        $this->alokasi($igd, $this->id['UJI28-101-2'])->assertStatus(409)->assertJsonPath('error.kode', 'tempat_tidur_ditutup');
        DB::table('inti.tempat_tidur')->where('id', $this->id['UJI28-101-2'])->update(['berlaku_sampai' => null, 'berlaku_dari' => '2026-11-03']);
        $this->alokasi($igd, $this->id['UJI28-101-2'])->assertStatus(409)->assertJsonPath('error.kode', 'tempat_tidur_tidak_tersedia');
        $poliKedua = $this->permintaanPoli();
        $this->masukSebagai($this->admisi);
        $kelas = $this->alokasi($poliKedua, $this->id['UJI28-101-3'])->assertStatus(422)->assertJsonPath('error.kode', 'kelas_tidak_sesuai');
        $this->assertSesuaiKontrak($kelas, 'POST', '/permintaan-tempat-tidur/{id}/alokasi');
        // Isian dan id tidak sah.
        foreach ([null, 'ngawur', (string) Str::uuid7()] as $tt) {
            $this->minta('POST', self::URL."/$igd/alokasi", $tt === null ? [] : ['tempat_tidur_id' => $tt])->assertStatus(422)
                ->assertJsonPath('error.rincian.0.kolom', 'tempat_tidur_id');
        }
        $this->assertSesuaiKontrak($this->alokasi((string) Str::uuid7(), $this->id['UJI28-101-3'])->assertNotFound(), 'POST', '/permintaan-tempat-tidur/{id}/alokasi');
        $this->alokasi('bukan-uuid', $this->id['UJI28-101-3'])->assertNotFound();

        // Permintaan IGD tanpa kelas diminta menerima kelas apa pun; papan IGD menampilkan TT dipesan, ETag berubah.
        $this->alokasi($igd, $this->id['UJI28-101-3'])->assertOk()->assertJsonPath('data.tempat_tidur.kode', 'UJI28-101-3');
        $this->masukSebagai($this->dokterIgd);
        $this->headerTambahan = ['If-None-Match' => $etag];
        $papan = $this->minta('GET', '/api/v1/igd/papan')->assertOk();
        $this->headerTambahan = [];
        $papan->assertJsonPath('data.0.permintaan_kamar.status', 'dialokasikan')
            ->assertJsonPath('data.0.permintaan_kamar.tempat_tidur', ['id' => $this->id['UJI28-101-3'], 'kode' => 'UJI28-101-3',
                'bangsal' => ['id' => $this->id['UJI28-BA'], 'kode' => 'UJI28-BA', 'nama' => 'Bangsal UJI28-BA']]);
        $this->assertNotSame($etag, $papan->headers->get('ETag'));
        $this->assertSesuaiKontrak($papan, 'GET', '/igd/papan');
    }

    public function test_permintaan_batal_lewat_disposisi_lain_dan_pembatalan_kunjungan_tt_kembali_kosong_siap(): void
    {
        $kunjungan = $this->kunjunganIgdMenungguKamar();
        $igd = (string) DB::table('kunjungan.permintaan_tempat_tidur')->where('kunjungan_id', $kunjungan)->value('id');
        $this->masukSebagai($this->admisi);
        $this->alokasi($igd, $this->id['UJI28-201-1'])->assertOk();

        // Pasien APS sesudah menunggu kamar: kejadian DisposisiDicatat menutup permintaan, TT dipesan kembali kosong siap.
        $this->masukSebagai($this->dokterIgd);
        $this->minta('POST', "/api/v1/kunjungan/$kunjungan/disposisi", ['tujuan' => 'aps', 'alasan' => 'Keluarga menolak rawat inap'])->assertCreated();
        $p = DB::table('kunjungan.permintaan_tempat_tidur')->where('id', $igd)->first();
        $this->assertSame(['batal', $this->dokterIgd->id], [$p->status, $p->dibatalkan_oleh]);
        $this->assertStringContainsString('aps', (string) $p->alasan_batal);
        $this->assertSame('batal', DB::table('kunjungan.surat_kontrol')->where('id', $p->surat_kontrol_id)->value('status'));
        $this->assertSame('kosong_siap', DB::table('inti.tempat_tidur')->where('id', $this->id['UJI28-201-1'])->value('status_saat_ini'));
        $kembali = DB::table('kunjungan.riwayat_status_tempat_tidur')->where('tempat_tidur_id', $this->id['UJI28-201-1'])
            ->where('sumber_kejadian', 'alokasi_batal')->first();
        $this->assertNotNull($kembali);
        $this->assertSame(['dipesan', 'kosong_siap', $this->dokterIgd->id], [$kembali->status_sebelum, $kembali->status, $kembali->oleh]);
        $this->assertAuditTerakhir('rawat_inap.permintaan_kamar_dibatalkan', $igd);
        $this->masukSebagai($this->dokterIgd);
        $this->minta('GET', '/api/v1/igd/papan')->assertOk()->assertJsonCount(0, 'data');

        // Kunjungan IGD (masih terdaftar) dibatalkan sesudah TT dialokasikan: kejadian KunjunganDibatalkan membatalkan
        // permintaan, TT kembali kosong siap, dan permintaan hilang dari antrean.
        $kedua = $this->kunjunganIgdMenungguKamar();
        $idKedua = (string) DB::table('kunjungan.permintaan_tempat_tidur')->where('kunjungan_id', $kedua)->value('id');
        $this->masukSebagai($this->admisi);
        $this->alokasi($idKedua, $this->id['UJI28-101-1'])->assertOk();
        $petugas = $this->petugasPendaftaran();
        $this->masukSebagai($petugas);
        $this->minta('POST', "/api/v1/kunjungan/$kedua/batal", ['alasan_batal' => 'Pasien salah pilih (uji sintetis)'])->assertOk();
        $this->assertSame(['batal', $petugas->id], [DB::table('kunjungan.permintaan_tempat_tidur')->where('id', $idKedua)->value('status'),
            DB::table('kunjungan.permintaan_tempat_tidur')->where('id', $idKedua)->value('dibatalkan_oleh')]);
        $this->assertSame('kosong_siap', DB::table('inti.tempat_tidur')->where('id', $this->id['UJI28-101-1'])->value('status_saat_ini'));
        $lepas = DB::table('kunjungan.riwayat_status_tempat_tidur')->where('tempat_tidur_id', $this->id['UJI28-101-1'])
            ->where('sumber_kejadian', 'alokasi_batal')->first();
        $this->assertNotNull($lepas);
        $this->assertSame(['dipesan', 'kosong_siap', $petugas->id], [$lepas->status_sebelum, $lepas->status, $lepas->oleh]);
        $this->masukSebagai($this->admisi);
        $this->assertFalse(collect($this->minta('GET', self::URL)->assertOk()->json('data'))->whereIn('id', [$igd, $idKedua])->isNotEmpty());
        $this->alokasi($idKedua, $this->id['UJI28-101-2'])->assertStatus(409)->assertJsonPath('error.rincian.0.status', 'batal');
    }

    public function test_izin_spri_sel_matriks_klinis_dan_izin_antrean_alokasi(): void
    {
        $poli = $this->kunjunganPoli();
        $url = "/api/v1/kunjungan/$poli/spri";

        // Tanpa izin sel matriks: 403 sebelum isian dibaca.
        $this->masukSebagai($this->buatPengguna());
        $this->assertSesuaiKontrak($this->minta('POST', $url, [])->assertForbidden(), 'POST', '/kunjungan/{id}/spri');
        $this->minta('GET', self::URL)->assertForbidden();
        $this->minta('POST', self::URL.'/'.Str::uuid7().'/alokasi', [])->assertForbidden();

        // rawat_jalan.pemeriksaan.sahkan kini izin klinis (keputusan pemilik 10 Nov 2026): tanpa SIP/SPK berlaku → 403.
        $this->assertTrue((bool) DB::table('akses.izin')->where('kode', 'rawat_jalan.pemeriksaan.sahkan')->value('butuh_kewenangan_klinis'));
        /** @var Pegawai $perawat */
        $perawat = Pegawai::factory()->create(['profesi' => 'perawat']);
        $tanpaSip = $this->buatPengguna(['pegawai_id' => $perawat->id]);
        $this->tugaskan($tanpaSip, $this->buatPeran(['rawat_jalan.pemeriksaan']));
        $this->masukSebagai($tanpaSip);
        $this->spri($poli)->assertForbidden()->assertJsonPath('error.kode', 'izin_praktik_tidak_berlaku');
        // Cakupan unit lain → 403.
        $unitLain = UnitOrganisasi::query()->create(['kode' => 'UJI28-POLI-L', 'nama' => 'Poli lain (uji)', 'jenis' => 'poli', 'berlaku_dari' => '2026-01-01']);
        $luar = $this->akunDokter($this->dokter());
        $this->tugaskan($luar, $this->buatPeran(['rawat_jalan.pemeriksaan']), cakupan: new Cakupan('unit', $unitLain->id));
        $this->masukSebagai($luar);
        $this->spri($poli)->assertForbidden();

        $this->masukSebagai($this->dokterPoli);
        foreach ([['kelas_rawat_id' => null], ['kelas_rawat_id' => 'ngawur'], ['alasan' => 'abc'], ['kelas_rawat_id' => (string) Str::uuid7()],
            ['bangsal_id' => (string) Str::uuid7()], ['tanggal_rencana' => '2026-11-01']] as $salah) {
            $this->assertSesuaiKontrak($this->spri($poli, $salah)->assertStatus(422), 'POST', '/kunjungan/{id}/spri');
        }
        $this->spri((string) Str::uuid7())->assertNotFound();
        $this->spri('bukan-uuid')->assertNotFound();
        // Kunjungan IGD lewat disposisi; kunjungan selesai → 409.
        $this->masukSebagai($this->dokterIgd);
        $igd = (string) $this->minta('POST', '/api/v1/kunjungan-igd', ['pasien_id' => $this->pasien()->id, 'cara_bayar' => 'non_jkn'])->assertCreated()->json('data.id');
        $this->masukSebagai($this->dokterPoli);
        $this->spri($igd)->assertStatus(409)->assertJsonPath('error.kode', 'spri_igd_lewat_disposisi')->assertJsonPath('error.rincian', []);
        // Kunjungan poli yang belum mulai diperiksa (terdaftar, bisa batal otomatis jam tutup) → 409.
        $terdaftar = $this->kunjunganPoli(periksa: false);
        $this->masukSebagai($this->dokterPoli);
        $belum = $this->spri($terdaftar)->assertStatus(409)->assertJsonPath('error.kode', 'status_tidak_sesuai')->assertJsonPath('error.rincian.0.status', 'terdaftar');
        $this->assertSesuaiKontrak($belum, 'POST', '/kunjungan/{id}/spri');
        Kunjungan::query()->whereKey($poli)->update(['status' => 'selesai']);
        $selesai = $this->spri($poli)->assertStatus(409)->assertJsonPath('error.kode', 'status_tidak_sesuai')->assertJsonPath('error.rincian.0.status', 'selesai');
        $this->assertSesuaiKontrak($selesai, 'POST', '/kunjungan/{id}/spri');
        // Sel matriks poliklinik → rawat_inap nonaktif → 422.
        Kunjungan::query()->whereKey($poli)->update(['status' => 'dilayani']);
        DB::table('kunjungan.aturan_perpindahan')->where('pos_asal', 'poliklinik')->where('pos_tujuan', 'rawat_inap')->update(['status' => 'nonaktif']);
        $this->spri($poli)->assertStatus(422)->assertJsonPath('error.kode', 'perpindahan_di_luar_matriks');
        DB::table('kunjungan.aturan_perpindahan')->where('pos_asal', 'poliklinik')->where('pos_tujuan', 'rawat_inap')->update(['status' => 'aktif']);
        $this->assertFalse(DB::table('kunjungan.permintaan_tempat_tidur')->where('kunjungan_id', $poli)->exists());
        $this->spri($poli)->assertCreated();
        $permintaan = (string) DB::table('kunjungan.permintaan_tempat_tidur')->where('kunjungan_id', $poli)->value('id');

        // Admisi lihat saja: antrean boleh, alokasi 403. Cakupan unit ruang RA: TT bangsal BB → 403, BA → boleh.
        $lihat = $this->buatPengguna();
        $peran = $this->buatPeran([]);
        PeranMenu::query()->create(['peran_id' => $peran->id, 'menu_id' => Menu::query()->where('kode', 'pendaftaran.admisi')->value('id')]);
        PeranIzin::query()->create(['peran_id' => $peran->id, 'izin_id' => Izin::query()->where('kode', 'pendaftaran.admisi.lihat')->value('id')]);
        $this->tugaskan($lihat, $peran);
        $this->masukSebagai($lihat);
        $this->minta('GET', self::URL)->assertOk();
        $this->alokasi($permintaan, $this->id['UJI28-101-1'])->assertForbidden();
        $ruangA = $this->buatPengguna();
        $this->tugaskan($ruangA, $this->buatPeran(['pendaftaran.admisi']), cakupan: new Cakupan('unit', $this->id['UJI28-RA']));
        $this->masukSebagai($ruangA);
        $this->assertSesuaiKontrak($this->alokasi($permintaan, $this->id['UJI28-201-1'])->assertForbidden(), 'POST', '/permintaan-tempat-tidur/{id}/alokasi');
        $this->alokasi($permintaan, $this->id['UJI28-101-1'])->assertOk();
    }

    public function test_eskalasi_menunggu_kamar_ke_kepala_igd_bila_jenis_tugas_diaktifkan(): void
    {
        /** @var Pegawai $kepala */
        $kepala = Pegawai::factory()->create(['profesi' => 'dokter']);
        $akunKepala = $this->buatPengguna(['pegawai_id' => $kepala->id]);
        $unitIgd = UnitOrganisasi::query()->create(['kode' => 'UJI28-IGD', 'nama' => 'Instalasi Gawat Darurat (uji)', 'jenis' => 'instalasi',
            'berlaku_dari' => '2026-01-01', 'kepala_pegawai_id' => $kepala->id]);

        // RS mengaktifkan dan menyetel batas di layar Jenis Tugas (PATCH /jenis-tugas/{id}); angka uji, bukan nilai klinis.
        $admin = $this->buatPengguna();
        $this->tugaskan($admin, $this->buatPeran(['master_data.jenis_tugas']));
        $this->masukSebagai($admin);
        $jenis = JenisTugas::query()->where('kode', 'PERMINTAAN_KAMAR_MENUNGGU')->firstOrFail();
        $this->mintaDenganVersi('PATCH', "/api/v1/jenis-tugas/{$jenis->id}", (string) $jenis->revisi, [
            'aktif' => true, 'batas_menit' => 60, 'unit_teguran_id' => $unitIgd->id,
            'eskalasi' => ['pengingat_menit' => -30, 'teguran_menit' => 0, 'eskalasi_menit' => 120], 'dasar_perubahan' => 'SK uji sintetis',
        ])->assertOk()->assertJsonPath('data.aktif', true);

        // SPRI poli tidak dieskalasi ke IGD.
        $poli = $this->permintaanPoli();
        $this->assertFalse(Tugas::query()->where('objek_id', $poli)->exists());

        $kunjungan = $this->kunjunganIgdMenungguKamar();
        $igd = (string) DB::table('kunjungan.permintaan_tempat_tidur')->where('kunjungan_id', $kunjungan)->value('id');
        $tugas = Tugas::query()->where('objek_id', $igd)->where('pelaksana_id', $this->id['peran_admisi'])->firstOrFail();
        $this->assertSame(['peran', $unitIgd->id, 'terbuka', 'permintaan_tempat_tidur'], [$tugas->pelaksana_jenis, $tugas->unit_id, $tugas->status, $tugas->objek_jenis]);

        // Lewat batas → teguran (tingkat 2) ke Kepala IGD dengan jam tercatat.
        $this->travel(61)->minutes();
        $this->artisan('notifikasi:proses-eskalasi')->assertSuccessful();
        $this->assertSame(2, $tugas->refresh()->tingkat_saat_ini);
        $teguran = DB::table('inti.tugas_kejadian')->where('tugas_id', $tugas->id)->where('jenis', 'teguran')->first();
        $this->assertNotNull($teguran);
        $this->assertContains($akunKepala->id, json_decode((string) $teguran->penerima, true));
        $this->assertTrue(Carbon::parse((string) $teguran->terjadi_pada)->equalTo(now()));

        // Alokasi menyelesaikan tugas.
        $this->masukSebagai($this->admisi);
        $this->alokasi($igd, $this->id['UJI28-101-1'])->assertOk();
        $this->assertSame('selesai', $tugas->refresh()->status);

        // Permintaan IGD lain yang batal (pasien pulang) → tugasnya batal.
        $lain = $this->kunjunganIgdMenungguKamar();
        $idLain = (string) DB::table('kunjungan.permintaan_tempat_tidur')->where('kunjungan_id', $lain)->value('id');
        $this->assertTrue(Tugas::query()->where('objek_id', $idLain)->where('status', 'terbuka')->exists());
        $this->masukSebagai($this->dokterIgd);
        $this->minta('POST', "/api/v1/kunjungan/$lain/disposisi", ['tujuan' => 'pulang', 'alasan' => 'Kondisi membaik (uji sintetis)'])->assertCreated();
        $this->assertSame(['batal'], Tugas::query()->where('objek_id', $idLain)->pluck('status')->unique()->values()->all());
    }

    public function test_tutup_tt_dipesan_atau_terisi_ditolak_dan_usulan_matriks_bukan_klinis_gagal_diterapkan(): void
    {
        // Tinjauan #198 butir 1: TT dikunci lalu status dibaca; dipesan/terisi → 409.
        $penyunting = $this->buatPengguna();
        $kelola = app(KelolaTempatTidur::class);
        foreach (['dipesan', 'terisi'] as $status) {
            DB::table('inti.tempat_tidur')->where('id', $this->id['UJI28-101-1'])->update(['status_saat_ini' => $status]);
            try {
                $kelola->tutup($penyunting, TempatTidur::query()->findOrFail($this->id['UJI28-101-1']), '0', SuntinganBerversi::hariIni(), 'Renovasi (uji)', 'SK uji');
                $this->fail('Penutupan TT '.$status.' seharusnya ditolak.');
            } catch (ApiError $e) {
                $this->assertSame([409, 'tempat_tidur_terisi'], [$e->getStatusCode(), $e->kode]);
            }
        }
        $this->assertNull(TempatTidur::query()->findOrFail($this->id['UJI28-101-1'])->berlaku_sampai);
        DB::table('inti.tempat_tidur')->where('id', $this->id['UJI28-101-1'])->update(['status_saat_ini' => 'kosong_siap']);
        $kelola->tutup($penyunting, TempatTidur::query()->findOrFail($this->id['UJI28-101-1']), '0', SuntinganBerversi::hariIni(), 'Renovasi (uji)', 'SK uji');
        $this->assertNotNull(TempatTidur::query()->findOrFail($this->id['UJI28-101-1'])->berlaku_sampai);

        // Tinjauan #194 butir 2: izin yang berubah tidak klinis sesudah diajukan → usulan gagal diterapkan.
        $peran = $this->buatPeran(['master_data.usulan', 'master_data.matriks_perpindahan']);
        [$pengusul, $pemverifikasi, $penyetuju] = [$this->buatPengguna(), $this->buatPengguna(), $this->buatPengguna()];
        foreach ([$pengusul, $pemverifikasi, $penyetuju] as $akun) {
            $this->tugaskan($akun, $peran);
        }
        $sel = DB::table('kunjungan.aturan_perpindahan')->where('pos_asal', 'igd')->where('pos_tujuan', 'pulang')->value('id');
        $this->masukSebagai($pengusul);
        $usulan = (string) $this->minta('POST', '/api/v1/usulan-data-induk', [
            'jenis_data' => 'matriks_perpindahan', 'berlaku_dari' => SuntinganBerversi::hariIni(), 'alasan' => 'Penyesuaian alur uji', 'dasar' => 'SK uji',
            'perubahan' => [['objek_jenis' => 'aturan_perpindahan', 'aksi' => 'ubah', 'objek_id' => $sel, 'nilai_baru' => ['izin_perintah' => 'rawat_jalan.cppt.darurat']]],
        ])->assertCreated()->json('data.id');
        DB::table('akses.izin')->where('kode', 'rawat_jalan.cppt.darurat')->update(['butuh_kewenangan_klinis' => false]);
        $this->masukSebagai($pemverifikasi);
        $this->minta('POST', "/api/v1/usulan-data-induk/$usulan/verifikasi")->assertOk();
        $this->masukSebagai($penyetuju);
        $this->minta('POST', "/api/v1/usulan-data-induk/$usulan/setujui")->assertOk()->assertJsonPath('data.tahap', 'gagal_diterapkan');
        $this->assertSame('igd.disposisi.input', DB::table('kunjungan.aturan_perpindahan')->where('id', $sel)->value('izin_perintah'));
    }

    private function siapkanTempatTidur(): void
    {
        $irna = UnitOrganisasi::query()->create(['kode' => 'UJI28-IRNA', 'nama' => 'Instalasi Rawat Inap (uji)', 'jenis' => 'instalasi', 'berlaku_dari' => '2026-01-01']);
        foreach (['UJI28-RA', 'UJI28-RB'] as $kode) {
            $this->id[$kode] = UnitOrganisasi::query()->create(['kode' => $kode, 'nama' => "Ruang $kode", 'jenis' => 'ruang', 'induk_id' => $irna->id, 'berlaku_dari' => '2026-01-01'])->id;
        }
        foreach (['UJI28-K3', 'UJI28-K1'] as $kode) {
            $this->id[$kode] = KelasRawat::query()->create(['kode' => $kode, 'nama' => "Kelas $kode", 'berlaku_dari' => '2026-01-01'])->id;
        }
        foreach (['UJI28-BA' => 'UJI28-RA', 'UJI28-BB' => 'UJI28-RB'] as $kode => $unit) {
            $this->id[$kode] = Bangsal::query()->create(['kode' => $kode, 'nama' => "Bangsal $kode", 'unit_organisasi_id' => $this->id[$unit], 'status' => 'aktif'])->id;
        }
        $gedung = $this->lokasi(null, 'gedung', 'UJI28', 'UJI28');
        $this->tt($gedung, '101', 'UJI28-BA', ['1' => 'UJI28-K3', '2' => 'UJI28-K3', '3' => 'UJI28-K1']);
        $this->tt($gedung, '201', 'UJI28-BB', ['1' => 'UJI28-K3']);
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
        $ruang = $this->lokasi($gedung, 'ruang', $kodeRuang, "UJI28-$kodeRuang", 'rawat_inap');
        $kamar = Kamar::query()->create(['bangsal_id' => $this->id[$bangsal], 'lokasi_id' => $ruang])->id;
        foreach ($kelas as $kode => $kodeKelas) {
            $kodeTt = "UJI28-$kodeRuang-$kode";
            $this->id[$kodeTt] = TempatTidur::query()->create([
                'kamar_id' => $kamar, 'lokasi_id' => $this->lokasi($ruang, 'tempat_tidur', (string) $kode, $kodeTt), 'kode' => $kodeTt,
                'kelas_rawat_id' => $this->id[$kodeKelas], 'berlaku_dari' => '2026-01-01',
            ])->id;
        }
    }

    /** @param  bool  $periksa  true = dokter poli mulai periksa (status `dilayani`, syarat SPRI) */
    private function kunjunganPoli(bool $periksa = true): string
    {
        $this->masukSebagai($this->petugasPendaftaran());
        $kunjungan = (string) $this->daftarKunjungan($this->pasien(), JadwalPraktik::query()->findOrFail($this->id['jadwal']))
            ->assertCreated()->json('data.id');
        if ($periksa) {
            $this->masukSebagai($this->dokterPoli);
            $this->minta('POST', "/api/v1/kunjungan/$kunjungan/mulai-periksa")->assertOk()->assertJsonPath('data.status', 'dilayani');
        }

        return $kunjungan;
    }

    /** @return string id permintaan kamar poli (kelas K3) */
    private function permintaanPoli(): string
    {
        $kunjungan = $this->kunjunganPoli();
        $this->masukSebagai($this->dokterPoli);

        return (string) $this->spri($kunjungan)->assertCreated()->json('data.id');
    }

    /** @return string id kunjungan IGD yang baru diberi disposisi rawat inap */
    private function kunjunganIgdMenungguKamar(): string
    {
        $this->masukSebagai($this->dokterIgd);
        $kunjungan = (string) $this->minta('POST', '/api/v1/kunjungan-igd', ['pasien_id' => $this->pasien()->id, 'cara_bayar' => 'non_jkn'])
            ->assertCreated()->json('data.id');
        $this->minta('POST', "/api/v1/kunjungan/$kunjungan/disposisi", ['tujuan' => 'rawat_inap', 'alasan' => 'Perlu observasi rawat inap (uji sintetis)',
            'serah_terima' => 'Infus terpasang (uji sintetis)'])->assertCreated();

        return $kunjungan;
    }

    /** @param  array<string, mixed>  $ganti */
    private function spri(string $kunjungan, array $ganti = []): TestResponse
    {
        return $this->minta('POST', "/api/v1/kunjungan/$kunjungan/spri", [
            'alasan' => 'Observasi rawat inap (uji sintetis)', 'kelas_rawat_id' => $this->id['UJI28-K3'], ...$ganti,
        ]);
    }

    private function alokasi(string $permintaan, string $tempatTidur): TestResponse
    {
        return $this->minta('POST', self::URL."/$permintaan/alokasi", ['tempat_tidur_id' => $tempatTidur]);
    }
}
