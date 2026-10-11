<?php

namespace Tests\Feature;

use App\Core\Log\SqlMasker;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Error basis data tidak boleh membawa nilai (contoh: NIK sintetis) ke berkas log maupun respons.
 * Diperiksa juga potongan 12 digit pertama, karena jejak tumpukan PHP memotong argumen string
 * ke 15 karakter.
 */
class MaskingLogTest extends TestCase
{
    private const NIK = '1234567890123456';

    private string $berkasLog;

    protected function setUp(): void
    {
        parent::setUp();

        $this->berkasLog = storage_path('framework/testing/masking-'.Str::random(8).'.log');
        config([
            'logging.default' => 'stack',
            'logging.channels.stack.channels' => ['single'],
            'logging.channels.single.path' => $this->berkasLog,
        ]);
        app('log')->forgetChannel('stack');
        app('log')->forgetChannel('single');

        $nik = self::NIK;
        Route::middleware('api')->get('/api/v1/uji-masking/tipe', fn () => DB::select('SELECT ?::uuid', [$nik]));
        Route::middleware('api')->get('/api/v1/uji-masking/unik', function () use ($nik) {
            foreach ([1, 2] as $_) {
                DB::table('sdm.pegawai')->insert(['id' => (string) Str::uuid7(), 'nip' => $nik, 'nama' => 'Pegawai Sintetis']);
            }
        });
        Route::middleware('web')->get('/uji-masking/web', fn () => DB::select('SELECT ?::uuid', [$nik]));
    }

    protected function tearDown(): void
    {
        @unlink($this->berkasLog);
        parent::tearDown();
    }

    public function test_error_tipe_tidak_membocorkan_nilai_di_respons_api_dan_log(): void
    {
        $respons = $this->getJson('/api/v1/uji-masking/tipe');

        $respons->assertStatus(500)->assertExactJson([
            'error' => ['kode' => 'kesalahan_server', 'pesan' => 'Terjadi kesalahan pada server.', 'rincian' => []],
        ]);
        $this->assertTidakBocor($respons->getContent());

        $log = $this->isiLog();
        $this->assertTidakBocor($log);
        $this->assertStringContainsString('SQL: SELECT ?::uuid', $log, 'SQL tetap tercatat dengan placeholder.');
        $this->assertStringContainsString(SqlMasker::PENANDA, $log);
    }

    public function test_detail_pelanggaran_unik_tidak_membocorkan_nilai(): void
    {
        $respons = $this->getJson('/api/v1/uji-masking/unik');

        $respons->assertStatus(500);
        $this->assertTidakBocor($respons->getContent());
        $log = $this->isiLog();
        $this->assertTidakBocor($log);
        $this->assertStringContainsString('duplicate key value violates unique constraint', $log);
    }

    public function test_respons_api_tetap_umum_walau_debug_menyala(): void
    {
        config(['app.debug' => true]);

        $respons = $this->getJson('/api/v1/uji-masking/tipe');

        $respons->assertStatus(500)->assertJsonPath('error.kode', 'kesalahan_server');
        $this->assertStringNotContainsString('SQL', $respons->getContent());
        $this->assertTidakBocor($respons->getContent());
    }

    public function test_halaman_web_produksi_tidak_memuat_sql(): void
    {
        config(['app.debug' => false]);

        $respons = $this->get('/uji-masking/web');

        $respons->assertStatus(500);
        $this->assertStringNotContainsString('SQLSTATE', $respons->getContent());
        $this->assertTidakBocor($respons->getContent());
    }

    public function test_log_manual_dengan_binding_pesan_mentah_dan_jejak_argumen_tetap_tersamar(): void
    {
        $cari = function (string $nik) {
            return DB::transaction(fn () => DB::select('SELECT ?::uuid', [$nik]));
        };

        try {
            $cari(self::NIK);
            $this->fail('Kueri seharusnya gagal.');
        } catch (QueryException $e) {
            // Pesan mentah diambil sebelum melewati penangan exception mana pun.
            Log::warning($e->getMessage());
            Log::error('Pencarian gagal', ['exception' => $e]);
        }
        Log::debug('kueri', ['sql' => 'select * from pasien where nik = ?', 'bindings' => [self::NIK]]);

        $log = $this->isiLog();
        $this->assertTidakBocor($log);
        $this->assertStringContainsString('Pencarian gagal', $log);
        $this->assertMatchesRegularExpression('/^#\d+ .*MaskingLogTest/m', $log, 'Jejak tumpukan tetap tercatat.');
        $this->assertDoesNotMatchRegularExpression("/^#\\d+ .*\\('/m", $log, 'Jejak tumpukan tanpa argumen string.');
    }

    private function isiLog(): string
    {
        $this->assertFileExists($this->berkasLog);

        return (string) file_get_contents($this->berkasLog);
    }

    private function assertTidakBocor(string $teks): void
    {
        $this->assertStringNotContainsString(self::NIK, $teks);
        $this->assertStringNotContainsString(substr(self::NIK, 0, 12), $teks);
    }
}
