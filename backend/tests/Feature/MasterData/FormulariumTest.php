<?php

namespace Tests\Feature\MasterData;

use App\Modules\Akses\Models\Izin;
use App\Modules\Akses\Models\Menu;
use App\Modules\Akses\Models\PeranIzin;
use App\Modules\Akses\Models\PeranMenu;
use App\Modules\MasterData\Models\Barang;
use App\Modules\MasterData\Models\Penjamin;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\AksesUji;
use Tests\Support\KlienSpa;
use Tests\Support\KontrakApi;
use Tests\TestCase;

/** Tiket 2.11-be: katalog obat + formularium (kontrak v0.23.0). Izin farmasi.formularium.* cakupan `rs`. */
class FormulariumTest extends TestCase
{
    use AksesUji, KlienSpa, KontrakApi;

    private const DASAR = 'SK KFT 3/2026';

    private const JUDUL_BARANG = 'kode,nama,jenis,generik_paten,zat_aktif,kekuatan,sediaan,satuan_terkecil,kode_kfa,kewaspadaan_tinggi,narkotika,lasa';

    protected function setUp(): void
    {
        parent::setUp();
        $pengguna = $this->buatPengguna();
        $this->tugaskan($pengguna, $this->buatPeran(['farmasi.formularium']));
        $this->masukSebagai($pengguna);
    }

