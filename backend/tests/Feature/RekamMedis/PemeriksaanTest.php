<?php

namespace Tests\Feature\RekamMedis;

use App\Modules\Akses\Models\Pengguna;
use App\Modules\MasterData\Models\JadwalPraktik;
use App\Modules\MasterData\Models\Poliklinik;
use App\Modules\MasterData\Models\UnitOrganisasi;
use App\Modules\Pendaftaran\Models\Pasien;
use App\Modules\RekamMedis\Events\PemeriksaanDimulai;
use App\Modules\Sdm\Models\Pegawai;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\AksesUji;
use Tests\Support\KlienSpa;
use Tests\Support\KontrakApi;
use Tests\Support\KunjunganUji;
use Tests\TestCase;

/**
 * Tiket 2.15-be: mulai periksa, diagnosis ICD-10, selesai kunjungan, ringkasan klinis, cari kode (kontrak v0.25.0).
 * Kode ICD di sini SINTETIS (awalan ZZUJI), bukan kode ICD-10 resmi: paket resmi dimuat RS sendiri (K7).
 */
class PemeriksaanTest extends TestCase
{
    use AksesUji, KlienSpa, KontrakApi, KunjunganUji;

    private Pegawai $dokter;

    private Pengguna $akunDokter;

    private JadwalPraktik $jadwal;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->siapkanKunjungan();
        DB::table('inti.modul_aktif')->insert(['id' => (string) Str::uuid7(), 'modul_no' => 7]);
        $this->dokter = $this->dokter();
        $this->jadwal = $this->jadwal($this->dokter);
        $this->akunDokter = $this->akunPemeriksa();
    }

    public function test_mulai_diagnosis_selesai_menutup_relasi_dan_antrean(): void
    {
        $this->muatIcdSintetis();
        Event::fake([PemeriksaanDimulai::class]); // PemeriksaanSelesai tetap nyata: pendengar antrean diuji lewat akibatnya.
        $kunjungan = $this->kunjungan($this->pasien());
        $this->masukSebagai($this->akunDokter);

        // Diagnosis sebelum mulai periksa ditolak.
        $this->catat($kunjungan, 'ZZUJI1', 'utama')->assertStatus(409)->assertJsonPath('error.kode', 'status_tidak_sesuai');
        $mulai = $this->minta('POST', "/api/v1/kunjungan/$kunjungan/mulai-periksa")->assertOk()->assertJsonPath('data.status', 'dilayani');
        $this->assertSesuaiKontrak($mulai, 'POST', '/kunjungan/{id}/mulai-periksa');
        // Membuka RM lagi: tanpa perubahan, kejadian tidak diulang.
        $this->minta('POST', "/api/v1/kunjungan/$kunjungan/mulai-periksa")->assertOk()->assertJsonPath('data.versi', $mulai->json('data.versi'));
        Event::assertDispatchedTimes(PemeriksaanDimulai::class, 1);

        $utama = $this->catat($kunjungan, 'ZZUJI1', 'utama')->assertCreated()->assertJsonPath('data.tampilan', 'Diagnosis sintetis satu')
            ->assertJsonPath('data.dapat.ubah', true);
        $this->assertSesuaiKontrak($utama, 'POST', '/kunjungan/{id}/diagnosis');
        $this->catat($kunjungan, 'ZZUJI2', 'utama')->assertStatus(409)->assertJsonPath('error.kode', 'diagnosis_utama_sudah_ada');
        $this->catat($kunjungan, 'ZZUJI1', 'sekunder')->assertStatus(409)->assertJsonPath('error.kode', 'diagnosis_sudah_tercatat');
        $this->catat($kunjungan, 'ZZUJI9', 'sekunder')->assertStatus(422)->assertJsonPath('error.rincian.0.kolom', 'kode');
        $this->catat($kunjungan, 'ZZUJI3', 'sekunder')->assertStatus(422); // nonaktif di paket
        $this->catat($kunjungan, 'ZZUJI2', 'sekunder')->assertCreated();
        $daftar = $this->minta('GET', "/api/v1/kunjungan/$kunjungan/diagnosis")->assertOk()->assertJsonCount(2, 'data');
        $this->assertSesuaiKontrak($daftar, 'GET', '/kunjungan/{id}/diagnosis');
        // Kode diagnosis tidak masuk audit.
        $this->assertStringNotContainsString('ZZUJI', (string) DB::table('audit.log_aktivitas')->where('aksi', 'like', 'klinis.diagnosis%')->pluck('sesudah')->implode(' '));

        $versi = (string) DB::table('kunjungan.kunjungan')->where('id', $kunjungan)->value('revisi');
        $this->minta('POST', "/api/v1/kunjungan/$kunjungan/selesai")->assertStatus(428);
        $selesai = $this->mintaDenganVersi('POST', "/api/v1/kunjungan/$kunjungan/selesai", $versi)->assertOk()->assertJsonPath('data.status', 'selesai');
        $this->assertSesuaiKontrak($selesai, 'POST', '/kunjungan/{id}/selesai');
        $this->assertSame(['batal'], DB::table('akses.relasi_perawatan')->where('sumber_id', $kunjungan)->pluck('status')->unique()->values()->all());
        $this->assertSame('selesai', DB::table('kunjungan.antrean')->where('kunjungan_id', $kunjungan)->where('titik', 'poli')->value('status'));

        // Sesudah selesai: diagnosis terkunci.
        $this->catat($kunjungan, 'ZZUJI2', 'sekunder')->assertStatus(409)->assertJsonPath('error.kode', 'status_tidak_sesuai');
        $this->mintaDenganVersi('PATCH', '/api/v1/diagnosis/'.$utama->json('data.id'), '0', ['jenis' => 'sekunder'])
            ->assertStatus(409)->assertJsonPath('error.kode', 'status_tidak_sesuai');
        $this->minta('POST', "/api/v1/kunjungan/$kunjungan/mulai-periksa")->assertStatus(409);
    }

    public function test_selesai_menyebut_semua_yang_kurang_tanpa_membuka_draf_orang_lain(): void
    {
        $this->muatIcdSintetis();
        $kunjungan = $this->kunjungan($this->pasien());
        $lain = $this->akunPemeriksa(['rawat_jalan.pemeriksaan', 'rawat_jalan.cppt']);
        $this->masukSebagai($lain);
        $this->minta('POST', "/api/v1/kunjungan/$kunjungan/cppt", ['asesmen' => 'Draf dokter lain'])->assertCreated();
        $this->masukSebagai($this->akunDokter);
        $this->minta('POST', "/api/v1/kunjungan/$kunjungan/mulai-periksa")->assertOk();
        $versi = (string) DB::table('kunjungan.kunjungan')->where('id', $kunjungan)->value('revisi');

        $this->mintaDenganVersi('POST', "/api/v1/kunjungan/$kunjungan/selesai", $versi)->assertStatus(409)
            ->assertJsonPath('error.kode', 'syarat_selesai_belum_lengkap')
            ->assertJsonPath('error.rincian.0.kolom', 'diagnosis_utama')
            ->assertJsonPath('error.rincian.1.kolom', 'cppt_dokter_draf')
            ->assertJsonPath('error.rincian.1.jumlah', 1)
            ->assertJsonPath('error.rincian.1.id', []);
        $this->assertSame('dilayani', DB::table('kunjungan.kunjungan')->where('id', $kunjungan)->value('status'));
        $this->mintaDenganVersi('POST', "/api/v1/kunjungan/$kunjungan/selesai", '99')->assertStatus(412);
    }

    public function test_ubah_diagnosis_hanya_pencatat_ganti_jenis_dan_batal(): void
    {
        $this->muatIcdSintetis();
        $kunjungan = $this->kunjungan($this->pasien());
        $this->masukSebagai($this->akunDokter);
        $this->minta('POST', "/api/v1/kunjungan/$kunjungan/mulai-periksa")->assertOk();
        $id = $this->catat($kunjungan, 'ZZUJI1', 'utama')->assertCreated()->json('data.id');
        $sekunder = $this->catat($kunjungan, 'ZZUJI2', 'sekunder')->json('data.id');

        $this->masukSebagai($this->akunPemeriksa());
        $this->mintaDenganVersi('PATCH', "/api/v1/diagnosis/$id", '0', ['jenis' => 'sekunder'])->assertForbidden();
        $this->assertFalse($this->minta('GET', "/api/v1/kunjungan/$kunjungan/diagnosis")->json('data.0.dapat.ubah'));

        $this->masukSebagai($this->akunDokter);
        $this->mintaDenganVersi('PATCH', "/api/v1/diagnosis/$sekunder", '0', ['jenis' => 'utama'])->assertStatus(409)->assertJsonPath('error.kode', 'diagnosis_utama_sudah_ada');
        $this->mintaDenganVersi('PATCH', "/api/v1/diagnosis/$id", '0', ['jenis' => 'sekunder', 'status' => 'batal', 'alasan_batal' => 'Salah pilih'])->assertStatus(422);
        $ganti = $this->mintaDenganVersi('PATCH', "/api/v1/diagnosis/$id", '0', ['jenis' => 'sekunder'])->assertOk()->assertJsonPath('data.jenis', 'sekunder');
        $this->assertSesuaiKontrak($ganti, 'PATCH', '/diagnosis/{id}');
        $this->mintaDenganVersi('PATCH', "/api/v1/diagnosis/$id", '0', ['status' => 'batal', 'alasan_batal' => 'Salah pilih kode'])->assertStatus(412);
        $this->mintaDenganVersi('PATCH', "/api/v1/diagnosis/$id", '1', ['status' => 'batal', 'alasan_batal' => 'Salah pilih kode'])
            ->assertOk()->assertJsonPath('data.status', 'batal')->assertJsonPath('data.dapat.ubah', false);
        $this->mintaDenganVersi('PATCH', "/api/v1/diagnosis/$id", '2', ['jenis' => 'utama'])->assertStatus(409)->assertJsonPath('error.kode', 'status_tidak_sesuai');
        $this->assertSame(1, DB::table('klinis.diagnosis')->where('id', $id)->count()); // tidak dihapus
        $this->minta('GET', "/api/v1/kunjungan/$kunjungan/diagnosis?status=batal")->assertJsonCount(1, 'data')->assertJsonPath('data.0.alasan_batal', 'Salah pilih kode');
        // Kode yang dibatalkan boleh dicatat ulang.
        $this->catat($kunjungan, 'ZZUJI1', 'utama')->assertCreated();
    }

    public function test_paket_belum_dimuat_dan_cari_kode(): void
    {
        $kunjungan = $this->kunjungan($this->pasien());
        $this->masukSebagai($this->akunDokter);
        $this->minta('GET', '/api/v1/paket-referensi/icd10/kode?q=sintetis')->assertStatus(409)->assertJsonPath('error.kode', 'paket_referensi_belum_dimuat');
        $this->minta('POST', "/api/v1/kunjungan/$kunjungan/mulai-periksa")->assertOk();
        $this->catat($kunjungan, 'ZZUJI1', 'utama')->assertStatus(409)->assertJsonPath('error.kode', 'paket_referensi_belum_dimuat');

        $this->muatIcdSintetis();
        $cari = $this->minta('GET', '/api/v1/paket-referensi/icd10/kode?q=SINTETIS&batas=1')->assertOk()
            ->assertJsonPath('data.0.kode', 'ZZUJI1')->assertJsonCount(1, 'data');
        $this->assertSesuaiKontrak($cari, 'GET', '/paket-referensi/{sistem}/kode');
        $kedua = $this->minta('GET', '/api/v1/paket-referensi/icd10/kode?q=SINTETIS&kursor='.$cari->json('meta.kursor_berikutnya'))->assertOk();
        $this->assertSame(['ZZUJI2'], $kedua->json('data.*.kode')); // ZZUJI3 nonaktif tidak ikut
        $this->minta('GET', '/api/v1/paket-referensi/icd10/kode?q=a')->assertStatus(422);
        $this->minta('GET', '/api/v1/paket-referensi/wilayah/kode?q=aceh')->assertNotFound();

        $this->masukSebagai($this->buatPengguna());
        $this->minta('GET', '/api/v1/paket-referensi/icd10/kode?q=sintetis')->assertForbidden();
    }

    public function test_ringkasan_klinis_cakupan_dan_poli_sensitif(): void
    {
        $this->muatIcdSintetis();
        $pasien = $this->pasien();
        $kunjungan = $this->kunjungan($pasien);
        $unitSensitif = UnitOrganisasi::query()->create(['kode' => 'POLI-S', 'nama' => 'Poli S', 'jenis' => 'poli', 'berlaku_dari' => '2026-01-01']);
        $poliSensitif = Poliklinik::query()->create(['kode' => 'UJS', 'nama' => 'Poli Sensitif Uji', 'unit_organisasi_id' => $unitSensitif->id]);
        $poliSensitif->forceFill(['nama_netral' => 'Klinik 7', 'antrean_prefiks' => 'K7', 'antrean_area' => 'poli-k7'])->save();
        $kunjunganSensitif = $this->kunjungan($pasien, $this->jadwal($this->dokter(), ['poli_id' => $poliSensitif->id]));

        $this->masukSebagai($this->akunDokter);
        foreach ([[$kunjungan, 'ZZUJI1'], [$kunjunganSensitif, 'ZZUJI2']] as [$k, $kode]) {
            $this->minta('POST', "/api/v1/kunjungan/$k/mulai-periksa")->assertOk();
            $this->catat($k, $kode, 'utama')->assertCreated();
        }

        $ringkasan = $this->minta('GET', "/api/v1/pasien/{$pasien->id}/ringkasan-klinis")->assertOk()
            ->assertJsonPath('data.diagnosis_aktif.*.kode', ['ZZUJI1']);
        $this->assertSesuaiKontrak($ringkasan, 'GET', '/pasien/{id}/ringkasan-klinis');
        $baris = collect($ringkasan->json('data.kunjungan_terakhir'))->keyBy('kunjungan_id');
        $this->assertSame('Klinik 7', $baris[$kunjunganSensitif]['poliklinik']['nama']);
        $this->assertNull($baris[$kunjunganSensitif]['diagnosis_utama']);
        $this->assertSame('ZZUJI1', $baris[$kunjungan]['diagnosis_utama']['kode']);

        // Di luar cakupan unit, dan pasien yang tidak ada: sama-sama 403.
        $unitLain = UnitOrganisasi::query()->create(['kode' => 'POLI-L', 'nama' => 'Poli L', 'jenis' => 'poli', 'berlaku_dari' => '2026-01-01']);
        $luar = $this->buatPengguna();
        $this->tugaskan($luar, $this->buatPeran(['rawat_jalan.pemeriksaan']), cakupan: new \App\Modules\Akses\Domain\Cakupan('unit', $unitLain->id));
        $this->masukSebagai($luar);
        $this->minta('GET', "/api/v1/pasien/{$pasien->id}/ringkasan-klinis")->assertForbidden();
        $this->masukSebagai($this->akunDokter);
        $this->minta('GET', '/api/v1/pasien/'.Str::uuid7().'/ringkasan-klinis')->assertForbidden();
    }

    /** Paket ICD-10 SINTETIS untuk uji (bukan kode resmi): dua aktif, satu nonaktif. */
    private function muatIcdSintetis(): void
    {
        DB::table('inti.paket_referensi')->insert(['id' => (string) Str::uuid7(), 'sistem' => 'icd10', 'versi' => 'uji-sintetis', 'sumber' => 'Data sintetis uji',
            'jumlah_kode' => 3, 'sidik_isi' => str_repeat('0', 64), 'dimuat_pada' => now()]);
        foreach ([['ZZUJI1', 'Diagnosis sintetis satu', true], ['ZZUJI2', 'Diagnosis sintetis dua', true], ['ZZUJI3', 'Diagnosis sintetis tiga', false]] as [$kode, $tampilan, $aktif]) {
            DB::table('inti.terminologi')->insert(['id' => (string) Str::uuid7(), 'sistem' => 'icd10', 'kode' => $kode, 'tampilan' => $tampilan,
                'aktif' => $aktif, 'versi_terakhir' => 'uji-sintetis', 'dinonaktifkan_pada' => $aktif ? null : now()]);
        }
    }

    /** @param  list<string>  $subMenu */
    private function akunPemeriksa(array $subMenu = ['rawat_jalan.pemeriksaan']): Pengguna
    {
        $akun = $this->buatPengguna(['pegawai_id' => $this->dokter()->id]);
        $this->tugaskan($akun, $this->buatPeran($subMenu));

        return $akun;
    }

    private function kunjungan(Pasien $pasien, ?JadwalPraktik $jadwal = null): string
    {
        $this->masukSebagai($this->petugasPendaftaran());

        return $this->daftarKunjungan($pasien, $jadwal ?? $this->jadwal)->assertCreated()->json('data.id');
    }

    private function catat(string $kunjungan, string $kode, string $jenis): \Illuminate\Testing\TestResponse
    {
        return $this->minta('POST', "/api/v1/kunjungan/$kunjungan/diagnosis", ['kode' => $kode, 'jenis' => $jenis]);
    }
}
