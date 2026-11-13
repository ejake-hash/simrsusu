<?php

namespace Tests\Feature\Lab;

use App\Modules\Akses\Domain\Cakupan;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\MasterData\Models\Bangsal;
use App\Modules\MasterData\Models\JadwalPraktik;
use App\Modules\MasterData\Models\Kamar;
use App\Modules\MasterData\Models\KelasRawat;
use App\Modules\MasterData\Models\Lokasi;
use App\Modules\MasterData\Models\TempatTidur;
use App\Modules\MasterData\Models\UnitOrganisasi;
use App\Modules\Pendaftaran\Models\Pasien;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\AksesUji;
use Tests\Support\KlienSpa;
use Tests\Support\KontrakApi;
use Tests\Support\KunjunganUji;
use Tests\TestCase;

/** Tiket 2.30-be: daftar kerja lab dan spesimen (kontrak v0.40.0). Butir tarif dan pasien SINTETIS. */
class DaftarKerjaLabTest extends TestCase
{
    use AksesUji, KlienSpa, KontrakApi, KunjunganUji;

    private const PERAN_DOKTER = ['rawat_jalan.pemeriksaan', 'rawat_jalan.pemeriksaan.order', 'master_data.tarif'];

    private JadwalPraktik $jadwal;

    private Pengguna $dokter;

    private Pengguna $analis;

