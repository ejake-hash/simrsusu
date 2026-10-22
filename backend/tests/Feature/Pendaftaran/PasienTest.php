<?php

namespace Tests\Feature\Pendaftaran;

use App\Modules\Akses\Domain\Totp;
use App\Modules\Akses\Models\Izin;
use App\Modules\Akses\Models\Menu;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\Akses\Models\PeranIzin;
use App\Modules\Akses\Models\PeranMenu;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\AksesUji;
use Tests\Support\KlienSpa;
use Tests\Support\KontrakApi;
use Tests\TestCase;

/** Tiket 2.5-be: cari pasien satu kolom, pasien baru, perbaikan identitas (kontrak v0.19.0). Data sintetis saja. */
class PasienTest extends TestCase
{
    use AksesUji, KlienSpa, KontrakApi;

    // Kode provinsi 99 tidak ada: NIK dan No. kartu sintetis, tidak valid secara nasional (FR-54-10).
    private const NIK = '9901015501900042';

    private const NIK_2 = '9901016601850007';

    private const KARTU = '0009900000001';

    private const ALASAN = 'Salah ketik saat daftar';

    private const KELURAHAN = '99.01.01.2001';

    protected function setUp(): void
    {
        parent::setUp();
        $versi = ['aktif' => true, 'versi_terakhir' => 'uji'];
        $induk = null;
        foreach ([['99', 'provinsi', 'Provinsi Contoh'], ['99.01', 'kabupaten', 'Kota Contoh'], ['99.01.01', 'kecamatan', 'Kecamatan Contoh'],
            [self::KELURAHAN, 'kelurahan', 'Kelurahan Contoh']] as [$kode, $tingkat, $nama]) {
            $id = (string) Str::uuid7();
            DB::table('inti.wilayah')->insert(['id' => $id, 'kode_kemendagri' => $kode, 'tingkat' => $tingkat, 'nama' => $nama, 'induk_id' => $induk, ...$versi]);
            $induk = $id;
        }
        DB::table('inti.referensi')->insert(['id' => (string) Str::uuid7(), 'kelompok' => 'agama', 'kode' => 'uji_1', 'label' => 'Agama Uji', ...$versi]);
    }

