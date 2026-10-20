<?php

namespace Tests\Feature\MasterData;

use App\Core\Http\ApiError;
use App\Core\Instalasi\StatusInstalasi;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\MasterData\Application\KelolaTarif;
use App\Modules\MasterData\Application\SuntinganBerversi;
use App\Modules\MasterData\Models\KelasRawat;
use App\Modules\MasterData\Models\Tarif;
use App\Modules\MasterData\Models\UsulanDataInduk;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\AksesUji;
use Tests\Support\KlienSpa;
use Tests\Support\KontrakApi;
use Tests\TestCase;

/** Tiket 2.2-be: buku tarif berversi dan usulan jenis `tarif` (kontrak v0.18.0). */
class TarifTest extends TestCase
{
    use AksesUji, KlienSpa, KontrakApi;

    private const DASAR = 'Keputusan Direktur 7/2026';

    private Pengguna $pembaca;

    private Pengguna $pengusul;

    private Pengguna $pemverifikasi;

    private Pengguna $penyetuju;

    private string $kelas1;

    private string $kelas2;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->pembaca = $this->buatPengguna();
        $this->tugaskan($this->pembaca, $this->buatPeran(['master_data.tarif']));
        $peran = $this->buatPeran(['master_data.usulan', 'master_data.tarif']);
        foreach (['pengusul', 'pemverifikasi', 'penyetuju'] as $p) {
            $this->$p = $this->buatPengguna();
            $this->tugaskan($this->$p, $peran);
        }
        $this->kelas1 = KelasRawat::query()->create(['kode' => 'I', 'nama' => 'Kelas I', 'berlaku_dari' => '2026-01-01', 'status' => 'aktif'])->id;
        $this->kelas2 = KelasRawat::query()->create(['kode' => 'II', 'nama' => 'Kelas II', 'berlaku_dari' => '2026-01-01', 'status' => 'aktif'])->id;
    }

    public function test_sub_menu_tarif_dan_alur_usulan_terdaftar(): void
    {
        $this->assertTrue(DB::table('akses.menu')->where('kode', 'master_data.tarif')->exists());
        $this->assertTrue(DB::table('inti.alur_usulan')->where('jenis_data', 'tarif')->exists());
    }

    public function test_harga_lama_hari_ini_harga_baru_besok_lewat_usulan_dengan_pemisahan_tugas(): void
    {
        $tarif = $this->tarifAwal('KONS-01', '50000', '20000', '5000');
        $besok = Carbon::parse(SuntinganBerversi::hariIni())->addDay()->toDateString();

        $this->masukSebagai($this->pengusul);
        $ajukan = $this->minta('POST', '/api/v1/usulan-data-induk', $this->usulan([[
            'objek_jenis' => 'tarif', 'aksi' => 'ubah', 'objek_id' => $tarif->id,
            'nilai_baru' => ['harga' => [['kelas_rawat_id' => $this->kelas1, 'jasa_sarana' => '60000', 'jasa_pelayanan' => '25000.5', 'bhp' => '5000']]],
        ]], $besok))->assertCreated()->assertJsonPath('data.ringkasan', 'Ubah 1 tarif')->assertJsonPath('data.perubahan.0.objek_label', 'KONS-01 · Konsultasi');
        $this->assertSesuaiKontrak($ajukan, 'POST', '/usulan-data-induk');
        $id = $ajukan->json('data.id');
        $this->assertSame('Kelas I: sarana 50000.00, pelayanan 20000.00, BHP 5000.00, total 75000.00', $ajukan->json('data.perubahan.0.tampilan.0.lama'));
        $this->minta('POST', "/api/v1/usulan-data-induk/$id/verifikasi")->assertStatus(403)->assertJsonPath('error.kode', 'pengusul_sendiri');
        // Butir yang sama tidak boleh diusulkan dua kali.
        $this->minta('POST', '/api/v1/usulan-data-induk', $this->usulan([['objek_jenis' => 'tarif', 'aksi' => 'tutup', 'objek_id' => $tarif->id,
            'nilai_baru' => ['alasan' => 'Tidak dilayani lagi']]], $besok))->assertStatus(409)->assertJsonPath('error.kode', 'objek_sedang_diusulkan');

        $this->masukSebagai($this->pemverifikasi);
        $this->minta('POST', "/api/v1/usulan-data-induk/$id/verifikasi")->assertOk();
        $this->masukSebagai($this->penyetuju);
        $this->minta('POST', "/api/v1/usulan-data-induk/$id/setujui")->assertOk()->assertJsonPath('data.tahap', 'disetujui');

        // Hari ini: harga lama; versi terjadwal terlihat di akan_berlaku dan usulan terbuka di baris.
        $this->masukSebagai($this->pembaca);
        $hariIni = $this->assertSesuaiKontrak($this->minta('GET', '/api/v1/data-induk/tarif')->assertOk(), 'GET', '/data-induk/tarif');
        $this->assertSame('75000.00', $hariIni->json('data.0.harga.0.total'));
        $this->assertSame($besok, $hariIni->json('data.0.akan_berlaku.berlaku_dari'));
        $this->assertSame('90000.50', $hariIni->json('data.0.akan_berlaku.harga.0.total'));
        $this->assertNull($hariIni->json('data.0.usulan_terbuka'));

        // Besok 00.00: diterapkan; harga baru berlaku, harga lama tetap terbaca pada tanggal kemarin.
        $this->travelTo(Carbon::parse($besok.' 00:01', (string) config('instalasi.zona_waktu')));
        $this->artisan('master-data:terapkan-usulan')->assertSuccessful();
        $this->masukSebagai($this->pembaca);
        $baru = $this->minta('GET', '/api/v1/data-induk/tarif')->assertOk();
        $this->assertSame(['90000.50', $besok, null], [$baru->json('data.0.harga.0.total'), $baru->json('data.0.berlaku_dari'), $baru->json('data.0.akan_berlaku')]);
        $lama = $this->minta('GET', '/api/v1/data-induk/tarif?tanggal='.Carbon::parse($besok)->subDay()->toDateString())->assertOk();
        $this->assertSame('75000.00', $lama->json('data.0.harga.0.total'));

        $versi = $this->assertSesuaiKontrak($this->minta('GET', "/api/v1/data-induk/tarif/{$tarif->id}/versi")->assertOk(), 'GET', '/data-induk/tarif/{id}/versi');
        $this->assertSame([2, 1], $versi->json('data.*.versi'));
        $this->assertSame(UsulanDataInduk::query()->value('nomor'), $versi->json('data.0.usulan.nomor'));
        $this->assertNull($versi->json('data.1.usulan'));
        $this->assertSame('75000.00', $versi->json('data.0.sebelum.harga.0.total'));
        $this->assertSame('tarif', $versi->json('data.0.objek_jenis'));
    }

    public function test_tambah_dan_tutup_tarif_lewat_usulan(): void
    {
        $this->masukSebagai($this->pengusul);
        $hariIni = SuntinganBerversi::hariIni();
        $id = $this->minta('POST', '/api/v1/usulan-data-induk', $this->usulan([['objek_jenis' => 'tarif', 'aksi' => 'tambah', 'objek_id' => null,
            'nilai_baru' => ['kode' => 'LAB-01', 'nama' => 'Darah rutin', 'kelompok' => 'Laboratorium', 'harga' => [
                ['kelas_rawat_id' => $this->kelas1, 'jasa_sarana' => '10000', 'jasa_pelayanan' => '5000', 'bhp' => '2500'],
                ['kelas_rawat_id' => $this->kelas2, 'jasa_sarana' => '8000', 'jasa_pelayanan' => '4000', 'bhp' => '2500'],
            ]]]], $hariIni))->assertCreated()->assertJsonPath('data.perubahan.0.objek_label', 'Tarif baru LAB-01 · Darah rutin')->json('data.id');
        $this->setujuiOlehDuaOrang($id);

        $this->masukSebagai($this->pembaca);
        $daftar = $this->minta('GET', '/api/v1/data-induk/tarif?kelas_rawat_id='.$this->kelas2)->assertOk();
        $this->assertSame(['LAB-01'], $daftar->json('data.*.kode'));
        $this->assertSame(['14500.00'], array_column($daftar->json('data.0.harga'), 'total'));
        $tarifId = $daftar->json('data.0.id');
        $this->assertSame(['LAB-01'], $this->minta('GET', '/api/v1/data-induk/tarif?cari=darah&kelompok=Laboratorium&status=aktif')->json('data.*.kode'));

        $this->masukSebagai($this->pengusul);
        $tutup = $this->minta('POST', '/api/v1/usulan-data-induk', $this->usulan([['objek_jenis' => 'tarif', 'aksi' => 'tutup', 'objek_id' => $tarifId,
            'nilai_baru' => ['alasan' => 'Layanan dihentikan']]], $hariIni))->assertCreated();
        $this->assertSame('Ditutup mulai '.Carbon::parse($hariIni)->format('d-m-Y'), $tutup->json('data.perubahan.0.tampilan.0.baru'));
        $this->setujuiOlehDuaOrang($tutup->json('data.id'));

        $this->masukSebagai($this->pembaca);
        $this->assertSame([], $this->minta('GET', '/api/v1/data-induk/tarif?status=aktif')->json('data'));
        $nonaktif = $this->minta('GET', '/api/v1/data-induk/tarif?status=nonaktif')->assertOk();
        $this->assertSame(['nonaktif', '14500.00'], [$nonaktif->json('data.0.status'), $nonaktif->json('data.0.harga.1.total') ?? $nonaktif->json('data.0.harga.0.total')]);
        $this->assertSame(2, DB::table('keuangan.tarif_versi')->where('tarif_id', $tarifId)->count());
        $this->masukSebagai($this->pengusul);
        $dua = $this->minta('POST', '/api/v1/usulan-data-induk', $this->usulan([['objek_jenis' => 'tarif', 'aksi' => 'tutup', 'objek_id' => $tarifId,
            'nilai_baru' => ['alasan' => 'Ditutup lagi']]]))->assertStatus(422);
        $this->assertSame(['sudah_nonaktif'], collect($dua->json('error.rincian'))->pluck('kode')->all());
    }

    public function test_validasi_butir_tarif(): void
    {
        $tarif = $this->tarifAwal('KONS-01', '50000', '20000', '5000');
        $this->masukSebagai($this->pengusul);
        $kode = fn (array $b) => collect($this->minta('POST', '/api/v1/usulan-data-induk', $this->usulan([$b]))->assertStatus(422)->json('error.rincian'))->pluck('kode')->all();
        $harga = fn (array $h) => ['objek_jenis' => 'tarif', 'aksi' => 'ubah', 'objek_id' => $tarif->id, 'nilai_baru' => ['harga' => $h]];
        $baris = ['kelas_rawat_id' => $this->kelas1, 'jasa_sarana' => '1', 'jasa_pelayanan' => '1', 'bhp' => '1'];

        $this->assertSame(['isian_tidak_valid'], $kode($harga([[...$baris, 'bhp' => '-5']])));
        $this->assertSame(['kelas_ganda'], $kode($harga([$baris, $baris])));
        $this->assertSame(['kelas_tidak_valid'], $kode($harga([[...$baris, 'kelas_rawat_id' => (string) Str::uuid()]])));
        $this->assertSame(['isian_tidak_valid'], $kode(['objek_jenis' => 'tarif', 'aksi' => 'tambah', 'objek_id' => null,
            'nilai_baru' => ['kode' => 'X', 'nama' => 'Tanpa harga', 'kelompok' => 'Umum', 'harga' => []]]));
        $this->assertSame(['kode_sudah_dipakai'], $kode(['objek_jenis' => 'tarif', 'aksi' => 'tambah', 'objek_id' => null,
            'nilai_baru' => ['kode' => 'KONS-01', 'nama' => 'Ganda', 'kelompok' => 'Umum', 'harga' => [$baris]]]));
        // Jenis data tidak boleh bercampur: butir tarif di usulan ruang_tt.
        $campur = $this->minta('POST', '/api/v1/usulan-data-induk', [...$this->usulan([$harga([$baris])]), 'jenis_data' => 'ruang_tt'])->assertStatus(422);
        $this->assertSame(['jenis_campur'], collect($campur->json('error.rincian'))->pluck('kode')->all());
        $this->minta('POST', '/api/v1/usulan-data-induk', [...$this->usulan([$harga([$baris])]), 'jenis_data' => 'lain'])->assertStatus(422);
    }

    public function test_pengisian_langsung_hanya_sebelum_siap_melayani(): void
    {
        $this->tarifAwal('KONS-01', '50000', '20000', '5000');
        $this->app->bind(StatusInstalasi::class, fn () => new class implements StatusInstalasi
        {
            public function siapMelayani(): bool
            {
                return true;
            }
        });
        try {
            app(KelolaTarif::class)->buatLangsung($this->pengusul, ['kode' => 'TND-01', 'nama' => 'Tindakan', 'kelompok' => 'Umum', 'harga' => [
                ['kelas_rawat_id' => $this->kelas1, 'jasa_sarana' => '1', 'jasa_pelayanan' => '1', 'bhp' => '1']]], SuntinganBerversi::hariIni(), self::DASAR);
            $this->fail('Seharusnya ditolak.');
        } catch (ApiError $e) {
            $this->assertSame([409, 'harus_lewat_usulan'], [$e->getStatusCode(), $e->kode]);
        }
        $this->assertSame(1, Tarif::query()->count());
    }

    public function test_izin_dan_paginasi(): void
    {
        $this->tarifAwal('A-1', '1', '1', '1');
        $this->tarifAwal('A-2', '1', '1', '1');
        $this->masukSebagai($this->buatPengguna());
        $this->minta('GET', '/api/v1/data-induk/tarif')->assertStatus(403);
        $this->masukSebagai($this->pembaca);
        $this->minta('GET', '/api/v1/data-induk/tarif/'.Tarif::query()->value('id').'/versi')->assertOk();
        $this->minta('GET', '/api/v1/data-induk/tarif/'.Str::uuid().'/versi')->assertNotFound();
        $h1 = $this->minta('GET', '/api/v1/data-induk/tarif?batas=1')->assertOk();
        $h2 = $this->minta('GET', '/api/v1/data-induk/tarif?batas=1&kursor='.urlencode($h1->json('meta.kursor_berikutnya')))->assertOk();
        $this->assertSame(['A-1', 'A-2'], [$h1->json('data.0.kode'), $h2->json('data.0.kode')]);
        $this->minta('GET', '/api/v1/data-induk/tarif?batas=201')->assertStatus(422);
        // Pembaca tanpa izin usulan tidak melihat usulan terbuka.
        $this->assertNull($h1->json('data.0.usulan_terbuka'));
    }

    private function tarifAwal(string $kode, string $sarana, string $pelayanan, string $bhp): Tarif
    {
        return app(KelolaTarif::class)->buatLangsung($this->pengusul, ['kode' => $kode, 'nama' => 'Konsultasi', 'kelompok' => 'Rawat jalan', 'harga' => [
            ['kelas_rawat_id' => $this->kelas1, 'jasa_sarana' => $sarana, 'jasa_pelayanan' => $pelayanan, 'bhp' => $bhp]]], '2026-01-01', self::DASAR);
    }

    private function setujuiOlehDuaOrang(string $id): void
    {
        $this->masukSebagai($this->pemverifikasi);
        $this->minta('POST', "/api/v1/usulan-data-induk/$id/verifikasi")->assertOk();
        $this->masukSebagai($this->penyetuju);
        $this->minta('POST', "/api/v1/usulan-data-induk/$id/setujui")->assertOk();
    }

    /**
     * @param  list<array<string, mixed>>  $perubahan
     * @return array<string, mixed>
     */
    private function usulan(array $perubahan, ?string $berlakuDari = null): array
    {
        return ['jenis_data' => 'tarif', 'perubahan' => $perubahan, 'berlaku_dari' => $berlakuDari ?? SuntinganBerversi::hariIni(),
            'alasan' => 'Penyesuaian tarif sesuai analisis unit cost', 'dasar' => self::DASAR];
    }
}
