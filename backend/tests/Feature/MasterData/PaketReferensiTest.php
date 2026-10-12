<?php

namespace Tests\Feature\MasterData;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\AksesUji;
use Tests\Support\KlienSpa;
use Tests\Support\KontrakApi;
use Tests\TestCase;

/**
 * Tiket 1.8e-be: pemuat paket referensi (kontrak v0.11.0). Data uji sintetis (bukan berkas resmi): kode wilayah dan
 * terminologi di sini hanya contoh bentuk.
 */
class PaketReferensiTest extends TestCase
{
    use AksesUji, KlienSpa, KontrakApi;

    private const WILAYAH_V1 = "kode,nama\n99,Provinsi Uji\n99.01,Kabupaten Uji\n99.01.01,Kecamatan Uji\n99.01.01.2001,Desa Satu\n99.01.01.2002,Desa Dua\n";

    protected function setUp(): void
    {
        parent::setUp();
        $admin = $this->buatPengguna();
        $this->tugaskan($admin, $this->buatPeran(['master_data.katalog']));
        $this->masukSebagai($admin);
    }

    public function test_wilayah_pratinjau_terapkan_idempoten_dan_versi_baru_menonaktifkan_kode_hilang(): void
    {
        $pratinjau = $this->muat('wilayah', self::WILAYAH_V1, 'pratinjau', '2025.1')->assertOk()
            ->assertJsonPath('data.diterapkan', false)->assertJsonPath('data.jumlah_baru', 5);
        $this->assertSesuaiKontrak($pratinjau, 'POST', '/paket-referensi/{sistem}/muat');
        $this->assertSame(0, DB::table('inti.wilayah')->count());

        $this->muat('wilayah', self::WILAYAH_V1, 'terapkan', '2025.1')->assertOk()->assertJsonPath('data.diterapkan', true);
        $desa = DB::table('inti.wilayah')->where('kode_kemendagri', '99.01.01.2001')->first();
        $kecamatan = DB::table('inti.wilayah')->where('kode_kemendagri', '99.01.01')->value('id');
        $this->assertSame(['kelurahan', $kecamatan, true], [$desa->tingkat, $desa->induk_id, $desa->aktif]);

        // Versi sama, isi sama: idempoten (tidak ada perubahan, tidak ada baris paket kedua).
        $this->muat('wilayah', self::WILAYAH_V1, 'terapkan', '2025.1')->assertOk()->assertJsonPath('data.jumlah_baru', 0)->assertJsonPath('data.jumlah_diubah', 0);
        $this->assertSame(1, DB::table('inti.paket_referensi')->where('sistem', 'wilayah')->count());
        $this->muat('wilayah', self::WILAYAH_V1, 'pratinjau', '2025.1')->assertOk()->assertJsonPath('data.diterapkan', false)
            ->assertJsonPath('data.jumlah_baru', 0)->assertJsonPath('data.jumlah_diubah', 0)->assertJsonPath('data.jumlah_dinonaktifkan', 0);
        $this->assertSame(1, DB::table('audit.log_aktivitas')->where('aksi', 'master_data.referensi_dimuat')->count());
        // Versi sama, isi lain: ditolak.
        $this->muat('wilayah', str_replace('Desa Dua', 'Desa Dua Baru', self::WILAYAH_V1), 'terapkan', '2025.1')
            ->assertStatus(409)->assertJsonPath('error.kode', 'versi_sudah_dimuat');
        $this->muat('wilayah', str_replace('Desa Dua', 'Desa Dua Baru', self::WILAYAH_V1), 'pratinjau', '2025.1')
            ->assertStatus(409)->assertJsonPath('error.kode', 'versi_sudah_dimuat');
        $paketId = DB::table('inti.paket_referensi')->where('sistem', 'wilayah')->value('id');
        $this->assertSame($paketId, DB::table('audit.log_aktivitas')->where('aksi', 'master_data.referensi_dimuat')->value('subjek_id'));

        // Versi baru: Desa Dua hilang (dinonaktifkan, tidak dihapus), Desa Satu berganti nama, Desa Tiga baru.
        $v2 = "kode,nama\n99,Provinsi Uji\n99.01,Kabupaten Uji\n99.01.01,Kecamatan Uji\n99.01.01.2001,Desa Satu Raya\n99.01.01.2003,Desa Tiga\n";
        $this->muat('wilayah', $v2, 'terapkan', '2025.2')->assertOk()->assertJsonPath('data.jumlah_baru', 1)
            ->assertJsonPath('data.jumlah_diubah', 1)->assertJsonPath('data.jumlah_dinonaktifkan', 1);
        $dua = DB::table('inti.wilayah')->where('kode_kemendagri', '99.01.01.2002')->first();
        $this->assertFalse((bool) $dua->aktif);
        $this->assertNotNull($dua->dinonaktifkan_pada);
        $this->assertSame('2025.1', $dua->versi_terakhir);
        // Versi lama yang sudah digantikan tidak bisa dimuat ulang, walau isinya sama.
        $this->muat('wilayah', self::WILAYAH_V1, 'pratinjau', '2025.1')->assertStatus(409)->assertJsonPath('error.kode', 'versi_sudah_dimuat');
        // Muncul lagi di versi berikutnya: diaktifkan kembali.
        $this->muat('wilayah', self::WILAYAH_V1, 'terapkan', '2025.3')->assertOk()->assertJsonPath('data.jumlah_diaktifkan_lagi', 1);
        $this->assertTrue((bool) DB::table('inti.wilayah')->where('kode_kemendagri', '99.01.01.2002')->value('aktif'));
        $this->assertSame(3, DB::table('audit.log_aktivitas')->where('aksi', 'master_data.referensi_dimuat')->count());
    }

