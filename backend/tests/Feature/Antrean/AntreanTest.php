<?php

namespace Tests\Feature\Antrean;

use App\Modules\Akses\Models\Pengguna;
use App\Modules\Antrean\Models\Antrean;
use App\Modules\MasterData\Models\JadwalPraktik;
use App\Modules\Pendaftaran\Models\Pasien;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\AksesUji;
use Tests\Support\KlienSpa;
use Tests\Support\KontrakApi;
use Tests\Support\KunjunganUji;
use Tests\TestCase;

/** Tiket 2.8-be: antrean poli dari kunjungan, panggil/lewati/sisip/batal dengan aturan data induk, jejak jam server. */
class AntreanTest extends TestCase
{
    use AksesUji, KlienSpa, KontrakApi, KunjunganUji;

    private JadwalPraktik $jadwalPagi;

    private Pengguna $petugas;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanKunjungan();
        $this->jadwalPagi = $this->jadwal($this->dokter());
        $this->petugas = $this->petugasPendaftaran();
        $this->tugaskan($this->petugas, $this->buatPeran(['pendaftaran.antrean']));
        $this->masukSebagai($this->petugas);
    }

    /** Daftarkan kunjungan pasien baru lalu kembalikan antrean poli yang terbit dari event KunjunganDibuat. */
    private function antreanBaru(?Pasien $pasien = null): Antrean
    {
        $id = $this->daftarKunjungan($pasien ?? $this->pasien(), $this->jadwalPagi)->assertCreated()->json('data.id');

        return Antrean::query()->where('kunjungan_id', $id)->sole();
    }

    private function aksi(Antrean $antrean, string $aksi, array $isi = []): TestResponse
    {
        $respons = $this->minta('POST', "/api/v1/antrean/{$antrean->id}/{$aksi}", $isi);
        $this->assertSesuaiKontrak($respons, 'POST', "/antrean/{id}/{$aksi}");

        return $respons;
    }

    public function test_kunjungan_terdaftar_menerbitkan_antrean_poli_dan_batal_ikut(): void
    {
        $pertama = $this->antreanBaru();
        $kedua = $this->antreanBaru();
        $this->assertSame(['UJI-001', 'UJI-002'], [$pertama->nomor_tampil, $kedua->nomor_tampil]);
        $this->assertSame(['poli', 'uji', 'menunggu'], [$pertama->titik, $pertama->area, $pertama->status]);
        $this->assertSame(1, DB::table('kunjungan.antrean_jejak')->where('antrean_id', $pertama->id)->where('status', 'menunggu')->count());

        $this->minta('POST', "/api/v1/kunjungan/{$pertama->kunjungan_id}/batal", ['alasan_batal' => 'Pasien pulang'])->assertOk();
        $this->assertSame('batal', $pertama->refresh()->status);
        $this->assertSame('Kunjungan dibatalkan', $pertama->alasan_batal);

        // Antrean batal tidak membatalkan kunjungan.
        $this->aksi($kedua, 'batal', ['alasan_batal' => 'Tidak hadir sampai sesi berakhir'])->assertOk()
            ->assertJsonPath('data.status', 'batal')->assertJsonPath('data.dibatalkan_oleh.id', $this->petugas->id);
        $this->assertSame('terdaftar', DB::table('kunjungan.kunjungan')->where('id', $kedua->kunjungan_id)->value('status'));
        $this->aksi($kedua, 'batal', ['alasan_batal' => 'Ulang batal'])->assertStatus(409)->assertJsonPath('error.kode', 'sudah_batal');
        $audit = $this->assertAuditTerakhir('antrean.dibatalkan', $kedua->id);
        $this->assertStringContainsString('UJI-002', (string) $audit->sesudah);
    }

    public function test_setiap_transisi_menyimpan_jam_server_dan_pelaku(): void
    {
        $antrean = $this->antreanBaru();
        $this->travel(5)->minutes();
        $dipanggil = $this->aksi($antrean, 'panggil')->assertOk()
            ->assertJsonPath('data.status', 'dipanggil')->assertJsonPath('data.panggilan_ke', 1)->assertJsonPath('data.panggilan_maks', 3);
        $this->travel(2)->minutes();
        $this->aksi($antrean, 'lewati')->assertOk()->assertJsonPath('data.status', 'dilewati');
        $this->travel(3)->minutes();
        $jejak = $this->aksi($antrean, 'sisip')->assertOk()->assertJsonPath('data.status', 'menunggu')
            ->assertJsonPath('data.panggilan_ke', 0)->json('data.jejak');

        $this->assertSame(['menunggu', 'dipanggil', 'dilewati', 'menunggu'], array_column($jejak, 'status'));
        $this->assertSame(['07:00', '07:05', '07:07', '07:10'], array_map(
            fn ($j) => now()->parse($j['terjadi_pada'])->setTimezone((string) config('instalasi.zona_waktu'))->format('H:i'), $jejak));
        $this->assertSame(1, $jejak[1]['panggilan_ke']);
        $this->assertSame($this->petugas->id, $jejak[2]['oleh']['id']);
        $this->assertNotNull($dipanggil->json('data.boleh_panggil_pada'));

        $audit = $this->assertAuditTerakhir('antrean.dipanggil', $antrean->id);
        $pasien = DB::table('pasien.pasien')->join('kunjungan.kunjungan AS k', 'k.pasien_id', '=', 'pasien.pasien.id')
            ->where('k.id', $antrean->kunjungan_id)->first(['nama', 'no_rm']);
        $this->assertStringNotContainsString($pasien->nama, (string) $audit->sesudah);
        $this->assertStringNotContainsString($pasien->no_rm, (string) $audit->sesudah);
    }

    public function test_panggil_ulang_mengikuti_batas_dan_jeda_data_induk(): void
    {
        $antrean = $this->antreanBaru();
        $this->aksi($antrean, 'panggil')->assertOk();
        $this->aksi($antrean, 'panggil')->assertStatus(409)->assertJsonPath('error.kode', 'jeda_panggilan_belum_lewat')
            ->assertJsonPath('error.rincian.0.boleh_panggil_pada', now()->addSeconds(60)->toIso8601String());

        // Nilai diubah RS di data induk: jeda 10 detik, batas 2 kali.
        DB::table('kunjungan.aturan_antrean')->update(['jeda_panggilan_detik' => 10, 'batas_panggilan' => 2]);
        $this->travel(11)->seconds();
        $this->aksi($antrean, 'panggil')->assertOk()->assertJsonPath('data.panggilan_ke', 2)->assertJsonPath('data.panggilan_maks', 2);
        $this->travel(11)->seconds();
        $this->aksi($antrean, 'panggil')->assertStatus(409)->assertJsonPath('error.kode', 'batas_panggilan_tercapai');

        $this->aksi($antrean, 'sisip')->assertStatus(409)->assertJsonPath('error.kode', 'status_tidak_sesuai')
            ->assertJsonPath('error.rincian.0.status', 'dipanggil');
    }

    public function test_sisip_mengikuti_jumlah_nomor_di_data_induk_dan_ditolak_setelah_sesi(): void
    {
        $nomor = collect(range(1, 5))->map(fn () => $this->antreanBaru());
        $this->aksi($nomor[0], 'panggil')->assertOk();
        $this->aksi($nomor[0], 'lewati')->assertOk();

        // Bawaan data induk: sesudah 2 nomor menunggu berikutnya.
        $this->aksi($nomor[0], 'sisip')->assertOk();
        $urut = fn () => collect($this->minta('GET', '/api/v1/antrean?tanggal='.self::SENIN.'&poli_id='.$this->poli->id)
            ->assertOk()->json('data'))->pluck('nomor_tampil')->all();
        $this->assertSame(['UJI-002', 'UJI-003', 'UJI-001', 'UJI-004', 'UJI-005'], $urut());
        $this->assertSesuaiKontrak($this->minta('GET', '/api/v1/antrean?tanggal='.self::SENIN.'&poli_id='.$this->poli->id.'&batas=2'), 'GET', '/antrean')
            ->assertJsonCount(2, 'data')->assertJsonPath('meta.kursor_berikutnya', fn ($k) => is_string($k));

        DB::table('kunjungan.aturan_antrean')->update(['sisip_setelah' => 1, 'jeda_panggilan_detik' => 0]);
        $this->aksi($nomor[4], 'panggil')->assertOk();
        $this->aksi($nomor[4], 'lewati')->assertOk();
        $this->aksi($nomor[4], 'sisip')->assertOk();
        $this->assertSame(['UJI-002', 'UJI-005', 'UJI-003', 'UJI-001', 'UJI-004'], $urut());

        // Lebih dari jumlah yang menunggu → di akhir.
        DB::table('kunjungan.aturan_antrean')->update(['sisip_setelah' => 9]);
        $this->aksi($nomor[1], 'panggil')->assertOk();
        $this->aksi($nomor[1], 'lewati')->assertOk();
        $this->aksi($nomor[1], 'sisip')->assertOk();
        $this->assertSame(['UJI-005', 'UJI-003', 'UJI-001', 'UJI-004', 'UJI-002'], $urut());

        // Jam praktik jadwal sudah lewat (jam tutup dimajukan ke "sekarang").
        $this->aksi($nomor[2], 'panggil')->assertOk();
        $this->aksi($nomor[2], 'lewati')->assertOk();
        DB::table('kunjungan.jadwal_praktik')->where('id', $this->jadwalPagi->id)->update(['jam_mulai' => '06:00', 'jam_selesai' => '07:00']);
        $this->aksi($nomor[2], 'sisip')->assertStatus(409)->assertJsonPath('error.kode', 'sesi_berakhir');
    }

    public function test_izin_antrean_wajib_dan_lokasi_harus_ruang_aktif(): void
    {
        $antrean = $this->antreanBaru();
        $this->aksi($antrean, 'panggil', ['lokasi_id' => '01900000-0000-7000-8000-000000000000'])->assertStatus(422)
            ->assertJsonPath('error.kode', 'validasi_gagal');

        $this->masukSebagai($this->petugasPendaftaran());
        $this->aksi($antrean, 'panggil')->assertForbidden();
        $this->minta('GET', '/api/v1/antrean?tanggal='.self::SENIN.'&poli_id='.$this->poli->id)->assertForbidden();
        $this->assertSame('menunggu', $antrean->refresh()->status);
    }
}
