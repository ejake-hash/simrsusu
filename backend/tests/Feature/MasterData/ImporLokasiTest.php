<?php

namespace Tests\Feature\MasterData;

use App\Modules\MasterData\Models\Bangsal;
use App\Modules\MasterData\Models\KelasRawat;
use App\Modules\MasterData\Models\UnitOrganisasi;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\AksesUji;
use Tests\Support\KlienSpa;
use Tests\Support\KontrakApi;
use Tests\TestCase;

/** Tiket 1.8b-be: POST /lokasi/impor (CSV, pratinjau lalu terapkan; kontrak v0.5.0). */
class ImporLokasiTest extends TestCase
{
    use AksesUji, KlienSpa, KontrakApi;

    private const JUDUL = 'jenis,kode,induk,nama,fungsi,unit,bangsal,kelas_rawat,kapasitas_maksimum,ekstra';

    protected function setUp(): void
    {
        parent::setUp();
        $unit = UnitOrganisasi::query()->create(['kode' => 'IRNA', 'nama' => 'Instalasi Rawat Inap', 'jenis' => 'instalasi', 'berlaku_dari' => '2026-01-01']);
        Bangsal::query()->create(['kode' => 'MELATI', 'nama' => 'Bangsal Melati', 'unit_organisasi_id' => $unit->id]);
        KelasRawat::query()->create(['kode' => 'III', 'nama' => 'Kelas III', 'berlaku_dari' => '2026-01-01']);
        $pengguna = $this->buatPengguna();
        $this->tugaskan($pengguna, $this->buatPeran(['master_data.organisasi']));
        $this->masukSebagai($pengguna);
    }

    public function test_tiga_gedung_delapan_lantai_tiga_puluh_ruang_empat_tt(): void
    {
        $baris = [self::JUDUL];
        foreach (['A', 'B', 'C'] as $g) {
            $baris[] = "gedung,$g,,Gedung $g,,IRNA,,,,";
            foreach (range(1, 8) as $l) {
                $baris[] = "lantai,$l,$g,Lantai $l,,,,,,";
                foreach (range(1, 30) as $r) {
                    $ruang = sprintf('%d%02d', $l, $r);
                    $baris[] = "ruang,$ruang,$g-$l,Ruang $ruang,rawat_inap,,MELATI,,4,";
                    foreach (range(1, 4) as $t) {
                        $baris[] = "tempat_tidur,$t,$g-$l-$ruang,,,,,III,,";
                    }
                }
            }
        }
        $csv = implode("\n", $baris)."\n";

        $pratinjau = $this->impor($csv, 'pratinjau')->assertOk()->assertJsonPath('data.diterapkan', false)->assertJsonPath('data.jumlah_salah', 0)
            ->assertJsonPath('data.jumlah_baris', 3627)->assertJsonPath('data.per_jenis.tempat_tidur', 2880)->assertJsonPath('data.per_jenis.kamar', 720);
        $this->assertSesuaiKontrak($pratinjau, 'POST', '/lokasi/impor');
        $this->assertSame(0, DB::table('inti.lokasi')->count());

        $mulai = microtime(true);
        $this->impor($csv, 'terapkan')->assertOk()->assertJsonPath('data.diterapkan', true);
        $this->assertLessThan(30, microtime(true) - $mulai);
        $this->assertSame(3627, DB::table('inti.lokasi')->count());
        $this->assertSame(2880, DB::table('inti.tempat_tidur')->count());
        $this->assertSame(720, DB::table('inti.kamar')->count());
        $this->assertTrue(DB::table('inti.tempat_tidur')->where('kode', 'C-8-830-4')->exists());
        // Unit kosong mengikuti induk sampai ke TT.
        $this->assertSame(0, DB::table('inti.lokasi')->whereNull('unit_organisasi_id')->count());
        $this->assertTrue(DB::table('audit.log_aktivitas')->where('aksi', 'master_data.lokasi_diimpor')->exists());
    }

