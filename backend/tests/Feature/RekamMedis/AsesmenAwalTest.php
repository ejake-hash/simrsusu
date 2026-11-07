<?php

namespace Tests\Feature\RekamMedis;

use App\Modules\Akses\Domain\Cakupan;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\Antrean\Models\Antrean;
use App\Modules\MasterData\Models\JadwalPraktik;
use App\Modules\MasterData\Models\UnitOrganisasi;
use App\Modules\Pendaftaran\Models\Pasien;
use App\Modules\Sdm\Models\Pegawai;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\AksesUji;
use Tests\Support\KlienSpa;
use Tests\Support\KontrakApi;
use Tests\Support\KunjunganUji;
use Tests\TestCase;

/** Tiket 2.13-be: worklist poli, asesmen awal perawat, alergi (kontrak v0.23.0). Data sintetis. */
class AsesmenAwalTest extends TestCase
{
    use AksesUji, KlienSpa, KontrakApi, KunjunganUji;

    private JadwalPraktik $jadwalA;

    private Pengguna $perawat;

    private const TTV = ['sistolik' => 120, 'diastolik' => 80, 'nadi' => 88, 'napas' => 20, 'suhu' => 36.8, 'berat_badan' => 64, 'tinggi_badan' => 160];

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanKunjungan();
        // Modul 07 Rawat Jalan dinyalakan RS (inti.modul_aktif); instalasi uji hanya memasang modul fase 1.
        DB::table('inti.modul_aktif')->insert(['id' => (string) Str::uuid7(), 'modul_no' => 7]);
        $this->jadwalA = $this->jadwal($this->dokter());
        $this->perawat = $this->akunPerawat();
        $this->tugaskan($this->perawat, $this->buatPeran(['rawat_jalan.worklist', 'rawat_jalan.asesmen_perawat', 'pendaftaran.antrean']));
    }

    private function akunPerawat(): Pengguna
    {
        /** @var Pegawai $pegawai */
        $pegawai = Pegawai::factory()->create(['profesi' => 'perawat']);

        return $this->buatPengguna(['pegawai_id' => $pegawai->id]);
    }

    /** Kunjungan poli lewat petugas pendaftaran; antrean terbit dari KunjunganDibuat. */
    private function kunjungan(?JadwalPraktik $jadwal = null, ?Pasien $pasien = null): Antrean
    {
        $this->masukSebagai($this->petugasPendaftaran());
        $id = $this->daftarKunjungan($pasien ?? $this->pasien(), $jadwal ?? $this->jadwalA)->assertCreated()->json('data.id');

        return Antrean::query()->where('kunjungan_id', $id)->sole();
    }

    private function asesmen(Antrean $a, array $isi = []): TestResponse
    {
        return $this->minta('POST', "/api/v1/kunjungan/{$a->kunjungan_id}/asesmen-awal", $isi);
    }

    public function test_draf_lalu_selesai_menulis_observasi_loinc_dan_memindahkan_ke_tahap_dokter(): void
    {
        $a = $this->kunjungan();
        $this->masukSebagai($this->perawat);
        $draf = $this->asesmen($a, ['keluhan_utama' => 'Batuk tiga hari', 'tanda_vital' => ['berat_badan' => 64, 'tinggi_badan' => 160]])
            ->assertCreated()->assertJsonPath('data.status', 'draf')->assertJsonPath('data.imt', 25)->assertJsonPath('data.penulis.id', $this->perawat->id);
        $this->assertSesuaiKontrak($draf, 'POST', '/kunjungan/{id}/asesmen-awal');
        $id = $draf->json('data.id');
        $this->asesmen($a)->assertStatus(409)->assertJsonPath('error.kode', 'asesmen_sudah_ada');

        $this->mintaDenganVersi('POST', "/api/v1/asesmen-awal/$id/selesai", '0')->assertUnprocessable()->assertJsonPath('error.kode', 'validasi_gagal');
        $ubah = $this->mintaDenganVersi('PATCH', "/api/v1/asesmen-awal/$id", '0', ['tanda_vital' => self::TTV, 'skrining' => ['skala_nyeri' => 3, 'risiko_jatuh' => 'rendah']])
            ->assertOk()->assertJsonPath('data.jumlah_versi', 0)->assertJsonPath('data.skrining.skala_nyeri', 3)->assertJsonPath('data.keluhan_utama', 'Batuk tiga hari');
        $this->assertSesuaiKontrak($ubah, 'PATCH', '/asesmen-awal/{id}');
        $selesai = $this->mintaDenganVersi('POST', "/api/v1/asesmen-awal/$id/selesai", '1')->assertOk()->assertJsonPath('data.status', 'selesai');
        $this->assertSesuaiKontrak($selesai, 'POST', '/asesmen-awal/{id}/selesai');
        $this->assertNotNull($selesai->json('data.batas_perbaikan_pada'));

        $this->assertEqualsCanonicalizing(['8480-6', '8462-4', '8867-4', '9279-1', '8310-5', '29463-7', '8302-2', '39156-5'],
            DB::table('klinis.observasi')->where('catatan_klinis_id', $id)->pluck('kode_loinc')->all());
        $a->refresh();
        $this->assertSame(['dokter', 'menunggu'], [$a->tahap, $a->status]);
        $this->assertSame(2, DB::table('kunjungan.antrean_jejak')->where('antrean_id', $a->id)->where('status', 'menunggu')->count());
        // Audit tanpa isi klinis.
        $audit = $this->assertAuditTerakhir('klinis.asesmen_selesai', $id);
        $this->assertStringNotContainsString('Batuk', (string) $audit->sesudah);

        $baca = $this->minta('GET', "/api/v1/kunjungan/{$a->kunjungan_id}/asesmen-awal")->assertOk()->assertJsonPath('data.tanda_vital.sistolik', 120);
        $this->assertSesuaiKontrak($baca, 'GET', '/kunjungan/{id}/asesmen-awal');
    }

    public function test_perbaikan_sesudah_selesai_berversi_hanya_penulis_dan_batas_dua_kali_24_jam(): void
    {
        $a = $this->kunjungan();
        $this->masukSebagai($this->perawat);
        $id = $this->asesmen($a, ['keluhan_utama' => 'Demam', 'tanda_vital' => self::TTV])->json('data.id');
        $this->mintaDenganVersi('POST', "/api/v1/asesmen-awal/$id/selesai", '0')->assertOk();

        $this->mintaDenganVersi('PATCH', "/api/v1/asesmen-awal/$id", '1', ['tanda_vital' => ['suhu' => 38.2]])->assertUnprocessable();
        $this->mintaDenganVersi('PATCH', "/api/v1/asesmen-awal/$id", '1', ['alasan_perubahan' => 'Salah ketik suhu'])->assertUnprocessable();
        $this->mintaDenganVersi('PATCH', "/api/v1/asesmen-awal/$id", '1', ['tanda_vital' => ['suhu' => 38.2], 'alasan_perubahan' => 'Salah ketik suhu'])
            ->assertOk()->assertJsonPath('data.jumlah_versi', 1)->assertJsonPath('data.tanda_vital.suhu', 38.2);
        $this->assertSame(36.8, (float) json_decode((string) DB::table('klinis.catatan_klinis_versi')->where('catatan_klinis_id', $id)->value('isi'), true)['tanda_vital']['suhu']);
        $this->assertSame(1, DB::table('klinis.observasi')->where('catatan_klinis_id', $id)->where('kode_loinc', '8310-5')->whereNull('dibatalkan_pada')->count());
        $this->assertSame(38.2, (float) DB::table('klinis.observasi')->where('catatan_klinis_id', $id)->where('kode_loinc', '8310-5')->whereNull('dibatalkan_pada')->value('nilai_angka'));
        $this->mintaDenganVersi('PATCH', "/api/v1/asesmen-awal/$id", '1', ['catatan' => 'x', 'alasan_perubahan' => 'Versi lama'])->assertStatus(412);

        $lain = $this->akunPerawat();
        $this->tugaskan($lain, $this->buatPeran(['rawat_jalan.asesmen_perawat']));
        $this->masukSebagai($lain);
        $this->mintaDenganVersi('PATCH', "/api/v1/asesmen-awal/$id", '2', ['catatan' => 'x', 'alasan_perubahan' => 'Bukan penulis'])->assertForbidden();

        $this->travel(49)->hours();
        $this->masukSebagai($this->perawat);
        $this->mintaDenganVersi('PATCH', "/api/v1/asesmen-awal/$id", '2', ['catatan' => 'x', 'alasan_perubahan' => 'Terlambat'])
            ->assertStatus(409)->assertJsonPath('error.kode', 'batas_perbaikan_lewat');
    }

    public function test_worklist_perawat_seluruh_poli_dokter_hanya_pasiennya(): void
    {
        $dokterB = $this->dokter();
        $jadwalB = $this->jadwal($dokterB);
        $a1 = $this->kunjungan();
        $a2 = $this->kunjungan($jadwalB);
        $akunB = $this->akunDokter($dokterB);
        $this->tugaskan($akunB, $this->buatPeran(['rawat_jalan.worklist', 'rawat_jalan.pemeriksaan']));

        $this->masukSebagai($this->perawat);
        $this->minta('POST', '/api/v1/pasien/'.DB::table('kunjungan.kunjungan')->where('id', $a1->kunjungan_id)->value('pasien_id').'/alergi', ['zat' => 'Amoksisilin', 'jenis' => 'obat'])->assertCreated();
        $id = $this->asesmen($a1, ['keluhan_utama' => 'Pusing', 'tanda_vital' => self::TTV])->json('data.id');
        $this->mintaDenganVersi('POST', "/api/v1/asesmen-awal/$id/selesai", '0')->assertOk();

        $q = '?tanggal='.self::SENIN.'&poli_id='.$this->poli->id;
        $semua = $this->minta('GET', "/api/v1/rawat-jalan/worklist$q")->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.tahap', 'dokter')->assertJsonPath('data.0.asesmen_awal.status', 'selesai')->assertJsonPath('data.0.ada_alergi', true)
            ->assertJsonPath('data.1.tahap', 'perawat')->assertJsonPath('data.1.asesmen_awal', null)->assertJsonPath('data.1.ada_alergi', false);
        $this->assertSesuaiKontrak($semua, 'GET', '/rawat-jalan/worklist');
        $this->minta('GET', "/api/v1/rawat-jalan/worklist$q&tahap=perawat")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.antrean_id', $a2->id);

        $this->masukSebagai($akunB);
        $this->minta('GET', "/api/v1/rawat-jalan/worklist$q")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.antrean_id', $a2->id);
        // Dokter membaca asesmen perawat lewat izin pemeriksaan.
        $this->minta('GET', "/api/v1/kunjungan/{$a1->kunjungan_id}/asesmen-awal")->assertOk();

        // Dokter tanpa jadwal hari itu (mis. pengganti) tetap tidak melihat pasien dokter lain.
        $akunC = $this->akunDokter($this->dokter());
        $this->tugaskan($akunC, $this->buatPeran(['rawat_jalan.worklist']));
        $this->masukSebagai($akunC);
        $this->minta('GET', "/api/v1/rawat-jalan/worklist$q")->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_alergi_dicatat_sekali_dibaca_dokter_dan_dibatalkan_tanpa_isi_di_audit(): void
    {
        $pasien = $this->pasien();
        $this->masukSebagai($this->perawat);
        $catat = $this->minta('POST', "/api/v1/pasien/{$pasien->id}/alergi", ['zat' => 'Amoksisilin', 'jenis' => 'obat', 'reaksi' => 'Ruam', 'tingkat' => 'sedang'])
            ->assertCreated()->assertJsonPath('data.dicatat_oleh.id', $this->perawat->id);
        $this->assertSesuaiKontrak($catat, 'POST', '/pasien/{id}/alergi');
        $this->minta('POST', "/api/v1/pasien/{$pasien->id}/alergi", ['zat' => ' amoksi silin', 'jenis' => 'obat'])
            ->assertStatus(409)->assertJsonPath('error.kode', 'alergi_sudah_tercatat');
        $audit = $this->assertAuditTerakhir('klinis.alergi_dicatat', $catat->json('data.id'));
        $this->assertStringNotContainsString('Amoksisilin', (string) $audit->sesudah);

        $dokter = $this->akunDokter($this->dokter());
        $this->tugaskan($dokter, $this->buatPeran(['rawat_jalan.pemeriksaan']));
        $this->masukSebagai($dokter);
        $daftar = $this->minta('GET', "/api/v1/pasien/{$pasien->id}/alergi")->assertOk()->assertJsonCount(1, 'data');
        $this->assertSesuaiKontrak($daftar, 'GET', '/pasien/{id}/alergi');
        $this->minta('POST', "/api/v1/pasien/{$pasien->id}/alergi", ['zat' => 'Udang', 'jenis' => 'makanan'])->assertForbidden();

        $this->masukSebagai($this->perawat);
        $batal = $this->minta('POST', '/api/v1/alergi/'.$catat->json('data.id').'/batal', ['alasan' => 'Salah pasien saat dicatat'])
            ->assertOk()->assertJsonPath('data.status', 'batal');
        $this->assertSesuaiKontrak($batal, 'POST', '/alergi/{id}/batal');
        $this->minta('POST', '/api/v1/alergi/'.$catat->json('data.id').'/batal', ['alasan' => 'Ulang batal'])->assertStatus(409);
        $this->minta('GET', "/api/v1/pasien/{$pasien->id}/alergi")->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_tanpa_izin_di_luar_cakupan_dan_kunjungan_batal_ditolak(): void
    {
        $a = $this->kunjungan();
        $tanpa = $this->akunPerawat();
        $this->tugaskan($tanpa, $this->buatPeran(['rawat_jalan.worklist']));
        $this->masukSebagai($tanpa);
        $tolak = $this->asesmen($a, ['keluhan_utama' => 'x'])->assertForbidden()->assertJsonPath('error.kode', 'akses_ditolak');
        $this->assertSesuaiKontrak($tolak, 'POST', '/kunjungan/{id}/asesmen-awal');
        $this->minta('GET', "/api/v1/kunjungan/{$a->kunjungan_id}/asesmen-awal")->assertForbidden();

        $unitLain = UnitOrganisasi::query()->create(['kode' => 'POLI-LAIN', 'nama' => 'Poli Lain', 'jenis' => 'poli', 'berlaku_dari' => '2026-01-01']);
        $luar = $this->akunPerawat();
        $this->tugaskan($luar, $this->buatPeran(['rawat_jalan.worklist', 'rawat_jalan.asesmen_perawat']), cakupan: new Cakupan('unit', $unitLain->id));
        $this->masukSebagai($luar);
        $this->asesmen($a, ['keluhan_utama' => 'x'])->assertForbidden();
        $this->minta('GET', '/api/v1/rawat-jalan/worklist?tanggal='.self::SENIN.'&poli_id='.$this->poli->id)->assertForbidden();

        $this->masukSebagai($this->perawat);
        $draf = $this->asesmen($a, ['keluhan_utama' => 'x'])->assertCreated()->json('data.id');
        $this->masukSebagai($this->petugasPendaftaran());
        $this->minta('POST', "/api/v1/kunjungan/{$a->kunjungan_id}/batal", ['alasan_batal' => 'Pasien pulang'])->assertOk();
        $this->masukSebagai($this->perawat);
        $this->mintaDenganVersi('PATCH', "/api/v1/asesmen-awal/$draf", '0', ['catatan' => 'y'])->assertStatus(409)->assertJsonPath('error.kode', 'status_tidak_sesuai');
        $b = $this->kunjungan();
        $this->masukSebagai($this->petugasPendaftaran());
        $this->minta('POST', "/api/v1/kunjungan/{$b->kunjungan_id}/batal", ['alasan_batal' => 'Pasien pulang'])->assertOk();
        $this->masukSebagai($this->perawat);
        $this->asesmen($b, ['keluhan_utama' => 'x'])->assertStatus(409)->assertJsonPath('error.kode', 'status_tidak_sesuai');
    }
}
