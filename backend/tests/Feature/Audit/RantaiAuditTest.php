<?php

namespace Tests\Feature\Audit;

use App\Core\Audit\CatatAktivitas;
use App\Core\Audit\RantaiAudit;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Sebagian tes butuh baris yang sudah di-commit (dilihat superuser atau proses lain), jadi tabel
 * audit di simrs_test dikosongkan lewat simrs_migrator sebelum dan sesudah setiap tes.
 */
class RantaiAuditTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['logging.channels.audit_jangkar.path' => storage_path('framework/testing/audit-jangkar-'.Str::random(8).'.log')]);

        $this->kosongkanAudit();
        // Dijalankan sesudah rollback DatabaseTransactions, supaya TRUNCATE tidak menunggu kunci.
        $this->beforeApplicationDestroyed(fn () => $this->kosongkanAudit());
    }

    public function test_rantai_valid_dan_mengikuti_rumus(): void
    {
        $audit = $this->app->make(CatatAktivitas::class);
        foreach (['uji.satu', 'uji.dua', 'uji.tiga'] as $aksi) {
            $audit->catat($aksi, sesudah: ['angka' => 1.5, 'teks' => 'ü', 'z' => null, 'a' => [2, 1]]);
        }

        $baris = DB::table('audit.log_aktivitas')->orderBy('urutan')
            ->selectRaw('urutan, hash_rantai, audit.kanonik_log(log_aktivitas) AS kanonik')->get();

        // hash_rantai = SHA-256(hash sebelumnya || serialisasi kanonik), dihitung ulang di PHP.
        $sebelumnya = RantaiAudit::HASH_AWAL;
        foreach ($baris as $b) {
            $this->assertSame(hash('sha256', $sebelumnya.$b->kanonik), $b->hash_rantai);
            $sebelumnya = $b->hash_rantai;
        }

        $this->artisan('audit:verifikasi-rantai')
            ->expectsOutputToContain('Rantai utuh: 3 baris')
            ->assertSuccessful();
    }

    public function test_ubah_satu_baris_lewat_superuser_terdeteksi_di_baris_itu(): void
    {
        $urutan = $this->tulisTerkomit(5);

        $this->superuser()->update('UPDATE audit.log_aktivitas SET aksi = ? WHERE urutan = ?', ['uji.dipalsukan', $urutan[2]]);

        $this->artisan('audit:verifikasi-rantai')
            ->expectsOutputToContain("Rantai PUTUS pada urutan {$urutan[2]}")
            ->assertFailed();
    }

    public function test_hapus_baris_tengah_terdeteksi_di_baris_sesudahnya(): void
    {
        $urutan = $this->tulisTerkomit(5);

        $this->superuser()->delete('DELETE FROM audit.log_aktivitas WHERE urutan = ?', [$urutan[2]]);

        $this->artisan('audit:verifikasi-rantai')
            ->expectsOutputToContain("Rantai PUTUS pada urutan {$urutan[3]}")
            ->assertFailed();
    }

    public function test_hapus_baris_ekor_hanya_terdeteksi_lewat_jangkar(): void
    {
        $urutan = $this->tulisTerkomit(4);
        $this->artisan('audit:ekspor-jangkar', ['--tanggal' => now(config('audit.zona_waktu'))->toDateString()])->assertSuccessful();

        $this->superuser()->delete('DELETE FROM audit.log_aktivitas WHERE urutan = ?', [$urutan[3]]);

        $this->artisan('audit:verifikasi-rantai')
            ->expectsOutputToContain('Rantai utuh: 3 baris')
            ->expectsOutputToContain('TIDAK COCOK')
            ->assertFailed();
    }

    public function test_verifikasi_rentang_tanggal(): void
    {
        $zona = config('audit.zona_waktu');
        $audit = $this->app->make(CatatAktivitas::class);
        $this->travelTo(now($zona)->setDate(2026, 10, 1)->setTime(10, 0));
        $audit->catat('uji.hari.satu');
        $this->travelTo(now($zona)->setDate(2026, 10, 2)->setTime(10, 0));
        $audit->catat('uji.hari.dua');
        $audit->catat('uji.hari.dua');

        $this->artisan('audit:verifikasi-rantai', ['--dari' => '2026-10-02', '--sampai' => '2026-10-02'])
            ->expectsOutputToContain('Rantai utuh: 2 baris')
            ->assertSuccessful();
    }

    public function test_penulisan_paralel_tetap_satu_rantai(): void
    {
        $proses = 4;
        $perProses = 40;
        $mulai = microtime(true) + 2.0;

        $daftar = [];
        foreach (range(1, $proses) as $label) {
            $p = new Process(
                [PHP_BINARY, base_path('tests/Support/penulis-audit-paralel.php'), "p{$label}", (string) $perProses, (string) $mulai],
                base_path(),
                ['APP_ENV' => 'testing', 'DB_DATABASE' => config('database.connections.pgsql.database')],
                timeout: 120,
            );
            $p->start();
            $daftar[] = $p;
        }
        foreach ($daftar as $p) {
            $p->wait();
            $this->assertTrue($p->isSuccessful(), $p->getErrorOutput());
        }

        $aksi = DB::table('audit.log_aktivitas')->orderBy('urutan')->pluck('aksi');
        $this->assertCount($proses * $perProses, $aksi);

        // Bukti benar-benar bersamaan: urutan rantai berpindah-pindah antarproses.
        $perpindahan = 0;
        foreach ($aksi as $i => $a) {
            $perpindahan += $i > 0 && $a !== $aksi[$i - 1] ? 1 : 0;
        }
        $this->assertGreaterThan($proses - 1, $perpindahan, 'Proses tidak tumpang tindih; uji paralel tidak bermakna.');

        $this->artisan('audit:verifikasi-rantai')
            ->expectsOutputToContain('Rantai utuh: '.($proses * $perProses).' baris')
            ->assertSuccessful();
    }

    public function test_ekspor_jangkar_ke_berkas_dan_log_terpisah(): void
    {
        $zona = config('audit.zona_waktu');
        $this->travelTo(now($zona)->setDate(2026, 10, 5)->setTime(23, 0));
        $audit = $this->app->make(CatatAktivitas::class);
        $audit->catat('uji.jangkar');
        $audit->catat('uji.jangkar');
        $kepala = DB::table('audit.log_aktivitas')->orderByDesc('urutan')->first();
        $this->travel(2)->hours();
        $audit->catat('uji.hari.berikutnya');

        $this->artisan('audit:ekspor-jangkar', ['--tanggal' => '2026-10-05'])->assertSuccessful();
        $this->artisan('audit:ekspor-jangkar', ['--tanggal' => '2026-10-05'])->expectsOutputToContain('sudah ada')->assertSuccessful();

        $baris = array_values(array_filter(explode("\n", Storage::disk('local')->get(config('audit.jangkar.berkas')))));
        $this->assertCount(1, $baris);
        $jangkar = json_decode($baris[0], true);
        $this->assertSame(['tanggal' => '2026-10-05', 'urutan' => (int) $kepala->urutan, 'hash_rantai' => $kepala->hash_rantai],
            array_intersect_key($jangkar, array_flip(['tanggal', 'urutan', 'hash_rantai'])));

        $log = file_get_contents(config('logging.channels.audit_jangkar.path'));
        $this->assertStringContainsString($kepala->hash_rantai, $log);
    }

    public function test_perintah_terjadwal_membuat_partisi_bulan_ini_dan_ke_depan(): void
    {
        $this->travelTo(now()->setDate(2027, 5, 15));

        $this->artisan('audit:siapkan-partisi', ['--bulan' => 2])->assertSuccessful();

        foreach (['2027_05', '2027_06', '2027_07'] as $bulan) {
            $this->assertNotNull(DB::scalar('SELECT to_regclass(?)', ["audit.log_aktivitas_{$bulan}"]));
        }
        $this->artisan('audit:siapkan-partisi', ['--bulan' => 2])->expectsOutputToContain('sudah_ada')->assertSuccessful();
    }

    public function test_bulan_yang_sudah_masuk_default_dilaporkan_bukan_dipaksa(): void
    {
        $this->travelTo(now()->setDate(2031, 1, 10));
        $this->app->make(CatatAktivitas::class)->catat('uji.ke.default');
        $this->assertSame('audit.log_aktivitas_default', DB::scalar('SELECT tableoid::regclass::text FROM audit.log_aktivitas LIMIT 1'));

        $this->artisan('audit:siapkan-partisi', ['--bulan' => 0])
            ->expectsOutputToContain('bentrok_default')
            ->assertFailed();
    }

    public function test_simrs_app_tidak_bisa_update_delete_truncate_di_audit(): void
    {
        $this->app->make(CatatAktivitas::class)->catat('uji.hak');
        $tabel = collect(DB::select("SELECT inhrelid::regclass::text AS nama FROM pg_inherits WHERE inhparent = 'audit.log_aktivitas'::regclass"))
            ->pluck('nama')->push('audit.log_aktivitas');
        $this->assertGreaterThanOrEqual(5, $tabel->count());

        foreach ($tabel as $t) {
            $this->assertDitolak(fn () => DB::update("UPDATE $t SET aksi = 'x'"));
            $this->assertDitolak(fn () => DB::delete("DELETE FROM $t"));
            $this->assertDitolak(fn () => DB::statement("TRUNCATE $t"));
        }
    }

    /** Menulis n baris yang langsung di-commit (koneksi migrator); mengembalikan urutannya. */
    private function tulisTerkomit(int $jumlah): array
    {
        $migrator = DB::connection('pgsql_migrator');
        foreach (range(1, $jumlah) as $i) {
            $migrator->table('audit.log_aktivitas')->insert([
                'id' => (string) Str::uuid7(),
                'aksi' => "uji.terkomit.{$i}",
                'terjadi_pada' => now()->utc()->format('Y-m-d H:i:s.uP'),
            ]);
        }

        return $migrator->table('audit.log_aktivitas')->orderBy('urutan')->pluck('urutan')->map(fn ($u) => (int) $u)->all();
    }

    private function superuser(): Connection
    {
        config(['database.connections.pgsql_uji_superuser' => array_merge(config('database.connections.pgsql'), [
            'username' => env('DB_SUPERUSER_USERNAME', 'postgres'),
            'password' => env('DB_SUPERUSER_PASSWORD', 'postgres'),
        ])]);

        return DB::connection('pgsql_uji_superuser');
    }

    private function kosongkanAudit(): void
    {
        $migrator = DB::connection('pgsql_migrator');
        $migrator->statement('TRUNCATE audit.log_aktivitas');
        $migrator->disconnect();
    }
}
