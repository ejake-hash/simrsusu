<?php

namespace Tests\Feature\MasterData;

use App\Modules\Akses\Domain\Cakupan;
use App\Modules\Akses\Models\Izin;
use App\Modules\Akses\Models\Menu;
use App\Modules\Akses\Models\PeranIzin;
use App\Modules\Akses\Models\PeranMenu;
use App\Modules\MasterData\Models\Penjamin;
use App\Modules\MasterData\Models\UnitOrganisasi;
use Tests\Support\AksesUji;
use Tests\Support\KlienSpa;
use Tests\Support\KontrakApi;
use Tests\TestCase;

/** Tiket 2.1-be: /data-induk/penjamin (kontrak v0.17.0). Data seluruh RS: izin master_data.penjamin.* cakupan `rs`. */
class PenjaminTest extends TestCase
{
    use AksesUji, KlienSpa, KontrakApi;

    private const DASAR = 'Perjanjian kerja sama 1/2026';

    private function admin(): void
    {
        $pengguna = $this->buatPengguna();
        $this->tugaskan($pengguna, $this->buatPeran(['master_data.penjamin']));
        $this->masukSebagai($pengguna);
    }

    public function test_tambah_ubah_daftar_dan_nonaktifkan(): void
    {
        $this->admin();

        $bpjs = $this->minta('POST', '/api/v1/data-induk/penjamin', ['kode' => 'BPJS', 'nama' => 'BPJS Kesehatan', 'jenis' => 'bpjs', 'dasar_perubahan' => self::DASAR])->assertCreated();
        $this->assertSesuaiKontrak($bpjs, 'POST', '/data-induk/penjamin');
        $bpjs->assertJsonPath('data.status', 'aktif')->assertJsonPath('data.plafon', null)->assertJsonPath('data.versi', '0');

        $asuransi = $this->minta('POST', '/api/v1/data-induk/penjamin', [
            'kode' => 'ASR-1', 'nama' => 'Asuransi Contoh', 'jenis' => 'asuransi', 'masa_berlaku_gl_hari' => 30,
            'plafon' => '25000000.50', 'jatuh_tempo_hari' => 45, 'dasar_perubahan' => self::DASAR,
        ])->assertCreated()->assertJsonPath('data.plafon', '25000000.50');
        $this->assertAuditTerakhir('master_data.penjamin_dibuat', $asuransi->json('data.id'));

        // Kode sama → 422 validasi_gagal pada field kode.
        $ganda = $this->minta('POST', '/api/v1/data-induk/penjamin', ['kode' => 'BPJS', 'nama' => 'Lagi', 'jenis' => 'umum', 'dasar_perubahan' => self::DASAR])
            ->assertStatus(422)->assertJsonPath('error.kode', 'validasi_gagal');
        $this->assertSesuaiKontrak($ganda, 'POST', '/data-induk/penjamin');
        $this->assertSame(1, Penjamin::query()->where('kode', 'BPJS')->count());

        $daftar = $this->assertSesuaiKontrak($this->minta('GET', '/api/v1/data-induk/penjamin')->assertOk(), 'GET', '/data-induk/penjamin');
        $this->assertSame(['ASR-1', 'BPJS'], $daftar->json('data.*.kode'));
        $this->assertSame(['BPJS'], $this->minta('GET', '/api/v1/data-induk/penjamin?jenis=bpjs')->json('data.*.kode'));
        $this->assertSame(['ASR-1'], $this->minta('GET', '/api/v1/data-induk/penjamin?cari=contoh')->json('data.*.kode'));
        $this->minta('GET', '/api/v1/data-induk/penjamin?jenis=lain')->assertStatus(422);

        $id = $asuransi->json('data.id');
        $ubah = $this->mintaDenganVersi('PATCH', "/api/v1/data-induk/penjamin/$id", '0', ['plafon' => null, 'status' => 'nonaktif', 'dasar_perubahan' => self::DASAR])->assertOk();
        $this->assertSesuaiKontrak($ubah, 'PATCH', '/data-induk/penjamin/{id}');
        $ubah->assertJsonPath('data.plafon', null)->assertJsonPath('data.versi', '1');
        $this->mintaDenganVersi('PATCH', "/api/v1/data-induk/penjamin/$id", '0', ['nama' => 'Basi', 'dasar_perubahan' => self::DASAR])->assertStatus(412);
        $this->minta('PATCH', "/api/v1/data-induk/penjamin/$id", ['nama' => 'Tanpa versi', 'dasar_perubahan' => self::DASAR])->assertStatus(428);
        $this->mintaDenganVersi('PATCH', "/api/v1/data-induk/penjamin/$id", '1', ['kode' => 'BPJS', 'dasar_perubahan' => self::DASAR])->assertStatus(422);
        $this->mintaDenganVersi('PATCH', "/api/v1/data-induk/penjamin/$id", '1', ['dasar_perubahan' => self::DASAR])->assertStatus(422);
        $this->assertAuditTerakhir('master_data.penjamin_diubah', $id);

        // Nonaktif tidak muncul di pilihan (status=aktif), tetap terbaca di rincian dan daftar tanpa filter.
        $this->assertSame(['BPJS'], $this->minta('GET', '/api/v1/data-induk/penjamin?status=aktif')->json('data.*.kode'));
        $this->assertSesuaiKontrak($this->minta('GET', "/api/v1/data-induk/penjamin/$id")->assertOk()->assertJsonPath('data.status', 'nonaktif'), 'GET', '/data-induk/penjamin/{id}');
        $this->assertSame(2, Penjamin::query()->count());
    }

