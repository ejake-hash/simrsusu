<?php

namespace Tests\Feature\Pendaftaran;

use App\Core\ProfilRs\ProfilRs;
use App\Modules\Akses\Models\Peran;
use App\Modules\Integrasi\Application\KelolaKredensial;
use App\Modules\Integrasi\Application\PengirimKiriman;
use App\Modules\Integrasi\Domain\SistemIntegrasi;
use App\Modules\Integrasi\Infrastructure\JawabanHttp;
use App\Modules\Integrasi\Infrastructure\SimulatorIntegrasi;
use App\Modules\MasterData\Models\JadwalPraktik;
use App\Modules\Pendaftaran\Models\IdentitasPasien;
use App\Modules\Pendaftaran\Models\Pasien;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\AksesUji;
use Tests\Support\KlienSpa;
use Tests\Support\KontrakApi;
use Tests\Support\KunjunganUji;
use Tests\TestCase;

/**
 * Tiket 2.7-be: kepesertaan, rujukan, dan SEP terhadap simulator VClaim (kontrak v0.21.0). Peserta sintetis
 * 9990000000001, rujukan SIMFKTP001 bertanggal 2026-10-01; "hari ini" Senin 2 Nov 2026. Tidak ada kredensial nyata.
 */
class SepTest extends TestCase
{
    use AksesUji, KlienSpa, KontrakApi, KunjunganUji;

    private const KARTU = '9990000000001';

    private Pasien $peserta;

