<?php

namespace Tests\Feature\MasterData;

use App\Modules\Akses\Domain\Cakupan;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\MasterData\Application\SuntinganBerversi;
use App\Modules\MasterData\Models\UnitOrganisasi;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\AksesUji;
use Tests\Support\KlienSpa;
use Tests\Support\KontrakApi;
use Tests\TestCase;

/** Tiket 1.8b-be: lokasi fisik, bangsal, kamar, TT, kelas rawat, poliklinik, kapasitas (kontrak v0.5.0). */
class LokasiRuangTempatTidurTest extends TestCase
{
    use AksesUji, KlienSpa, KontrakApi;

    private const DASAR = 'SK Direktur 4/2026';

    private UnitOrganisasi $unit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->unit = UnitOrganisasi::query()->create(['kode' => 'IRNA', 'nama' => 'Instalasi Rawat Inap', 'jenis' => 'instalasi', 'berlaku_dari' => '2026-01-01']);
        $this->masukSebagai($this->pengelolaData());
    }

    public function test_pohon_lokasi_dengan_kode_lengkap_jalur_dan_pindah_induk(): void
    {
        $gedung = $this->lokasi(null, 'gedung', 'B', 'Gedung B');
        $this->assertSesuaiKontrak($gedung, 'POST', '/lokasi');
        $lantai = $this->lokasi($gedung->json('data.id'), 'lantai', '3', 'Lantai 3');
        $ruang = $this->lokasi($lantai->json('data.id'), 'ruang', '304', 'Ruang 304', 'rawat_inap')
            ->assertJsonPath('data.kode_lengkap', 'B-3-304')->assertJsonPath('data.unit', null)
            ->assertJsonPath('data.jalur.0.kode_lengkap', 'B')->assertJsonPath('data.jalur.1.kode_lengkap', 'B-3');

        $this->assertSesuaiKontrak($this->minta('GET', '/api/v1/lokasi?akar=true')->assertOk()
            ->assertJsonPath('data.0.kode_lengkap', 'B')->assertJsonPath('data.0.jumlah_anak', 1), 'GET', '/lokasi');
        $this->minta('GET', '/api/v1/lokasi?cari=B-3-30')->assertOk()->assertJsonCount(1, 'data');

        // Pindah lantai ke gedung lain: kode_lengkap turunan ikut berubah.
        $gedungC = $this->lokasi(null, 'gedung', 'C', 'Gedung C');
        $pindah = $this->mintaDenganVersi('PATCH', '/api/v1/lokasi/'.$lantai->json('data.id'), $lantai->json('data.versi'), [
            'induk_id' => $gedungC->json('data.id'), 'dasar_perubahan' => self::DASAR,
        ])->assertOk()->assertJsonPath('data.kode_lengkap', 'C-3');
        $this->assertSesuaiKontrak($pindah, 'PATCH', '/lokasi/{id}');
        $this->minta('GET', '/api/v1/lokasi/'.$ruang->json('data.id'))->assertOk()->assertJsonPath('data.kode_lengkap', 'C-3-304');
    }

    public function test_aturan_jenis_kode_siklus_dan_versi(): void
    {
        $gedung = $this->lokasi(null, 'gedung', 'B', 'Gedung B');
        $lantai = $this->lokasi($gedung->json('data.id'), 'lantai', '3', 'Lantai 3');
        $buat = fn (?string $induk, string $jenis, string $kode, ?string $fungsi = null) => $this->minta('POST', '/api/v1/lokasi', [
            'induk_id' => $induk, 'jenis' => $jenis, 'kode' => $kode, 'nama' => 'Uji '.$kode, 'fungsi' => $fungsi, 'dasar_perubahan' => self::DASAR,
        ]);

        $buat(null, 'lantai', '1')->assertStatus(422)->assertJsonPath('error.kode', 'jenis_induk_tidak_sesuai');
        $buat($gedung->json('data.id'), 'ruang', '101', 'rawat_jalan')->assertStatus(422)->assertJsonPath('error.kode', 'jenis_induk_tidak_sesuai');
        $buat($lantai->json('data.id'), 'ruang', '301')->assertStatus(422)->assertJsonPath('error.kode', 'fungsi_wajib');
        $buat($gedung->json('data.id'), 'lantai', '4', 'gudang')->assertStatus(422)->assertJsonPath('error.kode', 'fungsi_hanya_ruang');
        $buat($lantai->json('data.id'), 'tempat_tidur', '1')->assertStatus(422)->assertJsonPath('error.kode', 'kelola_lewat_tempat_tidur');
        $this->assertSesuaiKontrak($buat($gedung->json('data.id'), 'lantai', '3')->assertStatus(409)->assertJsonPath('error.kode', 'kode_sudah_dipakai'), 'POST', '/lokasi');
        $buat(null, 'gedung', 'b-1')->assertStatus(422);

        $sayap = $this->lokasi($gedung->json('data.id'), 'sayap', 'U', 'Sayap utara');
        $patch = fn (TestResponse $r, array $isi, ?string $versi = null) => $this->mintaDenganVersi('PATCH', '/api/v1/lokasi/'.$r->json('data.id'),
            $versi ?? (string) $this->minta('GET', '/api/v1/lokasi/'.$r->json('data.id'))->json('data.versi'), [...$isi, 'dasar_perubahan' => self::DASAR]);
        $patch($gedung, ['induk_id' => $sayap->json('data.id')])->assertStatus(422)->assertJsonPath('error.kode', 'siklus');
        $patch($lantai, ['nama' => 'Lantai tiga'], '999')->assertStatus(412);
        $patch($lantai, ['status' => 'renovasi', 'status_berlaku_dari' => now()->addDays(3)->toDateString()])
            ->assertStatus(422)->assertJsonPath('error.kode', 'tanggal_tidak_valid');
        $patch($gedung, ['status' => 'ditutup'])->assertStatus(409)->assertJsonPath('error.kode', 'lokasi_masih_dipakai')
            ->assertJsonPath('error.rincian.0.jumlah_anak_aktif', 2);
    }

    public function test_bangsal_kamar_tt_dan_kode_tt_tidak_dipakai_ulang(): void
    {
        [$ruang, $bangsal, $kelas] = $this->ruangBangsalKelas();
        $kamar = $this->minta('POST', '/api/v1/kamar', [
            'bangsal_id' => $bangsal, 'lokasi_id' => $ruang, 'kapasitas_maksimum' => 2,
            'atribut_standar' => ['kamar_mandi_dalam' => true, 'outlet_oksigen_per_tt' => 1], 'dasar_perubahan' => self::DASAR,
        ])->assertCreated()->assertJsonPath('data.nomor', '304')->assertJsonPath('data.atribut_standar.kamar_mandi_dalam', true);
        $this->assertSesuaiKontrak($kamar, 'POST', '/kamar');
        $this->minta('POST', '/api/v1/kamar', ['bangsal_id' => $bangsal, 'lokasi_id' => $ruang, 'dasar_perubahan' => self::DASAR])
            ->assertStatus(409)->assertJsonPath('error.kode', 'ruang_sudah_kamar');
        $kamarId = $kamar->json('data.id');

        $tt = fn (string $kode) => $this->minta('POST', '/api/v1/tempat-tidur', ['kamar_id' => $kamarId, 'kode' => $kode, 'kelas_rawat_id' => $kelas, 'dasar_perubahan' => self::DASAR]);
        $satu = $tt('1')->assertCreated()->assertJsonPath('data.kode', 'B-3-304-1')->assertJsonPath('data.status_operasional', 'aktif')
            ->assertJsonPath('peringatan', []);
        $this->assertSesuaiKontrak($satu, 'POST', '/tempat-tidur');
        $tt('2')->assertCreated();
        $tt('3')->assertCreated()->assertJsonPath('peringatan', ['melebihi_kapasitas']);
        $this->minta('GET', "/api/v1/kamar/$kamarId")->assertOk()->assertJsonPath('data.melebihi_kapasitas', true)->assertJsonPath('data.jumlah_tempat_tidur_aktif', 3);
        $this->assertSesuaiKontrak($this->minta('GET', '/api/v1/kamar?melebihi_kapasitas=true')->assertOk()->assertJsonCount(1, 'data'), 'GET', '/kamar');

        // Node lokasi TT tidak bisa diubah lewat /lokasi; kode TT yang ditutup tidak dipakai ulang.
        $this->mintaDenganVersi('PATCH', '/api/v1/lokasi/'.$satu->json('data.lokasi_id'), '0', ['nama' => 'Ubah', 'dasar_perubahan' => self::DASAR])
            ->assertStatus(422)->assertJsonPath('error.kode', 'kelola_lewat_tempat_tidur');
        $ditutup = $this->mintaDenganVersi('POST', '/api/v1/tempat-tidur/'.$satu->json('data.id').'/tutup', $satu->json('data.versi'), [
            'berlaku_dari' => SuntinganBerversi::hariIni(), 'alasan' => 'Ruang dipersempit', 'dasar_perubahan' => self::DASAR,
        ])->assertOk()->assertJsonPath('data.status_operasional', 'ditutup');
        $this->assertSesuaiKontrak($ditutup, 'POST', '/tempat-tidur/{id}/tutup');
        $tt('1')->assertStatus(409)->assertJsonPath('error.kode', 'kode_sudah_dipakai');
        $this->minta('GET', '/api/v1/lokasi/'.$satu->json('data.lokasi_id'))->assertOk()->assertJsonPath('data.status', 'ditutup');
        $this->assertSesuaiKontrak($this->minta('GET', '/api/v1/tempat-tidur?status_operasional=aktif&lokasi_id='.$ruang)->assertOk()
            ->assertJsonCount(2, 'data'), 'GET', '/tempat-tidur');

        // Bangsal dan kelas yang masih punya TT tidak bisa dinonaktifkan.
        $this->mintaDenganVersi('PATCH', "/api/v1/bangsal/$bangsal", '0', ['status' => 'nonaktif', 'dasar_perubahan' => self::DASAR])
            ->assertStatus(409)->assertJsonPath('error.kode', 'bangsal_masih_dipakai')->assertJsonPath('error.rincian.0.jumlah_tempat_tidur', 2);
        $this->mintaDenganVersi('PATCH', "/api/v1/kelas-rawat/$kelas", '0', ['status' => 'nonaktif', 'dasar_perubahan' => self::DASAR])
            ->assertStatus(409)->assertJsonPath('error.kode', 'kelas_masih_dipakai');
        $this->assertSesuaiKontrak($this->minta('GET', "/api/v1/bangsal/$bangsal")->assertOk()
            ->assertJsonPath('data.jumlah_kamar', 1)->assertJsonPath('data.ringkasan_tt.0.tersedia', 2)
            ->assertJsonPath('data.ringkasan_tt.0.ditutup', 1), 'GET', '/bangsal/{id}');
    }

    public function test_tutup_tt_besok_hari_ini_masih_dihitung_dan_rekaman_hari_lewat_tetap(): void
    {
        [$ruang, $bangsal, $kelas] = $this->ruangBangsalKelas();
        $kamar = $this->minta('POST', '/api/v1/kamar', ['bangsal_id' => $bangsal, 'lokasi_id' => $ruang, 'dasar_perubahan' => self::DASAR])->json('data.id');
        $ekstra = $this->minta('POST', '/api/v1/tempat-tidur', ['kamar_id' => $kamar, 'kode' => '9', 'kelas_rawat_id' => $kelas, 'ekstra' => true, 'dasar_perubahan' => self::DASAR])
            ->assertCreated();
        $tt = $this->minta('POST', '/api/v1/tempat-tidur', ['kamar_id' => $kamar, 'kode' => '1', 'kelas_rawat_id' => $kelas, 'dasar_perubahan' => self::DASAR]);
        $this->artisan('master-data:rekam-kapasitas-tt')->assertSuccessful();
        $hariIni = SuntinganBerversi::hariIni();
        $besok = now(config('instalasi.zona_waktu'))->addDay()->toDateString();

        $tutup = fn (string $tanggal) => $this->mintaDenganVersi('POST', '/api/v1/tempat-tidur/'.$tt->json('data.id').'/tutup',
            (string) $this->minta('GET', '/api/v1/tempat-tidur/'.$tt->json('data.id'))->json('data.versi'),
            ['berlaku_dari' => $tanggal, 'alasan' => 'Renovasi permanen', 'dasar_perubahan' => self::DASAR]);
        $tutup(now(config('instalasi.zona_waktu'))->subDay()->toDateString())->assertStatus(422)->assertJsonPath('error.kode', 'tanggal_tidak_valid');
        $tutup($besok)->assertOk()->assertJsonPath('data.status_operasional', 'akan_ditutup')->assertJsonPath('data.berlaku_sampai', $hariIni);
        $tutup($besok)->assertStatus(409)->assertJsonPath('error.kode', 'sudah_ditutup');

        $kapasitas = fn (string $tanggal) => $this->minta('GET', "/api/v1/kapasitas-tempat-tidur?tanggal=$tanggal")->assertOk();
        $this->assertSesuaiKontrak($kapasitas($hariIni)->assertJsonPath('data.0.kelas.0.jumlah_tersedia', 1)
            ->assertJsonPath('data.0.kelas.0.jumlah_ekstra', 1)->assertJsonPath('data.0.sumber', 'dihitung'), 'GET', '/kapasitas-tempat-tidur');
        $kapasitas($besok)->assertJsonPath('data.0.kelas.0.jumlah_tersedia', 0)->assertJsonPath('data.0.kelas.0.jumlah_ekstra', 1);

        // Besoknya: TT ekstra juga ditutup; angka kemarin dari rekaman tidak berubah.
        $this->travel(1)->days();
        $this->masukSebagai($this->pengelolaData());
        $this->mintaDenganVersi('POST', '/api/v1/tempat-tidur/'.$ekstra->json('data.id').'/tutup', $ekstra->json('data.versi'), [
            'berlaku_dari' => $besok, 'alasan' => 'Lonjakan selesai', 'dasar_perubahan' => self::DASAR,
        ])->assertOk();
        $kapasitas($hariIni)->assertJsonPath('data.0.sumber', 'tersimpan')->assertJsonPath('data.0.kelas.0.jumlah_tersedia', 1)
            ->assertJsonPath('data.0.kelas.0.jumlah_ekstra', 1);
        $kapasitas($besok)->assertJsonPath('data.0.kelas', []);
    }

    public function test_kelas_rawat_dan_poliklinik(): void
    {
        $kelas = $this->minta('POST', '/api/v1/kelas-rawat', ['kode' => 'KRIS', 'nama' => 'KRIS', 'dasar_perubahan' => self::DASAR])
            ->assertCreated()->assertJsonPath('data.kode_aplicares', null);
        $this->assertSesuaiKontrak($kelas, 'POST', '/kelas-rawat');
        $this->minta('POST', '/api/v1/kelas-rawat', ['kode' => 'KRIS', 'nama' => 'Lagi', 'dasar_perubahan' => self::DASAR])->assertStatus(409);
        $this->assertSesuaiKontrak($this->minta('GET', '/api/v1/kelas-rawat')->assertOk()->assertJsonCount(1, 'data'), 'GET', '/kelas-rawat');

        $gedung = $this->lokasi(null, 'gedung', 'A', 'Gedung A');
        $lantai = $this->lokasi($gedung->json('data.id'), 'lantai', '1', 'Lantai 1');
        $ruangPoli = $this->lokasi($lantai->json('data.id'), 'ruang', '101', 'Ruang 101', 'rawat_jalan');
        $isi = ['kode' => 'PDL', 'nama' => 'Poli Penyakit Dalam', 'unit_organisasi_id' => $this->unit->id, 'dasar_perubahan' => self::DASAR];
        $this->minta('POST', '/api/v1/poliklinik', [...$isi, 'lokasi_id' => $lantai->json('data.id')])->assertStatus(422)->assertJsonPath('error.kode', 'bukan_ruang');
        $poli = $this->minta('POST', '/api/v1/poliklinik', [...$isi, 'lokasi_id' => $ruangPoli->json('data.id')])->assertCreated()
            ->assertJsonPath('data.lokasi.kode_lengkap', 'A-1-101')->assertJsonPath('data.unit.kode', 'IRNA');
        $this->assertSesuaiKontrak($poli, 'POST', '/poliklinik');

        // Ruang yang dipakai poliklinik aktif tidak bisa ditutup.
        $this->mintaDenganVersi('PATCH', '/api/v1/lokasi/'.$ruangPoli->json('data.id'), $ruangPoli->json('data.versi'), [
            'status' => 'ditutup', 'dasar_perubahan' => self::DASAR,
        ])->assertStatus(409)->assertJsonPath('error.rincian.0.jumlah_poliklinik', 1);
        $this->assertSesuaiKontrak($this->mintaDenganVersi('PATCH', '/api/v1/poliklinik/'.$poli->json('data.id'), $poli->json('data.versi'), [
            'status' => 'nonaktif', 'dasar_perubahan' => self::DASAR,
        ])->assertOk()->assertJsonPath('data.status', 'nonaktif'), 'PATCH', '/poliklinik/{id}');
    }

    public function test_hanya_pemegang_izin_bercakupan_rs(): void
    {
        $this->masukSebagai($this->buatPengguna());
        $this->minta('GET', '/api/v1/lokasi')->assertForbidden();

        $unitSaja = $this->buatPengguna();
        $this->tugaskan($unitSaja, $this->buatPeran(['master_data.organisasi']), cakupan: new Cakupan('unit', $this->unit->id));
        $this->masukSebagai($unitSaja);
        foreach (['/api/v1/lokasi', '/api/v1/bangsal', '/api/v1/tempat-tidur', '/api/v1/kapasitas-tempat-tidur'] as $uri) {
            $this->minta('GET', $uri)->assertForbidden();
        }
        $this->minta('POST', '/api/v1/kelas-rawat', ['kode' => 'III', 'nama' => 'Kelas III', 'dasar_perubahan' => self::DASAR])->assertForbidden();
        $this->assertSame('Organisasi, Unit, Ruang & Tempat Tidur', DB::table('akses.menu')->where('kode', 'master_data.organisasi')->value('label'));
    }

    /** @return array{0: string, 1: string, 2: string} ruang B-3-304, bangsal, kelas */
    private function ruangBangsalKelas(): array
    {
        $gedung = $this->lokasi(null, 'gedung', 'B', 'Gedung B');
        $lantai = $this->lokasi($gedung->json('data.id'), 'lantai', '3', 'Lantai 3');
        $ruang = $this->lokasi($lantai->json('data.id'), 'ruang', '304', 'Ruang 304', 'rawat_inap');
        $bangsal = $this->minta('POST', '/api/v1/bangsal', ['kode' => 'MELATI', 'nama' => 'Bangsal Melati', 'unit_organisasi_id' => $this->unit->id, 'dasar_perubahan' => self::DASAR])
            ->assertCreated();
        $this->assertSesuaiKontrak($bangsal, 'POST', '/bangsal');
        $kelas = $this->minta('POST', '/api/v1/kelas-rawat', ['kode' => 'III', 'nama' => 'Kelas III', 'dasar_perubahan' => self::DASAR])->assertCreated();

        return [$ruang->json('data.id'), $bangsal->json('data.id'), $kelas->json('data.id')];
    }

    private function lokasi(?string $induk, string $jenis, string $kode, string $nama, ?string $fungsi = null): TestResponse
    {
        return $this->minta('POST', '/api/v1/lokasi', [
            'induk_id' => $induk, 'jenis' => $jenis, 'kode' => $kode, 'nama' => $nama, 'fungsi' => $fungsi, 'dasar_perubahan' => self::DASAR,
        ])->assertCreated();
    }

    private function pengelolaData(): Pengguna
    {
        $pengguna = $this->buatPengguna();
        $this->tugaskan($pengguna, $this->buatPeran(['master_data.organisasi']));

        return $pengguna;
    }
}
