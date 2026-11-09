<?php

namespace Tests\Feature\MasterData;

use App\Modules\Akses\Domain\Cakupan;
use App\Modules\Akses\Models\Izin;
use App\Modules\Akses\Models\Menu;
use App\Modules\Akses\Models\PeranIzin;
use App\Modules\Akses\Models\PeranMenu;
use App\Modules\MasterData\Models\InstrumenTriase;
use Tests\Support\AksesUji;
use Tests\Support\KlienSpa;
use Tests\Support\KontrakApi;
use Tests\Support\KunjunganUji;
use Tests\TestCase;

/**
 * Tiket 2.24: data induk instrumen triase /data-induk/instrumen-triase (kontrak v0.32.0). Pola penjamin: edit langsung
 * berizin master_data.instrumen_triase.* cakupan rs, dasar perubahan, audit. Instrumen dan skala sintetis berlabel contoh.
 */
class InstrumenTriaseTest extends TestCase
{
    use AksesUji, KlienSpa, KontrakApi, KunjunganUji;

    private const DASAR = 'SK Komite Medik contoh 1/2026';

    private const URL = '/api/v1/data-induk/instrumen-triase';

    private function admin(): void
    {
        $pengguna = $this->buatPengguna();
        $this->tugaskan($pengguna, $this->buatPeran(['master_data.instrumen_triase']));
        $this->masukSebagai($pengguna);
    }

    /** @return list<array{kode: string, label: string}> */
    private static function skala(int $n): array
    {
        return array_map(fn (int $i) => ['kode' => "C$i", 'label' => "Tingkat contoh $i"], range(1, $n));
    }

    public function test_tambah_daftar_ubah_skala_dan_nonaktifkan_dengan_audit(): void
    {
        $this->admin();

        $baru = $this->minta('POST', self::URL, ['kode' => 'CONTOH-A', 'nama' => 'Instrumen contoh A', 'skala' => self::skala(3), 'dasar_perubahan' => self::DASAR])
            ->assertCreated()->assertJsonPath('data.status', 'aktif')->assertJsonPath('data.versi', '0')
            ->assertJsonPath('data.skala', self::skala(3));
        $this->assertSesuaiKontrak($baru, 'POST', '/data-induk/instrumen-triase');
        $id = (string) $baru->json('data.id');
        $audit = $this->assertAuditTerakhir('master_data.instrumen_triase_dibuat', $id);
        $this->assertStringContainsString(self::DASAR, (string) $audit->sesudah);

        // Tanpa skala = instrumen tanpa skala.
        $this->minta('POST', self::URL, ['kode' => 'CONTOH-B', 'nama' => 'Instrumen contoh B', 'dasar_perubahan' => self::DASAR])
            ->assertCreated()->assertJsonPath('data.skala', []);

        $daftar = $this->minta('GET', self::URL)->assertOk()->assertJsonPath('data.*.kode', ['CONTOH-A', 'CONTOH-B']);
        $this->assertSesuaiKontrak($daftar, 'GET', '/data-induk/instrumen-triase');
        $this->minta('GET', self::URL.'?cari=contoh%20b')->assertOk()->assertJsonPath('data.*.kode', ['CONTOH-B']);
        $this->minta('GET', self::URL.'?status=hapus')->assertStatus(422);
        $this->assertSesuaiKontrak($this->minta('GET', self::URL."/$id")->assertOk(), 'GET', '/data-induk/instrumen-triase/{id}');

        // Skala diganti utuh, urutan = urutan tampil.
        $ubah = $this->mintaDenganVersi('PATCH', self::URL."/$id", '0', ['nama' => 'Instrumen contoh A revisi',
            'skala' => [['kode' => 'C2', 'label' => 'Tingkat contoh 2'], ['kode' => 'C1', 'label' => '  Tingkat contoh 1  ']], 'dasar_perubahan' => self::DASAR])
            ->assertOk()->assertJsonPath('data.versi', '1')->assertJsonPath('data.skala.*.kode', ['C2', 'C1'])->assertJsonPath('data.skala.1.label', 'Tingkat contoh 1');
        $this->assertSesuaiKontrak($ubah, 'PATCH', '/data-induk/instrumen-triase/{id}');
        $audit = $this->assertAuditTerakhir('master_data.instrumen_triase_diubah', $id);
        $this->assertStringContainsString('Instrumen contoh A', (string) $audit->sebelum);

        $this->mintaDenganVersi('PATCH', self::URL."/$id", '0', ['nama' => 'Basi', 'dasar_perubahan' => self::DASAR])->assertStatus(412);
        $this->minta('PATCH', self::URL."/$id", ['nama' => 'Tanpa versi', 'dasar_perubahan' => self::DASAR])->assertStatus(428);
        $this->mintaDenganVersi('PATCH', self::URL."/$id", '1', ['dasar_perubahan' => self::DASAR])->assertStatus(422);
        $this->mintaDenganVersi('PATCH', self::URL."/$id", '1', ['kode' => 'CONTOH-B', 'dasar_perubahan' => self::DASAR])
            ->assertStatus(422)->assertJsonPath('error.rincian.0.kolom', 'kode');

        $this->mintaDenganVersi('PATCH', self::URL."/$id", '1', ['status' => 'nonaktif', 'dasar_perubahan' => self::DASAR])
            ->assertOk()->assertJsonPath('data.status', 'nonaktif');
        $this->minta('GET', self::URL.'?status=aktif')->assertOk()->assertJsonPath('data.*.kode', ['CONTOH-B']);
        $this->assertSame('nonaktif', InstrumenTriase::query()->findOrFail($id)->status);
    }

