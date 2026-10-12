<?php

namespace Tests\Feature\Integrasi;

use App\Core\Instalasi\SyaratKredensialIntegrasi;
use App\Modules\Akses\Domain\Totp;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\Integrasi\Application\DaftarPenangan;
use App\Modules\Integrasi\Application\KelolaKredensial;
use App\Modules\Integrasi\Application\KotakKeluar;
use App\Modules\Integrasi\Application\PengirimKiriman;
use App\Modules\Integrasi\Domain\HasilKirim;
use App\Modules\Integrasi\Domain\Kiriman;
use App\Modules\Integrasi\Domain\PenanganKiriman;
use App\Modules\Integrasi\Domain\SistemIntegrasi;
use App\Modules\Integrasi\Infrastructure\JawabanHttp;
use App\Modules\Integrasi\Infrastructure\PanggilanHttp;
use App\Modules\Integrasi\Infrastructure\SimulatorIntegrasi;
use App\Modules\Integrasi\Jobs\KirimKiriman;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Support\Testing\Fakes\QueueFake;
use Illuminate\Testing\TestResponse;
use Tests\Support\AksesUji;
use Tests\Support\KlienSpa;
use Tests\Support\KontrakApi;
use Tests\TestCase;

/**
 * Tiket 1.9 (kontrak v0.13.0): kredensial tulis saja (K9), uji koneksi, outbox dengan retry + circuit breaker +
 * tertahan, lingkungan tidak berpindah, log disamarkan. Semua panggilan ke simulator dalam repo (K8); data sintetis.
 */
class GatewayIntegrasiTest extends TestCase
{
    use AksesUji, KlienSpa, KontrakApi;

    private const RAHASIA_PANJANG = 'rahasia-sintetis-panjang-ABCD';

    /** @var array<string, int> panggilan ke sistem luar per kunci idempotensi */
    public static array $terkirim = [];

    private Pengguna $admin;

    private int $diproses = 0;

    protected function setUp(): void
    {
        parent::setUp();
        self::$terkirim = [];
        PenangananUji::$siap = false;
        // Job integrasi memakai koneksi redis sendiri (JenisQueue): di tes dipalsukan, pekerja dijalankan lewat pekerja().
        Queue::fake();
        foreach (SistemIntegrasi::cases() as $s) {
            SimulatorIntegrasi::atur($s, 'normal');
        }
        $this->admin = $this->buatPengguna();
        // Izin perbaiki konfigurasi integrasi sensitif → pemegangnya wajib MFA.
        $this->admin->forceFill(['mfa_rahasia_enkripsi' => Totp::rahasiaBaru(), 'mfa_aktif_pada' => now()])->save();
        $this->tugaskan($this->admin, $this->buatPeran(['pengaturan.integrasi', 'integrasi.satusehat', 'integrasi.bpjs', 'integrasi.log']));
        $this->masukSebagai($this->admin);
        SimulatorIntegrasi::jawabanTambahan(SistemIntegrasi::Satusehat, function (string $metode, string $path, array $isi): ?JawabanHttp {
            if ($path !== '/uji/kirim') {
                return null;
            }
            self::$terkirim[$isi['kunci']] = (self::$terkirim[$isi['kunci']] ?? 0) + 1;

            return new JawabanHttp(201, (string) json_encode(['resourceType' => 'Location', 'id' => 'loc-'.$isi['kunci']]), 5, null);
        });
        app(DaftarPenangan::class)->pasang(new PenangananUji);
    }