    public function test_baris_salah_ditolak_per_baris_dan_tidak_ada_yang_disimpan(): void
    {
        $this->impor(self::JUDUL."\ngedung,B,,Gedung B,,,,,,\n", 'terapkan')->assertOk();
        $csv = implode("\n", [
            self::JUDUL,
            'lantai,1,B,Lantai 1,,,,,,',          // 2 benar
            'lantai,1,B,Lantai satu,,,,,,',       // 3 ganda dengan baris 2
            'gedung,B,,Gedung B lagi,,,,,,',      // 4 sudah dipakai
            'ruang,101,B-1,Ruang 101,,,,,,',       // 5 fungsi wajib
            'ruang,102,B-9,Ruang 102,rawat_inap,,,,,', // 6 induk tidak ada
            'ruang,103,X-2-1,Ruang X,igd,,,,,',     // 7 rujukan melingkar: induk di baris 8
            'lantai,1,X-2-1-103,Lantai X,,,,,,',    // 8 induk = baris 7 yang salah
            'ruang,104,B,Ruang 104,rawat_jalan,,,,,', // 9 jenis induk tidak sesuai
            'tempat_tidur,1,B-1-104,,,,,III,,',     // 10 induk tidak ditemukan (baris 9 salah)
            'kamar,1,B,Kamar,,,,,,',              // 11 jenis tidak dikenal
        ])."\n";

        $hasil = $this->impor($csv, 'pratinjau')->assertOk()->assertJsonPath('data.jumlah_baris', 10);
        $kode = collect($hasil->json('data.kesalahan'))->mapWithKeys(fn ($k) => [$k['baris'].':'.$k['kode'] => true]);
        foreach (['3:kode_ganda', '2:kode_ganda', '4:kode_sudah_dipakai', '5:fungsi_wajib', '6:induk_tidak_ditemukan', '7:induk_tidak_ditemukan',
            '8:induk_tidak_ditemukan', '9:jenis_induk_tidak_sesuai', '10:induk_tidak_ditemukan', '11:jenis_tidak_dikenal'] as $harap) {
            $this->assertTrue($kode->has($harap), "Kesalahan $harap tidak ada: ".json_encode($hasil->json('data.kesalahan')));
        }

        $ditolak = $this->impor($csv, 'terapkan')->assertStatus(422)->assertJsonPath('error.kode', 'impor_tidak_valid');
        $this->assertSesuaiKontrak($ditolak, 'POST', '/lokasi/impor');
        $this->assertSame(1, DB::table('inti.lokasi')->count());
    }

    public function test_berkas_tidak_valid(): void
    {
        $this->impor("jenis,kode\ngedung,B\n", 'pratinjau')->assertStatus(422)->assertJsonPath('error.kode', 'berkas_tidak_valid');
        $this->impor(self::JUDUL."\ngedung,B,,Gedung \xC3\x28,,,,,,\n", 'pratinjau')->assertStatus(422)->assertJsonPath('error.kode', 'berkas_tidak_valid');
        $this->impor(self::JUDUL."\n".str_repeat("gedung,B,,Gedung B,,,,,,\n", 5001), 'pratinjau')->assertStatus(422)->assertJsonPath('error.kode', 'berkas_tidak_valid');
        // Penanda BOM dari Excel diterima.
        $this->impor("\xEF\xBB\xBF".self::JUDUL."\ngedung,B,,Gedung B,,,,,,\n", 'pratinjau')->assertOk()->assertJsonPath('data.jumlah_salah', 0);
    }

    private function impor(string $csv, string $mode): TestResponse
    {
        return $this->minta('POST', '/api/v1/lokasi/impor', [
            'berkas' => UploadedFile::fake()->createWithContent('lokasi.csv', $csv), 'mode' => $mode, 'dasar_perubahan' => 'SK Direktur 5/2026',
        ]);
    }
}
