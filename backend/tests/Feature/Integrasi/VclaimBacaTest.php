<?php

namespace Tests\Feature\Integrasi;

use App\Modules\Integrasi\Application\KelolaKredensial;
use App\Modules\Integrasi\Application\LayananVclaim;
use App\Modules\Integrasi\Domain\SistemIntegrasi;
use App\Modules\Integrasi\Infrastructure\KlienVclaim;
use App\Modules\Integrasi\Infrastructure\SimulatorIntegrasi;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\Support\AksesUji;
use Tests\Support\KlienSpa;
use Tests\TestCase;

/**
 * Tiket 1.9 butir 3 (catatan lapangan §1, §3, §4.1, §10): VClaim baca terhadap simulator dengan respons terenkripsi
 * (AES-256-CBC + LZString, kunci dari timestamp permintaan), fallback rujukan FKTP → RS, cache referensi, circuit
 * breaker, dan log tanpa data peserta. Semua data sintetis.
 */
class VclaimBacaTest extends TestCase
{
    use AksesUji, KlienSpa;

    protected function setUp(): void
    {
        parent::setUp();
        SimulatorIntegrasi::atur(SistemIntegrasi::BpjsVclaim, 'normal');
        app(KelolaKredensial::class)->simpan(SistemIntegrasi::BpjsVclaim, 'sandbox',
            ['cons_id' => '1234', 'secret_key' => 'rahasia-sintetis', 'user_key' => 'kunci-sintetis'], true, false, $this->buatPengguna()->id);
    }

    public function test_dekripsi_memakai_timestamp_permintaan(): void
    {
        $kredensial = ['cons_id' => '1234', 'secret_key' => 'rahasia-sintetis', 'user_key' => 'x'];
        $sandi = KlienVclaim::tutup(['list' => [['kode' => '99']]], $kredensial, 1760000000);
        $this->assertSame(['list' => [['kode' => '99']]], KlienVclaim::buka($sandi, $kredensial, 1760000000));
        $this->assertNull(KlienVclaim::buka($sandi, $kredensial, 1760000001));
    }

    public function test_peserta_dan_log_tanpa_data_peserta(): void
    {
        $vclaim = app(LayananVclaim::class);
        $hasil = $vclaim->peserta('nik', '9900000000000001', '2026-10-12');
        $this->assertTrue($hasil['berhasil']);
        $this->assertSame(['PESERTA SINTETIS', '0'], [$hasil['data']['nama'], $hasil['data']['statusPeserta']['kode']]);
        $this->assertFalse($vclaim->peserta('nokartu', '9990000000999', '2026-10-12')['berhasil']);

        $log = DB::table('integrasi.log_api')->where('tujuan', 'bpjs_vclaim')->orderBy('terjadi_pada')->first();
        $this->assertSame('/Peserta/nik/***/tglSEP/***', $log->endpoint);
        foreach (['PESERTA SINTETIS', '9900000000000001', '9990000000001', '1990-01-01'] as $rahasia) {
            $this->assertStringNotContainsString($rahasia, (string) $log->respons);
        }
        // Kode status (bukan identitas) tetap terbaca untuk diagnosis.
        $this->assertStringContainsString('"statusPeserta":{"kode":"0"', (string) $log->respons);

        $this->expectException(InvalidArgumentException::class);
        $vclaim->peserta('nik', '99/../../referensi', '2026-10-12');
    }

    public function test_rujukan_fktp_lalu_rs_dan_respons_berbentuk_array(): void
    {
        $vclaim = app(LayananVclaim::class);
        $fktp = $vclaim->rujukan('SIMFKTP001');
        $this->assertSame([true, '1', 'SIMFKTP001'], [$fktp['berhasil'], $fktp['asal'], $fktp['data']['noKunjungan']]);
        $rs = $vclaim->rujukan('SIMRS001');
        $this->assertSame([true, '2', 'SIMRS001'], [$rs['berhasil'], $rs['asal'], $rs['data']['noKunjungan']]);
        $this->assertSame([false, null], [$vclaim->rujukan('TIDAKADA1')['berhasil'], $vclaim->rujukan('TIDAKADA1')['asal']]);
    }

    public function test_referensi_dari_cache_dan_parameter_divalidasi(): void
    {
        $vclaim = app(LayananVclaim::class);
        $this->assertSame([['kode' => '99', 'nama' => 'PROVINSI SINTETIS']], $vclaim->referensi('propinsi')['data']['list']);
        $sebelum = DB::table('integrasi.log_api')->count();
        $this->assertTrue($vclaim->referensi('propinsi')['berhasil']);
        $this->assertSame($sebelum, DB::table('integrasi.log_api')->count(), 'Panggilan kedua dari cache');
        $this->assertTrue($vclaim->referensi('poli', ['q' => 'INT'])['berhasil']);

        $this->expectException(InvalidArgumentException::class);
        $vclaim->referensi('poli', ['q' => '../Peserta']);
    }

    public function test_bpjs_mati_membuka_sirkuit_dan_gagal_cepat(): void
    {
        SimulatorIntegrasi::atur(SistemIntegrasi::BpjsVclaim, 'mati');
        $vclaim = app(LayananVclaim::class);
        for ($i = 0; $i < 5; $i++) {
            $this->assertFalse($vclaim->rujukan('SIMFKTP001')['berhasil']);
        }
        $this->assertSame('terbuka', DB::table('integrasi.status_sistem')->where('tujuan', 'bpjs_vclaim')->value('cb_status'));
        $log = DB::table('integrasi.log_api')->count();
        $this->assertStringContainsString('tidak tersambung', (string) $vclaim->peserta('nik', '9900000000000001', '2026-10-12')['pesan']);
        $this->assertSame($log, DB::table('integrasi.log_api')->count(), 'Sirkuit terbuka: tidak memanggil BPJS');

        SimulatorIntegrasi::atur(SistemIntegrasi::BpjsVclaim, 'normal');
        $this->travel(2)->minutes();
        $this->assertTrue($vclaim->rujukan('SIMFKTP001')['berhasil']);
        $this->assertSame('tertutup', DB::table('integrasi.status_sistem')->where('tujuan', 'bpjs_vclaim')->value('cb_status'));
    }
}