    public function test_kredensial_tulis_saja_tanpa_nilai_rahasia_di_respons_db_dan_audit(): void
    {
        $r = $this->simpan('satusehat', 'sandbox', ['client_id' => 'klien-uji', 'client_secret' => self::RAHASIA_PANJANG, 'organization_id' => 'org-uji-1'], true)->assertOk();
        $this->assertSesuaiKontrak($r, 'PUT', '/integrasi/sistem/{kode}/kredensial');
        $kolom = collect($r->json('data.kredensial.0.kolom'))->keyBy('nama');
        $this->assertSame([null, 'ABCD'], [$kolom['client_secret']['nilai'], $kolom['client_secret']['akhir']]);
        $this->assertSame('klien-uji', $kolom['client_id']['nilai']);
        $this->assertSame('sandbox', $r->json('data.lingkungan_aktif'));
        $this->assertStringNotContainsString(self::RAHASIA_PANJANG, $r->getContent());

        // Rahasia pendek: tidak ada karakter yang ditampilkan.
        $r = $this->simpan('satusehat', 'sandbox', ['client_secret' => 'pendek12'])->assertOk();
        $this->assertNull(collect($r->json('data.kredensial.0.kolom'))->keyBy('nama')['client_secret']['akhir']);

        $baris = DB::table('integrasi.kredensial_api')->where('tujuan', 'satusehat')->first();
        $this->assertStringNotContainsString('pendek12', (string) $baris->rahasia);
        $audit = DB::table('audit.log_aktivitas')->where('aksi', 'integrasi.kredensial_diubah')->get();
        $this->assertCount(2, $audit);
        foreach ($audit as $a) {
            $this->assertStringNotContainsString('pendek12', (string) $a->sesudah);
            $this->assertStringNotContainsString(self::RAHASIA_PANJANG, (string) $a->sesudah);
        }
    }

    public function test_validasi_dan_aktifkan_belum_lengkap(): void
    {
        $this->simpan('bpjs_vclaim', 'sandbox', ['bukan_kolom' => 'x'])->assertStatus(422)->assertJsonPath('error.kode', 'validasi_gagal')
            ->assertJsonPath('error.rincian.0.kolom', 'kolom.bukan_kolom');
        $r = $this->simpan('bpjs_vclaim', 'sandbox', ['cons_id' => '1234'], true)->assertStatus(422)->assertJsonPath('error.kode', 'kredensial_belum_lengkap');
        $this->assertEqualsCanonicalizing(['kolom.secret_key', 'kolom.user_key'], array_column($r->json('error.rincian'), 'kolom'));
        $this->assertNull(DB::table('integrasi.kredensial_api')->where('aktif', true)->value('id'));
        $this->minta('POST', '/api/v1/integrasi/sistem/bpjs_vclaim/uji-koneksi')->assertStatus(422)->assertJsonPath('error.kode', 'lingkungan_belum_aktif');
    }

    public function test_tanpa_kunci_kredensial_503(): void
    {
        config(['integrasi.kunci' => null]);
        $this->simpan('satusehat', 'sandbox', ['client_secret' => self::RAHASIA_PANJANG])->assertStatus(503)->assertJsonPath('error.kode', 'kredensial_kunci_belum_dikonfigurasi');
    }

