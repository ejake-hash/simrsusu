<?php

namespace Tests\Feature\Pendaftaran;

use App\Modules\Akses\Models\Izin;
use App\Modules\Akses\Models\Menu;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\Akses\Models\Peran;
use App\Modules\Akses\Models\PeranIzin;
use App\Modules\Akses\Models\PeranMenu;
use App\Modules\Antrean\Models\Antrean;
use App\Modules\Pendaftaran\Application\BentukEpisodeIgd;
use App\Modules\Pendaftaran\Application\KelolaKunjungan;
use App\Modules\Pendaftaran\Events\EpisodeIgdDibentuk;
use App\Modules\Pendaftaran\Events\KunjunganDibuat;
use App\Modules\Pendaftaran\Events\KunjunganIgdDibuat;
use App\Modules\Pendaftaran\Models\Kunjungan;
use App\Modules\Pendaftaran\Models\Pasien;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\AksesUji;
use Tests\Support\KlienSpa;
use Tests\Support\KontrakApi;
use Tests\Support\KunjunganUji;
use Tests\TestCase;

/** Tiket 2.23-be: registrasi cepat IGD, episode rawat jalan harian, tanda lebih 6 jam (kontrak v0.31.0). Data sintetis saja. */
class KunjunganIgdTest extends TestCase
{
    use AksesUji, KlienSpa, KontrakApi, KunjunganUji;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanKunjungan();
    }

    public function test_registrasi_minimal_jam_tiba_server_nomor_igd_tanpa_relasi_perawatan(): void
    {
        Event::fake([KunjunganIgdDibuat::class, KunjunganDibuat::class]);
        $pasien = $this->pasien();
        $this->masukSebagai($this->petugasIgd());

        // Jam tiba dari klien diabaikan: jam server.
        $respons = $this->daftarIgd($pasien, ['keluhan' => 'Sesak napas mendadak', 'tiba_pada' => '2020-01-01T00:00:00Z'])->assertCreated()
            ->assertJsonPath('data.jenis_kunjungan', 'igd')->assertJsonPath('data.nomor', 'IGD-20261102-0001')
            ->assertJsonPath('data.status', 'terdaftar')->assertJsonPath('data.poliklinik', null)->assertJsonPath('data.dokter', null)
            ->assertJsonPath('data.tanggal', self::SENIN)->assertJsonPath('data.lebih_6_jam', false)
            ->assertJsonPath('data.episode.jenis', 'rawat_jalan_harian')->assertJsonPath('data.episode.mulai_tanggal', self::SENIN)
            ->assertJsonPath('data.pasien.no_rm', $pasien->no_rm)->assertJsonMissingPath('data.pasien.nik')
            ->assertJsonPath('data.keluhan', 'Sesak napas mendadak');
        $this->assertSesuaiKontrak($respons, 'POST', '/kunjungan-igd');
        $this->assertTrue(Carbon::parse((string) $respons->json('data.tiba_pada'))->equalTo(now()));
        $this->assertStringContainsString('no-store', (string) $respons->headers->get('Cache-Control'));

        $id = (string) $respons->json('data.id');
        $audit = $this->assertAuditTerakhir('kunjungan.dibuat', $id);
        $this->assertStringNotContainsString($pasien->nama, (string) $audit->sesudah);
        $this->assertStringNotContainsString('Sesak napas', (string) $audit->sesudah);
        $this->assertFalse(DB::table('akses.relasi_perawatan')->where('sumber_id', $id)->exists());

        Event::assertDispatched(KunjunganIgdDibuat::class, fn (KunjunganIgdDibuat $e) => $e->kunjunganId === $id
            && $e->pasienId === $pasien->id && $e->episodeId === $respons->json('data.episode.id') && $e->caraBayar === 'non_jkn');
        Event::assertNotDispatched(KunjunganDibuat::class);
    }

    public function test_episode_sama_dengan_kunjungan_poli_hari_itu_dan_baru_bila_belum_ada(): void
    {
        $petugas = $this->petugasPendaftaran();
        $this->tugaskan($petugas, $this->petugasIgdPeran());
        $this->masukSebagai($petugas);
        $sudahPoli = $this->pasien();
        $episodePoli = $this->daftarKunjungan($sudahPoli, $this->jadwal($this->dokter()))->assertCreated()->json('data.episode.id');

        // 7.00.2 baris 1: IGD di hari yang sama masuk episode rawat jalan harian yang sudah ada.
        $this->daftarIgd($sudahPoli)->assertCreated()->assertJsonPath('data.episode.id', $episodePoli)
            ->assertJsonPath('data.nomor', 'IGD-20261102-0002');
        $this->assertSame(1, DB::table('kunjungan.episode')->where('pasien_id', $sudahPoli->id)->count());

        $baru = $this->daftarIgd($this->pasien())->assertCreated()->assertJsonPath('data.episode.mulai_tanggal', self::SENIN)
            ->assertJsonPath('data.episode.id', fn ($id) => $id !== $episodePoli);
        $this->assertSame('aktif', DB::table('kunjungan.episode')->where('id', $baru->json('data.episode.id'))->value('status'));

        // Tanggal episode = tanggal tiba zona RS, bukan tanggal UTC (06.30 WIB = 23.30 UTC hari sebelumnya).
        $this->travelTo(Carbon::parse(self::SENIN.' 06:30', (string) config('instalasi.zona_waktu')));
        $this->masukSebagai($petugas);
        $this->daftarIgd($this->pasien())->assertCreated()->assertJsonPath('data.tanggal', self::SENIN)
            ->assertJsonPath('data.episode.mulai_tanggal', self::SENIN);
    }

    public function test_satu_kunjungan_igd_aktif_per_pasien_dan_batal_atau_selesai_membuka_lagi(): void
    {
        $petugas = $this->petugasPendaftaran();
        $this->tugaskan($petugas, $this->petugasIgdPeran());
        $this->masukSebagai($petugas);
        $pasien = $this->pasien();

        $pertama = $this->daftarIgd($pasien)->assertCreated()->json('data.id');
        $ganda = $this->daftarIgd($pasien)->assertStatus(409)->assertJsonPath('error.kode', 'kunjungan_igd_aktif')
            ->assertJsonPath('error.rincian.0.kunjungan_id', $pertama);
        $this->assertSesuaiKontrak($ganda, 'POST', '/kunjungan-igd');
        Kunjungan::query()->whereKey($pertama)->update(['status' => 'dilayani']);
        $this->daftarIgd($pasien)->assertStatus(409);

        // Penjaga terakhir di basis data (indeks parsial), di savepoint agar transaksi uji tetap hidup.
        $episodeId = Kunjungan::query()->findOrFail($pertama)->episode_id;
        try {
            DB::transaction(fn () => DB::table('kunjungan.kunjungan')->insert([
                'id' => (string) Str::uuid7(), 'nomor' => 'IGD-UJI-GANDA', 'pasien_id' => $pasien->id, 'episode_id' => $episodeId,
                'jenis_kunjungan' => 'igd', 'tanggal' => self::SENIN, 'tiba_pada' => now(), 'cara_bayar' => 'non_jkn',
            ]));
            $this->fail('Kunjungan IGD aktif ganda harus ditolak basis data.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('kunjungan_igd_aktif_unik', $e->getMessage());
        }
        // IGD wajib jam tiba; poliklinik wajib jadwal, poli, dan dokter.
        foreach ([['jenis_kunjungan' => 'igd', 'tiba_pada' => null], ['jenis_kunjungan' => 'poliklinik', 'tiba_pada' => null]] as $isi) {
            try {
                DB::transaction(fn () => DB::table('kunjungan.kunjungan')->insert([
                    'id' => (string) Str::uuid7(), 'nomor' => 'UJI-'.Str::random(6), 'pasien_id' => $this->pasien()->id, 'episode_id' => $episodeId,
                    'tanggal' => self::SENIN, 'cara_bayar' => 'non_jkn', ...$isi,
                ]));
                $this->fail('CHECK kunjungan_jenis_cek harus menolak '.$isi['jenis_kunjungan']);
            } catch (QueryException $e) {
                $this->assertStringContainsString('kunjungan_jenis_cek', $e->getMessage());
            }
        }

        Kunjungan::query()->whereKey($pertama)->update(['status' => 'selesai']);
        $kedua = $this->daftarIgd($pasien)->assertCreated()->assertJsonPath('data.episode.id', $episodeId)->json('data.id');
        $batal = $this->minta('POST', "/api/v1/kunjungan/$kedua/batal", ['alasan_batal' => 'Salah pilih pasien'])->assertOk()
            ->assertJsonPath('data.status', 'batal')->assertJsonPath('data.poliklinik', null);
        $this->assertSesuaiKontrak($batal, 'POST', '/kunjungan/{id}/batal');
        $this->daftarIgd($pasien)->assertCreated();
    }

    public function test_izin_pasien_digabung_dan_validasi_isian(): void
    {
        $pasien = $this->pasien();
        $url = '/api/v1/kunjungan-igd';

        // Tanpa igd.pendaftaran (termasuk petugas pendaftaran poli): 403, sebelum isian divalidasi.
        foreach ([$this->buatPengguna(), $this->petugasPendaftaran()] as $akun) {
            $this->masukSebagai($akun);
            $this->daftarIgd($pasien)->assertForbidden();
            $this->minta('POST', $url, [])->assertForbidden();
            $this->minta('GET', $url)->assertForbidden();
        }
        // Hanya lihat: daftar boleh, registrasi tidak.
        $this->masukSebagai($this->petugasIgd(['igd.pendaftaran.input']));
        $this->minta('GET', $url)->assertOk();
        $this->daftarIgd($pasien)->assertForbidden();
        $this->assertFalse(Kunjungan::query()->where('pasien_id', $pasien->id)->exists());

        $this->masukSebagai($this->petugasIgd());
        $bpjs = $this->penjamin('bpjs');
        $this->daftarIgd($pasien, ['cara_bayar' => 'jkn'])->assertStatus(422)->assertJsonPath('error.kode', 'penjamin_wajib');
        $this->daftarIgd($pasien, ['penjamin_id' => $bpjs->id])->assertStatus(422)->assertJsonPath('error.kode', 'penjamin_tidak_sesuai');
        $this->daftarIgd($pasien, ['cara_bayar' => null])->assertStatus(422);
        $this->daftarIgd($pasien, ['pasien_id' => 'bukan-uuid'])->assertStatus(422);
        $this->daftarIgd($pasien, ['keluhan' => str_repeat('a', 1001)])->assertStatus(422);
        $this->assertSesuaiKontrak($this->daftarIgd($pasien, ['pasien_id' => (string) Str::uuid7()])->assertNotFound(), 'POST', '/kunjungan-igd');

        $tujuan = $this->pasien();
        $pasien->forceFill(['digabung_ke_id' => $tujuan->id, 'digabung_pada' => now()])->save();
        $this->daftarIgd($pasien)->assertStatus(409)->assertJsonPath('error.kode', 'pasien_digabung');

        $this->tandaiInstalasiSiap(false);
        $this->daftarIgd($tujuan)->assertStatus(409)->assertJsonPath('error.kode', 'instalasi_belum_siap');
        $this->tandaiInstalasiSiap();
        $this->daftarIgd($tujuan, ['cara_bayar' => 'jkn', 'penjamin_id' => $bpjs->id])->assertCreated()
            ->assertJsonPath('data.penjamin.jenis', 'bpjs');
    }

    public function test_tidak_membuat_antrean_poli_maupun_task_antrean_dan_tidak_batal_otomatis(): void
    {
        $petugas = $this->petugasPendaftaran();
        $this->tugaskan($petugas, $this->petugasIgdPeran());
        $this->masukSebagai($petugas);
        $bpjs = $this->penjamin('bpjs');
        $jkn = ['cara_bayar' => 'jkn', 'penjamin_id' => $bpjs->id];

        // Pembanding: kunjungan poli JKN tetap membuat antrean poli lewat KunjunganDibuat.
        $poli = $this->daftarKunjungan($this->pasien(), $this->jadwal($this->dokter()), $jkn)->assertCreated()->json('data.id');
        $this->assertTrue(Antrean::query()->where('kunjungan_id', $poli)->where('titik', 'poli')->exists());

        $igd = $this->daftarIgd($this->pasien(), $jkn)->assertCreated()->json('data.id');
        $this->assertFalse(Antrean::query()->where('kunjungan_id', $igd)->exists());
        $this->assertFalse(DB::table('kunjungan.antrean_task')->where('kunjungan_id', $igd)->exists());
        // Papan kunjungan poli tidak memuat IGD.
        $this->minta('GET', '/api/v1/kunjungan?tanggal='.self::SENIN.'&poli_id='.$this->poli->id)->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $poli);

        // Batal otomatis jam tutup poli hanya untuk kunjungan berjadwal.
        $this->travelTo(Carbon::parse('2026-11-03 08:00', (string) config('instalasi.zona_waktu')));
        app(KelolaKunjungan::class)->batalkanTakDilayani();
        $this->assertSame('terdaftar', Kunjungan::query()->findOrFail($igd)->status);
        $this->assertSame('batal', Kunjungan::query()->findOrFail($poli)->status);
    }

    public function test_daftar_igd_aktif_urut_jam_tiba_dan_tanda_lebih_6_jam(): void
    {
        $petugas = $this->petugasIgd();
        $this->masukSebagai($petugas);
        $awal = $this->daftarIgd($this->pasien())->assertCreated()->json('data.id');
        $this->travel(10)->minutes();
        $this->masukSebagai($petugas);
        $kemudian = $this->daftarIgd($this->pasien())->assertCreated()->json('data.id');
        $selesai = $this->daftarIgd($this->pasien())->assertCreated()->json('data.id');
        Kunjungan::query()->whereKey($selesai)->update(['status' => 'selesai']);

        $url = '/api/v1/kunjungan-igd';
        $daftar = $this->minta('GET', $url)->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $awal)->assertJsonPath('data.1.id', $kemudian)->assertJsonPath('data.0.lebih_6_jam', false);
        $this->assertSesuaiKontrak($daftar, 'GET', '/kunjungan-igd');
        $this->assertStringContainsString('no-store', (string) $daftar->headers->get('Cache-Control'));
        $halaman = $this->minta('GET', "$url?batas=1")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $awal);
        $this->minta('GET', "$url?batas=1&kursor=".urlencode((string) $halaman->json('meta.kursor_berikutnya')))->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $kemudian);
        $this->minta('GET', "$url?status=selesai")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $selesai);
        $this->minta('GET', "$url?batas=201")->assertStatus(422);
        $this->minta('GET', "$url?status=pulang")->assertStatus(422);

        // Tepat 6 jam sejak tiba belum "melewati"; 6 jam + 1 menit sudah. Sesi menganggur > 15 menit → masuk ulang.
        $tiba = Carbon::parse(self::SENIN.' 07:00', (string) config('instalasi.zona_waktu'));
        $this->travelTo($tiba->copy()->addHours(6));
        $this->masukSebagai($petugas);
        $this->minta('GET', $url)->assertJsonPath('data.0.id', $awal)->assertJsonPath('data.0.lebih_6_jam', false);
        $this->travelTo($tiba->copy()->addHours(6)->addMinute());
        $this->masukSebagai($petugas);
        $lewat = $this->minta('GET', $url)->assertOk()->assertJsonPath('data.0.id', $awal)->assertJsonPath('data.0.lebih_6_jam', true)
            ->assertJsonPath('data.1.lebih_6_jam', false);
        $this->assertSesuaiKontrak($lewat, 'GET', '/kunjungan-igd');
        // Yang sudah selesai tidak lagi ditandai; episode baru dipindah oleh tugas terjadwal (tes berikutnya).
        $this->minta('GET', "$url?status=selesai")->assertJsonPath('data.0.lebih_6_jam', false);
        $this->assertSame('rawat_jalan_harian', DB::table('kunjungan.episode')
            ->where('id', Kunjungan::query()->findOrFail($awal)->episode_id)->value('jenis'));
    }

    public function test_igd_lebih_6_jam_pindah_ke_episode_baru_beserta_aktivitasnya(): void
    {
        Event::fake([EpisodeIgdDibentuk::class]);
        $petugas = $this->petugasPendaftaran();
        $this->tugaskan($petugas, $this->petugasIgdPeran());
        $this->masukSebagai($petugas);

        // A: poli + IGD di episode harian yang sama. B: hanya IGD. C: IGD selesai. D: IGD batal.
        $a = $this->pasien();
        $poliA = $this->daftarKunjungan($a, $this->jadwal($this->dokter()))->assertCreated()->json('data.id');
        $igdA = $this->daftarIgd($a)->assertCreated();
        $episodeRj = (string) $igdA->json('data.episode.id');
        $igdA = (string) $igdA->json('data.id');
        $b = $this->pasien();
        $igdB = $this->daftarIgd($b)->assertCreated();
        $episodeB = (string) $igdB->json('data.episode.id');
        $igdB = (string) $igdB->json('data.id');
        $igdC = (string) $this->daftarIgd($this->pasien())->assertCreated()->json('data.id');
        Kunjungan::query()->whereKey($igdC)->update(['status' => 'selesai']);
        $igdD = (string) $this->daftarIgd($this->pasien())->assertCreated()->json('data.id');
        $this->minta('POST', "/api/v1/kunjungan/$igdD/batal", ['alasan_batal' => 'Pasien pulang paksa'])->assertOk();
        $sebelum = fn (string $id) => Kunjungan::query()->findOrFail($id)->episode_id;
        $episodeC = $sebelum($igdC);
        $episodeD = $sebelum($igdD);

        // Aktivitas: order dan catatan kunjungan IGD A, catatan kunjungan poli A (tetap di episode RJ).
        $orderIgd = $this->order($igdA, $a, $episodeRj, $petugas);
        $catatanIgd = $this->catatan($igdA, $a, $episodeRj, $petugas);
        $catatanPoli = $this->catatan((string) $poliA, $a, $episodeRj, $petugas);

        $tiba = Carbon::parse(self::SENIN.' 07:00', (string) config('instalasi.zona_waktu'));
        $this->travelTo($tiba->copy()->addHours(6));
        $this->artisan('kunjungan:episode-igd')->expectsOutput('Kunjungan IGD dipindah: 0')->assertSuccessful();
        $this->assertSame($episodeRj, $sebelum($igdA));

        $this->travelTo($tiba->copy()->addHours(6)->addMinute());
        $this->artisan('kunjungan:episode-igd')->expectsOutput('Kunjungan IGD dipindah: 2')->assertSuccessful();

        $kA = Kunjungan::query()->findOrFail($igdA);
        $episodeIgd = DB::table('kunjungan.episode')->where('id', $kA->episode_id)->first();
        $this->assertSame(['igd_lebih_6_jam', self::SENIN, 'aktif', $a->id], [$episodeIgd->jenis, $episodeIgd->mulai_tanggal, $episodeIgd->status, $episodeIgd->pasien_id]);
        $this->assertSame(1, $kA->revisi);
        $this->assertSame($kA->episode_id, DB::table('permintaan.permintaan_pemeriksaan')->where('id', $orderIgd)->value('episode_id'));
        $this->assertSame($kA->episode_id, DB::table('klinis.catatan_klinis')->where('id', $catatanIgd)->value('episode_id'));
        // Kunjungan poli dan catatannya tetap di episode RJ, yang masih aktif.
        $this->assertSame($episodeRj, $sebelum((string) $poliA));
        $this->assertSame($episodeRj, DB::table('klinis.catatan_klinis')->where('id', $catatanPoli)->value('episode_id'));
        $this->assertSame('aktif', DB::table('kunjungan.episode')->where('id', $episodeRj)->value('status'));

        // Episode RJ yang hanya berisi IGD batal dengan alasan; IGD selesai/batal tidak disentuh.
        $lamaB = DB::table('kunjungan.episode')->where('id', $episodeB)->first();
        $this->assertSame(['batal', 'Kunjungan dipindah ke episode IGD > 6 jam'], [$lamaB->status, $lamaB->alasan_batal]);
        $this->assertSame('igd_lebih_6_jam', DB::table('kunjungan.episode')->where('id', $sebelum($igdB))->value('jenis'));
        $this->assertSame([$episodeC, $episodeD], [$sebelum($igdC), $sebelum($igdD)]);

        $audit = $this->assertAuditTerakhir('episode.dipindah_igd_6_jam', $kA->episode_id);
        $this->assertNull($audit->pengguna_id);
        $this->assertStringNotContainsString($a->nama, (string) $audit->sesudah);
        $this->assertStringNotContainsString('Uji sintetis', (string) $audit->sesudah);
        Event::assertDispatchedTimes(EpisodeIgdDibentuk::class, 2);
        Event::assertDispatched(EpisodeIgdDibentuk::class, fn (EpisodeIgdDibentuk $e) => $e->kunjunganId === $igdA
            && $e->episodeLamaId === $episodeRj && $e->episodeId === $kA->episode_id);

        // Diulang: tidak ada episode kedua.
        $this->artisan('kunjungan:episode-igd')->expectsOutput('Kunjungan IGD dipindah: 0')->assertSuccessful();
        $this->assertSame(1, DB::table('kunjungan.episode')->where('pasien_id', $a->id)->where('jenis', 'igd_lebih_6_jam')->count());

        $this->masukSebagai($petugas);
        $daftar = $this->minta('GET', '/api/v1/kunjungan-igd')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.episode.jenis', 'igd_lebih_6_jam')->assertJsonPath('data.0.lebih_6_jam', true);
        $this->assertSesuaiKontrak($daftar, 'GET', '/kunjungan-igd');
    }

    /** Tabel baru ber-`episode_id` wajib ikut dipindah (atau dikecualikan dengan sengaja di sini), supaya tidak tertinggal di episode lama. */
    public function test_semua_tabel_ber_episode_id_tercakup_pemindahan_igd(): void
    {
        $ada = collect(DB::select("SELECT c.table_schema || '.' || c.table_name AS tabel FROM information_schema.columns c
            JOIN information_schema.tables t ON t.table_schema = c.table_schema AND t.table_name = c.table_name AND t.table_type = 'BASE TABLE'
            WHERE c.column_name = 'episode_id'"))->pluck('tabel')->sort()->values()->all();
        // kunjungan.perpindahan_pos dikecualikan dengan sengaja (2.26): hanya ditambah, episode_id = potret saat perintah, dan
        // kunjungan yang punya perintah menunggu pindah tidak dipindah tugas terjadwal.
        $tercakup = collect([...BentukEpisodeIgd::TABEL_AKTIVITAS, 'kunjungan.kunjungan', 'kunjungan.perpindahan_pos'])->sort()->values()->all();

        $this->assertSame($tercakup, $ada, 'Tabel ber-episode_id baru: tambahkan ke BentukEpisodeIgd::TABEL_AKTIVITAS.');
    }

    public function test_sep_kunjungan_igd_ditolak_422(): void
    {
        $petugas = $this->petugasIgd();
        $this->tugaskan($petugas, $this->buatPeran(['pendaftaran.sep']));
        $this->masukSebagai($petugas);
        $id = $this->daftarIgd($this->pasien(), ['cara_bayar' => 'jkn', 'penjamin_id' => $this->penjamin('bpjs')->id])->assertCreated()->json('data.id');

        $tolak = $this->minta('POST', "/api/v1/kunjungan/$id/sep", [])->assertStatus(422)->assertJsonPath('error.kode', 'jenis_belum_didukung');
        $this->assertSesuaiKontrak($tolak, 'POST', '/kunjungan/{id}/sep');
        $this->assertFalse(DB::table('integrasi.sep')->where('kunjungan_id', $id)->exists());
    }

    private function order(string $kunjunganId, Pasien $pasien, string $episodeId, Pengguna $oleh): string
    {
        $id = (string) Str::uuid7();
        DB::table('permintaan.permintaan_pemeriksaan')->insert(['id' => $id, 'kunjungan_id' => $kunjunganId, 'episode_id' => $episodeId,
            'pasien_id' => $pasien->id, 'jenis' => 'lab', 'prioritas' => 'cito', 'pemesan_id' => $oleh->id, 'diagnosis_kerja' => 'Uji sintetis']);

        return $id;
    }

    private function catatan(string $kunjunganId, Pasien $pasien, string $episodeId, Pengguna $penulis): string
    {
        $id = (string) Str::uuid7();
        DB::table('klinis.catatan_klinis')->insert(['id' => $id, 'episode_id' => $episodeId, 'kunjungan_id' => $kunjunganId,
            'unit_id' => $this->poli->unit_organisasi_id, 'pasien_id' => $pasien->id, 'penulis_id' => $penulis->id,
            'profesi' => 'perawat', 'tatanan' => 'igd', 'jenis' => 'soap']);

        return $id;
    }

    private function daftarIgd(Pasien $pasien, array $ganti = []): TestResponse
    {
        return $this->minta('POST', '/api/v1/kunjungan-igd', ['pasien_id' => $pasien->id, 'cara_bayar' => 'non_jkn', ...$ganti]);
    }

    /** Peran dengan aksi igd.pendaftaran, kecuali $tanpa. */
    private function petugasIgdPeran(array $tanpa = []): Peran
    {
        $peran = $this->buatPeran([]);
        $menu = Menu::query()->where('kode', 'igd.pendaftaran')->firstOrFail();
        PeranMenu::query()->create(['peran_id' => $peran->id, 'menu_id' => $menu->id]);
        foreach (Izin::query()->where('menu_id', $menu->id)->whereNotIn('kode', $tanpa)->pluck('id') as $izinId) {
            PeranIzin::query()->create(['peran_id' => $peran->id, 'izin_id' => $izinId]);
        }

        return $peran;
    }

    private function petugasIgd(array $tanpa = []): Pengguna
    {
        $pengguna = $this->buatPengguna();
        $this->tugaskan($pengguna, $this->petugasIgdPeran($tanpa));

        return $pengguna;
    }
}
