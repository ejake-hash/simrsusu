<?php

namespace Tests\Feature\Instalasi;

use App\Core\Instalasi\StatusInstalasi;
use App\Modules\Akses\Database\MenuBawaan;
use App\Modules\Akses\Domain\Cakupan;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\Akses\Models\Peran;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\AksesUji;
use Tests\Support\KlienSpa;
use Tests\Support\KontrakApi;
use Tests\TestCase;

/** Tiket 1.8f-be: wizard penyiapan instalasi kosong (kontrak v0.12.0, FR-54-08, K6, K7). */
class PenyiapanTest extends TestCase
{
    use AksesUji, KlienSpa, KontrakApi;

    private Pengguna $superadmin;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->superadmin, $rahasia] = $this->superadminBerMfa();
        $this->masukSebagai($this->superadmin, $rahasia);
    }

    public function test_instalasi_kosong_sembilan_langkah_belum_selesai(): void
    {
        $data = $this->muatStatus()->json('data');

        $this->assertSame('belum', $data['status']);
        $this->assertFalse($data['siap_melayani']);
        $this->assertNull($data['siap_melayani_sejak']);
        $this->assertSame(
            ['superadmin', 'profil_rs', 'organisasi', 'paket_referensi', 'pegawai', 'penjamin_tarif', 'formularium', 'kredensial_integrasi', 'modul_aktif'],
            array_column($data['langkah'], 'kode'),
        );
        $status = array_column($data['langkah'], 'status', 'kode');
        $this->assertNotContains('selesai', $status);
        $this->assertSame('menunggu_fase_2', $status['penjamin_tarif']);
        $this->assertSame('menunggu_fase_2', $status['formularium']);
        // Satu superadmin (pemanggil) dari dua; jaringan RS terisi di phpunit.xml.
        $this->assertSame('sebagian', $status['superadmin']);
        $this->assertSame('1 dari 2', $data['langkah'][0]['syarat'][0]['keterangan']);
        $this->assertSame('belum', $status['profil_rs']);
        $this->assertSame([true, true, true, true, true, true, false, false, false], array_column($data['langkah'], 'wajib_untuk_melayani'));
        $this->assertSame(['wilayah', 'referensi', 'icd10', 'icd9cm', 'loinc', 'ucum'], array_column($data['langkah'][3]['paket'], 'sistem'));
        $this->assertSame([true, true, false, false, true, true], array_column($data['langkah'][3]['paket'], 'wajib'));
        $this->assertNull($data['langkah'][0]['paket']);
        $this->assertFalse($data['langkah'][0]['dapat_dikonfirmasi']);
        // Modul inti fase 1 sudah aktif dari migrasi.
        $this->assertTrue($data['langkah'][8]['syarat'][0]['terpenuhi']);
    }

    public function test_data_sintetis_lengkap_langkah_1_5_dan_9_selesai_6_7_menunggu_fase_2_tidak_siap_melayani(): void
    {
        $this->isiDataSintetis();

        foreach (['superadmin', 'profil_rs', 'organisasi', 'paket_referensi', 'pegawai'] as $langkah) {
            $this->konfirmasi($langkah)->assertOk();
        }
        $this->konfirmasi('kredensial_integrasi', ['dilewati' => true, 'alasan' => 'Belum ada kredensial dari RS'])->assertOk();
        $data = $this->konfirmasi('modul_aktif')->assertOk()->json('data');

        $this->assertSame(
            ['selesai', 'selesai', 'selesai', 'selesai', 'selesai', 'menunggu_fase_2', 'menunggu_fase_2', 'dilewati', 'selesai'],
            array_column($data['langkah'], 'status'),
        );
        $this->assertSame('selesai', $data['status']);
        // Langkah 6 menunggu fase 2 (K6): penjaga tetap false.
        $this->assertFalse($data['siap_melayani']);
        $this->assertFalse(app(StatusInstalasi::class)->siapMelayani());
        $this->assertSame('Belum ada kredensial dari RS', $data['langkah'][7]['konfirmasi_terakhir']['alasan']);
        $this->assertSame($this->superadmin->id, $data['langkah'][0]['konfirmasi_terakhir']['oleh']['id']);
        $this->assertSame('selesai', DB::table('inti.profil_rs')->value('status_penyiapan'));
        $wilayah = collect($data['langkah'][3]['paket'])->firstWhere('sistem', 'wilayah');
        $this->assertSame(['2025.1', 'Kemendagri uji', 2], [$wilayah['versi'], $wilayah['sumber'], $wilayah['jumlah_kode_aktif']]);
        $this->assertSame(7, DB::table('audit.log_aktivitas')->where('aksi', 'pengaturan.penyiapan_dikonfirmasi')->count());
        $audit = DB::table('audit.log_aktivitas')->where('aksi', 'pengaturan.penyiapan_dikonfirmasi')->orderByDesc('urutan')->skip(1)->first();
        $this->assertEquals(['langkah' => 'kredensial_integrasi', 'dilewati' => true, 'alasan' => 'Belum ada kredensial dari RS', 'siap_melayani_terkunci' => false], json_decode($audit->sesudah, true));

        // Bisa diulang: konfirmasi baru tersimpan sebagai riwayat.
        $this->konfirmasi('superadmin')->assertOk();
        $this->assertSame(2, DB::table('inti.penyiapan_konfirmasi')->where('langkah', 'superadmin')->count());
        // Langkah 8 yang sudah dilewati tetap bisa dilewati ulang, langkah lain tidak.
        $this->assertTrue($data['langkah'][7]['dapat_dilewati']);
        $this->assertFalse($data['langkah'][4]['dapat_dilewati']);
    }

    public function test_syarat_urutan_fase_2_dan_validasi(): void
    {
        $this->konfirmasi('profil_rs')->assertStatus(409)->assertJsonPath('error.kode', 'langkah_sebelumnya_belum_selesai')
            ->assertJsonPath('error.rincian.0', ['langkah' => 'superadmin', 'status' => 'sebagian']);
        $this->konfirmasi('superadmin')->assertStatus(409)->assertJsonPath('error.kode', 'syarat_belum_lengkap')
            ->assertJsonPath('error.rincian.0.kode', 'superadmin_mfa')->assertJsonPath('error.rincian.0.keterangan', '1 dari 2');
        $this->konfirmasi('penjamin_tarif')->assertStatus(409)->assertJsonPath('error.kode', 'langkah_menunggu_fase_2');
        $this->konfirmasi('profil_rs', ['dilewati' => true, 'alasan' => 'Tidak perlu'])->assertStatus(422)->assertJsonPath('error.kode', 'tidak_bisa_dilewati');
        $this->konfirmasi('kredensial_integrasi', ['dilewati' => true])->assertStatus(422)->assertJsonPath('error.kode', 'validasi_gagal')
            ->assertJsonPath('error.rincian.0.kolom', 'alasan');
        // Urutan dijaga juga untuk langkah yang dilewati.
        $this->konfirmasi('kredensial_integrasi', ['dilewati' => true, 'alasan' => 'Belum ada kredensial'])->assertStatus(409)
            ->assertJsonPath('error.kode', 'langkah_sebelumnya_belum_selesai');
        $this->minta('POST', '/api/v1/penyiapan/langkah_lain/selesai')->assertNotFound();
        $this->assertSame(0, DB::table('inti.penyiapan_konfirmasi')->count());
    }

    public function test_langkah_mundur_kembali_sebagian_dan_siap_melayani_terkunci(): void
    {
        $kedua = $this->superadminLain();
        $this->konfirmasi('superadmin')->assertOk()->assertJsonPath('data.langkah.0.status', 'selesai');

        DB::table('akses.pengguna')->where('id', $kedua->id)->update(['status' => 'nonaktif']);
        $langkah = $this->muatStatus()->json('data.langkah.0');
        $this->assertSame('sebagian', $langkah['status']);
        $this->assertNotNull($langkah['konfirmasi_terakhir']);
        $this->assertFalse($langkah['dapat_dikonfirmasi']);

        // Kunci siap melayani tidak dilepas walau langkah 1–6 mundur (FR-54-08 "sebelum").
        $this->profil();
        DB::table('inti.profil_rs')->update(['siap_melayani_sejak' => now()]);
        $this->assertTrue(app(StatusInstalasi::class)->siapMelayani());
        $this->muatStatus()->assertJsonPath('data.siap_melayani', true);
    }

    public function test_tanpa_izin_ditolak_dan_superadmin_memegang_penyiapan_bawaan(): void
    {
        $this->masukSebagai($this->buatPengguna());
        $this->minta('GET', '/api/v1/penyiapan')->assertForbidden();
        $this->konfirmasi('superadmin')->assertForbidden();

        $lihat = $this->buatPengguna();
        $peran = $this->buatPeran([]);
        $this->beriIzin($peran, 'pengaturan.penyiapan', ['lihat']);
        $this->tugaskan($lihat, $peran);
        $this->masukSebagai($lihat);
        $data = $this->muatStatus()->json('data');
        $this->assertFalse(collect($data['langkah'])->contains(fn (array $l) => $l['dapat_dikonfirmasi'] || $l['dapat_dilewati']));
        $this->konfirmasi('superadmin')->assertForbidden();
    }

    public function test_peran_penyiap_bawaan_memuat_paket_dan_mengonfirmasi_tanpa_data_induk_lain(): void
    {
        $peran = Peran::query()->where('kode', MenuBawaan::PERAN_PENYIAP)->firstOrFail();
        $this->assertFalse($peran->bawaan_sistem);
        $this->assertEqualsCanonicalizing(MenuBawaan::IZIN_PENYIAP, DB::table('akses.peran_izin as pi')->join('akses.izin as i', 'i.id', '=', 'pi.izin_id')
            ->where('pi.peran_id', $peran->id)->pluck('i.kode')->all());

        [$penyiap, $rahasia] = $this->superadminBerMfa();
        DB::table('akses.pengguna_peran')->where('pengguna_id', $penyiap->id)->update(['peran_id' => $peran->id]);
        $this->masukSebagai($penyiap, $rahasia);
        $this->assertTrue(Gate::allows('master_data.katalog.input', Cakupan::rs()));
        $this->assertFalse(Gate::allows('master_data.organisasi.input', Cakupan::rs()));
        $this->assertTrue($this->muatStatus()->json('data.langkah.7.dapat_dilewati') === false);
        $this->konfirmasi('superadmin')->assertStatus(409)->assertJsonPath('error.kode', 'syarat_belum_lengkap');
    }

    private function muatStatus(): TestResponse
    {
        return $this->assertSesuaiKontrak($this->minta('GET', '/api/v1/penyiapan')->assertOk(), 'GET', '/penyiapan');
    }

    /** @param  array<string, mixed>  $isi */
    private function konfirmasi(string $langkah, array $isi = []): TestResponse
    {
        return $this->assertSesuaiKontrak($this->minta('POST', "/api/v1/penyiapan/{$langkah}/selesai", $isi), 'POST', '/penyiapan/{langkah}/selesai');
    }

    private function superadminLain(): Pengguna
    {
        return $this->superadminBerMfa()[0];
    }

    /** @param  list<string>  $aksi */
    private function beriIzin(Peran $peran, string $subMenu, array $aksi): void
    {
        $menu = DB::table('akses.menu')->where('kode', $subMenu)->value('id');
        DB::table('akses.peran_menu')->insert(['id' => (string) Str::uuid7(), 'peran_id' => $peran->id, 'menu_id' => $menu]);
        foreach ($aksi as $a) {
            DB::table('akses.peran_izin')->insert(['id' => (string) Str::uuid7(), 'peran_id' => $peran->id, 'izin_id' => DB::table('akses.izin')->where('kode', "$subMenu.$a")->value('id')]);
        }
    }

    private function profil(): void
    {
        if (! DB::table('inti.profil_rs')->exists()) {
            DB::table('inti.profil_rs')->insert([
                'id' => (string) Str::uuid7(), 'nama' => 'RS Contoh Sintetis',
                'paket_identitas' => json_encode(['warna' => ['brand' => '#0B5394', 'accent' => '#F1C232'], 'logo' => ['path' => 'identitas/logo.png', 'tipe' => 'image/png', 'ukuran' => 10]]),
            ]);
        }
    }

    /** Data sintetis minimal untuk langkah 1–5 (langkah 9 sudah terpenuhi dari migrasi). */
    private function isiDataSintetis(): void
    {
        $this->superadminLain();
        $this->profil();
        $id = fn () => (string) Str::uuid7();
        $hariIni = now(config('instalasi.zona_waktu'))->toDateString();
        $unit = $id();
        DB::table('inti.unit_organisasi')->insert(['id' => $unit, 'kode' => 'IRNA', 'nama' => 'Instalasi Rawat Inap', 'jenis' => 'instalasi', 'berlaku_dari' => '2026-01-01']);
        DB::table('inti.ksm')->insert(['id' => $id(), 'kode' => 'KSM-IPD', 'nama' => 'KSM Ilmu Penyakit Dalam']);
        $kelas = $id();
        DB::table('inti.kelas_rawat')->insert(['id' => $kelas, 'kode' => 'I', 'nama' => 'Kelas I', 'berlaku_dari' => '2026-01-01']);
        $bangsal = $id();
        DB::table('inti.bangsal')->insert(['id' => $bangsal, 'kode' => 'MLT', 'nama' => 'Melati', 'unit_organisasi_id' => $unit]);
        $ruang = $id();
        DB::table('inti.lokasi')->insert(['id' => $ruang, 'jenis' => 'ruang', 'kode' => '101', 'kode_lengkap' => '101', 'nama' => 'Ruang 101', 'fungsi' => 'rawat_inap', 'status_berlaku_dari' => '2026-01-01']);
        $kamar = $id();
        DB::table('inti.kamar')->insert(['id' => $kamar, 'bangsal_id' => $bangsal, 'lokasi_id' => $ruang]);
        $lokasiTt = $id();
        DB::table('inti.lokasi')->insert(['id' => $lokasiTt, 'induk_id' => $ruang, 'jenis' => 'tempat_tidur', 'kode' => '1', 'kode_lengkap' => '101-1', 'nama' => 'TT 1', 'status_berlaku_dari' => '2026-01-01']);
        DB::table('inti.tempat_tidur')->insert(['id' => $id(), 'kamar_id' => $kamar, 'lokasi_id' => $lokasiTt, 'kode' => '1', 'kelas_rawat_id' => $kelas, 'berlaku_dari' => $hariIni]);
        DB::table('inti.poliklinik')->insert(['id' => $id(), 'kode' => 'PD', 'nama' => 'Poli Penyakit Dalam', 'unit_organisasi_id' => $unit]);

        foreach (['wilayah', 'referensi', 'loinc', 'ucum'] as $sistem) {
            DB::table('inti.paket_referensi')->insert([
                'id' => $id(), 'sistem' => $sistem, 'versi' => '2025.1', 'sumber' => $sistem === 'wilayah' ? 'Kemendagri uji' : 'Sumber uji',
                'jumlah_kode' => 2, 'sidik_isi' => str_repeat('a', 64), 'dimuat_pada' => now(),
            ]);
        }
        DB::table('inti.wilayah')->insert([
            ['id' => $id(), 'kode_kemendagri' => '99', 'tingkat' => 'provinsi', 'nama' => 'Provinsi Uji', 'versi_terakhir' => '2025.1'],
            ['id' => $id(), 'kode_kemendagri' => '98', 'tingkat' => 'provinsi', 'nama' => 'Provinsi Uji Dua', 'versi_terakhir' => '2025.1'],
        ]);

        $pegawai = $id();
        DB::table('sdm.pegawai')->insert(['id' => $pegawai, 'nama' => 'Contoh Dokter Sintetis']);
        DB::table('sdm.izin_praktik')->insert(['id' => $id(), 'pegawai_id' => $pegawai, 'jenis' => 'str', 'nomor' => 'STR-UJI-1', 'terbit' => '2025-01-01']);
    }
}