    public function test_uji_koneksi_tiga_sistem_lewat_simulator_dan_syarat_penyiapan_langkah_8(): void
    {
        $this->aktifkanSemua();
        foreach (['bpjs_vclaim', 'aplicares', 'satusehat'] as $kode) {
            $r = $this->minta('POST', "/api/v1/integrasi/sistem/$kode/uji-koneksi")->assertOk()->assertJsonPath('data.berhasil', true);
            $this->assertSesuaiKontrak($r, 'POST', '/integrasi/sistem/{kode}/uji-koneksi');
        }
        $this->assertSame(['terpenuhi' => true, 'keterangan' => '3 dari 3'], app(SyaratKredensialIntegrasi::class)->periksa());

        // Ditolak dan tidak tersambung tetap 200 dengan berhasil false; syarat langkah 8 ikut turun.
        SimulatorIntegrasi::atur(SistemIntegrasi::Satusehat, 'tolak');
        $this->minta('POST', '/api/v1/integrasi/sistem/satusehat/uji-koneksi')->assertOk()->assertJsonPath('data.berhasil', false)->assertJsonPath('data.kode_http', 401);
        SimulatorIntegrasi::atur(SistemIntegrasi::BpjsVclaim, 'mati');
        $this->minta('POST', '/api/v1/integrasi/sistem/bpjs_vclaim/uji-koneksi')->assertOk()->assertJsonPath('data.berhasil', false)->assertJsonPath('data.kode_http', null);
        $this->assertSame('1 dari 3', app(SyaratKredensialIntegrasi::class)->periksa()['keterangan']);

        // Log tanpa rahasia dan tanpa token.
        $log = DB::table('integrasi.log_api')->get();
        $this->assertGreaterThanOrEqual(5, $log->count());
        foreach ($log as $l) {
            $this->assertStringNotContainsString(self::RAHASIA_PANJANG, (string) $l->permintaan.$l->respons);
            $this->assertStringNotContainsString('access_token":"', str_replace('access_token":"***"', '', (string) $l->respons));
        }
        $r = $this->minta('GET', '/api/v1/integrasi/sistem')->assertOk();
        $this->assertSesuaiKontrak($r, 'GET', '/integrasi/sistem');
        $this->assertSame([false, true, false], array_map(fn ($s) => $s['uji_koneksi_terakhir']['berhasil'], $r->json('data')));
    }

    public function test_uji_koneksi_dibatasi_6_per_menit(): void
    {
        $this->aktifkanSemua();
        for ($i = 0; $i < 6; $i++) {
            $this->minta('POST', '/api/v1/integrasi/sistem/aplicares/uji-koneksi')->assertOk();
        }
        $this->minta('POST', '/api/v1/integrasi/sistem/aplicares/uji-koneksi')->assertStatus(429)->assertHeader('Retry-After');
    }

    public function test_outbox_terkirim_lalu_sirkuit_terbuka_saat_simulator_mati_dan_pulih_tanpa_duplikat(): void
    {
        $this->aktifkanSemua();
        $pertama = $this->antrekan(SistemIntegrasi::Satusehat, 'uji', 'lokasi', (string) Str::uuid7());
        $this->assertSame('terkirim', $this->kiriman($pertama)->status);

        SimulatorIntegrasi::atur(SistemIntegrasi::Satusehat, 'mati');
        $id = [];
        for ($i = 0; $i < 6; $i++) {
            $id[] = $this->antrekan(SistemIntegrasi::Satusehat, 'uji', 'lokasi', (string) Str::uuid7());
        }
        $status = DB::table('integrasi.status_sistem')->where('tujuan', 'satusehat')->first();
        $this->assertSame(['terbuka', 5], [$status->cb_status, $status->gagal_beruntun]);
        // Kiriman ke-6 tidak dicoba selama sirkuit terbuka (tetap menunggu tanpa menambah percobaan).
        $this->assertSame([1, 0], [$this->kiriman($id[4])->percobaan, $this->kiriman($id[5])->percobaan]);
        $this->assertSame(array_fill(0, 6, 'menunggu'), array_map(fn ($k) => $this->kiriman($k)->status, $id));

        SimulatorIntegrasi::atur(SistemIntegrasi::Satusehat, 'normal');
        $this->travel(61)->minutes();
        $this->putaran();   // setengah terbuka: satu percobaan berhasil → tertutup
        $this->putaran();   // sisanya
        $this->assertSame('tertutup', DB::table('integrasi.status_sistem')->where('tujuan', 'satusehat')->value('cb_status'));
        $this->assertSame(array_fill(0, 6, 'terkirim'), array_map(fn ($k) => $this->kiriman($k)->status, $id));
        $this->assertSame([1], array_values(array_unique(self::$terkirim)), 'Setiap kiriman sampai tepat sekali');
        $this->assertSame('loc-'.$this->kiriman($id[0])->kunci_idempotensi, $this->kiriman($id[0])->id_luar);
    }