    public function test_pasien_baru_mendapat_no_rm_berurutan_dan_nik_ganda_ditolak_dengan_rujukan(): void
    {
        $this->masukSebagai($this->petugas());
        $pertama = $this->daftar([
            'nama' => 'Contoh Pasien Satu', 'nik' => self::NIK, 'no_kartu_bpjs' => self::KARTU, 'tanggal_lahir' => '1980-03-12',
            'tempat_lahir' => 'Kota Contoh', 'agama' => 'uji_1', 'telepon' => '081200000001',
            'alamat' => ['jalan' => 'Jl. Contoh No. 1', 'rt' => '001', 'rw' => '002', 'kode_pos' => '99111', 'wilayah_kode' => self::KELURAHAN],
            'kontak_darurat' => [['nama' => 'Kerabat Contoh', 'hubungan' => 'saudara', 'telepon' => '081200000002']],
        ])->assertCreated()
            ->assertJsonPath('data.nik_samaran', '9901 •••• •••• 0042')->assertJsonMissingPath('data.nik')
            ->assertJsonPath('data.agama', ['kode' => 'uji_1', 'label' => 'Agama Uji'])
            ->assertJsonPath('data.alamat.wilayah.kabupaten_kota', 'Kota Contoh')->assertJsonPath('data.kewarganegaraan', 'wni')
            ->assertJsonPath('data.kontak_darurat.0.telepon', '081200000002')->assertJsonPath('data.jumlah_kunjungan', 0);
        $this->assertStringContainsString('no-store', (string) $pertama->headers->get('Cache-Control'));
        $noRm = $pertama->json('data.no_rm');
        $this->assertSame(8, strlen($noRm));

        // NIK dan No. kartu yang sama: 409 dengan rujukan No. RM di rincian, tidak di pesan; nomor tidak terpakai.
        $ganda = $this->daftar(['nama' => 'Ganda', 'nik' => self::NIK, 'tanggal_lahir' => '1990-01-01'])
            ->assertStatus(409)->assertJsonPath('error.kode', 'nik_sudah_terdaftar')
            ->assertJsonPath('error.rincian.0', ['pasien_id' => $pertama->json('data.id'), 'no_rm' => $noRm]);
        $this->assertSesuaiKontrak($ganda, 'POST', '/pasien');
        $this->assertStringNotContainsString($noRm, (string) $ganda->json('error.pesan'));
        $this->daftar(['nama' => 'Ganda', 'no_kartu_bpjs' => self::KARTU, 'tanggal_lahir' => '1990-01-01'])
            ->assertStatus(409)->assertJsonPath('error.kode', 'no_kartu_sudah_terdaftar');

        // Bayi tanpa NIK tidak diperiksa; nomor berikutnya tanpa celah.
        $bayi = $this->daftar(['nama' => 'Bayi Contoh', 'tanggal_lahir' => '2026-10-20', 'jenis_kelamin' => 'perempuan'])->assertCreated();
        $this->assertSame((int) $noRm + 1, (int) $bayi->json('data.no_rm'));

        // Tersimpan terenkripsi; audit tanpa nilai identitas; No. RM tidak bisa diubah.
        $this->assertNotSame(self::NIK, DB::table('pasien.identitas')->where('jenis', 'nik')->value('nilai_enkripsi'));
        $audit = $this->assertAuditTerakhir('pasien.dibuat', $pertama->json('data.id'));
        $this->assertStringNotContainsString(self::NIK, (string) $audit->sesudah);
        $this->assertStringNotContainsString(self::KARTU, (string) $audit->sesudah);
        $this->assertContains('nik', json_decode((string) $audit->sesudah, true)['field']);
        $this->expectExceptionMessage('No. RM tidak bisa diubah');
        DB::table('pasien.pasien')->where('id', $pertama->json('data.id'))->update(['no_rm' => '99999999']);
    }

    public function test_pasien_tanpa_identitas_dilengkapi_kemudian_dengan_jejak_versi(): void
    {
        $this->masukSebagai($this->petugas());
        $mrx = $this->daftar(['tanpa_identitas' => true, 'jenis_kelamin' => 'laki_laki', 'nama' => null, 'tanggal_lahir' => null,
            'ciri_pengenal' => 'Ditemukan di halte, kemeja biru'])->assertCreated()
            ->assertJsonPath('data.nama', 'Mr. X')->assertJsonPath('data.tanpa_identitas', true)->assertJsonPath('data.tanggal_lahir', null);
        $id = $mrx->json('data.id');
        $this->daftar(['tanpa_identitas' => true, 'jenis_kelamin' => 'tidak_diketahui'])->assertCreated()->assertJsonPath('data.nama', 'X');
        $this->daftar(['nama' => 'Tanpa Tanggal', 'jenis_kelamin' => 'perempuan'])->assertUnprocessable()->assertJsonPath('error.rincian.0.kolom', 'tanggal_lahir');

        $this->mintaDenganVersi('PATCH', "/api/v1/pasien/$id", '0', ['tanpa_identitas' => false, 'tanggal_lahir' => '1975-05-05', 'alasan_perubahan' => self::ALASAN])
            ->assertUnprocessable()->assertJsonPath('error.rincian.0.kolom', 'nama');
        $this->minta('PATCH', "/api/v1/pasien/$id", ['nama' => 'Contoh', 'alasan_perubahan' => self::ALASAN])->assertStatus(428);
        $this->mintaDenganVersi('PATCH', "/api/v1/pasien/$id", '7', ['nama' => 'Contoh', 'alasan_perubahan' => self::ALASAN])->assertStatus(412);

        $lengkap = $this->mintaDenganVersi('PATCH', "/api/v1/pasien/$id", '0', [
            'tanpa_identitas' => false, 'nama' => 'Contoh Pasien Dua', 'tanggal_lahir' => '1975-05-05', 'nik' => self::NIK_2, 'alasan_perubahan' => 'Keluarga datang membawa KTP',
        ])->assertOk()->assertJsonPath('data.nama', 'Contoh Pasien Dua')->assertJsonPath('data.tanpa_identitas', false)
            ->assertJsonPath('data.versi', '1')->assertJsonPath('data.no_rm', $mrx->json('data.no_rm'));
        $this->assertSesuaiKontrak($lengkap, 'PATCH', '/pasien/{id}');

        $versi = DB::table('pasien.pasien_versi')->where('pasien_id', $id)->sole();
        $this->assertSame('Keluarga datang membawa KTP', $versi->alasan_perubahan);
        $lama = json_decode(Crypt::decryptString($versi->isi_enkripsi), true);
        $this->assertSame(['Mr. X', true, null], [$lama['nama'], $lama['tanpa_identitas'], $lama['nik']]);
        $audit = $this->assertAuditTerakhir('pasien.diubah', $id);
        $this->assertEqualsCanonicalizing(['nama', 'tanpa_identitas', 'tanggal_lahir', 'nik'], json_decode((string) $audit->sesudah, true)['field']);
        $this->assertStringNotContainsString('Contoh Pasien Dua', (string) $audit->sesudah);

        $this->mintaDenganVersi('PATCH', "/api/v1/pasien/$id", '1', ['tanpa_identitas' => true, 'alasan_perubahan' => self::ALASAN])
            ->assertUnprocessable()->assertJsonPath('error.kode', 'validasi_gagal');
    }

