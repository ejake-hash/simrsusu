<?php

namespace Tests\Feature\MasterData;

use App\Modules\Akses\Domain\Cakupan;
use App\Modules\Akses\Models\PenggunaPeran;
use App\Modules\MasterData\Models\Ksm;
use App\Modules\MasterData\Models\UnitOrganisasi;
use Tests\Support\AksesUji;
use Tests\Support\KlienSpa;
use Tests\Support\KontrakApi;
use Tests\TestCase;

/** Tiket 1.8a-be: /ksm (kontrak v0.3.0). Data seluruh RS: izin dengan cakupan `rs`. */
class KsmTest extends TestCase
{
    use AksesUji, KlienSpa, KontrakApi;

    private const DASAR = 'SK Komite Medik 1/2026';

    public function test_tambah_ubah_daftar_dan_nonaktifkan(): void
    {
        $pengguna = $this->buatPengguna();
        $this->tugaskan($pengguna, $this->buatPeran(['master_data.organisasi']));
        $this->masukSebagai($pengguna);

        $ipd = $this->minta('POST', '/api/v1/ksm', ['kode' => 'KSM-IPD', 'nama' => 'Ilmu Penyakit Dalam', 'dasar_perubahan' => self::DASAR])->assertCreated();
        $this->assertSesuaiKontrak($ipd, 'POST', '/ksm');
        $ipd->assertJsonPath('data.ketua', null)->assertJsonPath('data.status', 'aktif');
        $this->minta('POST', '/api/v1/ksm', ['kode' => 'KSM-ANAK', 'nama' => 'Ilmu Kesehatan Anak', 'dasar_perubahan' => self::DASAR])->assertCreated();
        $this->assertSesuaiKontrak($this->minta('POST', '/api/v1/ksm', ['kode' => 'KSM-IPD', 'nama' => 'Lagi', 'dasar_perubahan' => self::DASAR])
            ->assertStatus(409)->assertJsonPath('error.kode', 'kode_sudah_dipakai'), 'POST', '/ksm');

        $daftar = $this->assertSesuaiKontrak($this->minta('GET', '/api/v1/ksm')->assertOk(), 'GET', '/ksm');
        $this->assertSame(['KSM-ANAK', 'KSM-IPD'], $daftar->json('data.*.kode'));
        $this->assertSame(['KSM-IPD'], $this->minta('GET', '/api/v1/ksm?cari=dalam')->json('data.*.kode'));

        $id = $ipd->json('data.id');
        $ubah = $this->mintaDenganVersi('PATCH', "/api/v1/ksm/$id", $ipd->json('data.versi'), ['nama' => 'Penyakit Dalam', 'dasar_perubahan' => self::DASAR])->assertOk();
        $this->assertSesuaiKontrak($ubah, 'PATCH', '/ksm/{id}');
        $this->mintaDenganVersi('PATCH', "/api/v1/ksm/$id", $ipd->json('data.versi'), ['nama' => 'Basi', 'dasar_perubahan' => self::DASAR])->assertStatus(412);

        // Penugasan ber-cakupan KSM (belum bisa lewat API, disisipkan langsung) menahan penonaktifan.
        PenggunaPeran::query()->create([
            'pengguna_id' => $this->buatPengguna()->id, 'peran_id' => $this->buatPeran(['igd.papan'])->id,
            'cakupan_jenis' => 'ksm', 'cakupan_id' => $id, 'dasar_pemberian' => 'SK uji',
        ]);
        $this->mintaDenganVersi('PATCH', "/api/v1/ksm/$id", $ubah->json('data.versi'), ['status' => 'nonaktif', 'dasar_perubahan' => self::DASAR])
            ->assertStatus(409)->assertJsonPath('error.kode', 'ksm_masih_dipakai')->assertJsonPath('error.rincian.0.jumlah_penugasan', 1);
        $this->assertAuditTerakhir('master_data.ksm_diubah', $id);
        $this->assertSesuaiKontrak($this->minta('GET', "/api/v1/ksm/$id")->assertOk(), 'GET', '/ksm/{id}');
    }

    public function test_hanya_cakupan_rs(): void
    {
        $unit = UnitOrganisasi::query()->create(['kode' => 'IRNA', 'nama' => 'IRNA', 'jenis' => 'instalasi', 'berlaku_dari' => '2026-01-01']);
        $ksm = Ksm::query()->create(['kode' => 'KSM-IPD', 'nama' => 'Ilmu Penyakit Dalam']);
        $pengguna = $this->buatPengguna();
        $this->tugaskan($pengguna, $this->buatPeran(['master_data.organisasi']), cakupan: new Cakupan('unit', $unit->id));
        $this->masukSebagai($pengguna);

        $this->assertSesuaiKontrak($this->minta('GET', '/api/v1/ksm')->assertForbidden(), 'GET', '/ksm');
        $this->minta('GET', "/api/v1/ksm/{$ksm->id}")->assertForbidden();
        $this->minta('POST', '/api/v1/ksm', ['kode' => 'KSM-X', 'nama' => 'X', 'dasar_perubahan' => self::DASAR])->assertForbidden();
    }
}
