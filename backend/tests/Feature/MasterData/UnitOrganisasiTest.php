<?php

namespace Tests\Feature\MasterData;

use App\Modules\Akses\Application\AccessEvaluator;
use App\Modules\Akses\Domain\Cakupan;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\MasterData\Models\UnitOrganisasi;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\AksesUji;
use Tests\Support\KlienSpa;
use Tests\Support\KontrakApi;
use Tests\TestCase;

/** Tiket 1.8a-be: /unit-organisasi, lapis 3 cakupan unit (kontrak v0.3.0). */
class UnitOrganisasiTest extends TestCase
{
    use AksesUji, KlienSpa, KontrakApi;

    private const DASAR = 'SK Direktur 3/2026';

    public function test_menyusun_pohon_lalu_membaca_per_induk_dengan_jalur(): void
    {
        $this->masukSebagai($this->pengelolaData());

        $irna = $this->buat(null, 'IRNA', 'Instalasi Rawat Inap', 'instalasi');
        $this->assertSesuaiKontrak($irna, 'POST', '/unit-organisasi');
        $melati = $this->buat($irna->json('data.id'), 'MELATI', 'Ruang Melati', 'ruang');
        $this->buat($irna->json('data.id'), 'MAWAR', 'Ruang Mawar', 'ruang');
        $this->buat(null, 'IGD', 'Instalasi Gawat Darurat', 'instalasi');
        $melati->assertJsonPath('data.jalur', [['id' => $irna->json('data.id'), 'kode' => 'IRNA', 'nama' => 'Instalasi Rawat Inap']])
            ->assertJsonPath('data.kepala', null)->assertJsonPath('data.berlaku_dari', now('Asia/Jakarta')->toDateString());

        $akar = $this->assertSesuaiKontrak($this->minta('GET', '/api/v1/unit-organisasi?akar=1')->assertOk(), 'GET', '/unit-organisasi');
        $this->assertSame(['IGD', 'IRNA'], $akar->json('data.*.kode'));
        $this->assertSame([0, 2], $akar->json('data.*.jumlah_anak'));
        $anak = $this->minta('GET', '/api/v1/unit-organisasi?induk_id='.$irna->json('data.id'))->assertOk();
        $this->assertSame(['MAWAR', 'MELATI'], $anak->json('data.*.kode'));
        $this->assertSame(['MELATI'], $this->minta('GET', '/api/v1/unit-organisasi?cari=melat')->json('data.*.kode'));
        $this->assertSame(['IGD', 'IRNA'], $this->minta('GET', '/api/v1/unit-organisasi?jenis=instalasi')->json('data.*.kode'));
        $this->assertSesuaiKontrak($this->minta('GET', '/api/v1/unit-organisasi/'.$melati->json('data.id'))->assertOk(), 'GET', '/unit-organisasi/{id}');
        $this->assertAuditTerakhir('master_data.unit_dibuat', $melati->json('data.id'));
    }

