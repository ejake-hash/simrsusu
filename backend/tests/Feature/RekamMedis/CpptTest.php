<?php

namespace Tests\Feature\RekamMedis;

use App\Modules\Akses\Domain\Cakupan;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\MasterData\Models\JadwalPraktik;
use App\Modules\MasterData\Models\Poliklinik;
use App\Modules\MasterData\Models\UnitOrganisasi;
use App\Modules\Pendaftaran\Models\Pasien;
use App\Modules\RekamMedis\Application\KelolaAsesmenAwal;
use App\Modules\RekamMedis\Application\KelolaCppt;
use App\Modules\Sdm\Models\Pegawai;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\AksesUji;
use Tests\Support\KlienSpa;
use Tests\Support\KontrakApi;
use Tests\Support\KunjunganUji;
use Tests\TestCase;

/** Tiket 2.14-be: CPPT episode, pengesahan PIN T1, perbaikan berversi, adendum, label (kontrak v0.24.0). Data sintetis. */
class CpptTest extends TestCase
{
    use AksesUji, KlienSpa, KontrakApi, KunjunganUji;

    private const PIN = '482916';

    private const SOAP = ['subjektif' => 'Nyeri ulu hati dua hari', 'objektif' => 'Nyeri tekan epigastrium', 'asesmen' => 'Dispepsia', 'plan' => 'Edukasi diet'];

    private Pegawai $dokter;

    private Pengguna $akunDokter;