    public function test_validasi_skala_dan_kode(): void
    {
        $this->admin();
        $isi = fn (array $ganti) => ['kode' => 'CONTOH-V', 'nama' => 'Instrumen contoh V', 'skala' => self::skala(2), 'dasar_perubahan' => self::DASAR, ...$ganti];

        $ganda = $this->minta('POST', self::URL, $isi(['skala' => [['kode' => 'C1', 'label' => 'Satu'], ['kode' => 'C1', 'label' => 'Dua']]]))
            ->assertStatus(422)->assertJsonPath('error.kode', 'validasi_gagal');
        $this->assertSesuaiKontrak($ganda, 'POST', '/data-induk/instrumen-triase');
        $this->assertContains('skala.1.kode', $ganda->json('error.rincian.*.kolom'));
        $this->minta('POST', self::URL, $isi(['skala' => [['kode' => 'C1', 'label' => '']]]))->assertStatus(422)->assertJsonPath('error.rincian.0.kolom', 'skala.0.label');
        $this->minta('POST', self::URL, $isi(['skala' => [['kode' => 'C 1', 'label' => 'Spasi']]]))->assertStatus(422)->assertJsonPath('error.rincian.0.kolom', 'skala.0.kode');
        $this->minta('POST', self::URL, $isi(['skala' => [['kode' => 'C1', 'label' => 'Satu', 'nilai' => 9]]]))->assertStatus(422);
        $this->minta('POST', self::URL, $isi(['skala' => self::skala(21)]))->assertStatus(422)->assertJsonPath('error.rincian.0.kolom', 'skala');
        $this->minta('POST', self::URL, $isi(['skala' => 'C1,C2']))->assertStatus(422);
        $this->minta('POST', self::URL, $isi(['kode' => 'kecil']))->assertStatus(422)->assertJsonPath('error.rincian.0.kolom', 'kode');
        $this->minta('POST', self::URL, $isi(['dasar_perubahan' => null]))->assertStatus(422)->assertJsonPath('error.rincian.0.kolom', 'dasar_perubahan');
        $this->assertSame(0, InstrumenTriase::query()->where('kode', 'CONTOH-V')->count());

        $this->minta('POST', self::URL, $isi([]))->assertCreated();
        $this->minta('POST', self::URL, $isi(['nama' => 'Kode sama']))->assertStatus(422)->assertJsonPath('error.rincian.0.kolom', 'kode');
        $this->assertSame(1, InstrumenTriase::query()->where('kode', 'CONTOH-V')->count());
    }