    public function test_penolakan_kode_ganda_induk_nonaktif_siklus_dan_versi(): void
    {
        $this->masukSebagai($this->pengelolaData());
        $irna = $this->buat(null, 'IRNA', 'Instalasi Rawat Inap', 'instalasi')->json('data');
        $melati = $this->buat($irna['id'], 'MELATI', 'Ruang Melati', 'ruang')->json('data');

        $this->assertSesuaiKontrak($this->minta('POST', '/api/v1/unit-organisasi', [
            'induk_id' => null, 'kode' => 'IRNA', 'nama' => 'Lagi', 'jenis' => 'instalasi', 'dasar_perubahan' => self::DASAR,
        ])->assertStatus(409)->assertJsonPath('error.kode', 'kode_sudah_dipakai'), 'POST', '/unit-organisasi');
        $this->minta('POST', '/api/v1/unit-organisasi', ['induk_id' => null, 'kode' => 'huruf kecil', 'nama' => 'x', 'jenis' => 'lain'])
            ->assertStatus(422)->assertJsonPath('error.kode', 'validasi_gagal');

        $siklus = $this->mintaDenganVersi('PATCH', "/api/v1/unit-organisasi/{$irna['id']}", $irna['versi'], ['induk_id' => $melati['id'], 'dasar_perubahan' => self::DASAR])
            ->assertStatus(422)->assertJsonPath('error.kode', 'siklus');
        $this->assertSesuaiKontrak($siklus, 'PATCH', '/unit-organisasi/{id}');
        $this->assertSesuaiKontrak($this->minta('PATCH', "/api/v1/unit-organisasi/{$irna['id']}", ['nama' => 'Baru', 'dasar_perubahan' => self::DASAR])
            ->assertStatus(428), 'PATCH', '/unit-organisasi/{id}');
        $this->mintaDenganVersi('PATCH', "/api/v1/unit-organisasi/{$irna['id']}", '99', ['nama' => 'Baru', 'dasar_perubahan' => self::DASAR])
            ->assertStatus(412)->assertJsonPath('error.kode', 'versi_berubah');

        $nonaktif = $this->buat(null, 'LAMA', 'Unit Lama', 'bagian')->json('data');
        $this->mintaDenganVersi('PATCH', "/api/v1/unit-organisasi/{$nonaktif['id']}", $nonaktif['versi'], ['status' => 'nonaktif', 'dasar_perubahan' => self::DASAR])->assertOk();
        $this->minta('POST', '/api/v1/unit-organisasi', [
            'induk_id' => $nonaktif['id'], 'kode' => 'ANAK', 'nama' => 'Anak', 'jenis' => 'ruang', 'dasar_perubahan' => self::DASAR,
        ])->assertStatus(422)->assertJsonPath('error.kode', 'induk_tidak_aktif');
    }

    public function test_menonaktifkan_unit_yang_masih_dipakai_ditolak(): void
    {
        $this->masukSebagai($this->pengelolaData());
        $irna = $this->buat(null, 'IRNA', 'Instalasi Rawat Inap', 'instalasi')->json('data');
        $melati = $this->buat($irna['id'], 'MELATI', 'Ruang Melati', 'ruang')->json('data');
        $this->tugaskan($this->buatPengguna(), $this->buatPeran(['igd.papan']), cakupan: new Cakupan('unit', $melati['id']));

        $this->mintaDenganVersi('PATCH', "/api/v1/unit-organisasi/{$irna['id']}", $irna['versi'], ['status' => 'nonaktif', 'dasar_perubahan' => self::DASAR])
            ->assertStatus(409)->assertJsonPath('error.kode', 'unit_masih_dipakai')
            ->assertJsonPath('error.rincian.0', ['jumlah_anak_aktif' => 1, 'jumlah_penugasan' => 0, 'jumlah_modul_aktif' => 0]);
        $this->mintaDenganVersi('PATCH', "/api/v1/unit-organisasi/{$melati['id']}", $melati['versi'], ['status' => 'nonaktif', 'dasar_perubahan' => self::DASAR])
            ->assertStatus(409)->assertJsonPath('error.rincian.0.jumlah_penugasan', 1);
        $this->assertSame('aktif', UnitOrganisasi::query()->findOrFail($melati['id'])->status);
    }

