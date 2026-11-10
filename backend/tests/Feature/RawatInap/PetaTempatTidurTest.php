<?php

namespace Tests\Feature\RawatInap;

use App\Modules\Akses\Domain\Cakupan;
use App\Modules\Akses\Models\Izin;
use App\Modules\Akses\Models\Menu;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\Akses\Models\PeranIzin;
use App\Modules\Akses\Models\PeranMenu;
use App\Modules\MasterData\Models\Bangsal;
use App\Modules\MasterData\Models\Kamar;
use App\Modules\MasterData\Models\KelasRawat;
use App\Modules\MasterData\Models\Lokasi;
use App\Modules\MasterData\Models\TempatTidur;
use App\Modules\MasterData\Models\UnitOrganisasi;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\AksesUji;
use Tests\Support\KlienSpa;
use Tests\Support\KontrakApi;
use Tests\TestCase;

/**
 * Tiket 2.27: peta tempat tidur /peta-tempat-tidur (polling ETag) dan status TT /tempat-tidur/{id}/status (kontrak
 * v0.35.0). Lokasi, bangsal, dan TT sintetis langsung di tabel; jam dibekukan Senin 2 Nov 2026 08.00 zona RS.
 *
 *   Gedung A ─ lantai 1 ─ ruang 101 (bangsal UJI-BA): A-1-101-1 terisi, A-1-101-2 kosong siap
 *            └ lantai 2 ─ ruang 201 (bangsal UJI-BA): A-2-201-1 dibersihkan tetapi ditutup kemarin, A-2-201-2 dibersihkan
 *                                                      (hari terakhir berlaku = hari ini), A-2-201-3 baru berlaku besok
 *            └ lantai 10 ─ ruang 1001 (bangsal UJI-BA): A-10-1001-1 kosong siap (urut natural: lantai 2 sebelum 10)
 *   Gedung B ─ lantai 1 ─ ruang 101 (bangsal UJI-BB): B-1-101-1 dipesan
 *   Gedung C ─ ruang 1 tanpa lantai (bangsal UJI-BB): C-1-1 rencana pulang
 *   Gedung D ─ sayap S ─ lantai 3 ─ ruang 301 (bangsal UJI-BB): D-S-3-301-1 kosong siap
 * Basis data tes bisa memuat TT lain, jadi asersi daftar memakai TT uji saja (kodeUji) atau filter gedung/bangsal uji.
 */
class PetaTempatTidurTest extends TestCase
{
    use AksesUji, KlienSpa, KontrakApi;

    private const URL = '/api/v1/peta-tempat-tidur';

    private Pengguna $petugas;

    /** @var array<string, string> kode => id (TT, bangsal, gedung, unit) */
    private array $id = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-02 08:00', (string) config('instalasi.zona_waktu')));

        $irna = UnitOrganisasi::query()->create(['kode' => 'UJI-IRNA', 'nama' => 'Instalasi Rawat Inap (uji)', 'jenis' => 'instalasi', 'berlaku_dari' => '2026-01-01']);
        $this->id['UJI-IRNA'] = $irna->id;
        foreach (['UJI-RA', 'UJI-RB'] as $kode) {
            $this->id[$kode] = UnitOrganisasi::query()->create(['kode' => $kode, 'nama' => "Ruang $kode (uji)", 'jenis' => 'ruang', 'induk_id' => $irna->id, 'berlaku_dari' => '2026-01-01'])->id;
        }
        $kelas = KelasRawat::query()->create(['kode' => 'UJI-K3', 'nama' => 'Kelas 3 (uji)', 'berlaku_dari' => '2026-01-01']);
        $this->id['UJI-K3'] = $kelas->id;
        foreach (['UJI-BA' => 'UJI-RA', 'UJI-BB' => 'UJI-RB'] as $kode => $unit) {
            $this->id[$kode] = Bangsal::query()->create(['kode' => $kode, 'nama' => "Bangsal $kode", 'unit_organisasi_id' => $this->id[$unit], 'status' => 'aktif'])->id;
        }

        $a = $this->lokasi(null, 'gedung', 'A', 'A');
        $a1 = $this->lokasi($a, 'lantai', '1', 'A-1');
        $a2 = $this->lokasi($a, 'lantai', '2', 'A-2');
        $b = $this->lokasi(null, 'gedung', 'B', 'B');
        $c = $this->lokasi(null, 'gedung', 'C', 'C');
        $d = $this->lokasi(null, 'gedung', 'D', 'D');
        [$this->id['A'], $this->id['B'], $this->id['C'], $this->id['D']] = [$a, $b, $c, $d];
        $this->id['D-S-3'] = $this->lokasi($this->lokasi($d, 'sayap', 'S', 'D-S'), 'lantai', '3', 'D-S-3');