    public function test_tanpa_izin_input_ditolak_dan_cakupan_harus_rs(): void
    {
        $penjamin = Penjamin::query()->create(['kode' => 'UMUM', 'nama' => 'Umum', 'jenis' => 'umum']);

        $pembaca = $this->buatPengguna();
        $peran = $this->buatPeran([]);
        $menu = Menu::query()->where('kode', 'master_data.penjamin')->firstOrFail();
        PeranMenu::query()->create(['peran_id' => $peran->id, 'menu_id' => $menu->id]);
        PeranIzin::query()->create(['peran_id' => $peran->id, 'izin_id' => Izin::query()->where('kode', 'master_data.penjamin.lihat')->value('id')]);
        $this->tugaskan($pembaca, $peran);
        $this->masukSebagai($pembaca);

        $this->minta('GET', '/api/v1/data-induk/penjamin')->assertOk();
        $tolak = $this->minta('POST', '/api/v1/data-induk/penjamin', ['kode' => 'X', 'nama' => 'Xx', 'jenis' => 'umum', 'dasar_perubahan' => self::DASAR])
            ->assertForbidden()->assertJsonPath('error.kode', 'akses_ditolak');
        $this->assertSesuaiKontrak($tolak, 'POST', '/data-induk/penjamin');
        $this->mintaDenganVersi('PATCH', "/api/v1/data-induk/penjamin/{$penjamin->id}", '0', ['nama' => 'Ubah', 'dasar_perubahan' => self::DASAR])->assertForbidden();

        $unit = UnitOrganisasi::query()->create(['kode' => 'IRNA', 'nama' => 'IRNA', 'jenis' => 'instalasi', 'berlaku_dari' => '2026-01-01']);
        $terbatas = $this->buatPengguna();
        $this->tugaskan($terbatas, $this->buatPeran(['master_data.penjamin']), cakupan: new Cakupan('unit', $unit->id));
        $this->masukSebagai($terbatas);
        $this->minta('GET', '/api/v1/data-induk/penjamin')->assertForbidden();
        $this->minta('GET', "/api/v1/data-induk/penjamin/{$penjamin->id}")->assertForbidden();
    }
}
