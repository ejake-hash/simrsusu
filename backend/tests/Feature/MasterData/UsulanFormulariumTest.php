<?php

namespace Tests\Feature\MasterData;

use App\Core\Http\SuntinganBerversi;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\MasterData\Models\Barang;
use App\Modules\MasterData\Models\Formularium;
use App\Modules\MasterData\Models\ItemFormularium;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AksesUji;
use Tests\Support\KlienSpa;
use Tests\Support\KontrakApi;
use Tests\TestCase;

/** Tiket 2.12-be: usulan jenis `formularium` sesudah siap melayani (kontrak v0.24.0, FR-54-02). Data sintetis. */
class UsulanFormulariumTest extends TestCase
{
    use AksesUji, KlienSpa, KontrakApi;

    private const DASAR = 'Keputusan KFT 3/2026';

    private Pengguna $pengusul;

    private Pengguna $pemverifikasi;

    private Pengguna $penyetuju;

    private Formularium $formularium;

    private Barang $parasetamol;

    private Barang $amoksisilin;

    private ItemFormularium $itemLama;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $peran = $this->buatPeran(['master_data.usulan', 'farmasi.formularium']);
        foreach (['pengusul', 'pemverifikasi', 'penyetuju'] as $p) {
            $this->$p = $this->buatPengguna();
            $this->tugaskan($this->$p, $peran);
        }
        $this->parasetamol = Barang::query()->create(['kode' => 'PCT-500', 'nama' => 'Parasetamol 500 mg', 'jenis' => 'obat', 'generik_paten' => 'generik', 'satuan_terkecil' => 'tablet']);
        $this->amoksisilin = Barang::query()->create(['kode' => 'AMX-500', 'nama' => 'Amoksisilin 500 mg', 'jenis' => 'obat', 'generik_paten' => 'generik', 'satuan_terkecil' => 'kapsul']);
        $this->formularium = Formularium::query()->create(['kode' => 'FRS-2026', 'nama' => 'Formularium RS 2026', 'jenis' => 'rs', 'berlaku_dari' => '2026-01-01']);
        $this->itemLama = ItemFormularium::query()->create(['formularium_id' => $this->formularium->id, 'barang_id' => $this->parasetamol->id]);
        $this->tandaiInstalasiSiap();
    }

    public function test_alur_usulan_formularium_terdaftar(): void
    {
        $this->assertTrue(DB::table('inti.alur_usulan')->where('jenis_data', 'formularium')->exists());
    }

    public function test_tambah_dan_tutup_item_berlaku_besok_dengan_pemisahan_tugas(): void
    {
        $besok = Carbon::parse(SuntinganBerversi::hariIni())->addDay()->toDateString();
        $this->masukSebagai($this->pengusul);
        // Tulis langsung ditolak; jalur usulan menggantikannya.
        $this->minta('POST', "/api/v1/data-induk/formularium/{$this->formularium->id}/item", ['barang_id' => $this->amoksisilin->id, 'dasar_perubahan' => self::DASAR])
            ->assertStatus(409)->assertJsonPath('error.kode', 'harus_lewat_usulan');

        $ajukan = $this->minta('POST', '/api/v1/usulan-data-induk', $this->usulan([
            ['objek_jenis' => 'formularium_item', 'aksi' => 'tambah', 'objek_id' => null,
                'nilai_baru' => ['formularium_id' => $this->formularium->id, 'barang_id' => $this->amoksisilin->id, 'maks_per_resep' => 15, 'kronis' => false]],
            ['objek_jenis' => 'formularium_item', 'aksi' => 'tutup', 'objek_id' => $this->itemLama->id, 'nilai_baru' => ['alasan' => 'Diganti sediaan sirup']],
        ], $besok))->assertCreated()->assertJsonPath('data.ringkasan', 'Tambah 1 item formularium, Tutup 1 item formularium')
            ->assertJsonPath('data.perubahan.0.objek_label', 'Item baru Amoksisilin 500 mg · Formularium RS 2026')
            ->assertJsonPath('data.perubahan.1.objek_label', 'Parasetamol 500 mg · Formularium RS 2026');
        $this->assertSesuaiKontrak($ajukan, 'POST', '/usulan-data-induk');
        $tampil = array_column($ajukan->json('data.perubahan.0.tampilan'), 'baru', 'isian');
        $this->assertSame(['Amoksisilin 500 mg', 'Formularium RS 2026', 'Tidak'], [$tampil['barang_id'], $tampil['formularium_id'], $tampil['kronis']]);
        $this->assertSame('Nonaktif mulai '.Carbon::parse($besok)->format('d-m-Y'), $ajukan->json('data.perubahan.1.tampilan.0.baru'));
        $id = $ajukan->json('data.id');
        $this->minta('POST', "/api/v1/usulan-data-induk/$id/verifikasi")->assertStatus(403)->assertJsonPath('error.kode', 'pengusul_sendiri');
        $this->setujuiOlehDuaOrang($id);

        // Hari ini formularium lama; besok 00.00 yang baru.
        $this->assertSame([$this->parasetamol->id], $this->barangAktif());
        $this->travelTo(Carbon::parse($besok.' 00:01', (string) config('instalasi.zona_waktu')));
        $this->artisan('master-data:terapkan-usulan')->assertSuccessful();
        $this->assertSame([$this->amoksisilin->id], $this->barangAktif());
        $this->assertSame('berlaku', DB::table('inti.usulan_data_induk')->where('id', $id)->value('tahap'));
        $this->assertSame(15, ItemFormularium::query()->where('barang_id', $this->amoksisilin->id)->value('maks_per_resep'));
    }

    public function test_formularium_baru_berlaku_mulai_tanggal_usulan_dan_diubah(): void
    {
        $this->masukSebagai($this->pengusul);
        $hariIni = SuntinganBerversi::hariIni();
        $id = $this->minta('POST', '/api/v1/usulan-data-induk', $this->usulan([['objek_jenis' => 'formularium', 'aksi' => 'tambah', 'objek_id' => null,
            'nilai_baru' => ['kode' => 'FORNAS-2026', 'nama' => 'Fornas 2026', 'jenis' => 'fornas', 'penjamin_id' => null, 'berlaku_sampai' => null]]], $hariIni))
            ->assertCreated()->json('data.id');
        $this->setujuiOlehDuaOrang($id);
        $baru = Formularium::query()->where('kode', 'FORNAS-2026')->firstOrFail();
        $this->assertSame($hariIni, $baru->berlaku_dari);

        $this->masukSebagai($this->pengusul);
        $ubah = $this->minta('POST', '/api/v1/usulan-data-induk', $this->usulan([['objek_jenis' => 'formularium', 'aksi' => 'ubah', 'objek_id' => $baru->id,
            'nilai_baru' => ['nama' => 'Fornas 2026 revisi']]], $hariIni))->assertCreated();
        $this->assertSame(['Fornas 2026', 'Fornas 2026 revisi'], [$ubah->json('data.perubahan.0.tampilan.0.lama'), $ubah->json('data.perubahan.0.tampilan.0.baru')]);
        $this->setujuiOlehDuaOrang($ubah->json('data.id'));
        $this->assertSame('Fornas 2026 revisi', $baru->refresh()->nama);
    }

    public function test_validasi_butir_formularium(): void
    {
        $this->amoksisilin->forceFill(['status' => 'nonaktif'])->save();
        $this->masukSebagai($this->pengusul);
        $salah = $this->minta('POST', '/api/v1/usulan-data-induk', $this->usulan([
            ['objek_jenis' => 'formularium_item', 'aksi' => 'tambah', 'objek_id' => null,
                'nilai_baru' => ['formularium_id' => $this->formularium->id, 'barang_id' => $this->amoksisilin->id]],
            ['objek_jenis' => 'formularium', 'aksi' => 'tutup', 'objek_id' => $this->formularium->id, 'nilai_baru' => ['alasan' => 'Tidak dipakai']],
            ['objek_jenis' => 'tarif', 'aksi' => 'tutup', 'objek_id' => $this->formularium->id, 'nilai_baru' => ['alasan' => 'Salah jenis']],
        ]))->assertStatus(422)->assertJsonPath('error.kode', 'perubahan_tidak_valid');
        $this->assertSame(['perubahan.1.aksi', 'perubahan.2.objek_jenis'], collect($salah->json('error.rincian'))->pluck('kolom')->all());
        $this->assertSame(['aksi_tidak_dikenal', 'jenis_campur'], collect($salah->json('error.rincian'))->pluck('kode')->all());

        // Aturan 2.11 diperiksa uji kering: barang nonaktif.
        $kering = $this->minta('POST', '/api/v1/usulan-data-induk', $this->usulan([['objek_jenis' => 'formularium_item', 'aksi' => 'tambah', 'objek_id' => null,
            'nilai_baru' => ['formularium_id' => $this->formularium->id, 'barang_id' => $this->amoksisilin->id]]]))->assertStatus(422);
        $this->assertSame([['kolom' => 'perubahan.0.nilai_baru.barang_id', 'kode' => 'isian_tidak_valid', 'pesan' => 'Barang tidak ditemukan atau nonaktif.']],
            $kering->json('error.rincian'));
        $this->assertSame(1, ItemFormularium::query()->count());
    }

    /** @return list<string> */
    private function barangAktif(): array
    {
        return ItemFormularium::query()->where('formularium_id', $this->formularium->id)->where('status', 'aktif')->pluck('barang_id')->all();
    }

    private function setujuiOlehDuaOrang(string $id): void
    {
        $this->masukSebagai($this->pemverifikasi);
        $this->minta('POST', "/api/v1/usulan-data-induk/$id/verifikasi")->assertOk();
        $this->masukSebagai($this->penyetuju);
        $this->minta('POST', "/api/v1/usulan-data-induk/$id/setujui")->assertOk();
    }

    /**
     * @param  list<array<string, mixed>>  $perubahan
     * @return array<string, mixed>
     */
    private function usulan(array $perubahan, ?string $berlakuDari = null): array
    {
        return ['jenis_data' => 'formularium', 'perubahan' => $perubahan, 'berlaku_dari' => $berlakuDari ?? SuntinganBerversi::hariIni(),
            'alasan' => 'Penyesuaian formularium hasil rapat KFT', 'dasar' => self::DASAR];
    }
}