    public function test_generik_dan_paten_dua_kode_dan_kode_ganda_ditolak(): void
    {
        $generik = $this->barang('PCT-500', 'Parasetamol 500 mg', 'generik')->assertCreated();
        $this->assertSesuaiKontrak($generik, 'POST', '/data-induk/barang');
        $this->barang('PCT-500-P', 'Panadol 500 mg', 'paten')->assertCreated();
        $this->assertSame(2, Barang::query()->where('zat_aktif', 'parasetamol')->count());

        $ganda = $this->barang('PCT-500', 'Lain', 'generik')->assertUnprocessable()->assertJsonPath('error.kode', 'validasi_gagal');
        $this->assertSesuaiKontrak($ganda, 'POST', '/data-induk/barang');
        $this->minta('POST', '/api/v1/data-induk/barang', ['kode' => 'X1', 'nama' => 'Obat tanpa jenis generik', 'jenis' => 'obat',
            'satuan_terkecil' => 'tablet', 'dasar_perubahan' => self::DASAR])->assertUnprocessable();

        $id = $generik->json('data.id');
        $ubah = $this->mintaDenganVersi('PATCH', "/api/v1/data-induk/barang/$id", '0', ['kode_kfa' => '93000001', 'dasar_perubahan' => self::DASAR])
            ->assertOk()->assertJsonPath('data.kode_kfa', '93000001')->assertJsonPath('data.versi', '1');
        $this->assertSesuaiKontrak($ubah, 'PATCH', '/data-induk/barang/{id}');
        $daftar = $this->minta('GET', '/api/v1/data-induk/barang?tanpa_kfa=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.kode', 'PCT-500-P');
        $this->assertSesuaiKontrak($daftar, 'GET', '/data-induk/barang');
        $this->minta('GET', '/api/v1/data-induk/barang?cari=panadol')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_impor_barang_melaporkan_nomor_baris_dan_tidak_menyimpan_bila_ada_yang_salah(): void
    {
        $csv = implode("\n", [self::JUDUL_BARANG,
            'AMX-500,Amoksisilin 500 mg,obat,generik,amoksisilin,500 mg,kapsul,kapsul,,tidak,tidak,tidak',
            'KASA-1,Kasa steril,bmhp,,,,,,,,,',
            'ZZ-1,Barang aneh,mainan,,,,,buah,,,,',
            'AMX-500,Amoksisilin ganda,obat,generik,amoksisilin,500 mg,kapsul,kapsul,,,,',
        ])."\n";

        $pratinjau = $this->impor('/api/v1/data-induk/barang/impor', $csv, 'pratinjau')->assertOk()
            ->assertJsonPath('data.jumlah_baris', 4)->assertJsonPath('data.jumlah_salah', 3);
        $this->assertSesuaiKontrak($pratinjau, 'POST', '/data-induk/barang/impor');
        $kesalahan = collect($pratinjau->json('data.kesalahan'))->map(fn ($k) => $k['baris'].':'.$k['kode'])->all();
        $this->assertContains('3:kolom_wajib', $kesalahan);
        $this->assertContains('4:jenis_tidak_dikenal', $kesalahan);
        $this->assertContains('5:kode_ganda', $kesalahan);

        $ditolak = $this->impor('/api/v1/data-induk/barang/impor', $csv, 'terapkan')->assertUnprocessable()->assertJsonPath('error.kode', 'impor_tidak_valid');
        $this->assertSesuaiKontrak($ditolak, 'POST', '/data-induk/barang/impor');
        $this->assertSame(0, Barang::query()->count());

        $benar = implode("\n", [self::JUDUL_BARANG, 'AMX-500,Amoksisilin 500 mg,obat,generik,amoksisilin,500 mg,kapsul,kapsul,,ya,tidak,tidak'])."\n";
        $this->impor('/api/v1/data-induk/barang/impor', $benar, 'terapkan')->assertOk()->assertJsonPath('data.diterapkan', true);
        $this->assertTrue(Barang::query()->where('kode', 'AMX-500')->value('kewaspadaan_tinggi'));
        $this->assertSame(1, DB::table('audit.log_aktivitas')->where('aksi', 'master_data.barang_diimpor')->count());
    }

    public function test_formularium_item_dan_impor_item_sebelum_siap_melayani(): void
    {
        $barang = $this->barang('PCT-500', 'Parasetamol 500 mg', 'generik')->json('data.id');
        $this->barang('AMX-500', 'Amoksisilin 500 mg', 'generik');

        $f = $this->minta('POST', '/api/v1/data-induk/formularium', ['kode' => 'RS-2026', 'nama' => 'Formularium RS 2026', 'jenis' => 'rs',
            'berlaku_dari' => '2026-01-01', 'dasar_perubahan' => self::DASAR])->assertCreated()->assertJsonPath('data.jumlah_item_aktif', 0);
        $this->assertSesuaiKontrak($f, 'POST', '/data-induk/formularium');
        $fid = $f->json('data.id');
        $this->minta('POST', '/api/v1/data-induk/formularium', ['kode' => 'RS-2027', 'nama' => 'Tumpang tindih', 'jenis' => 'rs',
            'berlaku_dari' => '2026-06-01', 'dasar_perubahan' => self::DASAR])->assertUnprocessable()->assertJsonPath('error.rincian.0.kolom', 'berlaku_dari');
        $this->minta('POST', '/api/v1/data-induk/formularium', ['kode' => 'PJ-1', 'nama' => 'Penjamin tanpa id', 'jenis' => 'penjamin',
            'berlaku_dari' => '2026-01-01', 'dasar_perubahan' => self::DASAR])->assertUnprocessable();

        $item = $this->minta('POST', "/api/v1/data-induk/formularium/$fid/item", ['barang_id' => $barang, 'kronis' => true, 'dasar_perubahan' => self::DASAR])
            ->assertCreated()->assertJsonPath('data.barang.kode', 'PCT-500');
        $this->assertSesuaiKontrak($item, 'POST', '/data-induk/formularium/{id}/item');
        $this->minta('POST', "/api/v1/data-induk/formularium/$fid/item", ['barang_id' => $barang, 'dasar_perubahan' => self::DASAR])->assertUnprocessable();

        $csv = "kode_barang,restriksi,maks_per_resep,kronis\nAMX-500,Hanya infeksi bakteri,30,tidak\nPCT-500,,,\nTIDAK-ADA,,0,mungkin\n";
        $salah = collect($this->impor("/api/v1/data-induk/formularium/$fid/item/impor", $csv, 'pratinjau')->assertOk()->json('data.kesalahan'))->pluck('kode')->all();
        $this->assertEqualsCanonicalizing(['sudah_di_formularium', 'barang_tidak_ditemukan', 'nilai_tidak_valid', 'nilai_tidak_valid'], $salah);
        $this->impor("/api/v1/data-induk/formularium/$fid/item/impor", "kode_barang,restriksi,maks_per_resep,kronis\nAMX-500,Hanya infeksi bakteri,30,tidak\n", 'terapkan')->assertOk();

        $daftar = $this->minta('GET', "/api/v1/data-induk/formularium/$fid/item")->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.barang.kode', 'AMX-500')->assertJsonPath('data.0.maks_per_resep', 30);
        $this->assertSesuaiKontrak($daftar, 'GET', '/data-induk/formularium/{id}/item');
        $this->minta('GET', '/api/v1/data-induk/formularium?berlaku_pada=2026-03-01')->assertOk()->assertJsonPath('data.0.jumlah_item_aktif', 2);

        $itemId = $item->json('data.id');
        $nonaktif = $this->mintaDenganVersi('PATCH', "/api/v1/data-induk/formularium/$fid/item/$itemId", '0', ['status' => 'nonaktif', 'dasar_perubahan' => self::DASAR])
            ->assertOk()->assertJsonPath('data.status', 'nonaktif');
        $this->assertSesuaiKontrak($nonaktif, 'PATCH', '/data-induk/formularium/{id}/item/{itemId}');
        $this->mintaDenganVersi('PATCH', "/api/v1/data-induk/barang/$barang", '0', ['jenis' => 'bmhp', 'dasar_perubahan' => self::DASAR])
            ->assertUnprocessable()->assertJsonPath('error.rincian.0.kolom', 'jenis');
    }

    public function test_sesudah_siap_melayani_formularium_harus_lewat_usulan_barang_tetap_langsung(): void
    {
        $penjamin = Penjamin::query()->create(['kode' => 'ASR', 'nama' => 'Asuransi Contoh', 'jenis' => 'asuransi']);
        $f = $this->minta('POST', '/api/v1/data-induk/formularium', ['kode' => 'ASR-2026', 'nama' => 'Formularium Asuransi', 'jenis' => 'penjamin',
            'penjamin_id' => $penjamin->id, 'berlaku_dari' => '2026-01-01', 'dasar_perubahan' => self::DASAR])->assertCreated()->assertJsonPath('data.penjamin.kode', 'ASR');
        $this->tandaiInstalasiSiap();

        $tolak = $this->minta('POST', '/api/v1/data-induk/formularium', ['kode' => 'RS-2026', 'nama' => 'Formularium RS', 'jenis' => 'rs',
            'berlaku_dari' => '2026-01-01', 'dasar_perubahan' => self::DASAR])->assertStatus(409)->assertJsonPath('error.kode', 'harus_lewat_usulan');
        $this->assertSesuaiKontrak($tolak, 'POST', '/data-induk/formularium');
        $barang = $this->barang('PCT-500', 'Parasetamol 500 mg', 'generik')->assertCreated()->json('data.id');
        $this->minta('POST', '/api/v1/data-induk/formularium/'.$f->json('data.id').'/item', ['barang_id' => $barang, 'dasar_perubahan' => self::DASAR])
            ->assertStatus(409)->assertJsonPath('error.kode', 'harus_lewat_usulan');
        $this->mintaDenganVersi('PATCH', '/api/v1/data-induk/formularium/'.$f->json('data.id'), '0', ['nama' => 'Ubah', 'dasar_perubahan' => self::DASAR])
            ->assertStatus(409);
    }

    public function test_tanpa_izin_input_ditolak(): void
    {
        $barang = Barang::query()->create(['kode' => 'PCT-500', 'nama' => 'Parasetamol', 'jenis' => 'obat', 'generik_paten' => 'generik', 'satuan_terkecil' => 'tablet']);
        $pembaca = $this->buatPengguna();
        $peran = $this->buatPeran([]);
        PeranMenu::query()->create(['peran_id' => $peran->id, 'menu_id' => Menu::query()->where('kode', 'farmasi.formularium')->value('id')]);
        PeranIzin::query()->create(['peran_id' => $peran->id, 'izin_id' => Izin::query()->where('kode', 'farmasi.formularium.lihat')->value('id')]);
        $this->tugaskan($pembaca, $peran);
        $this->masukSebagai($pembaca);

        $this->minta('GET', '/api/v1/data-induk/barang')->assertOk();
        $this->minta('GET', "/api/v1/data-induk/barang/{$barang->id}")->assertOk();
        $tolak = $this->barang('AMX-500', 'Amoksisilin', 'generik')->assertForbidden()->assertJsonPath('error.kode', 'akses_ditolak');
        $this->assertSesuaiKontrak($tolak, 'POST', '/data-induk/barang');
        $this->mintaDenganVersi('PATCH', "/api/v1/data-induk/barang/{$barang->id}", '0', ['nama' => 'Ubah', 'dasar_perubahan' => self::DASAR])->assertForbidden();
        $this->impor('/api/v1/data-induk/barang/impor', self::JUDUL_BARANG."\n", 'pratinjau')->assertForbidden();
    }

    private function barang(string $kode, string $nama, string $generikPaten): TestResponse
    {
        return $this->minta('POST', '/api/v1/data-induk/barang', ['kode' => $kode, 'nama' => $nama, 'jenis' => 'obat', 'generik_paten' => $generikPaten,
            'zat_aktif' => str_contains($nama, 'Amoksisilin') ? 'amoksisilin' : 'parasetamol', 'kekuatan' => '500 mg', 'sediaan' => 'tablet',
            'satuan_terkecil' => 'tablet', 'dasar_perubahan' => self::DASAR]);
    }

    private function impor(string $uri, string $csv, string $mode): TestResponse
    {
        return $this->minta('POST', $uri, ['berkas' => UploadedFile::fake()->createWithContent('data.csv', $csv), 'mode' => $mode, 'dasar_perubahan' => self::DASAR]);
    }
}
