<?php

namespace Tests\Feature\Integrasi;

use App\Core\ProfilRs\ProfilRs;
use App\Modules\Integrasi\Application\KelolaKredensial;
use App\Modules\Integrasi\Application\PengirimKiriman;
use App\Modules\Integrasi\Domain\SistemIntegrasi;
use App\Modules\Integrasi\Infrastructure\SimulatorIntegrasi;
use App\Modules\Integrasi\Jobs\KirimKiriman;
use App\Modules\Integrasi\Jobs\ProsesKotakKeluar;
use App\Modules\MasterData\Application\SuntinganBerversi;
use App\Modules\MasterData\Models\UnitOrganisasi;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Testing\Fakes\QueueFake;
use Tests\Support\AksesUji;
use Tests\Support\KlienSpa;
use Tests\TestCase;

/**
 * Tiket 1.9 butir 5 (catatan lapangan §24, terhadap simulator K8): perubahan kapasitas TT → outbox → bed/update per
 * bangsal × kelas (bed/create bila ruang belum terdaftar), kelas tanpa kode Aplicares tertahan dengan penyebab dan
 * dikirim otomatis sesudah diperbaiki, tidak ada kiriman selama Aplicares belum aktif. Data sintetis.
 */
class AplicaresTest extends TestCase
{
    use AksesUji, KlienSpa;

    private const DASAR = 'SK Direktur 4/2026';

    private const PPK = 'SIM0001R';

    private UnitOrganisasi $unit;