    private Peran $peranPetugas;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanKunjungan();
        $this->poli->forceFill(['kode_poli_bpjs' => 'INT'])->save();
        ProfilRs::query()->create(['nama' => 'RS Contoh Pendidikan', 'kode_ppk_bpjs' => 'SIM0RS01']);
        SimulatorIntegrasi::atur(SistemIntegrasi::BpjsVclaim, 'normal');
        app(KelolaKredensial::class)->simpan(SistemIntegrasi::BpjsVclaim, 'sandbox',
            ['cons_id' => '1234', 'secret_key' => 'rahasia-sintetis', 'user_key' => 'kunci-sintetis'], true, false, $this->buatPengguna()->id);
        $this->peserta = $this->pasien();
        $kartu = new IdentitasPasien(['pasien_id' => $this->peserta->id, 'jenis' => 'kartu_bpjs']);
        $kartu->aturNilai(self::KARTU);
        $kartu->save();
        $petugas = $this->buatPengguna();
        $this->peranPetugas = $this->buatPeran(['pendaftaran.pasien', 'pendaftaran.sep']);
        $this->tugaskan($petugas, $this->peranPetugas);
        $this->masukSebagai($petugas);
    }

    protected function tearDown(): void
    {
        SimulatorIntegrasi::jawabanTambahan(SistemIntegrasi::BpjsVclaim, fn () => null);
        parent::tearDown();
    }

    public function test_peserta_dan_rujukan_hampir_habis_ditandai_tanpa_nomor_di_url_atau_audit(): void
    {
        $peserta = $this->baca('/api/v1/bpjs/peserta', '9990-0000-00001')->assertOk()
            ->assertJsonPath('data.status.aktif', true)->assertJsonPath('data.pasien.id', $this->peserta->id)
            ->assertJsonPath('data.nik_samaran', '9900 •••• •••• 0001')->assertJsonMissingPath('data.nik');
        $this->assertSesuaiKontrak($peserta, 'GET', '/bpjs/peserta');
        $this->assertStringContainsString('no-store', (string) $peserta->headers->get('Cache-Control'));
        $audit = DB::table('audit.log_aktivitas')->where('aksi', 'bpjs.peserta.dibaca')->orderByDesc('urutan')->first();
        $this->assertStringNotContainsString(self::KARTU, (string) $audit->sesudah);

        $this->baca('/api/v1/bpjs/peserta', '12345')->assertStatus(422)->assertJsonPath('error.kode', 'nomor_tidak_dikenali');
        $tidakAda = $this->baca('/api/v1/bpjs/peserta', '9990000000999')->assertStatus(422)
            ->assertJsonPath('error.kode', 'bpjs_menolak')->assertJsonPath('error.rincian.0.kode_bpjs', '201');
        $this->assertStringNotContainsString('9990000000999', (string) $tidakAda->json('error.pesan'));

        // Sisa ≤ 7 hari ditandai (FR-01-08): 2026-10-01 + 90 hari = 2026-12-30.
        $rujukan = $this->baca('/api/v1/bpjs/rujukan?tanggal=2026-12-25', 'SIMFKTP001')->assertOk()
            ->assertJsonPath('data.asal', 'fktp')->assertJsonPath('data.berlaku_sampai', '2026-12-30')->assertJsonPath('data.sisa_hari', 5)
            ->assertJsonPath('data.status_masa_berlaku', 'hampir_habis')->assertJsonPath('data.poli_tujuan.poliklinik.id', $this->poli->id);
        $this->assertSesuaiKontrak($rujukan, 'GET', '/bpjs/rujukan');
        $this->baca('/api/v1/bpjs/rujukan?asal=rs', 'SIMRS001')->assertOk()
            ->assertJsonPath('data.asal', 'rs')->assertJsonPath('data.status_masa_berlaku', 'berlaku');
        $this->baca('/api/v1/bpjs/rujukan', 'SIM/../1')->assertStatus(422)->assertJsonPath('error.kode', 'nomor_tidak_dikenali');
    }

    public function test_rujukan_kedaluwarsa_ditolak_dan_hampir_habis_terbit_dengan_peringatan(): void
    {
        $kunjungan = $this->kunjunganJkn();
        config(['integrasi.vclaim_masa_berlaku_rujukan_hari' => 20]);
        $this->ajukan($kunjungan)->assertStatus(422)->assertJsonPath('error.kode', 'rujukan_kedaluwarsa')
            ->assertJsonPath('error.rincian.0.berlaku_sampai', '2026-10-21');
        $this->assertSame(0, DB::table('integrasi.sep')->count());

        config(['integrasi.vclaim_masa_berlaku_rujukan_hari' => 35]);
        $terbit = $this->ajukan($kunjungan)->assertCreated()->assertJsonPath('data.status', 'terbit')
            ->assertJsonPath('data.peringatan.0.kode', 'rujukan_hampir_habis')->assertJsonPath('data.rujukan.status_masa_berlaku', 'hampir_habis');
        $this->assertSesuaiKontrak($terbit, 'POST', '/kunjungan/{id}/sep');
    }

    public function test_sep_terbit_dengan_isi_menurut_catatan_lapangan_dan_idempoten(): void
    {
        $kunjungan = $this->kunjunganJkn();
        $dikirim = null;
        SimulatorIntegrasi::jawabanTambahan(SistemIntegrasi::BpjsVclaim, function (string $metode, string $path, array $isi) use (&$dikirim) {
            $dikirim = $path === '/SEP/2.0/insert' ? $isi['request']['t_sep'] : $dikirim;

            return null;
        });
        $respons = $this->ajukan($kunjungan, [], 'kunci-1')->assertCreated()->assertJsonPath('data.status', 'terbit')
            ->assertJsonPath('data.diagnosis_awal.kode', 'Z00.0')->assertJsonPath('data.rujukan.asal', 'fktp')
            ->assertJsonPath('data.no_sep', fn (string $no) => str_starts_with($no, 'SIM0RS01V'));
        $this->assertSesuaiKontrak($respons, 'POST', '/kunjungan/{id}/sep');
        $this->assertSame([self::KARTU, '2026-11-02', 'SIM0RS01', '2', '1', 'SIMFKTP001', 'INT', '', ''], [$dikirim['noKartu'], $dikirim['tglSep'],
            $dikirim['ppkPelayanan'], $dikirim['jnsPelayanan'], $dikirim['rujukan']['asalRujukan'], $dikirim['rujukan']['noRujukan'],
            $dikirim['poli']['tujuan'], $dikirim['tujuanKunj'], $dikirim['dpjpLayan']]);

        $log = DB::table('integrasi.log_api')->where('endpoint', '/SEP/2.0/insert')->sole();
        foreach ([self::KARTU, $this->peserta->no_rm, 'SIMFKTP001'] as $rahasia) {
            $this->assertStringNotContainsString($rahasia, (string) $log->permintaan.$log->respons);
        }
        $audit = (string) $this->assertAuditTerakhir('sep.diterbitkan', $respons->json('data.id'))->sesudah;
        $this->assertStringNotContainsString(self::KARTU, $audit);

        // Kunci sama → respons pertama tanpa mengirim lagi; tanpa kunci → sudah terbit.
        $this->ajukan($kunjungan, [], 'kunci-1')->assertCreated()->assertJsonPath('data.id', $respons->json('data.id'));
        $this->assertSame(1, DB::table('integrasi.log_api')->where('endpoint', '/SEP/2.0/insert')->count());
        $this->ajukan($kunjungan)->assertStatus(409)->assertJsonPath('error.kode', 'sep_sudah_terbit')
            ->assertJsonPath('error.rincian.0.sep_id', $respons->json('data.id'));
    }

    public function test_galat_bpjs_ditampilkan_apa_adanya_dan_kunjungan_tetap_tersimpan(): void
    {
        $kunjungan = $this->kunjunganJkn();
        SimulatorIntegrasi::jawabanTambahan(SistemIntegrasi::BpjsVclaim, fn (string $metode, string $path) => $path === '/SEP/2.0/insert'
            ? new JawabanHttp(200, (string) json_encode(['metaData' => ['code' => '201', 'message' => 'Kuota SEP sintetis habis'], 'response' => null]), 5, null)
            : null);
        $ditolak = $this->ajukan($kunjungan)->assertStatus(422)->assertJsonPath('error.kode', 'bpjs_menolak')
            ->assertJsonPath('error.rincian.0.kode_bpjs', '201')->assertJsonPath('error.rincian.0.pesan_bpjs', 'Kuota SEP sintetis habis');
        $this->assertSesuaiKontrak($ditolak, 'POST', '/kunjungan/{id}/sep');
        $this->assertSame('ditolak', DB::table('integrasi.sep')->where('kunjungan_id', $kunjungan)->value('status'));
        $this->assertSame('terdaftar', DB::table('kunjungan.kunjungan')->where('id', $kunjungan)->value('status'));

        // Petugas boleh mengajukan ulang.
        SimulatorIntegrasi::jawabanTambahan(SistemIntegrasi::BpjsVclaim, fn () => null);
        $this->ajukan($kunjungan)->assertCreated()->assertJsonPath('data.status', 'terbit');
    }

    public function test_circuit_breaker_terbuka_simpan_terbitkan_nanti_lewat_kotak_kerja(): void
    {
        $siang = $this->jadwal($this->dokter(), ['jam_mulai' => '13:00', 'jam_selesai' => '16:00']);
        $pertama = $this->kunjunganJkn();
        $kedua = $this->kunjunganJkn($siang);
        DB::table('integrasi.status_sistem')->where('tujuan', 'bpjs_vclaim')
            ->update(['cb_status' => 'terbuka', 'cb_sejak' => now(), 'coba_lagi_pada' => now()->addMinutes(5)]);

        $tolak = $this->ajukan($pertama)->assertStatus(503)->assertJsonPath('error.kode', 'integrasi_tidak_tersedia')
            ->assertJsonPath('error.rincian.0.penyebab', 'circuit_breaker_terbuka');
        $this->assertSesuaiKontrak($tolak, 'POST', '/kunjungan/{id}/sep');
        $this->assertSame(0, DB::table('integrasi.sep')->count());

        $tunda = $this->ajukan($pertama, ['tunda' => true])->assertStatus(202)->assertJsonPath('data.status', 'menunggu')
            ->assertJsonPath('data.rujukan.tanggal_rujukan', null);
        $this->assertSesuaiKontrak($tunda, 'POST', '/kunjungan/{id}/sep');
        $this->ajukan($kedua, ['tunda' => true])->assertStatus(202);
        $tugas = fn (string $sepId) => DB::table('inti.tugas')->where('objek_id', $sepId)->where('status', 'terbuka')->first();
        $this->assertSame([$this->peranPetugas->id, 'peran'], [$tugas($tunda->json('data.id'))->pelaksana_id, $tugas($tunda->json('data.id'))->pelaksana_jenis]);
        $this->assertSame('terdaftar', DB::table('kunjungan.kunjungan')->where('id', $pertama)->value('status'));

        // Sirkuit tertutup: pekerja membaca rujukan, memeriksanya, lalu mengirim.
        DB::table('integrasi.status_sistem')->where('tujuan', 'bpjs_vclaim')->update(['cb_status' => 'tertutup', 'coba_lagi_pada' => null]);
        DB::table('integrasi.kotak_keluar')->update(['coba_lagi_pada' => now()]);
        app(PengirimKiriman::class)->proses($tunda->json('data.kiriman_id'));
        $this->assertSame('terbit', DB::table('integrasi.sep')->where('id', $tunda->json('data.id'))->value('status'));
        $this->assertNull($tugas($tunda->json('data.id')));

        // Rujukan sudah kedaluwarsa saat pekerja memeriksa → ditolak simrs tanpa dikirim; tugas diganti dengan alasannya.
        config(['integrasi.vclaim_masa_berlaku_rujukan_hari' => 20]);
        $kirimSebelum = DB::table('integrasi.log_api')->where('endpoint', '/SEP/2.0/insert')->count();
        app(PengirimKiriman::class)->proses(DB::table('integrasi.sep')->where('kunjungan_id', $kedua)->value('kiriman_id'));
        $sep = DB::table('integrasi.sep')->where('kunjungan_id', $kedua)->first();
        $this->assertSame(['ditolak', 'simrs', 'rujukan_kedaluwarsa'], [$sep->status, $sep->galat_sumber, $sep->galat_kode]);
        $this->assertSame($kirimSebelum, DB::table('integrasi.log_api')->where('endpoint', '/SEP/2.0/insert')->count());
        $this->assertStringContainsString('ditolak (rujukan_kedaluwarsa)', (string) $tugas($sep->id)->judul);
    }

    public function test_izin_dan_pemeriksaan_kunjungan(): void
    {
        $nonJkn = $this->daftarKunjungan($this->peserta, $this->jadwal($this->dokter()))->assertCreated()->json('data.id');
        $this->ajukan($nonJkn)->assertStatus(422)->assertJsonPath('error.kode', 'bukan_jkn');
        $kunjungan = $this->kunjunganJkn();
        $this->ajukan($kunjungan, ['no_rujukan' => null])->assertStatus(422)->assertJsonPath('error.kode', 'rujukan_wajib');
        $this->ajukan($kunjungan, ['diagnosis_awal' => 'X99.9'])->assertStatus(422)->assertJsonPath('error.kode', 'referensi_tidak_valid');

        $tanpaIzin = $this->buatPengguna();
        $this->tugaskan($tanpaIzin, $this->buatPeran(['pendaftaran.pasien']));
        $this->masukSebagai($tanpaIzin);
        $this->ajukan($kunjungan)->assertForbidden();
        $this->baca('/api/v1/bpjs/peserta', self::KARTU)->assertForbidden();
    }

    private function kunjunganJkn(?JadwalPraktik $jadwal = null): string
    {
        $penjamin = DB::table('inti.penjamin')->where('jenis', 'bpjs')->value('id') ?? $this->penjamin('bpjs')->id;

        return $this->daftarKunjungan($this->peserta, $jadwal ?? $this->jadwal($this->dokter()), ['cara_bayar' => 'jkn', 'penjamin_id' => $penjamin])
            ->assertCreated()->json('data.id');
    }

    /** @param  array<string, mixed>  $isi */
    private function ajukan(string $kunjungan, array $isi = [], ?string $kunci = null): TestResponse
    {
        $this->headerTambahan = $kunci === null ? [] : ['Idempotency-Key' => $kunci];
        try {
            return $this->minta('POST', "/api/v1/kunjungan/$kunjungan/sep", ['no_rujukan' => 'SIMFKTP001', ...$isi]);
        } finally {
            $this->headerTambahan = [];
        }
    }

    private function baca(string $uri, string $nomor): TestResponse
    {
        $this->headerTambahan = ['X-Nomor' => rawurlencode($nomor)];
        try {
            return $this->minta('GET', $uri);
        } finally {
            $this->headerTambahan = [];
        }
    }
}
