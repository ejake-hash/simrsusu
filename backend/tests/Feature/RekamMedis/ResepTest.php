<?php

namespace Tests\Feature\RekamMedis;

use App\Modules\Akses\Domain\Cakupan;
use App\Modules\Akses\Models\Izin;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\Akses\Models\PeranIzin;
use App\Modules\Akses\Models\PeranMenu;
use App\Modules\MasterData\Models\JadwalPraktik;
use App\Modules\MasterData\Models\Poliklinik;
use App\Modules\MasterData\Models\UnitOrganisasi;
use App\Modules\Pendaftaran\Models\Pasien;
use App\Modules\RekamMedis\Application\KelolaResep;
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

/** Tiket 2.17-be: e-resep rawat jalan → antrean farmasi (kontrak v0.27.0). Barang dan zat di sini SINTETIS (kode UJI-*). */
class ResepTest extends TestCase
{
    use AksesUji, KlienSpa, KontrakApi, KunjunganUji;

    private const PIN = '482916';

    private const PERAN = ['rawat_jalan.pemeriksaan', 'rawat_jalan.pemeriksaan.resep'];

    private Pegawai $dokter;

    private Pengguna $akunDokter;

    private JadwalPraktik $jadwal;

    private string $depo;

    /** @var array<string, string> */
    private array $barang = [];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->siapkanKunjungan();
        $this->dokter = $this->dokter();
        $this->jadwal = $this->jadwal($this->dokter);
        $this->akunDokter = $this->akunTtd($this->dokter);
        $this->depo = UnitOrganisasi::query()->create(['kode' => 'DEPO-UJI', 'nama' => 'Depo Uji', 'jenis' => 'depo', 'berlaku_dari' => '2026-01-01'])->id;
        foreach (['UJI-A' => 'Zatuji Alfa', 'UJI-B' => 'zatuji beta', 'UJI-AB' => 'ZATUJI  ALFA + Zatuji Gama'] as $kode => $zat) {
            $this->barang[$kode] = $this->barangBaru($kode, $zat);
        }
        $this->barang['UJI-TUTUP'] = $this->barangBaru('UJI-TUTUP', 'Zatuji Delta', 'nonaktif');
    }

    public function test_draf_peringatan_ditanggapi_lalu_sah_masuk_antrean_farmasi(): void
    {
        $pasien = $this->pasien();
        $this->formulariumRs([$this->barang['UJI-A']]);
        DB::table('klinis.alergi')->insert(['id' => (string) Str::uuid7(), 'pasien_id' => $pasien->id, 'zat' => ' zatuji   ALFA', 'jenis' => 'obat',
            'tingkat' => 'berat', 'dibuat_oleh' => $this->akunDokter->id]);
        $k = $this->kunjunganDilayani($pasien);
        $this->masukSebagai($this->akunDokter);

        $buat = $this->tulis($k, ['UJI-A', 'UJI-B'], ['catatan' => 'Catatan resep sintetis'])->assertCreated()
            ->assertJsonPath('data.status', 'draf')->assertJsonPath('data.antrean', null)->assertJsonPath('data.item.0.jumlah', 9)
            ->assertJsonPath('data.dapat', ['ubah' => true, 'sahkan' => true, 'batal' => true]);
        $this->assertSesuaiKontrak($buat, 'POST', '/kunjungan/{id}/resep');
        $id = $buat->json('data.id');
        $itemA = $buat->json('data.item.0.id');
        $this->assertSame([['alergi', $itemA, 'berat'], ['non_fornas', $buat->json('data.item.1.id'), null]],
            array_map(fn ($p) => [$p['jenis'], $p['resep_item_id'], $p['tingkat']], $buat->json('data.peringatan')));
        $this->assertStringContainsString("$itemA:alergi:", $buat->json('data.peringatan.0.kunci'));
        $this->assertSame(0, DB::table('kunjungan.antrean')->where('titik', 'farmasi')->count());

        // Peringatan belum ditanggapi → 409 sebelum PIN diperiksa (PIN salah tidak terhitung).
        $this->mintaDenganVersi('POST', "/api/v1/resep/$id/sahkan", '0', ['pin' => '000999', 'pernyataan_disetujui' => true,
            'tanggapan' => [['kunci' => $buat->json('data.peringatan.0.kunci'), 'alasan' => 'Alergi ringan, sudah dikonfirmasi']]])
            ->assertStatus(409)->assertJsonPath('error.kode', 'peringatan_belum_ditanggapi')->assertJsonCount(1, 'error.rincian')
            ->assertJsonPath('error.rincian.0.jenis', 'non_fornas');
        $this->assertSame(0, DB::table('ttd.kredensial')->where('pengguna_id', $this->akunDokter->id)->value('percobaan_gagal'));
        $this->assertSame('draf', DB::table('farmasi.resep')->where('id', $id)->value('status'));
        $tanggapan = array_map(fn ($p) => ['kunci' => $p['kunci'], 'alasan' => 'Tetap diberikan, alasan sintetis'], $buat->json('data.peringatan'));
        $this->mintaDenganVersi('POST', "/api/v1/resep/$id/sahkan", '0', ['pin' => '000999', 'pernyataan_disetujui' => true, 'tanggapan' => $tanggapan])
            ->assertStatus(422)->assertJsonPath('error.kode', 'pin_salah');
        $this->assertDatabaseHas('farmasi.resep', ['id' => $id, 'status' => 'draf', 'tanda_tangan_id' => null]);

        // Ubah draf (butir diganti, baris lama ditandai, tidak dihapus); kunci peringatan ikut butir baru.
        $ubah = $this->mintaDenganVersi('PATCH', "/api/v1/resep/$id", '0', ['racikan' => true, 'item' => [$this->butir('UJI-A', ['jumlah' => 5])]])
            ->assertOk()->assertJsonPath('data.racikan', true)->assertJsonPath('data.item.0.jumlah', 5)->assertJsonCount(1, 'data.peringatan');
        $this->assertSesuaiKontrak($ubah, 'PATCH', '/resep/{id}');
        $this->assertSame(3, DB::table('farmasi.resep_item')->where('resep_id', $id)->count());
        $this->mintaDenganVersi('PATCH', "/api/v1/resep/$id", '0', ['racikan' => false])->assertStatus(412);

        $sah = $this->mintaDenganVersi('POST', "/api/v1/resep/$id/sahkan", '1', ['pin' => self::PIN, 'pernyataan_disetujui' => true,
            'tanggapan' => [['kunci' => $ubah->json('data.peringatan.0.kunci'), 'alasan' => 'Tetap diberikan, alasan sintetis']]])->assertOk()
            ->assertJsonPath('data.status', 'sah')->assertJsonPath('data.antrean.nomor_tampil', 'F-001')->assertJsonPath('data.antrean.status', 'menunggu')
            ->assertJsonPath('data.dokter.id', $this->dokter->id)->assertJsonPath('data.peringatan.0.tanggapan.alasan', 'Tetap diberikan, alasan sintetis')
            ->assertJsonPath('data.dapat', ['ubah' => false, 'sahkan' => false, 'batal' => true]);
        $this->assertSesuaiKontrak($sah, 'POST', '/resep/{id}/sahkan');
        $this->assertSame(1, DB::table('kunjungan.antrean')->where('titik', 'farmasi')->where('kunjungan_id', $k)->count());
        $this->assertSame(['sah'], array_column($this->minta('GET', "/api/v1/ttd/verifikasi/resep/$id")->assertOk()->json('data.*.verifikasi'), 'status'));

        // Sah tidak bisa diubah atau disahkan ulang.
        $this->mintaDenganVersi('PATCH', "/api/v1/resep/$id", '2', ['racikan' => false])->assertStatus(409)->assertJsonPath('error.kode', 'sudah_disahkan');
        $this->mintaDenganVersi('POST', "/api/v1/resep/$id/sahkan", '2', ['pin' => self::PIN, 'pernyataan_disetujui' => true])
            ->assertStatus(409)->assertJsonPath('error.kode', 'sudah_disahkan');
        $daftar = $this->minta('GET', "/api/v1/kunjungan/$k/resep")->assertOk()->assertJsonCount(1, 'data');
        $this->assertSesuaiKontrak($daftar, 'GET', '/kunjungan/{id}/resep');

        // Isi klinis tidak masuk audit.
        $audit = (string) DB::table('audit.log_aktivitas')->where('aksi', 'like', 'farmasi.resep%')->pluck('sesudah')->implode(' ');
        $this->assertStringNotContainsString('sintetis', $audit);
        $this->assertSame(1, DB::table('audit.log_aktivitas')->where('aksi', 'farmasi.resep_disahkan')->count());
    }

    public function test_dua_poli_satu_antrean_duplikasi_dan_batal(): void
    {
        $pasien = $this->pasien();
        $k1 = $this->kunjunganDilayani($pasien);
        $dokter2 = $this->dokter();
        $akun2 = $this->akunTtd($dokter2);
        $k2 = $this->kunjunganDilayani($pasien, $this->jadwal($dokter2, ['poli_id' => $this->poliLain()->id]));

        $this->masukSebagai($this->akunDokter);
        $r1 = $this->tulis($k1, ['UJI-A'])->assertCreated()->assertJsonCount(0, 'data.peringatan')->json('data.id');
        $sah1 = $this->sahkan($r1)->assertOk();
        $antrean = $sah1->json('data.antrean.id');

        // Resep kedua dari poli lain: duplikasi zat (lintas resep sah, termasuk zat gabungan dengan spasi lain) dan antrean yang sama.
        $this->masukSebagai($akun2);
        $draf = $this->tulis($k2, ['UJI-AB', 'UJI-B'], ['racikan' => true])->assertCreated();
        $this->assertSame(['duplikasi'], array_column($draf->json('data.peringatan'), 'jenis'));
        $this->assertStringContainsString('zatuji alfa', $draf->json('data.peringatan.0.pesan'));
        $r2 = $draf->json('data.id');
        $sah2 = $this->sahkan($r2, $draf->json('data.peringatan'))->assertOk()->assertJsonPath('data.antrean.id', $antrean)->assertJsonPath('data.racikan', true);
        $this->assertSame(1, DB::table('kunjungan.antrean')->where('titik', 'farmasi')->count());
        $this->assertSame($sah2->json('data.peringatan.0.kunci'), $draf->json('data.peringatan.0.kunci'));
        // Draf ketiga tidak masuk antrean apa pun.
        $this->tulis($k2, ['UJI-B'])->assertCreated()->assertJsonPath('data.antrean', null);

        // Farmasi melihat antrean depo terlama dulu; draf tidak terkirim; poli tanpa id.
        $farmasi = $this->akunFarmasi($this->depo);
        $this->masukSebagai($farmasi);
        $depo = $this->minta('GET', "/api/v1/resep?depo_id=$this->depo")->assertOk()->assertJsonPath('data.*.id', [$r1, $r2]);
        $this->assertSesuaiKontrak($depo, 'GET', '/resep');
        $this->assertArrayNotHasKey('id', $depo->json('data.0.poliklinik'));
        $this->assertSesuaiKontrak($this->minta('GET', '/api/v1/depo')->assertOk()->assertJsonPath('data.0.kode', 'DEPO-UJI'), 'GET', '/depo');

        // Batal resep sah oleh bukan dokter jadwal → 403; oleh dokter jadwal → antrean tetap karena r1 masih sah.
        $this->masukSebagai($this->akunDokter);
        $this->minta('POST', "/api/v1/resep/$r2/batal", ['alasan_batal' => 'Salah pilih obat'])->assertForbidden()->assertJsonPath('error.kode', 'bukan_dokter_kunjungan');
        $this->masukSebagai($akun2);
        $batal = $this->minta('POST', "/api/v1/resep/$r2/batal", ['alasan_batal' => 'Salah pilih obat'])->assertOk()->assertJsonPath('data.status', 'batal');
        $this->assertSesuaiKontrak($batal, 'POST', '/resep/{id}/batal');
        $this->assertSame('menunggu', DB::table('kunjungan.antrean')->where('id', $antrean)->value('status'));
        $this->minta('POST', "/api/v1/resep/$r2/batal", ['alasan_batal' => 'Salah pilih obat'])->assertStatus(409);

        // Resep sah terakhir batal → antrean batal; resep sah baru sesudahnya menghidupkan antrean kunjungan itu lagi.
        $this->masukSebagai($this->akunDokter);
        $this->minta('POST', "/api/v1/resep/$r1/batal", ['alasan_batal' => 'Pasien menolak obat'])->assertOk();
        $this->assertSame('batal', DB::table('kunjungan.antrean')->where('id', $antrean)->value('status'));
        $r3 = $this->tulis($k1, ['UJI-B'])->assertCreated()->json('data.id');
        $this->sahkan($r3)->assertOk()->assertJsonPath('data.antrean.id', $antrean)->assertJsonPath('data.antrean.status', 'menunggu');
        $this->masukSebagai($farmasi);
        $this->minta('GET', "/api/v1/resep?depo_id=$this->depo&status=batal")->assertOk()->assertJsonPath('data.*.id', [$r1, $r2]);
    }

    public function test_sahkan_hanya_dokter_jadwal_dengan_sip_berlaku(): void
    {
        $k = $this->kunjunganDilayani($this->pasien());
        $lain = $this->akunTtd($this->dokter());
        $this->masukSebagai($lain);
        $id = $this->tulis($k, ['UJI-B'])->assertCreated()->assertJsonPath('data.dapat.sahkan', false)->json('data.id');
        $this->sahkan($id)->assertForbidden()->assertJsonPath('error.kode', 'bukan_dokter_kunjungan');

        // Penulis bukan dokter jadwal; dokter jadwal boleh mengubah dan mengesahkan draf itu.
        DB::table('sdm.izin_praktik')->where('pegawai_id', $this->dokter->id)->update(['berlaku_sampai' => '2026-01-01']);
        $this->masukSebagai($this->akunDokter);
        $this->sahkan($id)->assertForbidden()->assertJsonPath('error.kode', 'izin_praktik_tidak_berlaku');
        DB::table('sdm.izin_praktik')->where('pegawai_id', $this->dokter->id)->update(['berlaku_sampai' => '2040-01-01']);
        $this->masukSebagai($this->akunDokter);
        $this->sahkan($id)->assertOk();
    }

    public function test_izin_cakupan_status_dan_validasi(): void
    {
        $pasien = $this->pasien();
        $k = $this->kunjungan($pasien); // masih terdaftar
        $this->masukSebagai($this->akunDokter);
        $this->tulis($k, ['UJI-A'])->assertStatus(409)->assertJsonPath('error.kode', 'status_tidak_sesuai');
        DB::table('kunjungan.kunjungan')->where('id', $k)->update(['status' => 'dilayani']);
        $this->tulis($k, ['UJI-TUTUP'])->assertStatus(422)->assertJsonPath('error.rincian.0.kolom', 'item.0.barang_id');
        $this->tulis($k, ['UJI-A', 'UJI-A'])->assertStatus(422);
        $this->tulis($k, ['UJI-A'], ['depo_id' => $this->poli->unit_organisasi_id])->assertStatus(422)->assertJsonPath('error.rincian.0.kolom', 'depo_id');
        $id = $this->tulis($k, ['UJI-A'])->assertCreated()->json('data.id');
        $this->minta('PATCH', "/api/v1/resep/$id", ['racikan' => true])->assertStatus(428);
        $this->mintaDenganVersi('PATCH', "/api/v1/resep/$id", '0', [])->assertStatus(422);

        // Tanpa bagian resep: tidak bisa melihat, menulis, atau mengesahkan.
        $tanpa = $this->buatPengguna(['pegawai_id' => $this->dokter->id]);
        $this->tugaskan($tanpa, $this->buatPeran(['rawat_jalan.pemeriksaan']));
        $this->masukSebagai($tanpa);
        $this->minta('GET', "/api/v1/kunjungan/$k/resep")->assertForbidden();
        $this->tulis($k, ['UJI-B'])->assertForbidden();
        $this->sahkan($id)->assertForbidden();
        $this->minta('GET', '/api/v1/depo')->assertForbidden();

        // Di luar cakupan unit → 403; tidak ada → 404.
        $luar = $this->buatPengguna(['pegawai_id' => $this->dokter->id]);
        $this->tugaskan($luar, $this->buatPeran(self::PERAN), cakupan: new Cakupan('unit', $this->poliLain()->unit_organisasi_id));
        $this->masukSebagai($luar);
        $this->minta('GET', "/api/v1/kunjungan/$k/resep")->assertForbidden();
        $this->tulis($k, ['UJI-B'])->assertForbidden();
        $this->minta('POST', "/api/v1/resep/$id/batal", ['alasan_batal' => 'Tidak jadi diberikan'])->assertForbidden();
        $this->minta('POST', '/api/v1/resep/'.Str::uuid7().'/batal', ['alasan_batal' => 'Tidak jadi diberikan'])->assertNotFound();
        $this->minta('GET', "/api/v1/resep?depo_id=$this->depo")->assertForbidden();

        // Kunjungan selesai: draf tidak bisa disahkan, hanya dibatalkan.
        DB::table('kunjungan.kunjungan')->where('id', $k)->update(['status' => 'selesai']);
        $this->masukSebagai($this->akunDokter);
        $this->sahkan($id)->assertStatus(409)->assertJsonPath('error.kode', 'status_tidak_sesuai');
        $this->minta('POST', "/api/v1/resep/$id/batal", ['alasan_batal' => 'Tidak jadi diberikan'])->assertOk();
    }

    public function test_bagian_resep_efektif_bila_menu_diturunkan_dari_izin_lihat(): void
    {
        $this->assertTrue((bool) DB::table('akses.izin')->where('kode', 'rawat_jalan.pemeriksaan.resep.sahkan')->value('butuh_kewenangan_klinis'));
        // Sama dengan KelolaPeran/AkunSintetisSeeder: peran_menu hanya untuk node yang diberi izin `lihat`.
        $kode = ['rawat_jalan.pemeriksaan.lihat', 'rawat_jalan.pemeriksaan.resep.lihat', 'rawat_jalan.pemeriksaan.resep.input', 'rawat_jalan.pemeriksaan.resep.sahkan'];
        $izin = Izin::query()->whereIn('kode', $kode)->get(['id', 'menu_id', 'jenis_aksi']);
        $this->assertCount(count($kode), $izin);
        $peran = $this->buatPeran([]);
        foreach ($izin->where('jenis_aksi', 'lihat')->pluck('menu_id')->unique() as $menuId) {
            PeranMenu::query()->create(['peran_id' => $peran->id, 'menu_id' => $menuId]);
        }
        foreach ($izin as $i) {
            PeranIzin::query()->create(['peran_id' => $peran->id, 'izin_id' => $i->id]);
        }
        $akun = $this->buatPengguna(['pegawai_id' => $this->dokter->id]);
        $this->tugaskan($akun, $peran);
        $k = $this->kunjunganDilayani($this->pasien());
        $this->masukSebagai($akun);
        $id = $this->tulis($k, ['UJI-B'])->assertCreated()->json('data.id');
        $this->minta('GET', "/api/v1/kunjungan/$k/resep")->assertOk()->assertJsonPath('data.0.id', $id);
    }

    public function test_farmasi_membaca_alergi_hanya_pasien_beresep_di_depo_cakupannya(): void
    {
        $beresep = $this->pasien();
        $lain = $this->pasien();
        $k = $this->kunjunganDilayani($beresep);
        $this->masukSebagai($this->akunDokter);
        $id = $this->tulis($k, ['UJI-B'])->assertCreated()->json('data.id');

        $farmasi = $this->akunFarmasi($this->depo);
        $this->masukSebagai($farmasi);
        $this->minta('GET', "/api/v1/pasien/$beresep->id/alergi")->assertForbidden(); // masih draf
        $this->masukSebagai($this->akunDokter);
        $this->sahkan($id)->assertOk();
        $this->masukSebagai($farmasi);
        $this->assertSesuaiKontrak($this->minta('GET', "/api/v1/pasien/$beresep->id/alergi")->assertOk(), 'GET', '/pasien/{id}/alergi');
        $this->minta('GET', "/api/v1/pasien/$lain->id/alergi")->assertForbidden();
        $this->assertSame(['sah'], array_column($this->minta('GET', "/api/v1/ttd/verifikasi/resep/$id")->assertOk()->json('data.*.verifikasi'), 'status'));

        $depoLain = UnitOrganisasi::query()->create(['kode' => 'DEPO-L', 'nama' => 'Depo L', 'jenis' => 'depo', 'berlaku_dari' => '2026-01-01'])->id;
        $this->masukSebagai($this->akunFarmasi($depoLain));
        $this->minta('GET', "/api/v1/pasien/$beresep->id/alergi")->assertForbidden();
        $this->minta('GET', "/api/v1/ttd/verifikasi/resep/$id")->assertNotFound();
    }

    public function test_poli_sensitif_nama_netral_dan_duplikasi_di_luar_cakupan_tidak_dihitung(): void
    {
        $pasien = $this->pasien();
        $unitS = UnitOrganisasi::query()->create(['kode' => 'POLI-S', 'nama' => 'Poli S', 'jenis' => 'poli', 'berlaku_dari' => '2026-01-01']);
        $poliS = Poliklinik::query()->create(['kode' => 'UJS', 'nama' => 'Poli Sensitif Uji', 'unit_organisasi_id' => $unitS->id]);
        $poliS->forceFill(['nama_netral' => 'Klinik 7', 'antrean_prefiks' => 'K7', 'antrean_area' => 'poli-k7'])->save();
        $dokterS = $this->dokter();
        $akunS = $this->akunTtd($dokterS);
        $kS = $this->kunjunganDilayani($pasien, $this->jadwal($dokterS, ['poli_id' => $poliS->id]));
        $this->masukSebagai($akunS);
        $this->sahkan($this->tulis($kS, ['UJI-A'])->assertCreated()->json('data.id'))->assertOk()->assertJsonPath('data.poliklinik.nama', 'Klinik 7');

        // Dokter bercakupan poli uji saja: resep poli sensitif tidak dihitung duplikasi.
        $umum = $this->buatPengguna(['pegawai_id' => $this->dokter->id]);
        $this->tugaskan($umum, $this->buatPeran(self::PERAN), cakupan: new Cakupan('unit', $this->poli->unit_organisasi_id));
        $k = $this->kunjunganDilayani($pasien);
        $this->masukSebagai($umum);
        $this->tulis($k, ['UJI-A'])->assertCreated()->assertJsonCount(0, 'data.peringatan');
        // Akun bercakupan RS melihat duplikasinya.
        $this->masukSebagai($this->akunDokter);
        $this->tulis($k, ['UJI-A'])->assertCreated()->assertJsonPath('data.peringatan.0.jenis', 'duplikasi');
    }

    public function test_jumlah_hari_dibulatkan_ke_atas(): void
    {
        $this->assertSame(9.0, KelolaResep::jumlah(3, 1, 3));
        $this->assertSame(2.0, KelolaResep::jumlah(3, '0.1', 5)); // 1,5 → 2
        $this->assertSame(1.0, KelolaResep::jumlah(1, '0.1', 10)); // tanpa galat pecahan biner
    }

    private function akunTtd(Pegawai $pegawai): Pengguna
    {
        $akun = $this->buatPengguna(['pegawai_id' => $pegawai->id]);
        $this->tugaskan($akun, $this->buatPeran(self::PERAN));
        $this->masukSebagai($akun);
        $this->minta('POST', '/api/v1/saya/ttd/aktivasi', ['sandi' => self::SANDI_UJI, 'pin' => self::PIN, 'pin_ulang' => self::PIN])->assertSuccessful();

        return $akun;
    }

    private function akunFarmasi(string $depo): Pengguna
    {
        $akun = $this->buatPengguna();
        $this->tugaskan($akun, $this->buatPeran(['farmasi.resep']), cakupan: new Cakupan('unit', $depo));

        return $akun;
    }

    private function poliLain(): Poliklinik
    {
        $unit = UnitOrganisasi::query()->firstOrCreate(['kode' => 'POLI-B'], ['nama' => 'Poli B', 'jenis' => 'poli', 'berlaku_dari' => '2026-01-01']);

        return Poliklinik::query()->firstOrCreate(['kode' => 'UJB'], ['nama' => 'Poli Contoh B', 'unit_organisasi_id' => $unit->id]);
    }

    private function barangBaru(string $kode, string $zat, string $status = 'aktif'): string
    {
        $id = (string) Str::uuid7();
        DB::table('logistik.barang')->insert(['id' => $id, 'kode' => $kode, 'nama' => "Obat sintetis $kode", 'jenis' => 'obat', 'generik_paten' => 'generik',
            'zat_aktif' => $zat, 'satuan_terkecil' => 'tablet', 'status' => $status]);

        return $id;
    }

    /** @param  list<string>  $barang */
    private function formulariumRs(array $barang): string
    {
        $id = (string) Str::uuid7();
        DB::table('farmasi.formularium')->insert(['id' => $id, 'kode' => 'FORM-UJI', 'nama' => 'Formularium uji', 'jenis' => 'rs', 'berlaku_dari' => '2026-01-01']);
        foreach ($barang as $b) {
            DB::table('farmasi.formularium_item')->insert(['id' => (string) Str::uuid7(), 'formularium_id' => $id, 'barang_id' => $b]);
        }

        return $id;
    }

    private function kunjungan(Pasien $pasien, ?JadwalPraktik $jadwal = null): string
    {
        $this->masukSebagai($this->petugasPendaftaran());

        return $this->daftarKunjungan($pasien, $jadwal ?? $this->jadwal)->assertCreated()->json('data.id');
    }

    private function kunjunganDilayani(Pasien $pasien, ?JadwalPraktik $jadwal = null): string
    {
        $k = $this->kunjungan($pasien, $jadwal);
        DB::table('kunjungan.kunjungan')->where('id', $k)->update(['status' => 'dilayani']);

        return $k;
    }

    /** @return array<string, mixed> */
    private function butir(string $kode, array $ganti = []): array
    {
        return ['barang_id' => $this->barang[$kode], 'dosis' => '1 tablet', 'frekuensi_per_hari' => 3, 'jumlah_per_pakai' => 1, 'hari' => 3,
            'aturan_pakai' => 'Sesudah makan', ...$ganti];
    }

    /** @param  list<string>  $kode */
    private function tulis(string $kunjungan, array $kode, array $ganti = []): TestResponse
    {
        return $this->minta('POST', "/api/v1/kunjungan/$kunjungan/resep", [
            'depo_id' => $this->depo, 'item' => array_map(fn ($k) => $this->butir($k), $kode), ...$ganti,
        ]);
    }

    /** @param  list<array<string, mixed>>  $peringatan */
    private function sahkan(string $id, array $peringatan = []): TestResponse
    {
        $versi = (string) DB::table('farmasi.resep')->where('id', $id)->value('revisi');

        return $this->mintaDenganVersi('POST', "/api/v1/resep/$id/sahkan", $versi, ['pin' => self::PIN, 'pernyataan_disetujui' => true,
            'tanggapan' => array_map(fn ($p) => ['kunci' => $p['kunci'], 'alasan' => 'Tetap diberikan, alasan sintetis'], $peringatan)]);
    }
}