    /** @var array<string, string> */
    private array $tarif = [];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->siapkanKunjungan();
        $pegawai = $this->dokter();
        $this->jadwal = $this->jadwal($pegawai);
        $this->dokter = $this->buatPengguna(['pegawai_id' => $pegawai->id]);
        $this->tugaskan($this->dokter, $this->buatPeran(self::PERAN_DOKTER));
        $this->analis = $this->buatPengguna();
        $this->tugaskan($this->analis, $this->buatPeran(['penunjang.laboratorium']));
        foreach (['UJI30-LAB-1' => 'lab', 'UJI30-LAB-2' => 'lab', 'UJI30-RAD-1' => 'rad'] as $kode => $kategori) {
            $this->tarif[$kode] = $this->butir($kode, $kategori);
        }
    }

    public function test_daftar_kerja_cito_dan_rutin_terpisah_paling_lama_dulu_dengan_asal_dan_lokasi(): void
    {
        $rutin1 = $this->order($this->pasien(), ['UJI30-LAB-1', 'UJI30-LAB-2']);
        $this->travel(1)->minutes();
        $cito = $this->order($this->pasien(), ['UJI30-LAB-1'], ['prioritas' => 'cito']);
        $this->travel(1)->minutes();
        $rutin2 = $this->order($this->pasien(), ['UJI30-LAB-1']);
        // Order radiologi dan order lab batal tidak muncul.
        $this->order($this->pasien(), ['UJI30-RAD-1'], ['jenis' => 'rad']);
        $batal = $this->order($this->pasien(), ['UJI30-LAB-2']);
        $this->minta('POST', "/api/v1/permintaan-pemeriksaan/$batal/batal", ['alasan_batal' => 'Salah pilih pemeriksaan'])->assertOk();

        $this->masukSebagai($this->analis);
        $res = $this->minta('GET', '/api/v1/lab/daftar-kerja?prioritas=rutin')->assertOk()
            ->assertJsonPath('data.*.id', [$rutin1, $rutin2])
            ->assertJsonPath('data.0.asal.jenis_kunjungan', 'poliklinik')->assertJsonPath('data.0.asal.nama', 'Poli Contoh')
            ->assertJsonPath('data.0.lokasi_saat_ini.jenis', 'poli')->assertJsonPath('data.0.lokasi_saat_ini.nama', 'Poli Contoh')
            ->assertJsonPath('data.0.item.*.kode_item', ['UJI30-LAB-1', 'UJI30-LAB-2'])
            ->assertJsonPath('data.0.item.0.spesimen', null)->assertJsonPath('data.0.item.0.dapat.ambil', true)
            ->assertJsonPath('data.0.pemesan.id', $this->dokter->id)->assertJsonPath('data.0.diagnosis_kerja', 'Indikasi sintetis uji');
        $this->assertSesuaiKontrak($res, 'GET', '/lab/daftar-kerja');
        $this->assertStringContainsString('no-store', (string) $res->headers->get('Cache-Control'));
        $this->assertArrayNotHasKey('nik', $res->json('data.0.pasien'));
        $this->assertArrayNotHasKey('alamat', $res->json('data.0.pasien'));

        $this->minta('GET', '/api/v1/lab/daftar-kerja?prioritas=cito')->assertOk()->assertJsonPath('data.*.id', [$cito]);
        // Kursor: halaman kedua melanjutkan urutan.
        $hal1 = $this->minta('GET', '/api/v1/lab/daftar-kerja?prioritas=rutin&batas=1')->assertOk()->assertJsonPath('data.*.id', [$rutin1]);
        $this->minta('GET', '/api/v1/lab/daftar-kerja?prioritas=rutin&batas=1&kursor='.$hal1->json('meta.kursor_berikutnya'))
            ->assertOk()->assertJsonPath('data.*.id', [$rutin2]);

        $this->assertSesuaiKontrak($this->minta('GET', '/api/v1/lab/daftar-kerja')->assertStatus(422), 'GET', '/lab/daftar-kerja');
        $this->minta('GET', '/api/v1/lab/daftar-kerja?prioritas=sedang')->assertStatus(422);
        $this->minta('GET', '/api/v1/lab/daftar-kerja?prioritas=rutin&batas=201')->assertStatus(422)
            ->assertJsonPath('error.kode', 'ukuran_halaman_melebihi_batas');
    }

    public function test_lokasi_saat_ini_rawat_inap_dari_penempatan_tt_aktif(): void
    {
        $pasien = $this->pasien();
        $id = $this->order($pasien, ['UJI30-LAB-1']);
        $kunjungan = (string) DB::table('permintaan.permintaan_pemeriksaan')->where('id', $id)->value('kunjungan_id');
        $tt = $this->tempatTidur();
        DB::table('kunjungan.penempatan_tempat_tidur')->insert([
            'id' => (string) Str::uuid7(), 'tempat_tidur_id' => $tt, 'kunjungan_id' => $kunjungan, 'pasien_id' => $pasien->id,
            'mulai_pada' => now(), 'ditempatkan_oleh' => $this->dokter->id,
        ]);

        $this->masukSebagai($this->analis);
        $res = $this->minta('GET', '/api/v1/lab/daftar-kerja?prioritas=rutin')->assertOk()
            ->assertJsonPath('data.0.lokasi_saat_ini', ['jenis' => 'rawat_inap', 'nama' => 'Bangsal UJI30', 'tempat_tidur' => 'UJI30-101-1'])
            ->assertJsonPath('data.0.asal.jenis_kunjungan', 'poliklinik');
        $this->assertSesuaiKontrak($res, 'GET', '/lab/daftar-kerja');
    }

    public function test_ambil_dan_terima_mencatat_barcode_jam_server_dan_petugas(): void
    {
        $id = $this->order($this->pasien(), ['UJI30-LAB-1', 'UJI30-LAB-2'], ['prioritas' => 'cito']);
        [$item1, $item2] = $this->butirOrder($id);

        $this->masukSebagai($this->analis);
        $ambil = $this->ambil($item1)->assertCreated()
            ->assertJsonPath('data.permintaan_item_id', $item1)->assertJsonPath('data.item_status', 'sampel_diambil')
            ->assertJsonPath('data.diambil_oleh.id', $this->analis->id)
            ->assertJsonPath('data.diterima_pada', null)->assertJsonPath('data.kondisi', null);
        $this->assertTrue(Carbon::parse((string) $ambil->json('data.diambil_pada'))->equalTo(now()), 'Jam ambil = jam server.');
        $this->assertSesuaiKontrak($ambil, 'POST', '/permintaan-item/{id}/spesimen');
        $barcode1 = (string) $ambil->json('data.barcode');
        $this->assertMatchesRegularExpression('/^LAB-20261102-\d{5}$/', $barcode1);
        $barcode2 = (string) $this->ambil($item2, ['lokasi_pengambilan' => 'samping_tt'])->assertCreated()->json('data.barcode');
        $this->assertNotSame($barcode1, $barcode2);
        $this->assertSame((int) substr($barcode1, -5) + 1, (int) substr($barcode2, -5));
        $this->assertAuditTerakhir('lab.spesimen_diambil', (string) $ambil->json('data.id'));

        // Ambil kedua kali → 409; validasi isian → 422.
        $this->assertSesuaiKontrak($this->ambil($item1)->assertStatus(409)->assertJsonPath('error.kode', 'status_tidak_sesuai'),
            'POST', '/permintaan-item/{id}/spesimen');
        $this->ambil($item1, ['lokasi_pengambilan' => 'lorong'])->assertStatus(422);

        $this->minta('GET', '/api/v1/lab/daftar-kerja?prioritas=cito')->assertOk()
            ->assertJsonPath('data.0.item.0.status', 'sampel_diambil')->assertJsonPath('data.0.item.0.spesimen.barcode', $barcode1)
            ->assertJsonPath('data.0.item.0.dapat.terima', true)->assertJsonPath('data.0.item.0.dapat.ambil', false);

        $this->travel(5)->minutes();
        $spesimen = (string) $ambil->json('data.id');
        $terima = $this->minta('POST', "/api/v1/spesimen/$spesimen/terima")->assertOk()
            ->assertJsonPath('data.item_status', 'diterima_lab')->assertJsonPath('data.kondisi', 'baik')
            ->assertJsonPath('data.diterima_oleh.id', $this->analis->id);
        $this->assertTrue(Carbon::parse((string) $terima->json('data.diterima_pada'))->equalTo(now()), 'Jam terima = jam server.');
        $this->assertSesuaiKontrak($terima, 'POST', '/spesimen/{id}/terima');
        $this->assertStringContainsString('no-store', (string) $terima->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', (string) $ambil->headers->get('Cache-Control'));
        $this->assertAuditTerakhir('lab.spesimen_diterima', $spesimen);
        $this->assertSesuaiKontrak($this->minta('POST', "/api/v1/spesimen/$spesimen/terima")->assertStatus(409), 'POST', '/spesimen/{id}/terima');
        $this->minta('POST', '/api/v1/spesimen/'.Str::uuid7().'/terima')->assertNotFound();

        // Audit tanpa data pasien.
        $audit = (string) DB::table('audit.log_aktivitas')->where('aksi', 'like', 'lab.%')->pluck('sesudah')->implode(' ');
        $this->assertStringNotContainsString('Pasien Sintetis', $audit);

        // Order tetap di daftar selama butir 2 belum diterima; sesudah semua diterima order keluar dari daftar.
        $this->minta('GET', '/api/v1/lab/daftar-kerja?prioritas=cito')->assertOk()->assertJsonCount(1, 'data');
        $spesimen2 = (string) DB::table('lab.spesimen')->where('permintaan_item_id', $item2)->value('id');
        $this->minta('POST', "/api/v1/spesimen/$spesimen2/terima")->assertOk();
        $this->minta('GET', '/api/v1/lab/daftar-kerja?prioritas=cito')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_batal_order_sesudah_sampel_diambil_boleh_sesudah_diterima_409(): void
    {
        // Sampel diambil lalu order dibatalkan: ambil/terima sesudahnya 409.
        $a = $this->order($this->pasien(), ['UJI30-LAB-1', 'UJI30-LAB-2']);
        [$a1, $a2] = $this->butirOrder($a);
        $this->masukSebagai($this->analis);
        $sA = (string) $this->ambil($a1)->assertCreated()->json('data.id');
        $this->masukSebagai($this->dokter);
        $this->minta('POST', "/api/v1/permintaan-pemeriksaan/$a/batal", ['alasan_batal' => 'Pasien menolak pemeriksaan'])->assertOk()
            ->assertJsonPath('data.item.0.status', 'batal')->assertJsonPath('data.item.1.status', 'batal');
        $this->masukSebagai($this->analis);
        $this->ambil($a2)->assertStatus(409)->assertJsonPath('error.kode', 'status_tidak_sesuai');
        $this->minta('POST', "/api/v1/spesimen/$sA/terima")->assertStatus(409);
        $this->assertSame(1, DB::table('lab.spesimen')->where('id', $sA)->count()); // tidak dihapus

        // Diterima lab: pemesan tidak bisa membatalkan.
        $b = $this->order($this->pasien(), ['UJI30-LAB-1']);
        [$b1] = $this->butirOrder($b);
        $this->masukSebagai($this->analis);
        $sB = (string) $this->ambil($b1)->assertCreated()->json('data.id');
        $this->minta('POST', "/api/v1/spesimen/$sB/terima")->assertOk();
        $this->masukSebagai($this->dokter);
        $episode = (string) DB::table('permintaan.permintaan_pemeriksaan')->where('id', $b)->value('episode_id');
        $daftar = $this->minta('GET', "/api/v1/episode/$episode/permintaan-pemeriksaan")->assertOk()
            ->assertJsonPath('data.0.item.0.status', 'diterima_lab')->assertJsonPath('data.0.dapat.batal', false);
        $this->assertSesuaiKontrak($daftar, 'GET', '/episode/{id}/permintaan-pemeriksaan');
        $tolak = $this->minta('POST', "/api/v1/permintaan-pemeriksaan/$b/batal", ['alasan_batal' => 'Tidak jadi diperiksa'])
            ->assertStatus(409)->assertJsonPath('error.kode', 'status_tidak_sesuai');
        $this->assertSesuaiKontrak($tolak, 'POST', '/permintaan-pemeriksaan/{id}/batal');
        $this->assertSame('dipesan', DB::table('permintaan.permintaan_pemeriksaan')->where('id', $b)->value('status'));
    }

    public function test_izin_dan_cakupan_rs(): void
    {
        $id = $this->order($this->pasien(), ['UJI30-LAB-1']);
        [$item] = $this->butirOrder($id);
        $rad = $this->order($this->pasien(), ['UJI30-RAD-1'], ['jenis' => 'rad']);
        [$itemRad] = $this->butirOrder($rad);

        // Pemegang izin lab cakupan unit saja → 403 di ketiga endpoint.
        $unit = UnitOrganisasi::query()->create(['kode' => 'UJI30-LAB', 'nama' => 'Instalasi Lab (uji)', 'jenis' => 'instalasi', 'berlaku_dari' => '2026-01-01']);
        $unitSaja = $this->buatPengguna();
        $this->tugaskan($unitSaja, $this->buatPeran(['penunjang.laboratorium']), cakupan: new Cakupan('unit', $unit->id));
        $this->masukSebagai($unitSaja);
        $this->assertSesuaiKontrak($this->minta('GET', '/api/v1/lab/daftar-kerja?prioritas=rutin')->assertForbidden(), 'GET', '/lab/daftar-kerja');
        $this->ambil($item)->assertForbidden();

        // Tanpa izin lab (dokter pemesan) → 403.
        $this->masukSebagai($this->dokter);
        $this->minta('GET', '/api/v1/lab/daftar-kerja?prioritas=rutin')->assertForbidden();
        $this->ambil($item)->assertForbidden();

        $this->masukSebagai($this->analis);
        $s = (string) $this->ambil($item)->assertCreated()->json('data.id');

        $this->masukSebagai($unitSaja);
        $this->minta('POST', "/api/v1/spesimen/$s/terima")->assertForbidden();

        // Hanya lihat + input: terima butuh verifikasi.
        $pengambil = $this->buatPengguna();
        $peran = $this->buatPeran([]);
        foreach (\App\Modules\Akses\Models\Izin::query()->whereIn('kode', ['penunjang.laboratorium.lihat', 'penunjang.laboratorium.input'])->get(['id', 'menu_id']) as $i) {
            \App\Modules\Akses\Models\PeranMenu::query()->firstOrCreate(['peran_id' => $peran->id, 'menu_id' => $i->menu_id]);
            \App\Modules\Akses\Models\PeranIzin::query()->create(['peran_id' => $peran->id, 'izin_id' => $i->id]);
        }
        $this->tugaskan($pengambil, $peran);
        $this->masukSebagai($pengambil);
        $this->minta('GET', '/api/v1/lab/daftar-kerja?prioritas=rutin')->assertOk()->assertJsonPath('data.0.item.0.dapat.terima', false);
        $this->minta('POST', "/api/v1/spesimen/$s/terima")->assertForbidden();

        // Butir order radiologi atau tidak ada → 404.
        $this->masukSebagai($this->analis);
        $this->ambil($itemRad)->assertNotFound();
        $this->assertSesuaiKontrak($this->ambil((string) Str::uuid7())->assertNotFound(), 'POST', '/permintaan-item/{id}/spesimen');
    }

    public function test_izin_verifikasi_lab_terpasang_tidak_sensitif(): void
    {
        $izin = DB::table('akses.izin')->where('kode', 'penunjang.laboratorium.verifikasi')->first(['jenis_aksi', 'sensitif', 'butuh_mfa']);
        $this->assertNotNull($izin);
        $this->assertSame('verifikasi', $izin->jenis_aksi);
        $this->assertFalse((bool) $izin->sensitif);
        $this->assertFalse((bool) $izin->butuh_mfa);
    }

    private function butir(string $kode, string $kategori): string
    {
        $id = (string) Str::uuid7();
        DB::table('keuangan.tarif')->insert(['id' => $id, 'kode' => $kode]);
        DB::table('keuangan.tarif_versi')->insert(['id' => (string) Str::uuid7(), 'tarif_id' => $id, 'versi' => 1, 'nama' => "Butir sintetis $kode",
            'kelompok' => 'Uji', 'kategori' => $kategori, 'status' => 'aktif', 'berlaku_dari' => '2026-01-01', 'dasar' => 'Uji']);

        return $id;
    }

    /**
     * Order dari kunjungan poli dilayani pasien itu; sesudahnya sesi dokter pemesan.
     *
     * @param  list<string>  $kode
     * @param  array<string, mixed>  $ganti
     */
    private function order(Pasien $pasien, array $kode, array $ganti = []): string
    {
        $this->masukSebagai($this->petugasPendaftaran());
        $k = (string) $this->daftarKunjungan($pasien, $this->jadwal)->assertCreated()->json('data.id');
        DB::table('kunjungan.kunjungan')->where('id', $k)->update(['status' => 'dilayani']);
        $this->masukSebagai($this->dokter);

        return (string) $this->minta('POST', "/api/v1/kunjungan/$k/permintaan-pemeriksaan", [
            'jenis' => 'lab', 'prioritas' => 'rutin', 'diagnosis_kerja' => 'Indikasi sintetis uji',
            'item' => array_map(fn ($x) => ['tarif_id' => $this->tarif[$x]], $kode), ...$ganti,
        ])->assertCreated()->json('data.id');
    }

    /** @return list<string> id butir urut kode */
    private function butirOrder(string $permintaan): array
    {
        return DB::table('permintaan.permintaan_item')->where('permintaan_pemeriksaan_id', $permintaan)->orderBy('kode_item')
            ->pluck('id')->map(fn ($v) => (string) $v)->all();
    }

    /** @param  array<string, mixed>  $ganti */
    private function ambil(string $item, array $ganti = []): TestResponse
    {
        return $this->minta('POST', "/api/v1/permintaan-item/$item/spesimen", ['jenis' => 'Darah vena', 'lokasi_pengambilan' => 'instalasi', ...$ganti]);
    }

    /** TT sintetis UJI30-101-1 di Bangsal UJI30. */
    private function tempatTidur(): string
    {
        $irna = UnitOrganisasi::query()->create(['kode' => 'UJI30-IRNA', 'nama' => 'Instalasi Rawat Inap (uji)', 'jenis' => 'instalasi', 'berlaku_dari' => '2026-01-01']);
        $ruang = UnitOrganisasi::query()->create(['kode' => 'UJI30-RA', 'nama' => 'Ruang uji', 'jenis' => 'ruang', 'induk_id' => $irna->id, 'berlaku_dari' => '2026-01-01']);
        $kelas = KelasRawat::query()->create(['kode' => 'UJI30-K3', 'nama' => 'Kelas uji', 'berlaku_dari' => '2026-01-01'])->id;
        $bangsal = Bangsal::query()->create(['kode' => 'UJI30-BA', 'nama' => 'Bangsal UJI30', 'unit_organisasi_id' => $ruang->id, 'status' => 'aktif'])->id;
        $gedung = $this->lokasi(null, 'gedung', 'UJI30', 'UJI30');
        $ruangLokasi = $this->lokasi($gedung, 'ruang', '101', 'UJI30-101', 'rawat_inap');
        $kamar = Kamar::query()->create(['bangsal_id' => $bangsal, 'lokasi_id' => $ruangLokasi])->id;

        return TempatTidur::query()->create([
            'kamar_id' => $kamar, 'lokasi_id' => $this->lokasi($ruangLokasi, 'tempat_tidur', '1', 'UJI30-101-1'), 'kode' => 'UJI30-101-1',
            'kelas_rawat_id' => $kelas, 'berlaku_dari' => '2026-01-01',
        ])->id;
    }

    private function lokasi(?string $induk, string $jenis, string $kode, string $kodeLengkap, ?string $fungsi = null): string
    {
        return Lokasi::query()->create([
            'induk_id' => $induk, 'jenis' => $jenis, 'kode' => $kode, 'kode_lengkap' => $kodeLengkap, 'nama' => "Lokasi $kodeLengkap",
            'fungsi' => $fungsi, 'status' => 'aktif', 'status_berlaku_dari' => '2026-01-01',
        ])->id;
    }
}