    public function test_izin_cakupan_rs_dan_lihat_saja(): void
    {
        $instrumen = InstrumenTriase::query()->create(['kode' => 'CONTOH-I', 'nama' => 'Instrumen contoh I', 'skala' => self::skala(1)]);

        // Tanpa izin atau bercakupan unit: 403 sebelum isian dibaca.
        $tanpa = $this->buatPengguna();
        $unit = $this->buatPengguna();
        $this->siapkanKunjungan();
        $this->tugaskan($unit, $this->buatPeran(['master_data.instrumen_triase']), cakupan: new Cakupan('unit', $this->poli->unit_organisasi_id));
        foreach ([$tanpa, $unit] as $akun) {
            $this->masukSebagai($akun);
            $this->minta('GET', self::URL)->assertForbidden();
            $this->minta('GET', self::URL.'?status=ngawur')->assertForbidden();
            $this->minta('POST', self::URL, [])->assertForbidden();
            $this->mintaDenganVersi('PATCH', self::URL."/{$instrumen->id}", '0', [])->assertForbidden();
        }

        // Lihat saja: daftar dan rincian boleh, tambah/ubah tidak.
        $lihat = $this->buatPengguna();
        $peran = $this->buatPeran([]);
        $menu = Menu::query()->where('kode', 'master_data.instrumen_triase')->firstOrFail();
        PeranMenu::query()->create(['peran_id' => $peran->id, 'menu_id' => $menu->id]);
        PeranIzin::query()->create(['peran_id' => $peran->id, 'izin_id' => Izin::query()->where('kode', 'master_data.instrumen_triase.lihat')->value('id')]);
        $this->tugaskan($lihat, $peran);
        $this->masukSebagai($lihat);
        $this->minta('GET', self::URL)->assertOk()->assertJsonPath('data.0.kode', 'CONTOH-I');
        $this->minta('POST', self::URL, ['kode' => 'CONTOH-J', 'nama' => 'Instrumen contoh J', 'dasar_perubahan' => self::DASAR])->assertForbidden();
        $this->mintaDenganVersi('PATCH', self::URL."/{$instrumen->id}", '0', ['status' => 'nonaktif', 'dasar_perubahan' => self::DASAR])->assertForbidden();
        $this->assertSame('aktif', $instrumen->fresh()?->status);
    }

    public function test_instrumen_nonaktif_tidak_dipakai_triase_baru_dan_triase_lama_tetap_terbaca(): void
    {
        $this->siapkanKunjungan();
        $perawat = $this->buatPengguna();
        $this->tugaskan($perawat, $this->buatPeran(['igd.pendaftaran', 'igd.triase', 'master_data.instrumen_triase']));
        $this->masukSebagai($perawat);
        $id = (string) $this->minta('POST', self::URL, ['kode' => 'CONTOH-N', 'nama' => 'Instrumen contoh N', 'skala' => self::skala(2), 'dasar_perubahan' => self::DASAR])
            ->assertCreated()->json('data.id');
        $kunjungan = (string) $this->minta('POST', '/api/v1/kunjungan-igd', ['pasien_id' => $this->pasien()->id, 'cara_bayar' => 'non_jkn'])->assertCreated()->json('data.id');
        $this->minta('POST', "/api/v1/kunjungan/$kunjungan/triase", ['kategori' => 'kuning', 'instrumen_triase_id' => $id, 'skala' => 'C2'])->assertCreated();

        $this->mintaDenganVersi('PATCH', self::URL."/$id", '0', ['status' => 'nonaktif', 'dasar_perubahan' => self::DASAR])->assertOk();
        $this->minta('GET', '/api/v1/instrumen-triase')->assertOk()->assertJsonCount(0, 'data');
        $this->minta('POST', "/api/v1/kunjungan/$kunjungan/triase", ['kategori' => 'merah', 'instrumen_triase_id' => $id, 'skala' => 'C1'])
            ->assertStatus(422)->assertJsonPath('error.rincian.0.kolom', 'instrumen_triase_id');
        $this->minta('GET', "/api/v1/kunjungan/$kunjungan/triase")->assertOk()->assertJsonPath('data.0.instrumen.kode', 'CONTOH-N')
            ->assertJsonPath('data.0.skala', ['kode' => 'C2', 'label' => 'Tingkat contoh 2']);
    }
}
