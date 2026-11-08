<?php

namespace Tests\Feature\RekamMedis;

use App\Modules\Akses\Domain\Cakupan;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\MasterData\Models\JadwalPraktik;
use App\Modules\MasterData\Models\Poliklinik;
use App\Modules\MasterData\Models\UnitOrganisasi;
use App\Modules\Pendaftaran\Models\Pasien;
use App\Modules\RekamMedis\Events\PermintaanPemeriksaanDibuat;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\AksesUji;
use Tests\Support\KlienSpa;
use Tests\Support\KontrakApi;
use Tests\Support\KunjunganUji;
use Tests\TestCase;

/** Tiket 2.16-be: order penunjang bersama (kontrak v0.26.0). Butir tarif di sini SINTETIS (kode UJI-*). */
class PermintaanPemeriksaanTest extends TestCase
{
    use AksesUji, KlienSpa, KontrakApi, KunjunganUji;

    private const PERAN = ['rawat_jalan.pemeriksaan', 'rawat_jalan.pemeriksaan.order', 'master_data.tarif'];

    private JadwalPraktik $jadwal;

    private Pengguna $dokterA;

    private Pengguna $dokterB;

    /** @var array<string, string> */
    private array $tarif = [];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->siapkanKunjungan();
        $this->jadwal = $this->jadwal($this->dokter());
        $this->dokterA = $this->akun();
        $this->dokterB = $this->akun();
        foreach (['UJI-LAB-1' => 'lab', 'UJI-LAB-2' => 'lab', 'UJI-RAD-1' => 'rad', 'UJI-NON' => null] as $kode => $kategori) {
            $this->tarif[$kode] = $this->butir($kode, $kategori);
        }
        $this->tarif['UJI-LAB-TUTUP'] = $this->butir('UJI-LAB-TUTUP', 'lab', 'nonaktif');
    }

    public function test_pesan_terikat_kunjungan_dan_episode_lalu_batal(): void
    {
        Event::fake([PermintaanPemeriksaanDibuat::class]);
        $k = $this->kunjunganDilayani($this->pasien());
        $this->masukSebagai($this->dokterA);

        $pesan = $this->pesan($k, ['UJI-LAB-1', 'UJI-LAB-2'], ['prioritas' => 'cito'])->assertCreated()
            ->assertJsonPath('data.status', 'dipesan')->assertJsonPath('data.prioritas', 'cito')
            ->assertJsonPath('data.episode_id', DB::table('kunjungan.kunjungan')->where('id', $k)->value('episode_id'))
            ->assertJsonPath('data.item.0.kode_item', 'UJI-LAB-1')->assertJsonPath('data.dapat.batal', true);
        $this->assertSesuaiKontrak($pesan, 'POST', '/kunjungan/{id}/permintaan-pemeriksaan');
        Event::assertDispatched(PermintaanPemeriksaanDibuat::class, fn ($e) => $e->permintaanId === $pesan->json('data.id'));
        // Indikasi tidak masuk audit.
        $this->assertStringNotContainsString('Indikasi sintetis', (string) DB::table('audit.log_aktivitas')->where('aksi', 'like', 'permintaan.%')->pluck('sesudah')->implode(' '));

        $episodeAwal = $pesan->json('data.episode_id');
        $daftar = $this->minta('GET', "/api/v1/episode/$episodeAwal/permintaan-pemeriksaan")->assertOk()->assertJsonCount(1, 'data');
        $this->assertSesuaiKontrak($daftar, 'GET', '/episode/{id}/permintaan-pemeriksaan');
        // Keterikatan disimpan di order, tidak dibaca ulang dari kunjungan (7.00.5 butir 1).
        $episodeLain = DB::table('kunjungan.kunjungan')->where('id', $this->kunjungan($this->pasien()))->value('episode_id');
        DB::table('kunjungan.kunjungan')->where('id', $k)->update(['episode_id' => $episodeLain]);
        $this->assertSame($episodeAwal, DB::table('permintaan.permintaan_pemeriksaan')->where('id', $pesan->json('data.id'))->value('episode_id'));
        DB::table('kunjungan.kunjungan')->where('id', $k)->update(['episode_id' => $episodeAwal]);
        $this->masukSebagai($this->dokterA);

        // Jenis/kategori salah, butir nonaktif, butir ganda di satu permintaan, indikasi kosong → 422.
        $this->pesan($k, ['UJI-RAD-1'])->assertStatus(422)->assertJsonPath('error.rincian.0.kolom', 'item.0.tarif_id');
        $this->pesan($k, ['UJI-NON'])->assertStatus(422);
        $this->pesan($k, ['UJI-LAB-TUTUP'])->assertStatus(422);
        $this->pesan($k, ['UJI-RAD-1', 'UJI-RAD-1'], ['jenis' => 'rad'])->assertStatus(422);
        $this->pesan($k, ['UJI-RAD-1'], ['jenis' => 'rad', 'diagnosis_kerja' => ''])->assertStatus(422);

        $id = $pesan->json('data.id');
        $this->minta('POST', "/api/v1/permintaan-pemeriksaan/$id/batal", ['alasan_batal' => 'x'])->assertStatus(422);
        $batal = $this->minta('POST', "/api/v1/permintaan-pemeriksaan/$id/batal", ['alasan_batal' => 'Salah pilih pemeriksaan'])->assertOk()
            ->assertJsonPath('data.status', 'batal')->assertJsonPath('data.item.0.status', 'batal')->assertJsonPath('data.dapat.batal', false);
        $this->assertSesuaiKontrak($batal, 'POST', '/permintaan-pemeriksaan/{id}/batal');
        $this->assertSame(1, DB::table('permintaan.permintaan_pemeriksaan')->where('id', $id)->count()); // tidak dihapus
        $this->minta('POST', "/api/v1/permintaan-pemeriksaan/$id/batal", ['alasan_batal' => 'Salah pilih pemeriksaan'])
            ->assertStatus(409)->assertJsonPath('error.kode', 'status_tidak_sesuai');
        $this->minta('GET', "/api/v1/episode/$episodeAwal/permintaan-pemeriksaan?status=dipesan")->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_order_ganda_lintas_dokter_bisa_dilanjutkan_dengan_alasan(): void
    {
        $pasien = $this->pasien();
        $k1 = $this->kunjunganDilayani($pasien);
        $this->masukSebagai($this->dokterA);
        $this->pesan($k1, ['UJI-LAB-1'])->assertCreated();
        // Pemesan sama (klik ganda) juga tertangkap.
        $this->pesan($k1, ['UJI-LAB-1'])->assertStatus(409);

        $this->masukSebagai($this->dokterB);
        $ganda = $this->pesan($k1, ['UJI-LAB-2', 'UJI-LAB-1'])->assertStatus(409)->assertJsonPath('error.kode', 'order_ganda')
            ->assertJsonCount(1, 'error.rincian')->assertJsonPath('error.rincian.0.kolom', 'item.1.tarif_id')
            ->assertJsonPath('error.rincian.0.tarif_id', $this->tarif['UJI-LAB-1']);
        $this->assertArrayNotHasKey('pemesan', $ganda->json('error.rincian.0'));
        $this->assertSame(1, DB::table('permintaan.permintaan_pemeriksaan')->count());
        $this->pesan($k1, ['UJI-LAB-2', 'UJI-LAB-1'], ['alasan_ganda' => 'Ulang karena sampel lisis'])->assertCreated()
            ->assertJsonPath('data.alasan_ganda', 'Ulang karena sampel lisis');

        // Hari berikutnya bukan ganda.
        $this->travel(1)->days();
        $this->masukSebagai($this->dokterB); // token CSRF hari sebelumnya kedaluwarsa
        $this->pesan($k1, ['UJI-LAB-1'])->assertCreated();
    }

    public function test_status_kunjungan_izin_dan_cakupan(): void
    {
        $pasien = $this->pasien();
        $k = $this->kunjungan($pasien); // masih terdaftar
        $this->masukSebagai($this->dokterA);
        $this->pesan($k, ['UJI-LAB-1'])->assertStatus(409)->assertJsonPath('error.kode', 'status_tidak_sesuai');
        $this->minta('POST', "/api/v1/kunjungan/$k/mulai-periksa")->assertOk();
        $id = $this->pesan($k, ['UJI-LAB-1'])->assertCreated()->json('data.id');
        $episode = DB::table('kunjungan.kunjungan')->where('id', $k)->value('episode_id');

        // Tanpa bagian order: lihat boleh, pesan/batal tidak.
        $lihat = $this->buatPengguna();
        $this->tugaskan($lihat, $this->buatPeran(['rawat_jalan.pemeriksaan']));
        $this->masukSebagai($lihat);
        $this->minta('GET', "/api/v1/episode/$episode/permintaan-pemeriksaan")->assertOk()->assertJsonPath('data.0.dapat.batal', false);
        $this->pesan($k, ['UJI-LAB-2'])->assertForbidden();
        $this->minta('POST', "/api/v1/permintaan-pemeriksaan/$id/batal", ['alasan_batal' => 'Tidak jadi diperiksa'])->assertForbidden();

        // Di luar cakupan unit: semua 403; episode/permintaan tidak ada: 404.
        $unitLain = UnitOrganisasi::query()->create(['kode' => 'POLI-L', 'nama' => 'Poli L', 'jenis' => 'poli', 'berlaku_dari' => '2026-01-01']);
        $luar = $this->buatPengguna();
        $this->tugaskan($luar, $this->buatPeran(self::PERAN), cakupan: new Cakupan('unit', $unitLain->id));
        $this->masukSebagai($luar);
        $this->pesan($k, ['UJI-LAB-2'])->assertForbidden();
        $this->minta('GET', "/api/v1/episode/$episode/permintaan-pemeriksaan")->assertForbidden();
        $this->minta('POST', "/api/v1/permintaan-pemeriksaan/$id/batal", ['alasan_batal' => 'Tidak jadi diperiksa'])->assertForbidden();
        $this->minta('GET', '/api/v1/episode/'.Str::uuid7().'/permintaan-pemeriksaan')->assertNotFound();
        $this->minta('POST', '/api/v1/permintaan-pemeriksaan/'.Str::uuid7().'/batal', ['alasan_batal' => 'Tidak jadi diperiksa'])->assertNotFound();

        // Batal tetap boleh sesudah kunjungan selesai selama masih dipesan (izin batal dalam cakupan).
        DB::table('kunjungan.kunjungan')->where('id', $k)->update(['status' => 'selesai']);
        $this->masukSebagai($this->dokterA);
        $this->minta('POST', "/api/v1/permintaan-pemeriksaan/$id/batal", ['alasan_batal' => 'Tidak jadi diperiksa'])->assertOk();
    }

    public function test_poli_sensitif_di_luar_cakupan_tidak_terlihat_dan_tidak_dihitung_ganda(): void
    {
        $pasien = $this->pasien();
        $unitS = UnitOrganisasi::query()->create(['kode' => 'POLI-S', 'nama' => 'Poli S', 'jenis' => 'poli', 'berlaku_dari' => '2026-01-01']);
        $poliS = Poliklinik::query()->create(['kode' => 'UJS', 'nama' => 'Poli Sensitif Uji', 'unit_organisasi_id' => $unitS->id]);
        $poliS->forceFill(['nama_netral' => 'Klinik 7', 'antrean_prefiks' => 'K7', 'antrean_area' => 'poli-k7'])->save();
        $kS = $this->kunjunganDilayani($pasien, $this->jadwal($this->dokter(), ['poli_id' => $poliS->id]));
        $this->masukSebagai($this->dokterA);
        $this->pesan($kS, ['UJI-LAB-1'])->assertCreated();

        // Dokter poli umum (cakupan unit poli uji saja) di episode harian yang sama.
        $umum = $this->buatPengguna();
        $this->tugaskan($umum, $this->buatPeran(self::PERAN), cakupan: new Cakupan('unit', $this->poli->unit_organisasi_id));
        $k = $this->kunjunganDilayani($pasien);
        $this->masukSebagai($umum);
        $episode = DB::table('kunjungan.kunjungan')->where('id', $k)->value('episode_id');
        $this->assertSame($episode, DB::table('kunjungan.kunjungan')->where('id', $kS)->value('episode_id'));
        $this->minta('GET', "/api/v1/episode/$episode/permintaan-pemeriksaan")->assertOk()->assertJsonCount(0, 'data');
        $this->pesan($k, ['UJI-LAB-1'])->assertCreated();

        // Akun bercakupan RS melihat keduanya dan pesanan ketiga terhitung ganda.
        $this->masukSebagai($this->dokterB);
        $this->minta('GET', "/api/v1/episode/$episode/permintaan-pemeriksaan")->assertOk()->assertJsonCount(2, 'data');
        $this->pesan($k, ['UJI-LAB-1'])->assertStatus(409);
    }

    public function test_katalog_tarif_per_jenis(): void
    {
        $this->masukSebagai($this->dokterA);
        $res = $this->minta('GET', '/api/v1/data-induk/tarif?jenis=lab&status=aktif')->assertOk()
            ->assertJsonPath('data.*.kode', ['UJI-LAB-1', 'UJI-LAB-2'])->assertJsonPath('data.0.kategori', 'lab');
        $this->assertSesuaiKontrak($res, 'GET', '/data-induk/tarif');
        $this->minta('GET', '/api/v1/data-induk/tarif?jenis=pa')->assertStatus(422);
        // Tanpa master_data.tarif.lihat: izin order saja tidak membuka katalog.
        $tanpa = $this->buatPengguna();
        $this->tugaskan($tanpa, $this->buatPeran(['rawat_jalan.pemeriksaan', 'rawat_jalan.pemeriksaan.order']));
        $this->masukSebagai($tanpa);
        $this->minta('GET', '/api/v1/data-induk/tarif?jenis=lab')->assertForbidden();
    }

    public function test_bagian_order_efektif_bila_menu_diturunkan_dari_izin_lihat(): void
    {
        // Sama dengan KelolaPeran/AkunSintetisSeeder: peran_menu hanya untuk node yang diberi izin `lihat`.
        $kode = ['rawat_jalan.pemeriksaan.lihat', 'rawat_jalan.pemeriksaan.order.lihat', 'rawat_jalan.pemeriksaan.order.input', 'master_data.tarif.lihat'];
        $izin = \App\Modules\Akses\Models\Izin::query()->whereIn('kode', $kode)->get(['id', 'menu_id', 'jenis_aksi']);
        $this->assertCount(count($kode), $izin);
        $peran = $this->buatPeran([]);
        foreach ($izin->where('jenis_aksi', 'lihat')->pluck('menu_id')->unique() as $menuId) {
            \App\Modules\Akses\Models\PeranMenu::query()->create(['peran_id' => $peran->id, 'menu_id' => $menuId]);
        }
        foreach ($izin as $i) {
            \App\Modules\Akses\Models\PeranIzin::query()->create(['peran_id' => $peran->id, 'izin_id' => $i->id]);
        }
        $akun = $this->buatPengguna();
        $this->tugaskan($akun, $peran);
        $k = $this->kunjunganDilayani($this->pasien());
        $this->masukSebagai($akun);
        $this->pesan($k, ['UJI-LAB-1'])->assertCreated();
    }

    public function test_versi_tarif_baru_menyalin_kategori(): void
    {
        $tarif = \App\Modules\MasterData\Models\Tarif::query()->findOrFail($this->tarif['UJI-LAB-1']);
        app(\App\Modules\MasterData\Application\KelolaTarif::class)->ubah($this->dokterA, $tarif, (string) $tarif->revisi,
            ['nama' => 'Butir sintetis ganti nama'], self::SENIN, 'Uji');
        $this->assertSame(['lab', 'lab'], DB::table('keuangan.tarif_versi')->where('tarif_id', $tarif->id)->orderBy('versi')->pluck('kategori')->all());
        $this->masukSebagai($this->dokterA);
        $this->minta('GET', '/api/v1/data-induk/tarif?jenis=lab')->assertOk()->assertJsonPath('data.0.nama', 'Butir sintetis ganti nama');
    }

    private function akun(): Pengguna
    {
        $akun = $this->buatPengguna(['pegawai_id' => $this->dokter()->id]);
        $this->tugaskan($akun, $this->buatPeran(self::PERAN));

        return $akun;
    }

    private function butir(string $kode, ?string $kategori, string $status = 'aktif'): string
    {
        $id = (string) Str::uuid7();
        DB::table('keuangan.tarif')->insert(['id' => $id, 'kode' => $kode]);
        DB::table('keuangan.tarif_versi')->insert(['id' => (string) Str::uuid7(), 'tarif_id' => $id, 'versi' => 1, 'nama' => "Butir sintetis $kode",
            'kelompok' => 'Uji', 'kategori' => $kategori, 'status' => $status, 'berlaku_dari' => '2026-01-01', 'dasar' => 'Uji']);

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

    /** @param  list<string>  $kode */
    private function pesan(string $kunjungan, array $kode, array $ganti = []): TestResponse
    {
        return $this->minta('POST', "/api/v1/kunjungan/$kunjungan/permintaan-pemeriksaan", [
            'jenis' => 'lab', 'prioritas' => 'rutin', 'diagnosis_kerja' => 'Indikasi sintetis uji',
            'item' => array_map(fn ($k) => ['tarif_id' => $this->tarif[$k]], $kode), ...$ganti,
        ]);
    }
}