    private JadwalPraktik $jadwalA;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->siapkanKunjungan();
        DB::table('inti.modul_aktif')->insert(['id' => (string) Str::uuid7(), 'modul_no' => 7]);
        $this->dokter = $this->dokter();
        $this->jadwalA = $this->jadwal($this->dokter);
        $this->akunDokter = $this->akunKlinis($this->dokter);
    }

    public function test_draf_disahkan_pin_penulis_dan_linimasa_dua_poli_satu_episode(): void
    {
        $pasien = $this->pasien();
        $kunjunganA = $this->kunjungan($pasien, $this->jadwalA);
        $kunjunganB = $this->kunjungan($pasien, $this->jadwalPoliLain());

        $this->masukSebagai($this->akunDokter);
        $draf = $this->tulis($kunjunganA, self::SOAP)->assertCreated()->assertJsonPath('data.status', 'draf')
            ->assertJsonPath('data.profesi', 'dokter')->assertJsonPath('data.tatanan', 'rj')->assertJsonPath('data.dapat', ['ubah' => true, 'sahkan' => true, 'adendum' => false]);
        $this->assertSesuaiKontrak($draf, 'POST', '/kunjungan/{id}/cppt');
        $id = $draf->json('data.id');

        // PIN salah tidak mengubah status; hanya penulis yang mengesahkan.
        $this->mintaDenganVersi('POST', "/api/v1/cppt/$id/sahkan", '0', ['pin' => '000999', 'pernyataan_disetujui' => true])
            ->assertStatus(422)->assertJsonPath('error.kode', 'pin_salah');
        $this->assertSame('draf', DB::table('klinis.catatan_klinis')->where('id', $id)->value('status'));
        $this->assertSame(1, DB::table('ttd.kredensial')->where('pengguna_id', $this->akunDokter->id)->value('percobaan_gagal'));
        $lain = $this->akunKlinis($this->dokter());
        $this->masukSebagai($lain);
        $this->mintaDenganVersi('POST', "/api/v1/cppt/$id/sahkan", '0', ['pin' => self::PIN, 'pernyataan_disetujui' => true])
            ->assertForbidden()->assertJsonPath('error.kode', 'akses_ditolak');
        // Draf orang lain tidak ada di linimasa maupun GET.
        $this->minta('GET', "/api/v1/cppt/$id")->assertNotFound();

        $this->masukSebagai($this->akunDokter);
        $sah = $this->mintaDenganVersi('POST', "/api/v1/cppt/$id/sahkan", '0', ['pin' => self::PIN, 'pernyataan_disetujui' => true])
            ->assertJsonPath('error', null)->assertOk()->assertJsonPath('data.status', 'sah')->assertJsonPath('data.dapat.sahkan', false);
        $this->assertSesuaiKontrak($sah, 'POST', '/cppt/{id}/sahkan');
        $this->assertNotNull($sah->json('data.batas_perbaikan_pada'));
        $this->mintaDenganVersi('POST', "/api/v1/cppt/$id/sahkan", '1', ['pernyataan_disetujui' => true])->assertStatus(409)->assertJsonPath('error.kode', 'sudah_disahkan');
        $verifikasi = $this->minta('GET', "/api/v1/ttd/verifikasi/catatan_klinis/$id")->assertOk();
        $this->assertSame(['sah'], $verifikasi->json('data.*.verifikasi.status'));

        // Poli kedua hari yang sama: satu episode, satu linimasa, terbaru dulu.
        $this->travel(5)->minutes();
        $kedua = $this->tulis($kunjunganB, ['subjektif' => 'Kontrol poli lain'])->assertCreated();
        $episode = DB::table('kunjungan.kunjungan')->where('id', $kunjunganB)->value('episode_id');
        $this->assertSame($episode, DB::table('kunjungan.kunjungan')->where('id', $kunjunganA)->value('episode_id'));
        $linimasa = $this->minta('GET', "/api/v1/episode/$episode/cppt")->assertOk();
        $this->assertSesuaiKontrak($linimasa, 'GET', '/episode/{id}/cppt');
        $this->assertSame([$kedua->json('data.id'), $id], $linimasa->json('data.*.id'));
        $this->assertSame([], $this->minta('GET', "/api/v1/episode/$episode/cppt?profesi=perawat")->assertOk()->json('data'));

        // Dokter lain: hanya entri sah, lintas episode lewat pasien.
        $this->masukSebagai($lain);
        $this->assertSame([$id], $this->minta('GET', "/api/v1/pasien/{$pasien->id}/cppt")->assertOk()->json('data.*.id'));
    }

    public function test_perbaikan_sah_berversi_ditandatangani_ulang_lalu_adendum_sesudah_batas(): void
    {
        $kunjungan = $this->kunjungan($this->pasien(), $this->jadwalA);
        $this->masukSebagai($this->akunDokter);
        $id = $this->tulis($kunjungan, self::SOAP)->json('data.id');
        $this->mintaDenganVersi('POST', "/api/v1/cppt/$id/sahkan", '0', ['pin' => self::PIN, 'pernyataan_disetujui' => true])->assertOk();

        $this->mintaDenganVersi('PATCH', "/api/v1/cppt/$id", '1', ['asesmen' => 'Dispepsia fungsional'])->assertStatus(422)
            ->assertJsonPath('error.kode', 'validasi_gagal');
        $this->mintaDenganVersi('PATCH', "/api/v1/cppt/$id", '1', ['alasan_perubahan' => 'Hanya alasan', 'pernyataan_disetujui' => true])->assertStatus(422);
        $this->mintaDenganVersi('POST', "/api/v1/cppt/$id/adendum", '1', ['isi' => 'Tambahan', 'alasan' => 'Hasil lab menyusul', 'pernyataan_disetujui' => true])
            ->assertStatus(409)->assertJsonPath('error.kode', 'masih_bisa_diperbaiki');
        // Sesi tanda tangan masih berlaku: PIN boleh kosong.
        $ubah = $this->mintaDenganVersi('PATCH', "/api/v1/cppt/$id", '1', ['asesmen' => 'Dispepsia fungsional', 'alasan_perubahan' => 'Koreksi diagnosis kerja', 'pernyataan_disetujui' => true])
            ->assertOk()->assertJsonPath('data.jumlah_versi', 1)->assertJsonPath('data.isi.asesmen', 'Dispepsia fungsional');
        $this->assertSesuaiKontrak($ubah, 'PATCH', '/cppt/{id}');
        $this->assertSame('Dispepsia', json_decode((string) DB::table('klinis.catatan_klinis_versi')->where('catatan_klinis_id', $id)->value('isi'), true)['isi']['asesmen']);
        $this->assertSame(['isi_berubah', 'sah'], $this->minta('GET', "/api/v1/ttd/verifikasi/catatan_klinis/$id")->json('data.*.verifikasi.status'));

        $this->travel(49)->hours();
        $this->masukSebagai($this->akunDokter);
        $this->mintaDenganVersi('PATCH', "/api/v1/cppt/$id", '2', ['plan' => 'Ubah', 'alasan_perubahan' => 'Terlambat', 'pin' => self::PIN, 'pernyataan_disetujui' => true])
            ->assertStatus(409)->assertJsonPath('error.kode', 'batas_perbaikan_lewat');
        $adendum = $this->mintaDenganVersi('POST', "/api/v1/cppt/$id/adendum", '2', ['isi' => 'H. pylori positif', 'alasan' => 'Hasil lab menyusul', 'pin' => self::PIN, 'pernyataan_disetujui' => true])
            ->assertCreated()->assertJsonPath('data.adendum.0.isi', 'H. pylori positif')->assertJsonPath('data.dapat.adendum', true)->assertJsonPath('data.versi', '3');
        $this->assertSesuaiKontrak($adendum, 'POST', '/cppt/{id}/adendum');
        $adendumId = $adendum->json('data.adendum.0.id');
        $this->assertSame(['sah'], $this->minta('GET', "/api/v1/ttd/verifikasi/catatan_klinis_adendum/$adendumId")->json('data.*.verifikasi.status'));
        // Isi klinis tidak masuk audit.
        $audit = DB::table('audit.log_aktivitas')->where('aksi', 'like', 'klinis.cppt_%')->get(['sebelum', 'sesudah'])->toJson();
        $this->assertStringNotContainsString('pylori', $audit);
        $this->assertStringNotContainsString('Dispepsia', $audit);
    }

    public function test_label_terbatas_bagi_bukan_penulis_dan_keluar_dari_linimasa_berfilter(): void
    {
        $pasien = $this->pasien();
        $kunjungan = $this->kunjungan($pasien, $this->jadwalA);
        $this->masukSebagai($this->akunDokter);
        $berlabel = $this->tulis($kunjungan, [...self::SOAP, 'label' => 'psikiatri'])->assertCreated()->assertJsonPath('data.label', 'psikiatri')->json('data.id');
        $this->mintaDenganVersi('POST', "/api/v1/cppt/$berlabel/sahkan", '0', ['pin' => self::PIN, 'pernyataan_disetujui' => true])->assertOk();
        $biasa = $this->tulis($kunjungan, ['subjektif' => 'Catatan biasa'])->json('data.id');
        $this->mintaDenganVersi('POST', "/api/v1/cppt/$biasa/sahkan", '0', ['pernyataan_disetujui' => true])->assertOk();
        $this->tulis($kunjungan, ['label' => 'tidak_ada', 'subjektif' => 'x'])->assertStatus(422);

        $lain = $this->akunKlinis($this->dokter());
        $this->masukSebagai($lain);
        $episode = DB::table('kunjungan.kunjungan')->where('id', $kunjungan)->value('episode_id');
        $linimasa = $this->minta('GET', "/api/v1/episode/$episode/cppt")->assertOk();
        $this->assertSesuaiKontrak($linimasa, 'GET', '/episode/{id}/cppt');
        $terbatas = collect($linimasa->json('data'))->firstWhere('id', $berlabel);
        $this->assertSame(['id', 'terbatas', 'dibuat_pada'], array_keys($terbatas));
        $this->assertSame([$biasa], $this->minta('GET', "/api/v1/episode/$episode/cppt?profesi=dokter")->json('data.*.id'));
        $this->minta('GET', "/api/v1/cppt/$berlabel")->assertOk()->assertExactJson(['data' => ['terbatas' => true]]);
        $this->minta('GET', "/api/v1/ttd/verifikasi/catatan_klinis/$berlabel")->assertNotFound();

        // Asesmen awal berlabel: Terbatas bagi pembaca lain.
        $perawat = $this->akunKlinis($this->pegawai('perawat'), ['rawat_jalan.asesmen_perawat']);
        $this->masukSebagai($perawat);
        $this->minta('POST', "/api/v1/kunjungan/$kunjungan/asesmen-awal", ['keluhan_utama' => 'Cemas', 'label' => 'psikiatri'])->assertCreated()
            ->assertJsonPath('data.label', 'psikiatri');
        $this->masukSebagai($lain);
        $this->minta('GET', "/api/v1/kunjungan/$kunjungan/asesmen-awal")->assertOk()->assertExactJson(['data' => ['terbatas' => true]]);
    }

    public function test_asesmen_awal_ditandatangani_penulis_dan_perlu_ttd_ulang_sesudah_perbaikan(): void
    {
        $kunjungan = $this->kunjungan($this->pasien(), $this->jadwalA);
        $perawat = $this->akunKlinis($this->pegawai('perawat'), ['rawat_jalan.asesmen_perawat']);
        $this->masukSebagai($perawat);
        $id = $this->minta('POST', "/api/v1/kunjungan/$kunjungan/asesmen-awal", ['keluhan_utama' => 'Batuk',
            'tanda_vital' => ['sistolik' => 120, 'diastolik' => 80, 'nadi' => 80, 'napas' => 20, 'suhu' => 36.5]])->json('data.id');
        $ttd = fn () => $this->minta('POST', '/api/v1/ttd/tandatangani', ['objek' => [['jenis' => 'catatan_klinis', 'id' => $id]], 'pin' => self::PIN, 'pernyataan_disetujui' => true]);
        $ttd()->assertStatus(422)->assertJsonPath('error.rincian.0.kode', 'belum_diputuskan');
        $this->mintaDenganVersi('POST', "/api/v1/asesmen-awal/$id/selesai", '0')->assertOk()->assertJsonPath('data.ditandatangani_pada', null);
        $ttd()->assertOk();
        $this->assertNotNull($this->minta('GET', "/api/v1/kunjungan/$kunjungan/asesmen-awal")->json('data.ditandatangani_pada'));
        $this->mintaDenganVersi('PATCH', "/api/v1/asesmen-awal/$id", '1', ['keluhan_utama' => 'Batuk berdahak', 'alasan_perubahan' => 'Koreksi keluhan'])
            ->assertOk()->assertJsonPath('data.ditandatangani_pada', null);

        // CPPT tidak ditandatangani lewat jalur umum.
        $this->masukSebagai($this->akunDokter);
        $cppt = $this->tulis($kunjungan, self::SOAP)->json('data.id');
        $this->minta('POST', '/api/v1/ttd/tandatangani', ['objek' => [['jenis' => 'catatan_klinis', 'id' => $cppt]], 'pin' => self::PIN, 'pernyataan_disetujui' => true])
            ->assertStatus(422)->assertJsonPath('error.rincian.0.kode', 'belum_diputuskan');
    }

    public function test_profesi_tidak_dikenali_tanpa_izin_dan_di_luar_cakupan_ditolak(): void
    {
        $kunjungan = $this->kunjungan($this->pasien(), $this->jadwalA);
        $this->masukSebagai($this->akunKlinis($this->pegawai('non_kesehatan')));
        $this->tulis($kunjungan, self::SOAP)->assertForbidden()->assertJsonPath('error.kode', 'profesi_tidak_dikenali');

        $tanpaIzin = $this->buatPengguna(['pegawai_id' => $this->pegawai('dokter')->id]);
        $this->tugaskan($tanpaIzin, $this->buatPeran(['beranda']));
        $this->masukSebagai($tanpaIzin);
        $this->tulis($kunjungan, self::SOAP)->assertForbidden();
        $episode = DB::table('kunjungan.kunjungan')->where('id', $kunjungan)->value('episode_id');
        $this->minta('GET', "/api/v1/episode/$episode/cppt")->assertForbidden();

        $unitLain = UnitOrganisasi::query()->create(['kode' => 'POLI-LAIN', 'nama' => 'Poli Lain', 'jenis' => 'poli', 'berlaku_dari' => '2026-01-01']);
        $luar = $this->buatPengguna(['pegawai_id' => $this->pegawai('dokter')->id]);
        $this->tugaskan($luar, $this->buatPeran(['rawat_jalan.cppt']), cakupan: new Cakupan('unit', $unitLain->id));
        $this->masukSebagai($luar);
        $this->tulis($kunjungan, self::SOAP)->assertForbidden();
        $this->minta('GET', "/api/v1/episode/$episode/cppt")->assertForbidden();
    }

    /**
     * Tinjauan #191: penulis membaca episode dari baris kunjungan yang dikunci di dalam transaksi, bukan dari objek yang
     * dibaca controller sebelumnya. Objek lama di sini meniru kunjungan IGD yang dipindah episodenya (BentukEpisodeIgd)
     * di antara baca controller dan transaksi.
     */
    public function test_cppt_dan_asesmen_memakai_episode_terkini_dari_baris_terkunci(): void
    {
        $id = $this->kunjungan($this->pasien(), $this->jadwalA);
        $lama = DB::table('kunjungan.kunjungan')->where('id', $id)->first(['id', 'episode_id', 'pasien_id', 'status']);
        $baru = (string) Str::uuid7();
        DB::table('kunjungan.episode')->insert(['id' => $baru, 'pasien_id' => $lama->pasien_id, 'jenis' => 'igd_lebih_6_jam', 'mulai_tanggal' => self::SENIN]);
        DB::table('kunjungan.kunjungan')->where('id', $id)->update(['episode_id' => $baru]);
        $unit = $this->poli->unit_organisasi_id;

        $cppt = app(KelolaCppt::class)->buat($this->akunDokter, $lama, $unit, ['subjektif' => 'Uji sintetis']);
        $asesmen = app(KelolaAsesmenAwal::class)->buat($this->akunDokter, $lama, $unit, ['keluhan_utama' => 'Uji sintetis']);

        $this->assertSame([$baru, $baru], [$cppt->episode_id, $asesmen->episode_id]);
        $this->assertSame(0, DB::table('klinis.catatan_klinis')->where('episode_id', $lama->episode_id)->count());
    }

    private function pegawai(string $profesi): Pegawai
    {
        /** @var Pegawai $pegawai */
        $pegawai = Pegawai::factory()->create(['profesi' => $profesi]);

        return $pegawai;
    }

    /** @param  list<string>  $subMenu */
    private function akunKlinis(Pegawai $pegawai, array $subMenu = ['rawat_jalan.cppt', 'rawat_jalan.pemeriksaan']): Pengguna
    {
        $akun = $this->buatPengguna(['pegawai_id' => $pegawai->id]);
        $this->tugaskan($akun, $this->buatPeran($subMenu));
        $this->masukSebagai($akun);
        $this->minta('POST', '/api/v1/saya/ttd/aktivasi', ['sandi' => self::SANDI_UJI, 'pin' => self::PIN, 'pin_ulang' => self::PIN])->assertSuccessful();

        return $akun;
    }

    private function jadwalPoliLain(): JadwalPraktik
    {
        $unit = UnitOrganisasi::query()->create(['kode' => 'POLI-B', 'nama' => 'Poli B', 'jenis' => 'poli', 'berlaku_dari' => '2026-01-01']);
        $poli = Poliklinik::query()->create(['kode' => 'UJB', 'nama' => 'Poli Contoh B', 'unit_organisasi_id' => $unit->id]);

        return $this->jadwal($this->dokter(), ['poli_id' => $poli->id]);
    }

    private function kunjungan(Pasien $pasien, JadwalPraktik $jadwal): string
    {
        $this->masukSebagai($this->petugasPendaftaran());

        return $this->daftarKunjungan($pasien, $jadwal)->assertCreated()->json('data.id');
    }

    /** @param  array<string, mixed>  $isi */
    private function tulis(string $kunjungan, array $isi): TestResponse
    {
        return $this->minta('POST', "/api/v1/kunjungan/$kunjungan/cppt", $isi);
    }
}
