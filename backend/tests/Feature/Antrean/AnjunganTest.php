<?php

namespace Tests\Feature\Antrean;

use App\Modules\Akses\Models\Pengguna;
use App\Modules\Antrean\Models\Anjungan;
use App\Modules\MasterData\Models\JadwalPengecualian;
use App\Modules\MasterData\Models\Lokasi;
use App\Modules\MasterData\Models\Poliklinik;
use App\Modules\MasterData\Models\UnitOrganisasi;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\AksesUji;
use Tests\Support\KlienSpa;
use Tests\Support\KontrakApi;
use Tests\Support\KunjunganUji;
use Tests\TestCase;

/** Tiket 2.20-be: anjungan bertoken perangkat, nomor loket A/P, jadwal anjungan, antrean loket di layar petugas dan display. */
class AnjunganTest extends TestCase
{
    use AksesUji, KlienSpa, KontrakApi, KunjunganUji;

    private Lokasi $loket;

    private Pengguna $petugas;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanKunjungan();
        $lokasi = fn (string $kode, string $nama) => Lokasi::query()->create(['jenis' => 'ruang', 'fungsi' => 'administrasi', 'kode' => $kode, 'kode_lengkap' => $kode,
            'nama' => $nama, 'status' => 'aktif', 'status_berlaku_dari' => '2026-01-01']);
        $lokasi('LOBI', 'Lobi Uji');
        $this->loket = $lokasi('LOKET1', 'Loket Uji 1');
        $this->petugas = $this->petugasPendaftaran();
        $this->tugaskan($this->petugas, $this->buatPeran(['pendaftaran.antrean']));
        $this->masukSebagai($this->petugas);
    }

    private function token(string $kode = 'apm-uji'): string
    {
        Artisan::call('antrean:token-anjungan', ['kode' => $kode, '--lokasi' => 'LOBI']);
        Anjungan::query()->where('kode', $kode)->update(['lokasi_loket_id' => $this->loket->id]);

        return trim(explode("\n", Artisan::output())[0]);
    }

    /** Permintaan perangkat: tanpa cookie sesi petugas dan tanpa guard yang tersisa dari permintaan sebelumnya. */
    private function perangkat(string $metode, string $uri, ?string $token, array $data = []): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $this->app['session']->driver()->flush();
        $this->withCredentials = false;
        $this->unencryptedCookies = $this->defaultHeaders = [];
        return $this->json($metode, $uri, $data, $token === null ? [] : ['Authorization' => 'Bearer '.$token]);
    }

    private function nomor(string $token, ?string $alasan = null): TestResponse
    {
        $respons = $this->perangkat('POST', '/api/v1/anjungan/nomor-loket', $token, ['prioritas' => $alasan !== null, 'alasan_prioritas' => $alasan]);

        return $this->assertSesuaiKontrak($respons, 'POST', '/anjungan/nomor-loket');
    }

    public function test_nomor_a_p_berurutan_p_didahulukan_jejak_sesi_dan_panggil_loket(): void
    {
        $token = $this->token();
        $this->nomor($token)->assertCreated()->assertJsonPath('data.nomor_tampil', 'A-001')->assertJsonPath('data.menunggu_di_depan', 0)
            ->assertJsonPath('data.lokasi', 'Loket Uji 1')->assertJsonPath('data.tanggal', self::SENIN);
        $this->nomor($token)->assertJsonPath('data.nomor_tampil', 'A-002')->assertJsonPath('data.menunggu_di_depan', 1);
        $p1 = $this->nomor($token, 'lansia')->assertCreated()->assertJsonPath('data.nomor_tampil', 'P-001')
            ->assertJsonPath('data.prioritas', true)->assertJsonPath('data.menunggu_di_depan', 0);
        $this->nomor($token)->assertJsonPath('data.nomor_tampil', 'A-003')->assertJsonPath('data.menunggu_di_depan', 3);
        $this->nomor($token, 'ibu_hamil')->assertJsonPath('data.nomor_tampil', 'P-002')->assertJsonPath('data.menunggu_di_depan', 1);
        // Struk tanpa id antrean maupun data pasien.
        $this->assertSame(['nomor_tampil', 'prioritas', 'tanggal', 'diambil_pada', 'menunggu_di_depan', 'lokasi'], array_keys($p1->json('data')));

        // Alasan wajib bila prioritas, dilarang bila bukan, dan hanya empat kategori.
        $this->nomor($token, null)->assertCreated();
        $this->perangkat('POST', '/api/v1/anjungan/nomor-loket', $token, ['prioritas' => true])->assertStatus(422);
        $this->perangkat('POST', '/api/v1/anjungan/nomor-loket', $token, ['prioritas' => false, 'alasan_prioritas' => 'lansia'])->assertStatus(422);
        $this->perangkat('POST', '/api/v1/anjungan/nomor-loket', $token, ['prioritas' => true, 'alasan_prioritas' => 'diagnosis'])->assertStatus(422);
        $this->perangkat('POST', '/api/v1/anjungan/nomor-loket', $token, [])->assertStatus(422);

        $daftar = $this->assertSesuaiKontrak($this->minta('GET', '/api/v1/antrean?tanggal='.self::SENIN.'&titik=loket'), 'GET', '/antrean')->assertOk();
        $this->assertSame(['P-001', 'P-002', 'A-001', 'A-002', 'A-003', 'A-004'], array_column($daftar->json('data'), 'nomor_tampil'));
        $this->assertSame(['lansia', 'apm', null, 'loket'], [$daftar->json('data.0.alasan_prioritas'), $daftar->json('data.0.sumber'),
            $daftar->json('data.0.pasien'), $daftar->json('data.0.area')]);
        $this->minta('GET', '/api/v1/antrean?tanggal='.self::SENIN.'&titik=loket&poli_id='.$this->poli->id)->assertStatus(422);
        $this->minta('GET', '/api/v1/antrean?tanggal='.self::SENIN)->assertStatus(422);

        // Halaman berikutnya dengan kursor keyset (urutan, id).
        $hal = $this->minta('GET', '/api/v1/antrean?tanggal='.self::SENIN.'&titik=loket&batas=3')->assertOk();
        $lanjut = $this->minta('GET', '/api/v1/antrean?tanggal='.self::SENIN.'&titik=loket&batas=3&kursor='.$hal->json('meta.kursor_berikutnya'));
        $this->assertSame(['A-002', 'A-003', 'A-004'], array_column($lanjut->json('data'), 'nomor_tampil'));

        // Panggil di loket wajib menyebut loketnya; jam ambil dan jam dipanggil ada di jejak.
        $id = $daftar->json('data.0.id');
        $this->minta('POST', "/api/v1/antrean/{$id}/panggil")->assertStatus(422)->assertJsonPath('error.kode', 'validasi_gagal');
        $this->assertSesuaiKontrak($this->minta('POST', "/api/v1/antrean/{$id}/panggil", ['lokasi_id' => $this->loket->id]), 'POST', '/antrean/{id}/panggil')
            ->assertOk()->assertJsonPath('data.lokasi_panggil.id', $this->loket->id);
        $this->assertSame(['menunggu', 'dipanggil'], DB::table('kunjungan.antrean_jejak')->where('antrean_id', $id)->orderBy('terjadi_pada')->pluck('status')->all());

        // Display area loket: nomor + tujuan loket, tanpa inisial.
        Artisan::call('antrean:token-display', ['kode' => 'tv-loket', '--area' => ['loket']]);
        $tv = trim(explode("\n", Artisan::output())[0]);
        $display = $this->assertSesuaiKontrak($this->perangkat('GET', '/api/v1/display/loket', $tv), 'GET', '/display/{area}')->assertOk()
            ->assertJsonPath('data.area.nama', 'Loket Uji 1')->assertJsonPath('data.dipanggil.0.nomor_tampil', 'P-001')
            ->assertJsonPath('data.dipanggil.0.inisial', null)->assertJsonPath('data.dipanggil.0.tujuan', 'Loket Uji 1')
            ->assertJsonPath('data.berikutnya.0.nomor_tampil', 'P-002');
        $this->assertStringNotContainsString('lansia', (string) $display->getContent());

        // Sesi anjungan tercatat per nomor; alasan prioritas tidak masuk audit.
        $anjungan = Anjungan::query()->where('kode', 'apm-uji')->sole();
        $this->assertSame(6, DB::table('kunjungan.sesi_anjungan')->where('anjungan_id', $anjungan->id)
            ->where('layanan', 'nomor_loket')->where('hasil', 'ke_loket')->whereNull('kunjungan_id')->count());
        $this->assertFalse(DB::table('audit.log_aktivitas')->where('sesudah', 'like', '%lansia%')->orWhere('sebelum', 'like', '%lansia%')->exists());

        // Lewati lalu sisip pada hari berikutnya: sesi loket sudah berakhir.
        $this->travel(61)->seconds();
        $this->minta('POST', "/api/v1/antrean/{$id}/lewati")->assertOk();
        $this->travel(1)->days();
        $this->masukSebagai($this->petugas);
        $this->minta('POST', "/api/v1/antrean/{$id}/sisip")->assertStatus(409)->assertJsonPath('error.kode', 'sesi_berakhir');
    }

    public function test_token_anjungan_hanya_membuka_anjungan_dan_sebaliknya(): void
    {
        $token = $this->token();
        Artisan::call('antrean:token-display', ['kode' => 'tv-uji']);
        $tv = trim(explode("\n", Artisan::output())[0]);

        // Tanpa token, token acak, dan sesi cookie petugas → 401; token display → 403.
        foreach (['/api/v1/anjungan', '/api/v1/anjungan/jadwal-praktik'] as $uri) {
            $this->perangkat('GET', $uri, null)->assertUnauthorized();
            $this->perangkat('GET', $uri, 'acak|rahasia')->assertUnauthorized();
            $this->minta('GET', $uri)->assertUnauthorized();
            $this->perangkat('GET', $uri, $tv)->assertForbidden();
        }
        $this->minta('POST', '/api/v1/anjungan/nomor-loket', ['prioritas' => false])->assertUnauthorized();
        $this->perangkat('POST', '/api/v1/anjungan/nomor-loket', $tv, ['prioritas' => false])->assertForbidden();

        // Token anjungan di display → 403; di endpoint sesi → 401.
        $this->perangkat('GET', '/api/v1/display/uji', $token)->assertForbidden();
        $this->perangkat('GET', '/api/v1/antrean?tanggal='.self::SENIN.'&titik=loket', $token)->assertUnauthorized();
        $this->perangkat('GET', '/api/v1/jadwal-praktik', $token)->assertUnauthorized();

        $this->assertSesuaiKontrak($this->perangkat('GET', '/api/v1/anjungan', $token), 'GET', '/anjungan')->assertOk()
            ->assertJsonPath('data', ['kode' => 'apm-uji', 'lokasi' => 'Lobi Uji', 'mode' => 'pendaftaran', 'batas_diam_detik' => 60,
                'layanan' => ['nomor_loket', 'jadwal_praktik']]);
        $this->assertNotNull(Anjungan::query()->where('kode', 'apm-uji')->value('terakhir_aktif_pada'));

        DB::table('kunjungan.anjungan')->where('kode', 'apm-uji')->update(['mode' => 'farmasi']);
        $this->perangkat('GET', '/api/v1/anjungan', $token)->assertStatus(409)->assertJsonPath('error.kode', 'mode_belum_didukung');
        $this->perangkat('POST', '/api/v1/anjungan/nomor-loket', $token, ['prioritas' => false])->assertStatus(409);
        DB::table('kunjungan.anjungan')->where('kode', 'apm-uji')->update(['mode' => 'pendaftaran', 'status' => 'nonaktif']);
        $this->perangkat('GET', '/api/v1/anjungan', $token)->assertUnauthorized();

        // Token baru mengaktifkan lagi dan mencabut token lama; --cabut menonaktifkan dan tercatat di audit.
        $baru = $this->token();
        $this->perangkat('GET', '/api/v1/anjungan', $token)->assertUnauthorized();
        $this->perangkat('GET', '/api/v1/anjungan', $baru)->assertOk();
        $this->artisan('antrean:token-anjungan', ['kode' => 'apm-uji', '--cabut' => true])->assertSuccessful();
        $this->perangkat('GET', '/api/v1/anjungan', $baru)->assertUnauthorized();
        $this->assertAuditTerakhir('antrean.token_anjungan_dicabut', Anjungan::query()->where('kode', 'apm-uji')->value('id'));
        $this->artisan('antrean:token-anjungan', ['kode' => 'apm-baru'])->assertFailed();
        $this->artisan('antrean:token-anjungan', ['kode' => 'apm-baru', '--lokasi' => 'TIDAK-ADA'])->assertFailed();
    }

    public function test_jadwal_anjungan_status_nama_netral_tanpa_id_pegawai_dan_rentang(): void
    {
        $token = $this->token();
        $unit = UnitOrganisasi::query()->create(['kode' => 'POLI-SENS', 'nama' => 'Poli Sensitif Uji', 'jenis' => 'poli', 'berlaku_dari' => '2026-01-01']);
        $sensitif = Poliklinik::query()->create(['kode' => 'JIWA', 'nama' => 'Poli Jiwa', 'unit_organisasi_id' => $unit->id]);
        $sensitif->forceFill(['nama_netral' => 'Klinik 7', 'antrean_prefiks' => 'K7', 'antrean_area' => 'k7'])->save();
        $tersedia = $this->jadwal($this->dokter(), ['jam_mulai' => '08:00']);
        $cuti = $this->jadwal($this->dokter(), ['jam_mulai' => '09:00']);
        $penuh = $this->jadwal($this->dokter(), ['jam_mulai' => '10:00', 'kuota_jkn' => 0, 'kuota_non_jkn' => 0]);
        $jiwa = $this->jadwal($this->dokter(), ['jam_mulai' => '11:00', 'poli_id' => $sensitif->id]);
        $this->jadwal($this->dokter(), ['hari' => 2]);
        JadwalPengecualian::query()->create(['jenis' => 'cuti', 'pegawai_id' => $cuti->pegawai_id, 'dari_tanggal' => self::SENIN,
            'sampai_tanggal' => self::SENIN, 'alasan' => 'Cuti uji rahasia', 'status' => 'aktif']);

        $jadwal = fn (string $query = '') => $this->assertSesuaiKontrak(
            $this->perangkat('GET', '/api/v1/anjungan/jadwal-praktik'.$query, $token), 'GET', '/anjungan/jadwal-praktik');
        $respons = $jadwal()->assertOk();
        $this->assertSame([['Poli Contoh', '08:00', 'tersedia'], ['Poli Contoh', '09:00', 'tidak_praktik'], ['Poli Contoh', '10:00', 'penuh'],
            ['Klinik 7', '11:00', 'tersedia']], array_map(fn ($j) => [$j['poliklinik']['nama'], $j['jam_mulai'], $j['status']], $respons->json('data')));
        $isi = (string) $respons->getContent();
        foreach ([$tersedia->pegawai_id, $cuti->pegawai_id, $penuh->pegawai_id, $jiwa->pegawai_id, $tersedia->id, 'Jiwa', 'kuota', 'Cuti uji', 'cuti'] as $rahasia) {
            $this->assertStringNotContainsString((string) $rahasia, $isi);
        }

        $jadwal('?batas=2')->assertJsonCount(2, 'data');
        $hal = $this->perangkat('GET', '/api/v1/anjungan/jadwal-praktik?batas=2', $token)->json('meta.kursor_berikutnya');
        $this->assertSame(['10:00', '11:00'], array_column($jadwal('?batas=2&kursor='.$hal)->json('data'), 'jam_mulai'));
        $jadwal('?poli_id='.$sensitif->id)->assertJsonCount(1, 'data');
        $jadwal('?tanggal=2026-11-03')->assertJsonCount(1, 'data')->assertJsonPath('data.0.status', 'tersedia');
        $jadwal('?tanggal=2026-11-08')->assertOk();
        $jadwal('?tanggal=2026-11-09')->assertStatus(422);
        $jadwal('?tanggal=2026-11-01')->assertStatus(422);
    }

    public function test_batas_laju_per_perangkat(): void
    {
        config(['rate_limit.anjungan.per_perangkat_per_menit' => 2]);
        $token = $this->token();
        $this->perangkat('GET', '/api/v1/anjungan', $token)->assertOk();
        $this->perangkat('POST', '/api/v1/anjungan/nomor-loket', $token, ['prioritas' => false])->assertCreated();
        $this->assertSesuaiKontrak($this->perangkat('GET', '/api/v1/anjungan/jadwal-praktik', $token), 'GET', '/anjungan/jadwal-praktik')->assertStatus(429);
        // Perangkat lain di IP yang sama tidak ikut tertahan.
        $this->perangkat('GET', '/api/v1/anjungan', $this->token('apm-lain'))->assertOk();
    }
}