    public function test_berkas_rusak_ditolak_dengan_nomor_baris(): void
    {
        $csv = "kode,nama\n99,Provinsi Uji\n99.1,Salah Bentuk\n99.01,\n99.02.01,Tanpa Induk\n99,Ganda\n";
        $hasil = $this->muat('wilayah', $csv, 'pratinjau', 'x1')->assertOk();
        $this->assertSame([[3, 'kode_tidak_valid'], [4, 'kolom_wajib'], [5, 'induk_tidak_ditemukan'], [6, 'kode_ganda']],
            array_map(fn ($k) => [$k['baris'], $k['kode']], $hasil->json('data.kesalahan')));
        $tolak = $this->muat('wilayah', $csv, 'terapkan', 'x1')->assertStatus(422)->assertJsonPath('error.kode', 'muat_tidak_valid')
            ->assertJsonPath('error.rincian.0.baris', 3);
        $this->assertSesuaiKontrak($tolak, 'POST', '/paket-referensi/{sistem}/muat');
        $this->assertSame(0, DB::table('inti.wilayah')->count());
        $this->muat('wilayah', "kode;nama\n", 'pratinjau', 'x1')->assertStatus(422)->assertJsonPath('error.kode', 'berkas_tidak_valid');
        $this->muat('wilayah', "kd,nm\n11,A\n", 'pratinjau', 'x1')->assertStatus(422)->assertJsonPath('error.kode', 'berkas_tidak_valid');
        $this->muat('wilayah', "kode,nama\n99,\xFF\xFE\n", 'pratinjau', 'x1')->assertStatus(422)->assertJsonPath('error.kode', 'berkas_tidak_valid');
    }

    public function test_terminologi_dan_referensi_per_kelompok_lewat_konsol(): void
    {
        $berkas = tempnam(sys_get_temp_dir(), 'ref');
        file_put_contents($berkas, "\xEF\xBB\xBFkelompok;kode;label\nuji_agama;1;Satu\nuji_agama;2;Dua\nuji_kawin;A;Belum kawin\n");
        $this->artisan('referensi:muat', ['sistem' => 'referensi', 'berkas' => $berkas, '--versi' => 'r1', '--sumber' => 'Berkas uji sintetis'])->assertSuccessful();
        // Versi baru hanya memuat kelompok uji_agama: kelompok lain tidak disentuh.
        file_put_contents($berkas, "kelompok,kode,label\nuji_agama,1,Satu\n");
        $this->artisan('referensi:muat', ['sistem' => 'referensi', 'berkas' => $berkas, '--versi' => 'r2', '--sumber' => 'Berkas uji sintetis'])->assertSuccessful();
        $this->assertFalse((bool) DB::table('inti.referensi')->where('kelompok', 'uji_agama')->where('kode', '2')->value('aktif'));
        $this->assertTrue((bool) DB::table('inti.referensi')->where('kelompok', 'uji_kawin')->where('kode', 'A')->value('aktif'));
        // Aturan isian paket sama dengan endpoint: LOINC wajib atribusi, versi paling panjang 50.
        file_put_contents($berkas, "kode,tampilan,kode_induk\n1-8,Uji,\n");
        $this->artisan('referensi:muat', ['sistem' => 'loinc', 'berkas' => $berkas, '--versi' => 'l1', '--sumber' => 'Berkas uji sintetis'])->assertFailed();
        $this->artisan('referensi:muat', ['sistem' => 'ucum', 'berkas' => $berkas, '--versi' => str_repeat('v', 51), '--sumber' => 'Berkas uji sintetis'])->assertFailed();
        $this->assertFalse(DB::table('inti.paket_referensi')->whereIn('sistem', ['loinc', 'ucum'])->exists());
        unlink($berkas);
        $this->muat('loinc', "kode,tampilan,kode_induk\n1-8,Uji,\n", 'pratinjau', 'l1')->assertStatus(422)
            ->assertJsonPath('error.kode', 'validasi_gagal')->assertJsonPath('error.rincian.0.kolom', 'catatan_lisensi');

        $this->muat('ucum', "kode,tampilan,kode_induk\nmg,miligram,\nmL,mililiter,\n", 'terapkan', 'u1', 'Berkas uji sintetis', 'Contoh atribusi')->assertOk();
        $this->assertSame(2, DB::table('inti.terminologi')->where('sistem', 'ucum')->count());
        $this->assertSame('Contoh atribusi', DB::table('inti.paket_referensi')->where('sistem', 'ucum')->value('catatan_lisensi'));
        $this->muat('icd10', "kode,tampilan,kode_induk\nU99,Uji anak,U98\n", 'pratinjau', 'i1')->assertOk()
            ->assertJsonPath('data.kesalahan.0.kode', 'induk_tidak_ditemukan');
    }