    public function test_tanpa_bagian_identitas_nik_termasking_dari_server(): void
    {
        $this->masukSebagai($this->petugas(identitas: true));
        $dibuat = $this->daftar(['nama' => 'Contoh Pasien Tiga', 'nik' => self::NIK, 'tanggal_lahir' => '1980-03-12', 'kewarganegaraan' => 'wna', 'no_paspor' => 'A1234589'])
            ->assertCreated()->assertJsonPath('data.nik', self::NIK)->assertJsonPath('data.no_paspor', 'A1234589')->assertJsonPath('data.no_paspor_samaran', 'A12••••89');
        $this->assertSesuaiKontrak($this->cari(self::NIK)->assertOk()->assertJsonPath('data.0.nik', self::NIK), 'GET', '/pasien');

        $this->masukSebagai($this->petugas());
        $hasil = $this->cari('9901 0155 0190 0042')->assertOk()->assertJsonPath('meta.jenis_cari', 'nik')
            ->assertJsonMissingPath('data.0.nik')->assertJsonMissingPath('data.0.no_paspor')
            ->assertJsonPath('data.0.nik_samaran', '9901 •••• •••• 0042')->assertJsonPath('data.0.no_paspor_samaran', 'A12••••89');
        $this->assertSesuaiKontrak($hasil, 'GET', '/pasien');
        $this->assertStringNotContainsString(self::NIK, (string) $hasil->getContent());

        // Mengganti NIK yang sudah terisi butuh izin bagian; mengisi yang kosong (No. kartu) cukup izin perbaiki.
        $id = $dibuat->json('data.id');
        $this->mintaDenganVersi('PATCH', "/api/v1/pasien/$id", '0', ['nik' => self::NIK_2, 'alasan_perubahan' => self::ALASAN])
            ->assertForbidden()->assertJsonPath('error.kode', 'akses_ditolak');
        $this->mintaDenganVersi('PATCH', "/api/v1/pasien/$id", '0', ['nik' => null, 'alasan_perubahan' => self::ALASAN])->assertForbidden();
        $this->mintaDenganVersi('PATCH', "/api/v1/pasien/$id", '0', ['nik' => self::NIK, 'alasan_perubahan' => self::ALASAN])->assertForbidden();
        $this->mintaDenganVersi('PATCH', "/api/v1/pasien/$id", '0', ['no_kartu_bpjs' => self::KARTU, 'alasan_perubahan' => self::ALASAN])
            ->assertOk()->assertJsonPath('data.no_kartu_bpjs', self::KARTU)->assertJsonMissingPath('data.nik');
    }