    private int $diproses = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        SimulatorIntegrasi::atur(SistemIntegrasi::Aplicares, 'normal');
        ProfilRs::query()->create(['nama' => 'RS Contoh Pendidikan', 'kode_ppk_bpjs' => self::PPK]);
        $this->unit = UnitOrganisasi::query()->create(['kode' => 'IRNA', 'nama' => 'Instalasi Rawat Inap', 'jenis' => 'instalasi', 'berlaku_dari' => '2026-01-01']);
        $pengelola = $this->buatPengguna();
        $this->tugaskan($pengelola, $this->buatPeran(['master_data.organisasi']));
        $this->masukSebagai($pengelola);
    }

    public function test_tt_baru_terkirim_per_kelas_dengan_create_lalu_update(): void
    {
        $this->aktifkan();
        [$kamar, $kelas3] = $this->kamarDanKelas('SIM3');
        $this->tt($kamar, '1', $kelas3, 'laki_laki');
        $this->tt($kamar, '2', $kelas3, 'campur');
        $this->tt($kamar, '9', $kelas3, 'campur', true);
        $this->pekerja();

        $kiriman = DB::table('integrasi.kotak_keluar')->where('tujuan', 'aplicares')->get();
        $this->assertCount(1, $kiriman, 'Perubahan beruntun sebelum terkirim digabung menjadi satu kiriman per bangsal.');
        $this->assertSame(['terkirim', 'bed_update', 'bangsal'], [$kiriman[0]->status, $kiriman[0]->jenis_resource, $kiriman[0]->agregat_jenis]);
        // TT ekstra tidak dihitung.
        $this->assertSame(['SIM3|MELATI' => ['kodekelas' => 'SIM3', 'koderuang' => 'MELATI', 'namaruang' => 'Bangsal Melati', 'kapasitas' => 2,
            'tersedia' => 2, 'tersediapria' => 1, 'tersediawanita' => 0, 'tersediapriawanita' => 1]], SimulatorIntegrasi::ruangAplicares('sandbox', self::PPK));
        // Ruang belum terdaftar: update ditolak lalu create; keduanya tercatat, angka kapasitas terbaca di log (kode PPK
        // berpola nomor ikut disamarkan di path).
        $log = DB::table('integrasi.log_api')->where('tujuan', 'aplicares')->orderBy('terjadi_pada')->get();
        $this->assertSame(['/bed/update/***', '/bed/create/***'], $log->pluck('endpoint')->all());
        $this->assertSame([false, true], $log->pluck('berhasil')->all());
        $this->assertStringContainsString('"kapasitas":2', (string) $log[1]->permintaan);

        // Perubahan berikutnya: update langsung berhasil.
        $this->tt($kamar, '3', $kelas3, 'perempuan');
        $this->pekerja();
        $this->assertSame(3, SimulatorIntegrasi::ruangAplicares('sandbox', self::PPK)['SIM3|MELATI']['kapasitas']);
        $this->assertSame(2, DB::table('integrasi.kotak_keluar')->where('tujuan', 'aplicares')->where('status', 'terkirim')->count());
        $this->assertSame('/bed/update/***', DB::table('integrasi.log_api')->where('tujuan', 'aplicares')->orderByDesc('terjadi_pada')->value('endpoint'));
    }

    public function test_kelas_tanpa_kode_aplicares_tertahan_lalu_terkirim_sesudah_diperbaiki(): void
    {
        $this->aktifkan();
        [$kamar, $kelas] = $this->kamarDanKelas(null);
        $this->tt($kamar, '1', $kelas);
        $this->pekerja();

        $k = DB::table('integrasi.kotak_keluar')->where('tujuan', 'aplicares')->first();
        $this->assertSame(['tertahan', 'kode_kelas_aplicares_kosong', $this->unit->id], [$k->status, $k->penyebab_tertahan_kode, $k->pemilik_unit_id]);
        $this->assertStringContainsString('III', (string) $k->penyebab_tertahan_pesan);
        $this->assertSame(0, DB::table('integrasi.log_api')->where('tujuan', 'aplicares')->count());

        DB::table('inti.kelas_rawat')->where('id', $kelas)->update(['kode_aplicares' => 'SIM3']);
        DB::table('integrasi.kotak_keluar')->where('id', $k->id)->update(['coba_lagi_pada' => now()->subMinute()]);
        (new ProsesKotakKeluar)->handle(app(PengirimKiriman::class));
        $this->pekerja();
        $this->assertSame('terkirim', DB::table('integrasi.kotak_keluar')->where('id', $k->id)->value('status'));
        $this->assertSame(1, SimulatorIntegrasi::ruangAplicares('sandbox', self::PPK)['SIM3|MELATI']['kapasitas']);
    }

    public function test_kode_ppk_kosong_tertahan(): void
    {
        $this->aktifkan();
        DB::table('inti.profil_rs')->update(['kode_ppk_bpjs' => null]);
        [$kamar, $kelas] = $this->kamarDanKelas('SIM3');
        $this->tt($kamar, '1', $kelas);
        $this->pekerja();
        $this->assertSame(['status' => 'tertahan', 'penyebab_tertahan_kode' => 'kode_ppk_kosong'], (array) DB::table('integrasi.kotak_keluar')->where('tujuan', 'aplicares')
            ->first(['status', 'penyebab_tertahan_kode']));
    }

    public function test_tt_ditutup_mengirim_kapasitas_nol(): void
    {
        $this->aktifkan();
        [$kamar, $kelas] = $this->kamarDanKelas('SIM3');
        $tt = $this->tt($kamar, '1', $kelas);
        $this->pekerja();
        $this->assertSame(1, SimulatorIntegrasi::ruangAplicares('sandbox', self::PPK)['SIM3|MELATI']['kapasitas']);

        DB::table('inti.tempat_tidur')->where('id', $tt)->update(['berlaku_sampai' => now(config('instalasi.zona_waktu'))->subDays(2)->toDateString(), 'berlaku_dari' => '2026-01-01']);
        $this->artisan('integrasi:sinkron-aplicares')->expectsOutput('1 bangsal diantrekan.')->assertSuccessful();
        $this->pekerja();
        $this->assertSame(0, SimulatorIntegrasi::ruangAplicares('sandbox', self::PPK)['SIM3|MELATI']['kapasitas']);
    }

    public function test_tanpa_aplicares_aktif_tidak_ada_kiriman_dan_sinkron_menyusulkan_sesudah_aktif(): void
    {
        [$kamar, $kelas] = $this->kamarDanKelas('SIM3');
        $this->tt($kamar, '1', $kelas);
        $this->artisan('integrasi:sinkron-aplicares')->assertSuccessful();
        $this->assertSame(0, DB::table('integrasi.kotak_keluar')->where('tujuan', 'aplicares')->count());

        $this->aktifkan();
        // TT bertanggal ke depan: tidak dikirim saat disimpan, menunggu sinkron harian pada tanggalnya.
        $besok = now(config('instalasi.zona_waktu'))->addDay()->toDateString();
        $this->minta('POST', '/api/v1/tempat-tidur', ['kamar_id' => $kamar, 'kode' => '2', 'kelas_rawat_id' => $kelas, 'berlaku_dari' => $besok,
            'dasar_perubahan' => self::DASAR])->assertCreated();
        $this->assertSame(0, DB::table('integrasi.kotak_keluar')->where('tujuan', 'aplicares')->count());

        $this->artisan('integrasi:sinkron-aplicares')->expectsOutput('1 bangsal diantrekan.')->assertSuccessful();
        $this->pekerja();
        $this->assertSame(1, SimulatorIntegrasi::ruangAplicares('sandbox', self::PPK)['SIM3|MELATI']['kapasitas']);

        SuntinganBerversi::denganHariAcuan($besok, function () {
            $this->artisan('integrasi:sinkron-aplicares')->assertSuccessful();
            $this->pekerja();
        });
        $this->assertSame(2, SimulatorIntegrasi::ruangAplicares('sandbox', self::PPK)['SIM3|MELATI']['kapasitas']);
    }

    public function test_aplicares_menolak_kredensial_gagal_tanpa_retry(): void
    {
        $this->aktifkan();
        SimulatorIntegrasi::atur(SistemIntegrasi::Aplicares, 'tolak');
        [$kamar, $kelas] = $this->kamarDanKelas('SIM3');
        $this->tt($kamar, '1', $kelas);
        $this->pekerja();
        $k = DB::table('integrasi.kotak_keluar')->where('tujuan', 'aplicares')->first();
        $this->assertSame('gagal', $k->status);
        $this->assertStringContainsString('Unauthorized', (string) $k->error_terakhir);
        $this->assertSame('tertutup', DB::table('integrasi.status_sistem')->where('tujuan', 'aplicares')->value('cb_status'));
    }

    public function test_kelas_dengan_kode_sama_dijumlah_dan_kelas_ditolak_tidak_menghentikan_yang_lain(): void
    {
        $this->aktifkan();
        [$kamar, $kelas3] = $this->kamarDanKelas('SIM3');
        $kelas3Plus = $this->kelas('IIIP', 'SIM3');
        $kelasTolak = $this->kelas('II', 'TOLAK2');
        $this->tt($kamar, '1', $kelas3);
        $this->tt($kamar, '2', $kelas3Plus);
        $this->tt($kamar, '3', $kelasTolak);
        $this->pekerja();

        $ruang = SimulatorIntegrasi::ruangAplicares('sandbox', self::PPK);
        $this->assertSame(['SIM3|MELATI'], array_keys($ruang));
        $this->assertSame(2, $ruang['SIM3|MELATI']['kapasitas']);
        $k = DB::table('integrasi.kotak_keluar')->where('tujuan', 'aplicares')->first();
        $this->assertSame('gagal', $k->status);
        // Alasan penolakan update tetap terbaca walau create juga ditolak.
        $this->assertStringContainsString('TOLAK2|MELATI: Aplicares: Kode kelas tidak dikenal (kode 0); bed/create: Aplicares: Kode kelas tidak dikenal', (string) $k->error_terakhir);
    }

    public function test_ruang_yang_hilang_diturunkan_ke_nol(): void
    {
        $this->aktifkan();
        [$kamar, $kelas3] = $this->kamarDanKelas('SIM3');
        $tt = $this->tt($kamar, '1', $kelas3);
        $this->pekerja();
        $kelas2 = $this->kelas('II', 'SIM2');
        // Kelas TT diubah di tempat (tanpa versi kelas baru): ruang SIM3 tidak ada lagi di keadaan terkini.
        DB::table('inti.tempat_tidur')->where('id', $tt)->update(['kelas_rawat_id' => $kelas2]);
        $this->artisan('integrasi:sinkron-aplicares')->assertSuccessful();
        $this->pekerja();

        $ruang = SimulatorIntegrasi::ruangAplicares('sandbox', self::PPK);
        $this->assertSame([0, 1], [$ruang['SIM3|MELATI']['kapasitas'], $ruang['SIM2|MELATI']['kapasitas']]);
        $this->assertSame(['SIM2' => 1, 'SIM3' => 0], DB::table('integrasi.ruang_aplicares')->orderBy('kodekelas')->pluck('kapasitas', 'kodekelas')->all());

        // Sinkron berikutnya tidak mengirim ulang ruang yang sudah nol.
        $sebelum = DB::table('integrasi.log_api')->where('tujuan', 'aplicares')->count();
        $this->artisan('integrasi:sinkron-aplicares')->assertSuccessful();
        $this->pekerja();
        $this->assertSame($sebelum + 1, DB::table('integrasi.log_api')->where('tujuan', 'aplicares')->count());
    }

    public function test_bangsal_tertahan_tidak_diberi_kiriman_baru(): void
    {
        $this->aktifkan();
        [$kamar, $kelas] = $this->kamarDanKelas(null);
        $this->tt($kamar, '1', $kelas);
        $this->pekerja();
        $this->tt($kamar, '2', $kelas);
        $this->artisan('integrasi:sinkron-aplicares')->assertSuccessful();
        $this->artisan('integrasi:sinkron-aplicares')->assertSuccessful();
        $this->pekerja();
        $this->assertSame(['tertahan'], DB::table('integrasi.kotak_keluar')->where('tujuan', 'aplicares')->pluck('status')->all());
    }

    public function test_bangsal_yang_sedang_dikirim_pekerja_lain_ditunda_tanpa_dihitung(): void
    {
        $this->aktifkan();
        [$kamar, $kelas] = $this->kamarDanKelas('SIM3');
        $bangsal = DB::table('inti.kamar')->where('id', $kamar)->value('bangsal_id');
        // Pekerja lain (sesi database lain) memegang kunci bangsal ini.
        $lain = DB::connection('pgsql_migrator');
        $lain->select('SELECT pg_advisory_lock(hashtext(?), hashtext(?))', ['integrasi.aplicares', $bangsal]);
        try {
            $this->tt($kamar, '1', $kelas);
            $this->pekerja();
            $k = DB::table('integrasi.kotak_keluar')->where('tujuan', 'aplicares')->first();
            $this->assertSame(['menunggu', 0], [$k->status, (int) $k->percobaan]);
            $this->assertTrue(now()->lt($k->coba_lagi_pada));
            $this->assertSame(0, DB::table('integrasi.log_api')->where('tujuan', 'aplicares')->count());
        } finally {
            $lain->select('SELECT pg_advisory_unlock(hashtext(?), hashtext(?))', ['integrasi.aplicares', $bangsal]);
        }
        DB::table('integrasi.kotak_keluar')->where('id', $k->id)->update(['coba_lagi_pada' => now()->subSecond()]);
        app(PengirimKiriman::class)->proses($k->id);
        $this->assertSame('terkirim', DB::table('integrasi.kotak_keluar')->where('id', $k->id)->value('status'));
    }

    public function test_produksi_ditahan_sampai_isian_diverifikasi(): void
    {
        $this->aktifkan('produksi');
        [$kamar, $kelas] = $this->kamarDanKelas('SIM3');
        $this->tt($kamar, '1', $kelas);
        $this->pekerja();
        $k = DB::table('integrasi.kotak_keluar')->where('tujuan', 'aplicares')->first();
        $this->assertSame(['tertahan', 'isian_belum_diverifikasi'], [$k->status, $k->penyebab_tertahan_kode]);

        config(['integrasi.aplicares_isian_terverifikasi' => true]);
        DB::table('integrasi.kotak_keluar')->where('id', $k->id)->update(['coba_lagi_pada' => now()->subMinute()]);
        (new ProsesKotakKeluar)->handle(app(PengirimKiriman::class));
        $this->pekerja();
        $this->assertSame('terkirim', DB::table('integrasi.kotak_keluar')->where('id', $k->id)->value('status'));
        $this->assertSame(1, SimulatorIntegrasi::ruangAplicares('produksi', self::PPK)['SIM3|MELATI']['kapasitas']);
    }

    private function aktifkan(string $lingkungan = 'sandbox'): void
    {
        app(KelolaKredensial::class)->simpan(SistemIntegrasi::Aplicares, $lingkungan,
            ['cons_id' => '1234', 'secret_key' => 'rahasia-sintetis', 'user_key' => 'kunci-sintetis'], true, false, $this->buatPengguna()->id);
    }

    private function kelas(string $kode, ?string $kodeAplicares): string
    {
        return $this->minta('POST', '/api/v1/kelas-rawat', ['kode' => $kode, 'nama' => 'Kelas '.$kode, 'kode_aplicares' => $kodeAplicares,
            'dasar_perubahan' => self::DASAR])->assertCreated()->json('data.id');
    }

    /** @return array{0: string, 1: string} kamar id, kelas rawat id */
    private function kamarDanKelas(?string $kodeAplicares): array
    {
        $buatLokasi = fn (?string $induk, string $jenis, string $kode, ?string $fungsi = null) => $this->minta('POST', '/api/v1/lokasi', [
            'induk_id' => $induk, 'jenis' => $jenis, 'kode' => $kode, 'nama' => 'Uji '.$kode, 'fungsi' => $fungsi, 'dasar_perubahan' => self::DASAR,
        ])->assertCreated()->json('data.id');
        $ruang = $buatLokasi($buatLokasi($buatLokasi(null, 'gedung', 'B'), 'lantai', '3'), 'ruang', '304', 'rawat_inap');
        $bangsal = $this->minta('POST', '/api/v1/bangsal', ['kode' => 'MELATI', 'nama' => 'Bangsal Melati', 'unit_organisasi_id' => $this->unit->id,
            'dasar_perubahan' => self::DASAR])->assertCreated()->json('data.id');
        $kelas = $this->minta('POST', '/api/v1/kelas-rawat', ['kode' => 'III', 'nama' => 'Kelas III', 'kode_aplicares' => $kodeAplicares,
            'dasar_perubahan' => self::DASAR])->assertCreated()->json('data.id');
        $kamar = $this->minta('POST', '/api/v1/kamar', ['bangsal_id' => $bangsal, 'lokasi_id' => $ruang, 'dasar_perubahan' => self::DASAR])->assertCreated()->json('data.id');

        return [$kamar, $kelas];
    }

    private function tt(string $kamar, string $kode, string $kelas, string $jenisKelamin = 'campur', bool $ekstra = false): string
    {
        return $this->minta('POST', '/api/v1/tempat-tidur', ['kamar_id' => $kamar, 'kode' => $kode, 'kelas_rawat_id' => $kelas,
            'aturan_jenis_kelamin' => $jenisKelamin, 'ekstra' => $ekstra, 'dasar_perubahan' => self::DASAR])->assertCreated()->json('data.id');
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