    public function test_wilayah_90_ribu_baris_di_bawah_30_detik_dan_64_mb(): void
    {
        // Suite penuh berjalan dalam satu proses: yang diukur kenaikan puncak memori selama pemuatan (anggaran 64 MB, agar
        // satu permintaan tetap di bawah memory_limit PHP bawaan 128 MB), bukan memori proses; batas proses tidak dikembalikan
        // karena proses suite bisa sudah melewati 128 MB.
        ini_set('memory_limit', '1G');
        $csv = "kode,nama\n99,Provinsi Uji\n";
        for ($k = 1; $k <= 10; $k++) {
            $kab = sprintf('99.%02d', $k);
            $csv .= "$kab,Kabupaten $k\n";
            for ($c = 1; $c <= 30; $c++) {
                $kec = sprintf('%s.%02d', $kab, $c);
                $csv .= "$kec,Kecamatan $k-$c\n";
                for ($d = 1; $d <= 300; $d++) {
                    $csv .= sprintf("%s.%04d,Desa %d-%d-%d\n", $kec, 2000 + $d, $k, $c, $d);
                }
            }
        }
        $mulai = microtime(true);
        memory_reset_peak_usage();
        $awal = memory_get_usage();
        $this->muat('wilayah', $csv, 'terapkan', 'beban')->assertOk()->assertJsonPath('data.jumlah_baru', 90311);
        $detik = microtime(true) - $mulai;
        $this->assertLessThan(30, $detik, "Memuat 90.311 baris: {$detik} detik");
        $this->assertLessThan(64 << 20, memory_get_peak_usage() - $awal, 'Memori memuat 90.311 baris: '.((memory_get_peak_usage() - $awal) >> 20).' MB');
        $this->assertSame(90000, DB::table('inti.wilayah')->where('tingkat', 'kelurahan')->whereNotNull('induk_id')->count());

        // Versi berikutnya di atas 90 ribu kode yang sudah ada (jalur pembanding).
        $v2 = str_replace("99.10.30.2300,Desa 10-30-300\n", '', str_replace(",Desa 1-1-1\n", ",Desa 1-1-1 Baru\n", $csv));
        $mulai = microtime(true);
        memory_reset_peak_usage();
        $awal = memory_get_usage();
        $this->muat('wilayah', $v2, 'terapkan', 'beban2')->assertOk()->assertJsonPath('data.jumlah_baru', 0)
            ->assertJsonPath('data.jumlah_diubah', 1)->assertJsonPath('data.jumlah_dinonaktifkan', 1);
        $detik = microtime(true) - $mulai;
        $this->assertLessThan(30, $detik, "Memuat versi kedua: {$detik} detik");
        $this->assertLessThan(64 << 20, memory_get_peak_usage() - $awal, 'Memori memuat versi kedua: '.((memory_get_peak_usage() - $awal) >> 20).' MB');
    }

    public function test_hanya_pemegang_izin_katalog_input(): void
    {
        $this->masukSebagai($this->buatPengguna());
        $this->muat('wilayah', self::WILAYAH_V1, 'pratinjau', '2025.1')->assertForbidden();
    }

    private function muat(string $sistem, string $isi, string $mode, string $versi, string $sumber = 'Berkas uji sintetis', ?string $lisensi = null): TestResponse
    {
        return $this->minta('POST', "/api/v1/paket-referensi/$sistem/muat", [
            'berkas' => UploadedFile::fake()->createWithContent('paket.csv', $isi), 'mode' => $mode, 'versi' => $versi,
            'sumber' => $sumber, 'catatan_lisensi' => $lisensi,
        ]);
    }
}