    public function test_gagal_sesudah_batas_percobaan_dan_ditolak_isi_langsung_gagal(): void
    {
        $this->aktifkanSemua();
        config(['integrasi.circuit_breaker.ambang' => 100, 'integrasi.maks_percobaan' => 3]);
        SimulatorIntegrasi::atur(SistemIntegrasi::Satusehat, 'gangguan');
        $id = $this->antrekan(SistemIntegrasi::Satusehat, 'uji', 'lokasi', (string) Str::uuid7());
        for ($i = 0; $i < 3; $i++) {
            $this->travel(10)->minutes();
            $this->putaran();
        }
        $this->assertSame(['gagal', 3], [$this->kiriman($id)->status, $this->kiriman($id)->percobaan]);

        SimulatorIntegrasi::atur(SistemIntegrasi::Satusehat, 'normal');
        $ditolak = $this->antrekan(SistemIntegrasi::Satusehat, 'uji', 'lokasi', (string) Str::uuid7(), ['tolak' => true]);
        $this->assertSame(['gagal', 1], [$this->kiriman($ditolak)->status, $this->kiriman($ditolak)->percobaan]);
        $this->assertSame('tertutup', DB::table('integrasi.status_sistem')->where('tujuan', 'satusehat')->value('cb_status'));
    }

    public function test_muatan_kiriman_yang_sudah_dicoba_tidak_diganti_dan_exception_penangan_tidak_bocor(): void
    {
        $this->aktifkanSemua();
        $agregat = (string) Str::uuid7();
        // Belum pernah dicoba (sistem nonaktif sesaat tidak relevan): muatan diganti, bukan kiriman baru.
        SimulatorIntegrasi::atur(SistemIntegrasi::Satusehat, 'gangguan');
        $pertama = $this->antrekan(SistemIntegrasi::Satusehat, 'uji', 'lokasi', $agregat, ['versi' => 1]);
        $this->assertSame(1, $this->kiriman($pertama)->percobaan);
        // Sudah dicoba (kunci idempotensi terpakai): muatan baru menjadi kiriman baru, yang lama tidak disentuh.
        $kedua = $this->antrekan(SistemIntegrasi::Satusehat, 'uji', 'lokasi', $agregat, ['versi' => 2]);
        $this->assertNotSame($pertama, $kedua);
        $this->assertSame(['versi' => 1], json_decode($this->kiriman($pertama)->muatan, true));

        SimulatorIntegrasi::atur(SistemIntegrasi::Satusehat, 'normal');
        $lempar = $this->antrekan(SistemIntegrasi::Satusehat, 'uji', 'lokasi', (string) Str::uuid7(), ['lempar' => true]);
        $k = $this->kiriman($lempar);
        $this->assertSame(['menunggu', 1], [$k->status, $k->percobaan]);
        $this->assertStringNotContainsString('3201234567890001', (string) $k->error_terakhir);
        $this->assertStringContainsString('RuntimeException', (string) $k->error_terakhir);
    }

    public function test_tertahan_karena_data_induk_dikirim_ulang_otomatis_sesudah_diperbaiki(): void
    {
        $this->aktifkanSemua();
        $unit = DB::table('inti.unit_organisasi')->value('id');
        $id = $this->antrekan(SistemIntegrasi::Satusehat, 'uji', 'lokasi', (string) Str::uuid7(), ['kurang' => true], $unit);
        $k = $this->kiriman($id);
        $this->assertSame(['tertahan', 'induk_belum_terdaftar'], [$k->status, $k->penyebab_tertahan_kode]);

        $r = $this->minta('GET', '/api/v1/integrasi/kotak-keluar?status=tertahan')->assertOk();
        $this->assertSesuaiKontrak($r, 'GET', '/integrasi/kotak-keluar');
        $this->assertSame('induk_belum_terdaftar', $r->json('data.0.penyebab_tertahan.kode'));
        $this->assertArrayNotHasKey('muatan', $r->json('data.0'));

        PenangananUji::$siap = true;
        $this->travel(16)->minutes();
        $this->putaran();
        $this->assertSame('terkirim', $this->kiriman($id)->status);
        PenangananUji::$siap = false;
    }