    public function test_cakupan_unit_hanya_subpohonnya(): void
    {
        [$irna, $melati, $igd] = $this->pohonContoh();
        $pengguna = $this->buatPengguna();
        $this->tugaskan($pengguna, $this->buatPeran(['master_data.organisasi']), cakupan: new Cakupan('unit', $irna->id));
        $evaluator = app(AccessEvaluator::class);

        // Lapis 3: unit induk mencakup turunannya, tidak unit lain atau seluruh RS.
        $this->assertTrue($evaluator->boleh($pengguna, 'master_data.organisasi.perbaiki', new Cakupan('unit', $melati->id)));
        $this->assertFalse($evaluator->boleh($pengguna, 'master_data.organisasi.perbaiki', new Cakupan('unit', $igd->id)));
        $this->assertFalse($evaluator->boleh($pengguna, 'master_data.organisasi.perbaiki', Cakupan::rs()));

        $this->masukSebagai($pengguna);
        $this->assertSame(['IRNA'], $this->minta('GET', '/api/v1/unit-organisasi?akar=1')->json('data.*.kode'), 'Akar = akar subpohon cakupan.');
        $this->assertEqualsCanonicalizing(['IRNA', 'MELATI'], $this->minta('GET', '/api/v1/unit-organisasi')->json('data.*.kode'));
        $this->minta('GET', "/api/v1/unit-organisasi/{$igd->id}")->assertNotFound();
        $this->mintaDenganVersi('PATCH', "/api/v1/unit-organisasi/{$igd->id}", '0', ['nama' => 'Coba', 'dasar_perubahan' => self::DASAR])->assertForbidden();
        $this->minta('POST', '/api/v1/unit-organisasi', ['induk_id' => null, 'kode' => 'BARU', 'nama' => 'Akar baru', 'jenis' => 'bidang', 'dasar_perubahan' => self::DASAR])
            ->assertForbidden();
        $this->mintaDenganVersi('PATCH', "/api/v1/unit-organisasi/{$melati->id}", '0', ['induk_id' => $igd->id, 'dasar_perubahan' => self::DASAR])
            ->assertForbidden();
        $this->minta('POST', '/api/v1/unit-organisasi', ['induk_id' => $melati->id, 'kode' => 'MELATI-2', 'nama' => 'Melati 2', 'jenis' => 'ruang', 'dasar_perubahan' => self::DASAR])
            ->assertCreated();
        $this->minta('GET', '/api/v1/ksm')->assertForbidden();
        // Filter tidak bisa membuka unit di luar cakupan; pindah ke tingkat teratas butuh cakupan rs.
        $this->assertSame([], $this->minta('GET', "/api/v1/unit-organisasi?induk_id={$igd->id}")->json('data'));
        $this->assertSame([], $this->minta('GET', '/api/v1/unit-organisasi?cari=gawat')->json('data'));
        $this->mintaDenganVersi('PATCH', "/api/v1/unit-organisasi/{$melati->id}", '0', ['induk_id' => null, 'dasar_perubahan' => self::DASAR])
            ->assertForbidden();
        // Unit puncak subpohon sendiri boleh diubah (bukan dipindah).
        $this->mintaDenganVersi('PATCH', "/api/v1/unit-organisasi/{$irna->id}", '0', ['nama' => 'IRNA Baru', 'dasar_perubahan' => self::DASAR])
            ->assertOk();
    }

    public function test_pengelola_akun_boleh_membaca_dan_unit_milik_saya(): void
    {
        [$irna, $melati] = $this->pohonContoh();
        $this->masukSebagai($this->pengelola());
        $this->assertCount(3, $this->minta('GET', '/api/v1/unit-organisasi')->assertOk()->json('data'));

        $perawat = $this->buatPengguna();
        $this->tugaskan($perawat, $this->buatPeran(['igd.papan']), cakupan: new Cakupan('unit', $melati->id));
        $this->masukSebagai($perawat);
        $this->assertSame(['MELATI'], $this->minta('GET', '/api/v1/unit-organisasi?milik_saya=true&akar=1')->assertOk()->json('data.*.kode'));
        $this->minta('GET', '/api/v1/unit-organisasi?milik_saya=false')->assertForbidden();
        $this->minta('GET', '/api/v1/unit-organisasi?milik_saya=0')->assertForbidden();
        $this->assertSesuaiKontrak($this->minta('GET', "/api/v1/unit-organisasi/{$irna->id}")->assertForbidden(), 'GET', '/unit-organisasi/{id}');
    }

    public function test_penugasan_bercakupan_unit_dengan_label(): void
    {
        [, $melati] = $this->pohonContoh();
        $akun = $this->buatPengguna();
        $peran = $this->buatPeran(['igd.papan']);
        $this->masukSebagai($this->pengelola());

        $tugas = $this->minta('POST', "/api/v1/akun/{$akun->id}/penugasan", [
            'peran_id' => $peran->id, 'cakupan_jenis' => 'unit', 'cakupan_id' => $melati->id, 'dasar_pemberian' => self::DASAR,
        ])->assertCreated()->assertJsonPath('data.cakupan_label', 'MELATI · Ruang Melati');
        $this->assertSesuaiKontrak($tugas, 'POST', '/akun/{id}/penugasan');
        $this->minta('GET', "/api/v1/akun/{$akun->id}")->assertJsonPath('data.penugasan.0.cakupan_label', 'MELATI · Ruang Melati');
        $this->assertTrue(app(AccessEvaluator::class)->boleh($akun->refresh(), 'igd.papan.lihat', new Cakupan('unit', $melati->id)));
    }

