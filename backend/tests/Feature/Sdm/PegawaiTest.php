<?php

namespace Tests\Feature\Sdm;

use App\Modules\Akses\Domain\Totp;
use App\Modules\Akses\Models\Izin;
use App\Modules\Akses\Models\Menu;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\Akses\Models\PeranIzin;
use App\Modules\Akses\Models\PeranMenu;
use App\Modules\MasterData\Models\UnitOrganisasi;
use App\Modules\Sdm\Models\Pegawai;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\AksesUji;
use Tests\Support\KlienSpa;
use Tests\Support\KontrakApi;
use Tests\TestCase;

/** Tiket 1.8d-be: pegawai, izin praktik, RKK, akun dari pegawai, ganti sandi (kontrak v0.6.0). */
class PegawaiTest extends TestCase
{
    use AksesUji, KlienSpa, KontrakApi;

    private const DASAR = 'SK Direktur 6/2026';

    private const NIK = '1271015501900042';

    public function test_nik_tersimpan_terenkripsi_dan_hanya_utuh_dengan_izin_bagian_identitas(): void
    {
        $sdm = $this->petugasSdm();
        $this->masukSebagai($sdm);
        $this->minta('POST', '/api/v1/pegawai', ['nama' => 'Siti Aminah', 'nik' => self::NIK, 'dasar_perubahan' => self::DASAR])
            ->assertForbidden();
        $dibuat = $this->minta('POST', '/api/v1/pegawai', ['nama' => 'Siti Aminah', 'nip' => '1990001', 'profesi' => 'perawat', 'dasar_perubahan' => self::DASAR])
            ->assertCreated()->assertJsonPath('data.nik_samaran', null)->assertJsonPath('data.status', 'aktif')->assertJsonMissingPath('data.nik');
        $this->assertSesuaiKontrak($dibuat, 'POST', '/pegawai');
        $id = $dibuat->json('data.id');

        $identitas = $this->petugasSdm(identitas: true);
        $this->masukSebagai($identitas);
        $ubah = $this->mintaDenganVersi('PATCH', "/api/v1/pegawai/$id", $dibuat->json('data.versi'), ['nik' => self::NIK, 'dasar_perubahan' => self::DASAR])
            ->assertOk()->assertJsonMissingPath('data.nik')->assertJsonPath('data.nik_samaran', '1271 •••• •••• 0042')->assertJsonPath('data.nik_dapat_dilihat', true);
        $this->assertSesuaiKontrak($ubah, 'PATCH', '/pegawai/{id}');
        $this->assertFalse(DB::table('audit.log_aktivitas')->where('aksi', 'sdm.nik_dilihat')->exists());
        $this->assertSesuaiKontrak($this->minta('GET', "/api/v1/pegawai/$id?tampilkan_nik=true")->assertOk()->assertJsonPath('data.nik', self::NIK),
            'GET', '/pegawai/{id}');
        $this->assertNotSame(self::NIK, DB::table('sdm.pegawai')->where('id', $id)->value('nik_enkripsi'));
        $this->minta('POST', '/api/v1/pegawai', ['nama' => 'Ganda', 'nik' => self::NIK, 'dasar_perubahan' => self::DASAR])
            ->assertStatus(409)->assertJsonPath('error.kode', 'nik_sudah_dipakai');

        // Tanpa izin bagian: hanya samaran, tidak ada field nik; NIK tidak pernah masuk audit.
        $this->masukSebagai($sdm);
        $this->minta('GET', "/api/v1/pegawai/$id?tampilkan_nik=true")->assertForbidden();
        $this->assertSesuaiKontrak($this->minta('GET', "/api/v1/pegawai/$id")->assertOk()->assertJsonMissingPath('data.nik')
            ->assertJsonPath('data.nik_samaran', '1271 •••• •••• 0042')->assertJsonPath('data.nik_dapat_dilihat', false), 'GET', '/pegawai/{id}');
        $this->assertSesuaiKontrak($this->minta('GET', '/api/v1/pegawai?cari=siti')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.punya_akun', false), 'GET', '/pegawai');
        $this->assertSame(0, DB::table('audit.log_aktivitas')->whereRaw('sesudah::text LIKE ? OR sebelum::text LIKE ?', ['%'.self::NIK.'%', '%'.self::NIK.'%'])->count());
        $this->assertTrue(DB::table('audit.log_aktivitas')->where('aksi', 'sdm.nik_dilihat')->where('subjek_id', $id)->exists());
    }

    public function test_akun_dari_pegawai_wajib_ganti_sandi_dan_cek_kontekstual(): void
    {
        $pegawai = Pegawai::query()->create(['nama' => 'Budi Santoso']);
        $admin = $this->pengelola();
        $this->tugaskan($admin, $this->buatPeran(['sdm.pegawai']));
        $this->masukSebagai($admin);

        $dibuat = $this->minta('POST', "/api/v1/pegawai/{$pegawai->id}/akun", ['nama_pengguna' => 'budi.santoso', 'dasar_pemberian' => self::DASAR])
            ->assertCreated()->assertHeader('Cache-Control');
        $this->assertSesuaiKontrak($dibuat, 'POST', '/pegawai/{id}/akun');
        $this->assertStringContainsString('no-store', (string) $dibuat->headers->get('Cache-Control'));
        $sandiAwal = $dibuat->json('data.sandi_awal');
        $this->minta('POST', "/api/v1/pegawai/{$pegawai->id}/akun", ['nama_pengguna' => 'budi.lain', 'dasar_pemberian' => self::DASAR])
            ->assertStatus(409)->assertJsonPath('error.kode', 'pegawai_sudah_punya_akun');
        $this->assertFalse(DB::table('audit.log_aktivitas')->whereRaw('sesudah::text LIKE ?', ['%'.$sandiAwal.'%'])->exists());

        // Masuk dengan sandi awal (dua peramban): hanya /saya dan ganti sandi yang boleh.
        $this->mulaiSesiSpa();
        $this->minta('POST', '/api/v1/autentikasi/masuk', ['nama_pengguna' => 'budi.santoso', 'sandi' => $sandiAwal])->assertOk();
        $perambanLain = $this->peramban();
        $this->mulaiSesiSpa();
        $this->minta('POST', '/api/v1/autentikasi/masuk', ['nama_pengguna' => 'budi.santoso', 'sandi' => $sandiAwal])->assertOk()->assertJsonPath('status', 'masuk');
        $this->assertSesuaiKontrak($this->minta('GET', '/api/v1/saya')->assertOk()->assertJsonPath('data.wajib_ganti_sandi', true), 'GET', '/saya');
        $this->minta('GET', '/api/v1/saya/menu')->assertOk();
        $this->minta('GET', '/api/v1/beranda')->assertStatus(404);
        $this->minta('GET', '/api/v1/menu')->assertForbidden()->assertJsonPath('error.kode', 'wajib_ganti_sandi');

        $ganti = fn (string $lama, string $baru) => $this->minta('POST', '/api/v1/saya/sandi', ['sandi_lama' => $lama, 'sandi_baru' => $baru]);
        $ganti('salah-sandi-lama-99', 'Kuda-Biru-Lompat-77')->assertStatus(422)->assertJsonPath('error.kode', 'sandi_lama_salah');
        $ganti($sandiAwal, 'Budi.Santoso-Lompat-77')->assertStatus(422)->assertJsonPath('error.kode', 'validasi_gagal');
        $ganti($sandiAwal, 'Karsitara-Lompat-77')->assertStatus(422);
        $ganti($sandiAwal, 'pendek')->assertStatus(422);
        $berhasil = $ganti($sandiAwal, 'Kuda-Biru-Lompat-77')->assertOk()->assertJsonPath('data.wajib_ganti_sandi', false);
        $this->assertSesuaiKontrak($berhasil, 'POST', '/saya/sandi');
        $this->minta('GET', '/api/v1/saya')->assertOk();
        $this->minta('GET', '/api/v1/menu')->assertForbidden()->assertJsonPath('error.kode', 'akses_ditolak');
        $this->assertTrue(DB::table('audit.log_aktivitas')->where('aksi', 'akses.sandi_diganti')->exists());
        // Sesi di peramban lain diputus.
        $this->pakaiPeramban($perambanLain);
        $this->minta('GET', '/api/v1/saya')->assertUnauthorized();
    }

    public function test_pegawai_keluar_menonaktifkan_akun_dan_memutus_sesi(): void
    {
        $pegawai = Pegawai::query()->create(['nama' => 'Rina Keluar']);
        $akun = $this->buatPengguna(['pegawai_id' => $pegawai->id]);
        $this->masukSebagai($akun);
        $sesiAkun = $this->peramban();

        $this->masukSebagai($this->petugasSdm());
        $hariIni = now(config('instalasi.zona_waktu'))->toDateString();
        $besok = now(config('instalasi.zona_waktu'))->addDay()->toDateString();
        $ubah = fn (array $isi) => $this->mintaDenganVersi('PATCH', "/api/v1/pegawai/{$pegawai->id}",
            (string) $pegawai->refresh()->revisi, [...$isi, 'dasar_perubahan' => self::DASAR]);
        $ubah(['tanggal_keluar' => $besok])->assertStatus(422);
        $ubah(['tanggal_keluar' => $besok, 'alasan_keluar' => 'Kontrak berakhir'])->assertOk()->assertJsonPath('data.status', 'akan_keluar');
        $this->assertSame('aktif', $akun->refresh()->status);

        $this->travel(1)->days();
        $this->artisan('sdm:proses-pegawai-keluar')->assertSuccessful();
        $this->assertSame('nonaktif', $akun->refresh()->status);
        $this->pakaiPeramban($sesiAkun);
        $this->minta('GET', '/api/v1/saya')->assertUnauthorized();

        // Aktifkan kembali ditolak selama pegawainya keluar.
        $admin = $this->pengelola();
        $this->masukSebagai($admin);
        $this->assertSesuaiKontrak($this->minta('POST', "/api/v1/akun/{$akun->id}/aktifkan", ['alasan' => 'Salah input'])
            ->assertStatus(409)->assertJsonPath('error.kode', 'pegawai_keluar'), 'POST', '/akun/{id}/aktifkan');
        unset($hariIni);
    }

    public function test_superadmin_tidak_lewat_jalur_sdm_atau_akun(): void
    {
        [$superadmin] = $this->superadminBerMfa();
        [$lain, $rahasia] = $this->superadminBerMfa();
        $pegawai = Pegawai::query()->findOrFail($superadmin->pegawai_id);
        $this->masukSebagai($this->petugasSdm());
        $this->mintaDenganVersi('PATCH', "/api/v1/pegawai/{$pegawai->id}", (string) $pegawai->revisi, [
            'tanggal_keluar' => now()->toDateString(), 'alasan_keluar' => 'Coba kunci superadmin', 'dasar_perubahan' => self::DASAR,
        ])->assertStatus(409)->assertJsonPath('error.kode', 'superadmin_aktif');
        $this->assertSame('aktif', $superadmin->refresh()->status);

        $this->masukSebagai($lain, $rahasia);
        $this->assertSesuaiKontrak($this->minta('POST', "/api/v1/akun/{$superadmin->id}/nonaktifkan", ['alasan' => 'Coba nonaktifkan'])
            ->assertStatus(409)->assertJsonPath('error.kode', 'superadmin_aktif'), 'POST', '/akun/{id}/nonaktifkan');

        // Akun biasa: nonaktif lalu aktif kembali, sesi diputus saat nonaktif.
        $biasa = $this->buatPengguna(['pegawai_id' => Pegawai::query()->create(['nama' => 'Akun Biasa'])->id]);
        $this->minta('POST', "/api/v1/akun/{$biasa->id}/nonaktifkan", ['alasan' => 'Cuti panjang'])->assertOk()->assertJsonPath('data.status', 'nonaktif');
        $this->minta('POST', "/api/v1/akun/{$biasa->id}/nonaktifkan", ['alasan' => 'Cuti panjang'])->assertStatus(409)->assertJsonPath('error.kode', 'sudah_nonaktif');
        $this->minta('POST', "/api/v1/akun/{$biasa->id}/aktifkan", ['alasan' => 'Kembali bertugas'])->assertOk()->assertJsonPath('data.status', 'aktif');
    }

    public function test_izin_praktik_dan_kewenangan_klinis(): void
    {
        Storage::fake('local');
        $pegawai = Pegawai::query()->create(['nama' => 'dr. Andi', 'profesi' => 'dokter']);
        $this->masukSebagai($this->petugasSdm());
        $catat = fn (array $isi, ?UploadedFile $berkas = null) => $this->minta('POST', "/api/v1/pegawai/{$pegawai->id}/izin-praktik",
            array_filter([...$isi, 'berkas' => $berkas]));

        $catat(['jenis' => 'sip', 'nomor' => 'SIP/001/2026', 'terbit' => '2026-01-01'])->assertStatus(422)->assertJsonPath('error.kode', 'tanggal_tidak_valid');
        $catat(['jenis' => 'sip', 'nomor' => 'SIP/001/2026', 'terbit' => '2026-01-01', 'berlaku_sampai' => '2026-12-31'],
            UploadedFile::fake()->createWithContent('sip.exe', "MZ\x90\x00binary"))->assertStatus(422)->assertJsonPath('error.kode', 'tipe_berkas_tidak_diizinkan');
        $akanHabis = now(config('instalasi.zona_waktu'))->addDays(30)->toDateString();
        $sip = $catat(['jenis' => 'sip', 'nomor' => 'SIP/001/2026', 'terbit' => '2026-01-01', 'berlaku_sampai' => $akanHabis],
            UploadedFile::fake()->createWithContent('sip.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF"))
            ->assertCreated()->assertJsonPath('data.status', 'akan_habis')->assertJsonPath('data.dokumen.jenis_mime', 'application/pdf');
        $this->assertSesuaiKontrak($sip, 'POST', '/pegawai/{id}/izin-praktik');
        $catat(['jenis' => 'str', 'nomor' => 'STR/9/2020', 'terbit' => '2020-01-01'])->assertCreated()->assertJsonPath('data.status', 'berlaku');
        $catat(['jenis' => 'sip', 'nomor' => 'SIP/001/2026', 'terbit' => '2026-01-01', 'berlaku_sampai' => '2027-01-01'])
            ->assertStatus(409)->assertJsonPath('error.kode', 'izin_ganda');

        $dokumen = $this->minta('GET', "/api/v1/pegawai/{$pegawai->id}/izin-praktik/".$sip->json('data.id').'/dokumen')->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('attachment', (string) $dokumen->headers->get('Content-Disposition'));
        $this->assertSesuaiKontrak($this->minta('GET', "/api/v1/pegawai/{$pegawai->id}/izin-praktik")->assertOk()->assertJsonCount(2, 'data'),
            'GET', '/pegawai/{id}/izin-praktik');
        $this->minta('GET', "/api/v1/pegawai/{$pegawai->id}")->assertJsonPath('data.izin_praktik_status', 'akan_habis');
        $batal = $this->minta('POST', "/api/v1/pegawai/{$pegawai->id}/izin-praktik/".$sip->json('data.id').'/batal', ['alasan_batal' => 'Salah nomor'])
            ->assertOk()->assertJsonPath('data.status', 'batal');
        $this->assertSesuaiKontrak($batal, 'POST', '/pegawai/{id}/izin-praktik/{izinPraktikId}/batal');

        $rkk = $this->minta('POST', "/api/v1/pegawai/{$pegawai->id}/kewenangan-klinis", [
            'kategori' => 'Bedah minor', 'kode_tindakan' => ['86.3', '86.4'], 'berlaku_dari' => '2026-01-01', 'berlaku_sampai' => '2028-12-31', 'komite' => 'Komite Medik',
        ])->assertCreated()->assertJsonPath('data.status', 'berlaku');
        $this->assertSesuaiKontrak($rkk, 'POST', '/pegawai/{id}/kewenangan-klinis');
        $this->assertSesuaiKontrak($this->minta('POST', "/api/v1/pegawai/{$pegawai->id}/kewenangan-klinis/".$rkk->json('data.id').'/batal', ['alasan_batal' => 'Diganti SK baru'])
            ->assertOk()->assertJsonPath('data.status', 'batal'), 'POST', '/pegawai/{id}/kewenangan-klinis/{kewenanganId}/batal');
    }

    public function test_kepala_unit_dari_pegawai_dan_izin_cakupan_rs(): void
    {
        $unit = UnitOrganisasi::query()->create(['kode' => 'IGD', 'nama' => 'IGD', 'jenis' => 'instalasi', 'berlaku_dari' => '2026-01-01']);
        $kepala = Pegawai::query()->create(['nama' => 'dr. Kepala']);
        $data = $this->buatPengguna();
        $this->tugaskan($data, $this->buatPeran(['master_data.organisasi']));
        $this->masukSebagai($data);
        $this->assertSesuaiKontrak($this->mintaDenganVersi('PATCH', "/api/v1/unit-organisasi/{$unit->id}", '0', ['kepala_pegawai_id' => $kepala->id, 'dasar_perubahan' => self::DASAR])
            ->assertOk()->assertJsonPath('data.kepala.nama', 'dr. Kepala')->assertJsonPath('data.kepala.status', 'aktif'), 'PATCH', '/unit-organisasi/{id}');

        $this->masukSebagai($this->buatPengguna());
        $this->minta('GET', '/api/v1/pegawai')->assertForbidden();
    }

    private function petugasSdm(bool $identitas = false): Pengguna
    {
        $pengguna = $this->buatPengguna();
        $peran = $this->buatPeran(['sdm.pegawai', 'sdm.kredensial']);
        if ($identitas) {
            // Izin sensitif: di layar lewat persetujuan pemilik data; di tes dipasang langsung.
            PeranMenu::query()->create(['peran_id' => $peran->id, 'menu_id' => Menu::query()->where('kode', 'sdm.pegawai.identitas')->value('id')]);
            PeranIzin::query()->create(['peran_id' => $peran->id, 'izin_id' => Izin::query()->where('kode', 'sdm.pegawai.identitas.lihat')->value('id')]);
            $pengguna->forceFill(['mfa_rahasia_enkripsi' => Totp::rahasiaBaru(), 'mfa_aktif_pada' => now()])->save();
        }
        $this->tugaskan($pengguna, $peran);

        return $pengguna;
    }
}