    public function test_pindah_lingkungan_menahan_kiriman_lama_dan_kirim_ulang_tidak_memindahkan(): void
    {
        $this->aktifkanSemua();
        SimulatorIntegrasi::atur(SistemIntegrasi::Satusehat, 'gangguan');
        $lama = $this->antrekan(SistemIntegrasi::Satusehat, 'uji', 'lokasi', (string) Str::uuid7());
        $this->assertSame(['menunggu', 'sandbox'], [$this->kiriman($lama)->status, $this->kiriman($lama)->lingkungan]);

        $this->simpan('satusehat', 'produksi', ['client_id' => 'klien-prod', 'client_secret' => self::RAHASIA_PANJANG, 'organization_id' => 'org-prod'], true)->assertOk();
        $k = $this->kiriman($lama);
        $this->assertSame(['tertahan', 'lingkungan_berubah', 'sandbox'], [$k->status, $k->penyebab_tertahan_kode, $k->lingkungan]);
        $r = $this->minta('POST', "/api/v1/integrasi/kotak-keluar/$lama/kirim-ulang")->assertStatus(409)->assertJsonPath('error.kode', 'lingkungan_tidak_aktif');
        $this->assertSesuaiKontrak($r, 'POST', '/integrasi/kotak-keluar/{id}/kirim-ulang');

        // Nonaktif: kiriman baru berlingkungan null, tertahan; kirim ulang sesudah diaktifkan mengikatnya ke lingkungan aktif.
        $this->simpan('satusehat', 'produksi', [], false, true)->assertOk()->assertJsonPath('data.lingkungan_aktif', null)
            ->assertJsonPath('data.peringatan.0.kode', 'integrasi_nonaktif');
        $baru = $this->antrekan(SistemIntegrasi::Satusehat, 'uji', 'lokasi', (string) Str::uuid7());
        $this->assertSame(['tertahan', null], [$this->kiriman($baru)->status, $this->kiriman($baru)->lingkungan]);
        $this->travel(2)->minutes();
        $this->putaran();
        $this->assertSame('tertahan', $this->kiriman($baru)->status, 'integrasi_nonaktif tidak dikirim ulang otomatis');

        SimulatorIntegrasi::atur(SistemIntegrasi::Satusehat, 'normal');
        $this->simpan('satusehat', 'produksi', [], true)->assertOk();
        $r = $this->minta('POST', "/api/v1/integrasi/kotak-keluar/$baru/kirim-ulang")->assertOk()->assertJsonPath('data.status', 'menunggu');
        $this->assertSesuaiKontrak($r, 'POST', '/integrasi/kotak-keluar/{id}/kirim-ulang');
        $this->pekerja();
        $this->assertSame(['terkirim', 'produksi'], [$this->kiriman($baru)->status, $this->kiriman($baru)->lingkungan]);
        $this->minta('POST', "/api/v1/integrasi/kotak-keluar/$baru/kirim-ulang")->assertStatus(409)->assertJsonPath('error.kode', 'kiriman_tidak_bisa_dikirim_ulang');
    }