        $this->tt($a1, '101', 'UJI-BA', ['1' => 'terisi', '2' => 'kosong_siap']);
        $this->tt($a2, '201', 'UJI-BA', ['1' => 'dibersihkan', '2' => 'dibersihkan', '3' => 'kosong_siap']);
        $this->tt($this->lokasi($a, 'lantai', '10', 'A-10'), '1001', 'UJI-BA', ['1' => 'kosong_siap']);
        $this->tt($this->lokasi($b, 'lantai', '1', 'B-1'), '101', 'UJI-BB', ['1' => 'dipesan']);
        $this->tt($c, '1', 'UJI-BB', ['1' => 'rencana_pulang']);
        $this->tt($this->id['D-S-3'], '301', 'UJI-BB', ['1' => 'kosong_siap']);
        TempatTidur::query()->whereKey($this->id['A-2-201-1'])->update(['berlaku_sampai' => '2026-11-01', 'alasan_tutup' => 'Renovasi (uji)']);
        TempatTidur::query()->whereKey($this->id['A-2-201-2'])->update(['berlaku_sampai' => '2026-11-02']);
        TempatTidur::query()->whereKey($this->id['A-2-201-3'])->update(['berlaku_dari' => '2026-11-03']);

        $this->petugas = $this->buatPengguna();
        $this->tugaskan($this->petugas, $this->buatPeran(['rawat_inap.peta_tempat_tidur']));
    }

    public function test_status_urutan_isi_baris_dan_etag_304(): void
    {
        $this->masukSebagai($this->petugas);
        $peta = $this->minta('GET', self::URL)->assertOk()->assertJsonPath('meta.terpotong', false);
        $baris = $this->barisUji($peta);
        // Urut gedung, lantai (natural: 2 sebelum 10), bangsal, kode TT; TT yang baru berlaku besok tidak tampil.
        $this->assertSame(['A-1-101-1', 'A-1-101-2', 'A-2-201-1', 'A-2-201-2', 'A-10-1001-1', 'B-1-101-1', 'C-1-1', 'D-S-3-301-1'], array_keys($baris));
        // TT ditutup tampil tidak_dipakai (status tersimpan tetap dibersihkan); hari terakhir berlaku = hari ini masih terbuka.
        $this->assertSame(['terisi', 'kosong_siap', 'tidak_dipakai', 'dibersihkan', 'kosong_siap', 'dipesan', 'rencana_pulang', 'kosong_siap'],
            array_column($baris, 'status'));
        $this->assertSame([false, false, true, false, false, false, false, false], array_column($baris, 'ditutup'));
        $this->assertSame([false, true, false, false, true, false, false, true], array_column($baris, 'dapat_dipilih'));
        $this->assertSame(array_fill(0, 8, null), array_values(array_map(fn (array $b) => $b['hari_rawat'], $baris)));
        $a = $baris['A-1-101-1'];
        $this->assertSame(['id' => $this->id['A'], 'kode' => 'A', 'nama' => 'Lokasi A'], $a['gedung']);
        $this->assertSame(['1', '2', '10', null], [$a['lantai']['kode'], $baris['A-2-201-2']['lantai']['kode'], $baris['A-10-1001-1']['lantai']['kode'], $baris['C-1-1']['lantai']]);
        $this->assertSame('C', $baris['C-1-1']['gedung']['kode']);
        $this->assertSame(['101', 'Lokasi A-1-101'], [$a['kamar']['kode'], $a['kamar']['nama']]);
        $this->assertSame(['id' => $this->id['UJI-BA'], 'kode' => 'UJI-BA', 'nama' => 'Bangsal UJI-BA'], $a['bangsal']);
        $this->assertSame(['id' => $this->id['UJI-K3'], 'kode' => 'UJI-K3', 'nama' => 'Kelas 3 (uji)'], $a['kelas_rawat']);
        $this->assertSame(['campur', false, false], [$a['aturan_jenis_kelamin'], $a['isolasi'], $a['ekstra']]);
        $this->assertSesuaiKontrak($peta, 'GET', '/peta-tempat-tidur');
        $this->assertSame('dibersihkan', TempatTidur::query()->findOrFail($this->id['A-2-201-1'])->status_saat_ini);
        $this->assertStringContainsString('no-store', (string) $peta->headers->get('Cache-Control'));

        // Tidak berubah → 304 tanpa isi; status berubah → 200 dengan ETag baru.
        $etag = (string) $peta->headers->get('ETag');
        $this->travel(5)->minutes();
        $tetap = $this->mintaDenganEtag(self::URL, $etag)->assertStatus(304);
        $this->assertSame('', (string) $tetap->getContent());
        $this->assertStringContainsString('no-store', (string) $tetap->headers->get('Cache-Control'));
        $this->assertSesuaiKontrak($tetap, 'GET', '/peta-tempat-tidur');
        $this->ubahStatus($this->id['A-2-201-2'])->assertOk();
        $baru = $this->mintaDenganEtag(self::URL, $etag)->assertOk();
        $this->assertSame('kosong_siap', $this->barisUji($baru)['A-2-201-2']['status']);
        $this->assertNotSame($etag, $baru->headers->get('ETag'));
    }

    public function test_filter_bangsal_gedung_dan_bukan_uuid_422(): void
    {
        $this->masukSebagai($this->petugas);
        $this->minta('GET', self::URL.'?bangsal_id='.$this->id['UJI-BB'])->assertOk()->assertJsonPath('data.*.kode', ['B-1-101-1', 'C-1-1', 'D-S-3-301-1']);
        $this->minta('GET', self::URL.'?gedung_id='.$this->id['A'])->assertOk()
            ->assertJsonPath('data.*.kode', ['A-1-101-1', 'A-1-101-2', 'A-2-201-1', 'A-2-201-2', 'A-10-1001-1']);
        // Gedung → sayap → lantai → ruang: filter gedung menangkap TT di bawah sayap, lantai = lantai terdekat di atas ruang.
        $this->minta('GET', self::URL.'?gedung_id='.$this->id['D'])->assertOk()->assertJsonPath('data.*.kode', ['D-S-3-301-1'])
            ->assertJsonPath('data.0.lantai', ['id' => $this->id['D-S-3'], 'kode' => '3', 'nama' => 'Lokasi D-S-3'])
            ->assertJsonPath('data.0.gedung.kode', 'D');
        $this->minta('GET', self::URL.'?gedung_id='.$this->id['A'].'&bangsal_id='.$this->id['UJI-BB'])->assertOk()->assertJsonCount(0, 'data');
        $this->minta('GET', self::URL.'?gedung_id='.Str::uuid7())->assertOk()->assertJsonCount(0, 'data');
        foreach (['bangsal_id', 'gedung_id'] as $kunci) {
            $this->assertSesuaiKontrak($this->minta('GET', self::URL."?$kunci=ngawur")->assertStatus(422), 'GET', '/peta-tempat-tidur');
        }
    }

    public function test_ubah_dibersihkan_ke_kosong_siap_mencatat_riwayat_dan_audit(): void
    {
        $this->masukSebagai($this->petugas);
        $this->travel(10)->minutes();
        $jam = now()->copy();
        $tt = $this->id['A-2-201-2'];
        $hasil = $this->ubahStatus($tt)->assertOk()->assertJsonPath('data.id', $tt)->assertJsonPath('data.kode', 'A-2-201-2')
            ->assertJsonPath('data.status', 'kosong_siap')->assertJsonPath('data.dapat_dipilih', true)->assertJsonPath('data.ditutup', false)
            ->assertJsonPath('data.bangsal.kode', 'UJI-BA')->assertJsonPath('data.lantai.kode', '2');
        $this->assertSesuaiKontrak($hasil, 'POST', '/tempat-tidur/{id}/status');
        $this->assertStringContainsString('no-store', (string) $hasil->headers->get('Cache-Control'));
        $this->assertTrue(Carbon::parse((string) $hasil->json('data.status_sejak'))->equalTo($jam));

        $riwayat = DB::table('kunjungan.riwayat_status_tempat_tidur')->where('tempat_tidur_id', $tt)->get();
        $this->assertCount(1, $riwayat);
        $this->assertSame(['dibersihkan', 'kosong_siap', $this->petugas->id, 'petugas'],
            [$riwayat[0]->status_sebelum, $riwayat[0]->status, $riwayat[0]->oleh, $riwayat[0]->sumber_kejadian]);
        $this->assertTrue(Carbon::parse((string) $riwayat[0]->terjadi_pada)->equalTo($jam));

        $audit = $this->assertAuditTerakhir('rawat_inap.status_tempat_tidur_diubah', $tt);
        $this->assertSame($this->petugas->id, $audit->pengguna_id);
        $this->assertSame(['kode' => 'A-2-201-2', 'status' => 'dibersihkan'], json_decode((string) $audit->sebelum, true));
        $this->assertSame(['kode' => 'A-2-201-2', 'status' => 'kosong_siap', 'sumber_kejadian' => 'petugas'], json_decode((string) $audit->sesudah, true));

        // Diulang (status sekarang kosong_siap) → 409 tanpa riwayat baru.
        $ulang = $this->ubahStatus($tt)->assertStatus(409)->assertJsonPath('error.kode', 'transisi_status_tempat_tidur_tidak_sah')
            ->assertJsonPath('error.rincian.0', ['status_sekarang' => 'kosong_siap', 'status_diminta' => 'kosong_siap']);
        $this->assertSesuaiKontrak($ulang, 'POST', '/tempat-tidur/{id}/status');
        $this->assertSame(1, DB::table('kunjungan.riwayat_status_tempat_tidur')->where('tempat_tidur_id', $tt)->count());
    }

    public function test_status_lain_ditolak_409_422_404(): void
    {
        $this->masukSebagai($this->petugas);
        // Dari status selain dibersihkan → 409.
        foreach (['A-1-101-1', 'A-1-101-2', 'B-1-101-1', 'C-1-1'] as $kode) {
            $this->ubahStatus($this->id[$kode])->assertStatus(409)->assertJsonPath('error.kode', 'transisi_status_tempat_tidur_tidak_sah');
        }
        // TT ditutup (status tersimpan dibersihkan) → 409 tempat_tidur_ditutup.
        $tutup = $this->ubahStatus($this->id['A-2-201-1'])->assertStatus(409)->assertJsonPath('error.kode', 'tempat_tidur_ditutup');
        $this->assertSesuaiKontrak($tutup, 'POST', '/tempat-tidur/{id}/status');
        // Status selain kosong_siap atau kosong → 422.
        foreach (['terisi', 'dibersihkan', 'tidak_dipakai', 'ngawur', null] as $status) {
            $tolak = $this->ubahStatus($this->id['A-2-201-2'], $status)->assertStatus(422);
            $this->assertSesuaiKontrak($tolak, 'POST', '/tempat-tidur/{id}/status');
        }
        // TT tidak ada atau id bukan UUID → 404.
        $this->assertSesuaiKontrak($this->ubahStatus((string) Str::uuid7())->assertNotFound(), 'POST', '/tempat-tidur/{id}/status');
        $this->ubahStatus('bukan-uuid')->assertNotFound();
        $this->assertSame(0, DB::table('kunjungan.riwayat_status_tempat_tidur')->whereIn('tempat_tidur_id', array_values($this->id))->count());
        $this->assertSame('dibersihkan', TempatTidur::query()->findOrFail($this->id['A-2-201-2'])->status_saat_ini);
    }

    public function test_tanpa_izin_403_dan_cakupan_unit_bangsal(): void
    {
        // Tanpa izin peta: 403 sebelum isian dibaca.
        $this->masukSebagai($this->buatPengguna());
        $this->assertSesuaiKontrak($this->minta('GET', self::URL.'?bangsal_id=ngawur')->assertForbidden(), 'GET', '/peta-tempat-tidur');
        $this->assertSesuaiKontrak($this->ubahStatus($this->id['A-2-201-2'], 'ngawur')->assertForbidden(), 'POST', '/tempat-tidur/{id}/status');

        // Lihat saja: peta boleh, ubah status tidak.
        $lihat = $this->buatPengguna();
        $peran = $this->buatPeran([]);
        PeranMenu::query()->create(['peran_id' => $peran->id, 'menu_id' => Menu::query()->where('kode', 'rawat_inap.peta_tempat_tidur')->value('id')]);
        PeranIzin::query()->create(['peran_id' => $peran->id, 'izin_id' => Izin::query()->where('kode', 'rawat_inap.peta_tempat_tidur.lihat')->value('id')]);
        $this->tugaskan($lihat, $peran);
        $this->masukSebagai($lihat);
        $this->assertCount(8, $this->barisUji($this->minta('GET', self::URL)->assertOk()));
        $this->ubahStatus($this->id['A-2-201-2'])->assertForbidden();

        // Cakupan unit ruang A: hanya bangsal UJI-BA; ubah TT bangsal lain → 403.
        $ruangA = $this->buatPengguna();
        $this->tugaskan($ruangA, $this->buatPeran(['rawat_inap.peta_tempat_tidur']), cakupan: new Cakupan('unit', $this->id['UJI-RA']));
        $this->masukSebagai($ruangA);
        $this->minta('GET', self::URL)->assertOk()->assertJsonPath('data.*.kode', ['A-1-101-1', 'A-1-101-2', 'A-2-201-1', 'A-2-201-2', 'A-10-1001-1']);
        $this->minta('GET', self::URL.'?bangsal_id='.$this->id['UJI-BB'])->assertOk()->assertJsonCount(0, 'data');
        DB::table('inti.tempat_tidur')->where('id', $this->id['B-1-101-1'])->update(['status_saat_ini' => 'dibersihkan']);
        $this->ubahStatus($this->id['B-1-101-1'])->assertForbidden();
        $this->ubahStatus($this->id['A-2-201-2'])->assertOk();

        // Cakupan unit induk (instalasi) memuat semua ruang di bawahnya.
        $irna = $this->buatPengguna();
        $this->tugaskan($irna, $this->buatPeran(['rawat_inap.peta_tempat_tidur']), cakupan: new Cakupan('unit', $this->id['UJI-IRNA']));
        $this->masukSebagai($irna);
        $this->assertCount(8, $this->barisUji($this->minta('GET', self::URL)->assertOk()));
        $this->ubahStatus($this->id['B-1-101-1'])->assertOk()->assertJsonPath('data.status', 'kosong_siap');
    }

    public function test_peta_memakai_batas_laju_layar_kerja(): void
    {
        $router = app('router');
        $rute = collect($router->getRoutes()->getRoutes())->first(fn ($r) => $r->uri() === 'api/v1/peta-tempat-tidur' && in_array('GET', $r->methods(), true));
        $this->assertNotNull($rute);
        $throttle = collect($router->gatherRouteMiddleware($rute))->filter(fn ($m) => is_string($m) && str_contains($m, 'ThrottleRequests'))->values()->all();
        $this->assertCount(1, $throttle);
        $this->assertStringEndsWith(':api-layar-kerja', $throttle[0]);
    }

    /**
     * Baris peta milik TT uji saja (kode => baris), urutan respons dipertahankan.
     *
     * @return array<string, array<string, mixed>>
     */
    private function barisUji(TestResponse $respons): array
    {
        $hasil = [];
        foreach ((array) $respons->json('data') as $baris) {
            if (is_array($baris) && isset($this->id[(string) $baris['kode']])) {
                $hasil[(string) $baris['kode']] = $baris;
            }
        }

        return $hasil;
    }

    private function lokasi(?string $induk, string $jenis, string $kode, string $kodeLengkap, ?string $fungsi = null, ?string $unit = null): string
    {
        return Lokasi::query()->create([
            'induk_id' => $induk, 'jenis' => $jenis, 'kode' => $kode, 'kode_lengkap' => $kodeLengkap, 'nama' => "Lokasi $kodeLengkap",
            'fungsi' => $fungsi, 'unit_organisasi_id' => $unit, 'status' => 'aktif', 'status_berlaku_dari' => '2026-01-01',
        ])->id;
    }

    /** @param  array<int|string, string>  $status  kode TT => status tersimpan */
    private function tt(string $induk, string $kodeRuang, string $bangsal, array $status): void
    {
        $kodeInduk = (string) Lokasi::query()->whereKey($induk)->value('kode_lengkap');
        $ruang = $this->lokasi($induk, 'ruang', $kodeRuang, "$kodeInduk-$kodeRuang", 'rawat_inap');
        $kamar = Kamar::query()->create(['bangsal_id' => $this->id[$bangsal], 'lokasi_id' => $ruang])->id;
        foreach ($status as $kode => $nilai) {
            $kodeTt = "$kodeInduk-$kodeRuang-$kode";
            $tt = TempatTidur::query()->create([
                'kamar_id' => $kamar, 'lokasi_id' => $this->lokasi($ruang, 'tempat_tidur', (string) $kode, $kodeTt), 'kode' => $kodeTt,
                'kelas_rawat_id' => $this->id['UJI-K3'], 'berlaku_dari' => '2026-01-01',
            ]);
            DB::table('inti.tempat_tidur')->where('id', $tt->id)->update(['status_saat_ini' => $nilai]);
            $this->id[$kodeTt] = $tt->id;
        }
    }

    private function ubahStatus(string $tempatTidurId, ?string $status = 'kosong_siap'): TestResponse
    {
        return $this->minta('POST', "/api/v1/tempat-tidur/$tempatTidurId/status", $status === null ? [] : ['status' => $status]);
    }

    private function mintaDenganEtag(string $url, string $etag): TestResponse
    {
        $this->headerTambahan = ['If-None-Match' => $etag];
        try {
            return $this->minta('GET', $url);
        } finally {
            $this->headerTambahan = [];
        }
    }
}