    public function test_cari_satu_kolom_dengan_keyset_dan_audit_tanpa_kata_cari(): void
    {
        $this->masukSebagai($this->petugas());
        $satu = $this->daftar(['nama' => 'Contoh Pasien Empat', 'tanggal_lahir' => '1980-03-12', 'no_kartu_bpjs' => self::KARTU])->assertCreated();
        $this->daftar(['nama' => 'Contoh Pasien Lima', 'tanggal_lahir' => '1980-03-12'])->assertCreated();
        $this->daftar(['nama' => 'Lain Sekali', 'tanggal_lahir' => '1980-03-12'])->assertCreated();

        $this->cari(ltrim($satu->json('data.no_rm'), '0'))->assertOk()->assertJsonPath('meta.jenis_cari', 'no_rm')
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $satu->json('data.id'));
        $this->cari(self::KARTU)->assertOk()->assertJsonPath('meta.jenis_cari', 'no_kartu')->assertJsonPath('data.0.id', $satu->json('data.id'));
        $this->cari('contoh pasien 12/03/1980')->assertOk()->assertJsonPath('meta.jenis_cari', 'nama_tanggal_lahir')->assertJsonCount(2, 'data');
        $this->cari('Pasien Empat 1980-03-12')->assertOk()->assertJsonCount(1, 'data');
        $this->cari('Contoh Pasien 13-03-1980')->assertOk()->assertJsonCount(0, 'data');

        // Keyset: halaman berikutnya dengan header yang sama; terbaru dulu.
        $halaman = $this->cari('Contoh Pasien 12-03-1980', '?batas=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.nama', 'Contoh Pasien Lima');
        $this->assertNotNull($kursor = $halaman->json('meta.kursor_berikutnya'));
        $this->cari('Contoh Pasien 12-03-1980', "?batas=1&kursor=$kursor")->assertOk()->assertJsonPath('data.0.nama', 'Contoh Pasien Empat');

        $this->assertSesuaiKontrak($this->cari(null)->assertUnprocessable()->assertJsonPath('error.kode', 'kata_cari_wajib'), 'GET', '/pasien');
        foreach (['99010155019000', 'Contoh Pasien', '12-03-1980', 'Ab 12-03-1980', 'Contoh 31-02-1980'] as $kata) {
            $this->cari($kata)->assertUnprocessable()->assertJsonPath('error.kode', 'kata_cari_tidak_dikenali');
        }