    public function test_izin_per_tujuan_dan_log_disamarkan(): void
    {
        $this->aktifkanSemua();
        app(PanggilanHttp::class)->catat(SistemIntegrasi::BpjsVclaim, 'sandbox', 'GET', 'Peserta/nik/3201234567890001/tglSEP/2026-10-12',
            new JawabanHttp(200, null, 10, null), true, 'Peserta 0001234567890 ditemukan', ['noKartu' => '0001234567890'],
            ['metaData' => ['code' => '200', 'message' => 'OK'], 'response' => ['peserta' => ['nama' => 'Pasien Sintetis', 'nik' => '3201234567890001', 'tglLahir' => '1990-01-01']]]);
        $r = $this->minta('GET', '/api/v1/integrasi/log?tujuan=bpjs_vclaim')->assertOk();
        $this->assertSesuaiKontrak($r, 'GET', '/integrasi/log');
        $isi = (string) json_encode($r->json('data.0'));
        foreach (['3201234567890001', '0001234567890', 'Pasien Sintetis', '1990-01-01'] as $rahasia) {
            $this->assertStringNotContainsString($rahasia, $isi);
        }
        // Tanggal ikut disamarkan (bentuknya sama dengan tanggal lahir).
        $this->assertSame('/Peserta/nik/***/tglSEP/***', $r->json('data.0.endpoint'));
        $this->assertStringContainsString('"code":"200"', (string) $r->json('data.0.respons'));
        $this->minta('GET', '/api/v1/integrasi/log?batas=51')->assertStatus(422)->assertJsonPath('error.kode', 'ukuran_halaman_melebihi_batas');

        // Pemantau SATUSEHAT saja: status tanpa kredensial, kotak keluar BPJS ditolak, log ditolak.
        $pemantau = $this->buatPengguna();
        $this->tugaskan($pemantau, $this->buatPeran(['integrasi.satusehat']));
        $this->masukSebagai($pemantau);
        $r = $this->minta('GET', '/api/v1/integrasi/sistem')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.kode', 'satusehat')->assertJsonPath('data.0.kredensial', null);
        $this->assertSesuaiKontrak($r, 'GET', '/integrasi/sistem');
        $this->minta('GET', '/api/v1/integrasi/kotak-keluar?tujuan=bpjs_vclaim')->assertForbidden();
        $this->minta('GET', '/api/v1/integrasi/kotak-keluar')->assertOk();
        $this->minta('GET', '/api/v1/integrasi/log')->assertForbidden();
        $this->simpan('satusehat', 'sandbox', ['client_id' => 'x'])->assertForbidden();
    }

    public function test_rotasi_kunci_dan_masa_simpan_log_per_partisi(): void
    {
        $this->simpan('satusehat', 'sandbox', ['client_id' => 'klien-uji', 'client_secret' => self::RAHASIA_PANJANG, 'organization_id' => 'org'])->assertOk();
        $lama = (string) config('integrasi.kunci');
        config(['integrasi.kunci' => 'base64:'.base64_encode(str_repeat('k', 32)), 'integrasi.kunci_lama' => $lama]);
        $this->artisan('integrasi:rotasi-kunci')->expectsOutputToContain('1 kredensial')->assertSuccessful();
        config(['integrasi.kunci_lama' => null]);
        $this->assertSame(self::RAHASIA_PANJANG, app(KelolaKredensial::class)->nilai(SistemIntegrasi::Satusehat, 'sandbox')['client_secret'] ?? null);

        // Partisi bulan lama dibuat lalu dihapus utuh sesudah masa simpan; bulan berjalan tetap.
        DB::select('SELECT * FROM integrasi.siapkan_partisi(?::date, 0)', [now()->subMonths(6)->toDateString()]);
        app(PanggilanHttp::class)->catat(SistemIntegrasi::Satusehat, 'sandbox', 'GET', 'x', new JawabanHttp(200, null, 1, null), true, null, null, null);
        DB::table('integrasi.log_api')->insert(['id' => (string) Str::uuid7(), 'tujuan' => 'satusehat', 'lingkungan' => 'sandbox', 'metode' => 'GET',
            'endpoint' => '/lama', 'berhasil' => true, 'terjadi_pada' => now()->subMonths(6)]);
        $this->artisan('integrasi:hapus-log-lama')->assertSuccessful();
        $this->assertSame(['/x'], DB::table('integrasi.log_api')->pluck('endpoint')->all());
    }