    public function test_tanpa_izin_ditolak(): void
    {
        $this->masukSebagai($this->buatPengguna());
        $this->assertSesuaiKontrak($this->minta('GET', '/api/v1/unit-organisasi')->assertForbidden(), 'GET', '/unit-organisasi');
        $this->minta('POST', '/api/v1/unit-organisasi', [])->assertForbidden();
        $unit = UnitOrganisasi::query()->create(['kode' => 'IGD', 'nama' => 'IGD', 'jenis' => 'instalasi', 'berlaku_dari' => '2026-01-01']);
        $this->minta('GET', "/api/v1/unit-organisasi/{$unit->id}")->assertForbidden();
        $this->mintaDenganVersi('PATCH', "/api/v1/unit-organisasi/{$unit->id}", '0', ['nama' => 'X', 'dasar_perubahan' => self::DASAR])->assertForbidden();
        $this->minta('GET', '/api/v1/unit-organisasi?akar=ya')->assertStatus(422);
    }

    public function test_pohon_lima_ratus_unit_tetap_cepat(): void
    {
        $baris = [];
        for ($i = 0; $i < 25; $i++) {
            $baris[] = ['id' => $induk = (string) Str::uuid7(), 'induk_id' => null, 'kode' => sprintf('BEBAN-%02d', $i), 'nama' => "Instalasi $i", 'jenis' => 'instalasi', 'berlaku_dari' => '2026-01-01'];
            for ($j = 0; $j < 19; $j++) {
                $baris[] = ['id' => (string) Str::uuid7(), 'induk_id' => $induk, 'kode' => sprintf('BEBAN-%02d-%02d', $i, $j), 'nama' => "Ruang $i-$j", 'jenis' => 'ruang', 'berlaku_dari' => '2026-01-01'];
            }
        }
        DB::table('inti.unit_organisasi')->insert($baris);
        $this->masukSebagai($this->pengelolaData());
        $this->minta('GET', '/api/v1/unit-organisasi?akar=1')->assertOk();

        $waktu = [];
        foreach ([...array_fill(0, 10, '/api/v1/unit-organisasi?akar=1'), ...array_map(fn ($b) => '/api/v1/unit-organisasi?induk_id='.$b['id'], array_slice(array_filter($baris, fn ($b) => $b['induk_id'] === null), 0, 10))] as $uri) {
            $mulai = hrtime(true);
            $this->minta('GET', $uri)->assertOk();
            $waktu[] = (hrtime(true) - $mulai) / 1e6;
        }
        sort($waktu);

        $this->assertSame(500, UnitOrganisasi::query()->count());
        $this->assertLessThan(300, $waktu[(int) ceil(0.95 * count($waktu)) - 1], 'P95 (ms): '.json_encode($waktu));
    }

    private function pengelolaData(): Pengguna
    {
        $pengguna = $this->buatPengguna();
        $this->tugaskan($pengguna, $this->buatPeran(['master_data.organisasi']));

        return $pengguna;
    }

    /** @return array{0: UnitOrganisasi, 1: UnitOrganisasi, 2: UnitOrganisasi} IRNA, MELATI (anak IRNA), IGD */
    private function pohonContoh(): array
    {
        $irna = UnitOrganisasi::query()->create(['kode' => 'IRNA', 'nama' => 'Instalasi Rawat Inap', 'jenis' => 'instalasi', 'berlaku_dari' => '2026-01-01']);
        $melati = UnitOrganisasi::query()->create(['induk_id' => $irna->id, 'kode' => 'MELATI', 'nama' => 'Ruang Melati', 'jenis' => 'ruang', 'berlaku_dari' => '2026-01-01']);
        $igd = UnitOrganisasi::query()->create(['kode' => 'IGD', 'nama' => 'Instalasi Gawat Darurat', 'jenis' => 'instalasi', 'berlaku_dari' => '2026-01-01']);

        return [$irna, $melati, $igd];
    }

    private function buat(?string $induk, string $kode, string $nama, string $jenis): TestResponse
    {
        return $this->minta('POST', '/api/v1/unit-organisasi', [
            'induk_id' => $induk, 'kode' => $kode, 'nama' => $nama, 'jenis' => $jenis, 'dasar_perubahan' => self::DASAR,
        ])->assertCreated();
    }
}