        $audit = DB::table('audit.log_aktivitas')->where('aksi', 'pasien.dicari')->orderByDesc('urutan')->first();
        $this->assertNotNull($audit);
        $this->assertSame(0, DB::table('audit.log_aktivitas')->where('aksi', 'pasien.dicari')
            ->where(fn ($q) => $q->whereRaw('sesudah::text ILIKE ?', ['%contoh%'])->orWhereRaw('sesudah::text LIKE ?', ['%'.self::KARTU.'%']))->count());
    }

    public function test_referensi_wilayah_wna_dan_kontak_darurat_diganti(): void
    {
        $this->masukSebagai($this->petugas());
        $dasar = ['nama' => 'Contoh Pasien Enam', 'tanggal_lahir' => '1990-01-01'];
        $this->daftar([...$dasar, 'agama' => 'tidak_ada'])->assertUnprocessable()->assertJsonPath('error.kode', 'referensi_tidak_valid')
            ->assertJsonPath('error.rincian.0.kolom', 'agama');
        $this->daftar([...$dasar, 'alamat' => ['jalan' => 'Jl. Contoh', 'wilayah_kode' => '99.01.01.9999']])
            ->assertUnprocessable()->assertJsonPath('error.kode', 'wilayah_tidak_valid');
        $this->daftar([...$dasar, 'kewarganegaraan' => 'wna'])->assertUnprocessable()->assertJsonPath('error.rincian.0.kolom', 'no_paspor');
        $this->daftar([...$dasar, 'jenis_kelamin' => 'tidak_diketahui'])->assertUnprocessable()->assertJsonPath('error.rincian.0.kolom', 'jenis_kelamin');

        $dibuat = $this->daftar([...$dasar, 'kontak_darurat' => [
            ['nama' => 'Kerabat Satu', 'hubungan' => 'anak', 'telepon' => '081200000003'],
            ['nama' => 'Kerabat Dua', 'hubungan' => 'istri', 'telepon' => '081200000004'],
        ]])->assertCreated()->assertJsonCount(2, 'data.kontak_darurat');
        $id = $dibuat->json('data.id');
        $this->mintaDenganVersi('PATCH', "/api/v1/pasien/$id", '0', ['alasan_perubahan' => self::ALASAN])->assertUnprocessable();
        $this->mintaDenganVersi('PATCH', "/api/v1/pasien/$id", '0', [
            'kontak_darurat' => [['nama' => 'Kerabat Tiga', 'hubungan' => 'saudara', 'telepon' => '081200000005']], 'alasan_perubahan' => self::ALASAN,
        ])->assertOk()->assertJsonCount(1, 'data.kontak_darurat')->assertJsonPath('data.kontak_darurat.0.nama', 'Kerabat Tiga');
        $this->assertSame(2, DB::table('pasien.kontak_darurat')->where('pasien_id', $id)->where('aktif', false)->count());
        $this->assertNotSame('081200000005', DB::table('pasien.kontak_darurat')->where('pasien_id', $id)->where('aktif', true)->value('telepon_enkripsi'));
    }

    public function test_tanpa_izin_pendaftaran_ditolak(): void
    {
        $pengguna = $this->buatPengguna();
        $this->tugaskan($pengguna, $this->buatPeran(['sdm.pegawai']));
        $this->masukSebagai($pengguna);
        $this->cari('00000001')->assertForbidden();
        $this->daftar(['nama' => 'Contoh', 'tanggal_lahir' => '1990-01-01'])->assertForbidden();
        $this->mintaDenganVersi('PATCH', '/api/v1/pasien/'.Str::uuid7(), '0', ['nama' => 'Contoh', 'alasan_perubahan' => self::ALASAN])->assertForbidden();
    }

    /** @param  array<string, mixed>  $isi */
    private function daftar(array $isi): TestResponse
    {
        $respons = $this->minta('POST', '/api/v1/pasien', ['jenis_kelamin' => 'laki_laki', ...$isi]);
        if ($respons->isSuccessful()) {
            $this->assertSesuaiKontrak($respons, 'POST', '/pasien');
        }

        return $respons;
    }

    private function cari(?string $kata, string $query = ''): TestResponse
    {
        $this->headerTambahan = $kata === null ? [] : ['X-Cari' => rawurlencode($kata)];
        try {
            return $this->minta('GET', '/api/v1/pasien'.$query);
        } finally {
            $this->headerTambahan = [];
        }
    }

    private function petugas(bool $identitas = false): Pengguna
    {
        $pengguna = $this->buatPengguna();
        $peran = $this->buatPeran(['pendaftaran.pasien']);
        if ($identitas) {
            // Izin sensitif: di layar lewat persetujuan pemilik data; di tes dipasang langsung.
            PeranMenu::query()->create(['peran_id' => $peran->id, 'menu_id' => Menu::query()->where('kode', 'pendaftaran.pasien.identitas')->value('id')]);
            PeranIzin::query()->create(['peran_id' => $peran->id, 'izin_id' => Izin::query()->where('kode', 'pendaftaran.pasien.identitas.lihat')->value('id')]);
            $pengguna->forceFill(['mfa_rahasia_enkripsi' => Totp::rahasiaBaru(), 'mfa_aktif_pada' => now()])->save();
        }
        $this->tugaskan($pengguna, $peran);

        return $pengguna;
    }
}