    /** Pekerja queue `integrasi`: jalankan job KirimKiriman yang belum dijalankan, sampai tidak ada yang baru. */
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

    /** @param  array<string, mixed>  $muatan */
    private function antrekan(SistemIntegrasi $tujuan, string $jenis, string $agregatJenis, string $agregatId, array $muatan = [], ?string $unit = null): string
    {
        $id = app(KotakKeluar::class)->antrekan($tujuan, $jenis, $agregatJenis, $agregatId, $muatan, $unit);
        $this->pekerja();

        return $id;
    }

    private function putaran(): void
    {
        app(PengirimKiriman::class)->putaran();
        $this->pekerja();
    }

    private function aktifkanSemua(): void
    {
        $this->simpan('bpjs_vclaim', 'sandbox', ['cons_id' => '1234', 'secret_key' => 'rahasia-bpjs', 'user_key' => 'kunci-pengguna'], true)->assertOk();
        $this->simpan('aplicares', 'sandbox', ['cons_id' => '1234', 'secret_key' => 'rahasia-bpjs', 'user_key' => 'kunci-aplicares'], true)->assertOk();
        $this->simpan('satusehat', 'sandbox', ['client_id' => 'klien-uji', 'client_secret' => self::RAHASIA_PANJANG, 'organization_id' => 'org-uji-1'], true)->assertOk();
    }

    /** @param  array<string, string>  $kolom */
    private function simpan(string $kode, string $lingkungan, array $kolom, bool $aktifkan = false, bool $nonaktifkan = false): TestResponse
    {
        return $this->minta('PUT', "/api/v1/integrasi/sistem/$kode/kredensial", array_filter([
            'lingkungan' => $lingkungan, 'kolom' => $kolom === [] ? null : $kolom, 'aktifkan' => $aktifkan ?: null, 'nonaktifkan' => $nonaktifkan ?: null,
        ], fn ($v) => $v !== null));
    }

    private function kiriman(string $id): object
    {
        return DB::table('integrasi.kotak_keluar')->where('id', $id)->first();
    }
}

/** Penangan tiruan: mengirim ke path simulator `/uji/kirim`; `kurang` → tertahan, `tolak` → ditolak isi. */
final class PenangananUji implements PenanganKiriman
{
    public static bool $siap = false;

    public function tujuan(): SistemIntegrasi
    {
        return SistemIntegrasi::Satusehat;
    }

    public function jenisResource(): string
    {
        return 'uji';
    }

    public function periksa(Kiriman $kiriman): ?array
    {
        return ($kiriman->muatan['kurang'] ?? false) && ! self::$siap
            ? ['kode' => 'induk_belum_terdaftar', 'pesan' => 'Lokasi induk belum terdaftar di SATUSEHAT.', 'pemilik_unit_id' => null]
            : null;
    }

    public function kirim(Kiriman $kiriman, string $lingkungan, array $kredensial): HasilKirim
    {
        if ($kiriman->muatan['lempar'] ?? false) {
            throw new \RuntimeException('Gagal memanggil https://contoh.invalid/Patient?nik=3201234567890001');
        }
        if ($kiriman->muatan['tolak'] ?? false) {
            return HasilKirim::ditolak('Isi ditolak: physicalType tidak valid.');
        }
        $http = app(PanggilanHttp::class);
        $j = $http->kirim(SistemIntegrasi::Satusehat, $lingkungan, 'POST', 'uji/kirim', ['json' => ['kunci' => $kiriman->kunciIdempotensi]]);
        $berhasil = $j->kodeHttp === 201;
        $http->catat(SistemIntegrasi::Satusehat, $lingkungan, 'POST', 'uji/kirim', $j, $berhasil, $j->galat, null, null, $kiriman->id);

        return $berhasil ? HasilKirim::berhasil($j->json()['id'] ?? null) : HasilKirim::gangguan($j->galat ?? 'HTTP '.$j->kodeHttp);
    }
}
