<?php

namespace Tests\Feature\Pendaftaran;

use App\Modules\Akses\Models\Izin;
use App\Modules\Akses\Models\Menu;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\Akses\Models\PeranIzin;
use App\Modules\Akses\Models\PeranMenu;
use App\Modules\Pendaftaran\Models\Kunjungan;
use App\Modules\Pendaftaran\Models\Pasien;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\AksesUji;
use Tests\Support\KlienSpa;
use Tests\Support\KontrakApi;
use Tests\Support\KunjunganUji;
use Tests\TestCase;

/** Tiket 2.24: triase IGD dan ulang triase (kontrak v0.32.0). Instrumen dan skala sintetis berlabel contoh. */
class TriaseIgdTest extends TestCase
{
    use AksesUji, KlienSpa, KontrakApi, KunjunganUji;

    private Pengguna $perawat;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanKunjungan();
        $this->perawat = $this->buatPengguna();
        $this->tugaskan($this->perawat, $this->buatPeran(['igd.pendaftaran', 'igd.triase']));
    }

    public function test_triase_lalu_ulang_triase_menambah_baris_terbaru_berlaku_dan_audit_tanpa_keluhan(): void
    {
        $instrumen = $this->instrumen('CONTOH-A', [['kode' => 'C1', 'label' => 'Tingkat contoh 1'], ['kode' => 'C2', 'label' => 'Tingkat contoh 2']]);
        $this->masukSebagai($this->perawat);
        $pasien = $this->pasien();
        $kunjungan = $this->daftarIgd($pasien);
        $tiba = Kunjungan::query()->findOrFail($kunjungan)->tiba_pada;

        // Belum ditriase: riwayat kosong, daftar IGD memuat triase null.
        $this->minta('GET', "/api/v1/kunjungan/$kunjungan/triase")->assertOk()->assertJsonCount(0, 'data');
        $this->minta('GET', '/api/v1/kunjungan-igd')->assertOk()->assertJsonPath('data.0.triase', null);

        $this->travel(3)->minutes();
        $pertama = $this->triase($kunjungan, ['kategori' => 'kuning', 'instrumen_triase_id' => $instrumen, 'skala' => 'C2',
            'keluhan' => 'Nyeri dada kiri (uji sintetis)', 'ditriase_pada' => '2020-01-01T00:00:00Z'])->assertCreated()
            ->assertJsonPath('data.kategori', 'kuning')->assertJsonPath('data.instrumen.kode', 'CONTOH-A')
            ->assertJsonPath('data.skala', ['kode' => 'C2', 'label' => 'Tingkat contoh 2'])->assertJsonPath('data.berlaku', true)
            ->assertJsonPath('data.petugas.id', $this->perawat->id)->assertJsonPath('data.keluhan', 'Nyeri dada kiri (uji sintetis)');
        $this->assertSesuaiKontrak($pertama, 'POST', '/kunjungan/{id}/triase');
        $this->assertStringContainsString('no-store', (string) $pertama->headers->get('Cache-Control'));
        // Jam triase = jam server (isian klien diabaikan); jam tiba dibaca dari kunjungan, tidak disalin.
        $this->assertTrue(Carbon::parse((string) $pertama->json('data.ditriase_pada'))->equalTo(now()));
        $this->assertTrue(Carbon::parse((string) $pertama->json('data.tiba_pada'))->equalTo($tiba));
        $this->assertFalse(in_array('tiba_pada', array_column(DB::select("SELECT column_name FROM information_schema.columns WHERE table_schema = 'kunjungan' AND table_name = 'triase'"), 'column_name'), true));

        $audit = $this->assertAuditTerakhir('kunjungan.ditriase', (string) $pertama->json('data.id'));
        $this->assertSame($this->perawat->id, $audit->pengguna_id);
        $sesudah = json_decode((string) $audit->sesudah, true);
        // jsonb tidak menjaga urutan kunci: bandingkan per kunci.
        $this->assertSame([$kunjungan, 'kuning', false], [$sesudah['kunjungan_id'] ?? null, $sesudah['kategori'] ?? null, $sesudah['ulang'] ?? null]);
        $this->assertStringNotContainsString('Nyeri dada', (string) $audit->sesudah);
        $this->assertStringNotContainsString($pasien->nama, (string) $audit->sesudah);

        // Ulang triase: baris baru, baris lama tidak berubah.
        $this->travel(10)->minutes();
        $kedua = $this->triase($kunjungan, ['kategori' => 'merah', 'instrumen_triase_id' => $instrumen, 'skala' => 'C1'])->assertCreated()
            ->assertJsonPath('data.kategori', 'merah')->assertJsonPath('data.keluhan', null);
        $this->assertTrue(json_decode((string) $this->assertAuditTerakhir('kunjungan.ditriase', (string) $kedua->json('data.id'))->sesudah, true)['ulang']);
        $this->assertSame(2, DB::table('kunjungan.triase')->where('kunjungan_id', $kunjungan)->count());
        $this->assertSame('kuning', DB::table('kunjungan.triase')->where('id', $pertama->json('data.id'))->value('kategori'));

        $riwayat = $this->minta('GET', "/api/v1/kunjungan/$kunjungan/triase")->assertOk()
            ->assertJsonPath('data.*.kategori', ['merah', 'kuning'])->assertJsonPath('data.*.berlaku', [true, false]);
        $this->assertSesuaiKontrak($riwayat, 'GET', '/kunjungan/{id}/triase');
        // Halaman kedua tetap menandai baris lama bukan berlaku.
        $halaman = $this->minta('GET', "/api/v1/kunjungan/$kunjungan/triase?batas=1")->assertOk()->assertJsonPath('data.0.berlaku', true);
        $this->minta('GET', "/api/v1/kunjungan/$kunjungan/triase?batas=1&kursor=".urlencode((string) $halaman->json('meta.kursor_berikutnya')))
            ->assertOk()->assertJsonPath('data.0.kategori', 'kuning')->assertJsonPath('data.0.berlaku', false);

        // Daftar IGD memuat triase berlaku untuk papan (2.25).
        $daftar = $this->minta('GET', '/api/v1/kunjungan-igd')->assertOk()->assertJsonPath('data.0.id', $kunjungan)
            ->assertJsonPath('data.0.triase.kategori', 'merah');
        $this->assertSesuaiKontrak($daftar, 'GET', '/kunjungan-igd');
        $this->assertTrue(Carbon::parse((string) $daftar->json('data.0.triase.ditriase_pada'))->equalTo(now()));

        // Hanya ditambah: simrs_app tidak boleh mengubah atau menghapus baris triase.
        $this->assertDitolak(fn () => DB::table('kunjungan.triase')->where('kunjungan_id', $kunjungan)->update(['kategori' => 'hijau']));
        $this->assertDitolak(fn () => DB::table('kunjungan.triase')->where('kunjungan_id', $kunjungan)->delete());
    }

    public function test_hanya_kunjungan_igd_aktif_selain_itu_409_dan_404(): void
    {
        $this->masukSebagai($this->perawat);
        $selesai = $this->daftarIgd($this->pasien());
        Kunjungan::query()->whereKey($selesai)->update(['status' => 'selesai']);
        $this->triase($selesai)->assertStatus(409)->assertJsonPath('error.kode', 'status_tidak_sesuai')
            ->assertJsonPath('error.rincian.0.status', 'selesai');

        $dilayani = $this->daftarIgd($this->pasien());
        Kunjungan::query()->whereKey($dilayani)->update(['status' => 'dilayani']);
        $this->triase($dilayani)->assertCreated();

        $batal = $this->daftarIgd($this->pasien());
        Kunjungan::query()->whereKey($batal)->update(['status' => 'batal', 'alasan_batal' => 'Salah pilih pasien', 'dibatalkan_pada' => now()]);
        $tolak = $this->triase($batal)->assertStatus(409)->assertJsonPath('error.rincian.0.status', 'batal');
        $this->assertSesuaiKontrak($tolak, 'POST', '/kunjungan/{id}/triase');

        // Kunjungan poli bukan IGD.
        $this->masukSebagai($this->petugasPendaftaran());
        $poli = (string) $this->daftarKunjungan($this->pasien(), $this->jadwal($this->dokter()))->assertCreated()->json('data.id');
        $this->masukSebagai($this->perawat);
        $this->triase($poli)->assertStatus(409)->assertJsonPath('error.rincian.0.jenis_kunjungan', 'poliklinik');
        $this->assertFalse(DB::table('kunjungan.triase')->whereIn('kunjungan_id', [$selesai, $batal, $poli])->exists());

        $tidakAda = (string) Str::uuid7();
        $this->assertSesuaiKontrak($this->triase($tidakAda)->assertNotFound(), 'POST', '/kunjungan/{id}/triase');
        $this->minta('GET', "/api/v1/kunjungan/$tidakAda/triase")->assertNotFound();
        $this->triase('bukan-uuid')->assertNotFound();

        $this->tandaiInstalasiSiap(false);
        $this->triase($dilayani)->assertStatus(409)->assertJsonPath('error.kode', 'instalasi_belum_siap');
    }

    public function test_kategori_dan_instrumen_dari_data_induk_aktif(): void
    {
        $this->masukSebagai($this->perawat);
        $kunjungan = $this->daftarIgd($this->pasien());

        // RS belum menetapkan instrumen: triase tanpa instrumen boleh, skala tanpa instrumen tidak.
        $this->assertSesuaiKontrak($this->minta('GET', '/api/v1/instrumen-triase')->assertOk()->assertJsonCount(0, 'data'), 'GET', '/instrumen-triase');
        $this->triase($kunjungan, ['skala' => 'C1'])->assertStatus(422)->assertJsonPath('error.rincian.0.kolom', 'skala');
        $this->triase($kunjungan, ['kategori' => 'hijau'])->assertCreated()->assertJsonPath('data.instrumen', null)->assertJsonPath('data.skala', null);

        // Hanya empat kategori FR-03-02.
        foreach (['biru', 'MERAH', '', null] as $kategori) {
            $this->triase($kunjungan, ['kategori' => $kategori])->assertStatus(422)->assertJsonPath('error.rincian.0.kolom', 'kategori');
        }
        $this->triase($kunjungan, ['keluhan' => str_repeat('a', 1001)])->assertStatus(422);
        $this->triase($kunjungan, ['instrumen_triase_id' => 'bukan-uuid'])->assertStatus(422);

        $aktif = $this->instrumen('CONTOH-B', [['kode' => 'C1', 'label' => 'Tingkat contoh 1']]);
        $tanpaSkala = $this->instrumen('CONTOH-C', []);
        $nonaktif = $this->instrumen('CONTOH-D', [], 'nonaktif');
        $daftar = $this->minta('GET', '/api/v1/instrumen-triase')->assertOk()->assertJsonPath('data.*.kode', ['CONTOH-B', 'CONTOH-C'])
            ->assertJsonPath('data.0.skala', [['kode' => 'C1', 'label' => 'Tingkat contoh 1']]);
        $this->assertSesuaiKontrak($daftar, 'GET', '/instrumen-triase');

        // Ada instrumen aktif: wajib memilih; nonaktif/tak dikenal ditolak; skala harus dari instrumen itu.
        $this->triase($kunjungan)->assertStatus(422)->assertJsonPath('error.rincian.0.kolom', 'instrumen_triase_id');
        $this->triase($kunjungan, ['instrumen_triase_id' => $nonaktif])->assertStatus(422)->assertJsonPath('error.rincian.0.kolom', 'instrumen_triase_id');
        $this->triase($kunjungan, ['instrumen_triase_id' => (string) Str::uuid7()])->assertStatus(422)->assertJsonPath('error.rincian.0.kolom', 'instrumen_triase_id');
        $this->triase($kunjungan, ['instrumen_triase_id' => $aktif])->assertStatus(422)->assertJsonPath('error.rincian.0.kolom', 'skala');
        $this->triase($kunjungan, ['instrumen_triase_id' => $aktif, 'skala' => 'C9'])->assertStatus(422)->assertJsonPath('error.rincian.0.kolom', 'skala');
        $this->triase($kunjungan, ['instrumen_triase_id' => $tanpaSkala, 'skala' => 'C1'])->assertStatus(422)->assertJsonPath('error.rincian.0.kolom', 'skala');
        $this->triase($kunjungan, ['instrumen_triase_id' => $tanpaSkala])->assertCreated()->assertJsonPath('data.instrumen.kode', 'CONTOH-C')->assertJsonPath('data.skala', null);
        $this->assertSame(2, DB::table('kunjungan.triase')->where('kunjungan_id', $kunjungan)->count());
    }

    public function test_izin_triase_dan_daftar_igd(): void
    {
        $kunjungan = $this->daftarIgdOleh($this->perawat, $this->pasien());
        $url = "/api/v1/kunjungan/$kunjungan/triase";

        // Tanpa igd.triase: 403 sebelum isian dibaca.
        $pendaftaranSaja = $this->buatPengguna();
        $this->tugaskan($pendaftaranSaja, $this->buatPeran(['igd.pendaftaran']));
        foreach ([$this->buatPengguna(), $pendaftaranSaja] as $akun) {
            $this->masukSebagai($akun);
            $this->minta('POST', $url, [])->assertForbidden();
            $this->minta('GET', $url)->assertForbidden();
            $this->minta('GET', '/api/v1/instrumen-triase')->assertForbidden();
        }
        // Pendaftaran IGD tanpa izin triase: daftar IGD tanpa field triase (bagian tanpa izin tidak dikirim).
        $this->minta('GET', '/api/v1/kunjungan-igd')->assertOk()->assertJsonMissingPath('data.0.triase');

        // Triase saja (tanpa igd.pendaftaran): daftar IGD tetap terbuka untuk memilih pasien.
        $triaseSaja = $this->buatPengguna();
        $this->tugaskan($triaseSaja, $this->buatPeran(['igd.triase']));
        $this->masukSebagai($triaseSaja);
        $this->minta('GET', '/api/v1/kunjungan-igd')->assertOk()->assertJsonPath('data.0.id', $kunjungan)->assertJsonPath('data.0.triase', null);
        $this->triase($kunjungan)->assertCreated();
        $this->minta('POST', '/api/v1/kunjungan-igd', ['pasien_id' => $this->pasien()->id, 'cara_bayar' => 'non_jkn'])->assertForbidden();

        // Lihat saja: riwayat boleh, mencatat tidak.
        $lihat = $this->buatPengguna();
        $peran = $this->buatPeran([]);
        $menu = Menu::query()->where('kode', 'igd.triase')->firstOrFail();
        PeranMenu::query()->create(['peran_id' => $peran->id, 'menu_id' => $menu->id]);
        PeranIzin::query()->create(['peran_id' => $peran->id, 'izin_id' => Izin::query()->where('kode', 'igd.triase.lihat')->value('id')]);
        $this->tugaskan($lihat, $peran);
        $this->masukSebagai($lihat);
        $this->minta('GET', $url)->assertOk()->assertJsonCount(1, 'data');
        $this->triase($kunjungan)->assertForbidden();
        $this->assertSame(1, DB::table('kunjungan.triase')->where('kunjungan_id', $kunjungan)->count());
    }

    /** @param  list<array{kode: string, label: string}>  $skala */
    private function instrumen(string $kode, array $skala, string $status = 'aktif'): string
    {
        $id = (string) Str::uuid7();
        DB::table('inti.instrumen_triase')->insert(['id' => $id, 'kode' => $kode, 'nama' => "Instrumen $kode (contoh)", 'skala' => json_encode($skala), 'status' => $status]);

        return $id;
    }

    private function daftarIgd(Pasien $pasien): string
    {
        return (string) $this->minta('POST', '/api/v1/kunjungan-igd', ['pasien_id' => $pasien->id, 'cara_bayar' => 'non_jkn'])->assertCreated()->json('data.id');
    }

    private function daftarIgdOleh(Pengguna $petugas, Pasien $pasien): string
    {
        $this->masukSebagai($petugas);

        return $this->daftarIgd($pasien);
    }

    /** @param  array<string, mixed>  $ganti */
    private function triase(string $kunjunganId, array $ganti = []): TestResponse
    {
        return $this->minta('POST', "/api/v1/kunjungan/$kunjunganId/triase", ['kategori' => 'kuning', ...$ganti]);
    }
}
