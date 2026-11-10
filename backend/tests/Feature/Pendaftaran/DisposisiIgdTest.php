<?php

namespace Tests\Feature\Pendaftaran;

use App\Core\Http\SuntinganBerversi;
use App\Modules\Akses\Domain\Cakupan;
use App\Modules\Akses\Models\Izin;
use App\Modules\Akses\Models\Menu;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\Akses\Models\PeranIzin;
use App\Modules\Akses\Models\PeranMenu;
use App\Modules\Pendaftaran\Events\DisposisiDicatat;
use App\Modules\Pendaftaran\Events\DisposisiRawatInap;
use App\Modules\Pendaftaran\Models\Kunjungan;
use App\Modules\Pendaftaran\Models\Pasien;
use App\Modules\Sdm\Models\Pegawai;
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

/**
 * Tiket 2.26-be: matriks perpindahan sebagai data induk dan disposisi IGD (kontrak v0.33.0; FR-00-02, FR-00-03,
 * FR-03-09). Isi matriks = tabel PRD 7.00.3 dari migrasi; data pasien sintetis.
 */
class DisposisiIgdTest extends TestCase
{
    use AksesUji, KlienSpa, KontrakApi, KunjunganUji;

    private const DASAR = 'Keputusan komite medik (uji sintetis)';

    private Pengguna $dokter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanKunjungan();
        $this->dokter = $this->akunDokter($this->dokter());
        $this->tugaskan($this->dokter, $this->buatPeran(['igd.pendaftaran', 'igd.disposisi']));
    }

    public function test_matriks_bawaan_dari_prd_dan_izin_baca(): void
    {
        $this->masukSebagai($this->dokter);
        $igd = $this->minta('GET', '/api/v1/data-induk/matriks-perpindahan?pos_asal=igd&batas=200')->assertOk();
        $this->assertSesuaiKontrak($igd, 'GET', '/data-induk/matriks-perpindahan');
        $this->assertStringContainsString('no-store', (string) $igd->headers->get('Cache-Control'));
        $tujuan = collect($igd->json('data'))->pluck('pos_tujuan')->sort()->values()->all();
        $this->assertSame(['aps', 'doa', 'icu_hcu', 'kamar_operasi', 'meninggal', 'penunjang', 'poli_lain', 'pulang', 'rawat_inap', 'rujuk_keluar'], $tujuan);
        $this->assertSame(['igd.disposisi.input'], collect($igd->json('data'))->pluck('izin_perintah')->unique()->values()->all());
        // IGD → IGD tidak ada di 7.00.3; poliklinik punya 9 sel.
        $this->minta('GET', '/api/v1/data-induk/matriks-perpindahan?pos_asal=poliklinik')->assertOk()->assertJsonCount(9, 'data');
        $this->minta('GET', '/api/v1/data-induk/matriks-perpindahan?pos_asal=gudang')->assertStatus(422);

        // Pengelola data induk boleh membaca; akun tanpa kedua izin tidak.
        $pengelola = $this->buatPengguna();
        $this->tugaskan($pengelola, $this->buatPeran(['master_data.matriks_perpindahan']));
        $this->masukSebagai($pengelola);
        // 19 sel 2.26 + rawat_inap → rawat_inap (pindah ruang, tiket 2.29).
        $this->minta('GET', '/api/v1/data-induk/matriks-perpindahan')->assertOk()->assertJsonCount(20, 'data');
        $this->masukSebagai($this->buatPengguna());
        $tolak = $this->minta('GET', '/api/v1/data-induk/matriks-perpindahan?pos_asal=gudang')->assertForbidden();
        $this->assertSesuaiKontrak($tolak, 'GET', '/data-induk/matriks-perpindahan');
    }

    public function test_pulang_menutup_kunjungan_igd_perintah_hanya_ditambah_dan_audit_tanpa_isi_klinis(): void
    {
        Event::fake([DisposisiDicatat::class, DisposisiRawatInap::class]);
        $this->masukSebagai($this->dokter);
        $pasien = $this->pasien();
        $daftar = $this->daftarIgd($pasien)->assertCreated();
        $kunjungan = (string) $daftar->json('data.id');
        $episode = (string) $daftar->json('data.episode.id');
        $this->minta('GET', "/api/v1/kunjungan/$kunjungan/disposisi")->assertOk()->assertJsonCount(0, 'data');

        $this->travel(40)->minutes();
        $this->masukSebagai($this->dokter);
        $respons = $this->disposisi($kunjungan, ['tujuan' => 'pulang', 'alasan' => 'Keluhan membaik (uji sintetis)', 'diputuskan_pada' => '2020-01-01T00:00:00Z'])
            ->assertCreated()->assertJsonPath('data.tujuan', 'pulang')->assertJsonPath('data.pos_asal', 'igd')
            ->assertJsonPath('data.status', 'selesai')->assertJsonPath('data.episode', ['id' => $episode, 'jenis' => 'rawat_jalan_harian', 'status' => 'aktif'])
            ->assertJsonPath('data.diputuskan_oleh.id', $this->dokter->id)->assertJsonPath('data.serah_terima', null);
        $this->assertSesuaiKontrak($respons, 'POST', '/kunjungan/{id}/disposisi');
        $this->assertStringContainsString('no-store', (string) $respons->headers->get('Cache-Control'));
        $this->assertTrue(Carbon::parse((string) $respons->json('data.diputuskan_pada'))->equalTo(now()));
        $id = (string) $respons->json('data.id');

        // Kunjungan selesai, keluar dari daftar IGD aktif, tidak lagi lebih 6 jam; episode rawat jalan harian tetap aktif.
        $this->assertSame('selesai', Kunjungan::query()->findOrFail($kunjungan)->status);
        $this->minta('GET', '/api/v1/kunjungan-igd')->assertOk()->assertJsonCount(0, 'data');
        $this->travel(7)->hours();
        $this->masukSebagai($this->dokter);
        $this->minta('GET', '/api/v1/kunjungan-igd?status=selesai')->assertOk()->assertJsonPath('data.0.id', $kunjungan)->assertJsonPath('data.0.lebih_6_jam', false);
        $this->artisan('kunjungan:episode-igd')->expectsOutput('Kunjungan IGD dipindah: 0')->assertSuccessful();
        $this->assertSame([$episode, 'aktif'], [Kunjungan::query()->findOrFail($kunjungan)->episode_id, DB::table('kunjungan.episode')->where('id', $episode)->value('status')]);

        $riwayat = $this->minta('GET', "/api/v1/kunjungan/$kunjungan/disposisi")->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $id)->assertJsonPath('data.0.alasan', 'Keluhan membaik (uji sintetis)');
        $this->assertSesuaiKontrak($riwayat, 'GET', '/kunjungan/{id}/disposisi');

        $baris = DB::table('kunjungan.perpindahan_pos')->where('id', $id)->first();
        $this->assertSame(['igd', 'pulang', 'igd.disposisi.input', $this->dokter->id], [$baris->pos_asal, $baris->pos_tujuan, $baris->izin_perintah, $baris->diputuskan_oleh]);
        $this->assertSame(DB::table('kunjungan.aturan_perpindahan')->where('pos_asal', 'igd')->where('pos_tujuan', 'pulang')->value('id'), $baris->aturan_perpindahan_id);

        $audit = $this->assertAuditTerakhir('kunjungan.disposisi', $id);
        $this->assertSame($this->dokter->id, $audit->pengguna_id);
        $sesudah = json_decode((string) $audit->sesudah, true);
        $this->assertSame([$kunjungan, 'pulang', 'selesai', 'selesai'], [$sesudah['kunjungan_id'], $sesudah['pos_tujuan'], $sesudah['status'], $sesudah['status_kunjungan']]);
        $this->assertStringNotContainsString('membaik', (string) $audit->sesudah);
        $this->assertStringNotContainsString($pasien->nama, (string) $audit->sesudah);

        Event::assertDispatched(DisposisiDicatat::class, fn (DisposisiDicatat $e) => $e->perpindahanId === $id && $e->kunjunganId === $kunjungan
            && $e->posTujuan === 'pulang' && $e->status === 'selesai');
        Event::assertNotDispatched(DisposisiRawatInap::class);

        // Hanya ditambah: simrs_app tidak boleh mengubah atau menghapus perintah.
        $this->assertDitolak(fn () => DB::table('kunjungan.perpindahan_pos')->where('id', $id)->update(['pos_tujuan' => 'aps']));
        $this->assertDitolak(fn () => DB::table('kunjungan.perpindahan_pos')->where('id', $id)->delete());

        // Sudah selesai: disposisi lagi → 409.
        $this->disposisi($kunjungan)->assertStatus(409)->assertJsonPath('error.kode', 'status_tidak_sesuai')->assertJsonPath('error.rincian.0.status', 'selesai');
    }

    public function test_disposisi_sesudah_6_jam_membentuk_dan_menutup_episode_igd(): void
    {
        $this->masukSebagai($this->dokter);
        $daftar = $this->daftarIgd($this->pasien())->assertCreated();
        $kunjungan = (string) $daftar->json('data.id');
        $episodeRj = (string) $daftar->json('data.episode.id');

        // Lewat 6 jam, tugas terjadwal belum berjalan: disposisi tetap masuk episode IGD > 6 jam, lalu menutupnya.
        $this->travelTo(Carbon::parse(self::SENIN.' 13:30', (string) config('instalasi.zona_waktu')));
        $this->masukSebagai($this->dokter);
        $this->minta('GET', '/api/v1/kunjungan-igd')->assertJsonPath('data.0.lebih_6_jam', true);
        $respons = $this->disposisi($kunjungan, ['tujuan' => 'meninggal'])->assertCreated()
            ->assertJsonPath('data.episode.jenis', 'igd_lebih_6_jam')->assertJsonPath('data.episode.status', 'selesai');
        $episodeIgd = (string) $respons->json('data.episode.id');
        $this->assertNotSame($episodeRj, $episodeIgd);

        $e = DB::table('kunjungan.episode')->where('id', $episodeIgd)->first();
        $this->assertSame('selesai', $e->status);
        $this->assertTrue(Carbon::parse((string) $e->selesai_pada)->equalTo(now()));
        $this->assertSame('batal', DB::table('kunjungan.episode')->where('id', $episodeRj)->value('status'));
        $this->assertSame([$episodeIgd, 'selesai'], [Kunjungan::query()->findOrFail($kunjungan)->episode_id, Kunjungan::query()->findOrFail($kunjungan)->status]);
        $this->minta('GET', '/api/v1/kunjungan-igd?status=selesai')->assertJsonPath('data.0.lebih_6_jam', false);
        $this->assertTrue((bool) json_decode((string) $this->assertAuditTerakhir('kunjungan.disposisi', (string) $respons->json('data.id'))->sesudah, true)['episode_igd_dibentuk']);
        $this->assertAuditTerakhir('episode.dipindah_igd_6_jam', $episodeIgd);
        $this->artisan('kunjungan:episode-igd')->expectsOutput('Kunjungan IGD dipindah: 0')->assertSuccessful();
    }

    public function test_rawat_inap_hanya_permintaan_kamar_tanpa_menempatkan(): void
    {
        Event::fake([DisposisiDicatat::class, DisposisiRawatInap::class]);
        $this->masukSebagai($this->dokter);
        $kunjungan = (string) $this->daftarIgd($this->pasien())->assertCreated()->json('data.id');
        $revisi = Kunjungan::query()->findOrFail($kunjungan)->revisi;

        // Perpindahan antar-ruang wajib serah terima (RM-15).
        $this->disposisi($kunjungan, ['tujuan' => 'rawat_inap'])->assertStatus(422)->assertJsonPath('error.rincian.0.kolom', 'serah_terima');
        $respons = $this->disposisi($kunjungan, ['tujuan' => 'rawat_inap', 'serah_terima' => 'Infus terpasang, observasi tiap jam (uji sintetis)'])
            ->assertCreated()->assertJsonPath('data.status', 'menunggu_kamar')->assertJsonPath('data.episode.status', 'aktif')
            ->assertJsonPath('data.serah_terima', 'Infus terpasang, observasi tiap jam (uji sintetis)');
        $this->assertSesuaiKontrak($respons, 'POST', '/kunjungan/{id}/disposisi');
        $id = (string) $respons->json('data.id');
        Event::assertDispatched(DisposisiRawatInap::class, fn (DisposisiRawatInap $e) => $e->perpindahanId === $id && $e->kunjunganId === $kunjungan);

        // Pasien belum ditempatkan: kunjungan tetap di IGD, tidak berubah.
        $k = Kunjungan::query()->findOrFail($kunjungan);
        $this->assertSame(['terdaftar', $revisi], [$k->status, $k->revisi]);
        $this->minta('GET', '/api/v1/kunjungan-igd')->assertJsonPath('data.0.id', $kunjungan);
        $this->assertStringNotContainsString('Infus', (string) $this->assertAuditTerakhir('kunjungan.disposisi', $id)->sesudah);

        $ganda = $this->disposisi($kunjungan, ['tujuan' => 'rawat_inap', 'serah_terima' => 'Serah terima ulang'])->assertStatus(409)
            ->assertJsonPath('error.kode', 'perintah_sudah_menunggu');
        $this->assertSesuaiKontrak($ganda, 'POST', '/kunjungan/{id}/disposisi');

        // Menunggu kamar lewat 6 jam: bukan "IGD > 6 jam tanpa rawat inap", tugas terjadwal tidak memindahkannya.
        $this->travelTo(Carbon::parse(self::SENIN.' 13:30', (string) config('instalasi.zona_waktu')));
        $this->artisan('kunjungan:episode-igd')->expectsOutput('Kunjungan IGD dipindah: 0')->assertSuccessful();
        // Pasien akhirnya pulang atas permintaan sendiri: kunjungan selesai, episode IGD > 6 jam dibentuk dan selesai.
        $this->masukSebagai($this->dokter);
        $this->disposisi($kunjungan, ['tujuan' => 'aps', 'alasan' => 'Keluarga menolak rawat inap'])->assertCreated()
            ->assertJsonPath('data.status', 'selesai')->assertJsonPath('data.episode.jenis', 'igd_lebih_6_jam');
        $this->minta('GET', "/api/v1/kunjungan/$kunjungan/disposisi")->assertJsonPath('data.*.tujuan', ['aps', 'rawat_inap']);
    }

    public function test_tujuan_di_luar_matriks_dan_izin_sel_lewat_usulan_data_induk(): void
    {
        $this->masukSebagai($this->dokter);
        $kunjungan = (string) $this->daftarIgd($this->pasien())->assertCreated()->json('data.id');
        // Pos tujuan matriks yang bukan disposisi FR-03-09 → kode khusus; kode tak dikenal → validasi_gagal.
        foreach (['icu_hcu', 'igd', 'poli_lain', 'penunjang'] as $tujuan) {
            $bukan = $this->disposisi($kunjungan, ['tujuan' => $tujuan])->assertStatus(422)->assertJsonPath('error.kode', 'tujuan_bukan_disposisi')
                ->assertJsonPath('error.rincian.0.kolom', 'tujuan')->assertJsonPath('error.rincian.0.pos_tujuan', $tujuan);
            $this->assertSesuaiKontrak($bukan, 'POST', '/kunjungan/{id}/disposisi');
        }
        foreach (['pulang_paksa', null] as $tujuan) {
            $this->disposisi($kunjungan, ['tujuan' => $tujuan])->assertStatus(422)->assertJsonPath('error.kode', 'validasi_gagal')
                ->assertJsonPath('error.rincian.0.kolom', 'tujuan');
        }
        $this->disposisi($kunjungan, ['alasan' => 'abc'])->assertStatus(422)->assertJsonPath('error.rincian.0.kolom', 'alasan');

        // Sel IGD → APS ditutup dan izin IGD → pulang diganti lewat usulan berjenjang (bukan edit langsung).
        $aps = DB::table('kunjungan.aturan_perpindahan')->where('pos_asal', 'igd')->where('pos_tujuan', 'aps')->first();
        $pulang = DB::table('kunjungan.aturan_perpindahan')->where('pos_asal', 'igd')->where('pos_tujuan', 'pulang')->first();
        [$pengusul, $pemverifikasi, $penyetuju] = $this->pengelolaUsulan();
        $this->masukSebagai($pengusul);
        // Kode izin tidak dikenal ditolak uji kering.
        $this->minta('POST', '/api/v1/usulan-data-induk', $this->usulan([
            ['objek_jenis' => 'aturan_perpindahan', 'aksi' => 'ubah', 'objek_id' => $pulang->id, 'nilai_baru' => ['izin_perintah' => 'igd.tidak_ada.input']],
        ]))->assertStatus(422)->assertJsonPath('error.kode', 'perubahan_tidak_valid')->assertJsonPath('error.rincian.0.kolom', 'perubahan.0.nilai_baru.izin_perintah');
        // Pasangan yang sudah ada tidak bisa ditambah lagi.
        $this->minta('POST', '/api/v1/usulan-data-induk', $this->usulan([
            ['objek_jenis' => 'aturan_perpindahan', 'aksi' => 'tambah', 'objek_id' => null, 'nilai_baru' => ['pos_asal' => 'igd', 'pos_tujuan' => 'pulang', 'izin_perintah' => 'igd.disposisi.input']],
        ]))->assertStatus(422)->assertJsonPath('error.rincian.0.kode', 'pasangan_sudah_ada');
        // Keputusan pemilik 10 Nov 2026 (tinjauan #194 butir 2): izin sel matriks wajib izin klinis (SIP/SPK).
        foreach ([
            ['objek_jenis' => 'aturan_perpindahan', 'aksi' => 'ubah', 'objek_id' => $pulang->id, 'nilai_baru' => ['izin_perintah' => 'igd.triase.input']],
            ['objek_jenis' => 'aturan_perpindahan', 'aksi' => 'tambah', 'objek_id' => null, 'nilai_baru' => ['pos_asal' => 'pendaftaran', 'pos_tujuan' => 'igd', 'izin_perintah' => 'igd.pendaftaran.input']],
        ] as $bukanKlinis) {
            $tolak = $this->minta('POST', '/api/v1/usulan-data-induk', $this->usulan([$bukanKlinis]))->assertStatus(422)
                ->assertJsonPath('error.rincian.0.kode', 'izin_bukan_klinis')->assertJsonPath('error.rincian.0.kolom', 'perubahan.0.nilai_baru.izin_perintah');
            $this->assertSesuaiKontrak($tolak, 'POST', '/usulan-data-induk');
        }

        $ajukan = $this->minta('POST', '/api/v1/usulan-data-induk', $this->usulan([
            ['objek_jenis' => 'aturan_perpindahan', 'aksi' => 'tutup', 'objek_id' => $aps->id, 'nilai_baru' => ['alasan' => 'APS dicatat sebagai pulang di RS ini']],
            ['objek_jenis' => 'aturan_perpindahan', 'aksi' => 'ubah', 'objek_id' => $pulang->id, 'nilai_baru' => ['izin_perintah' => 'rawat_jalan.pemeriksaan.sahkan']],
            ['objek_jenis' => 'aturan_perpindahan', 'aksi' => 'tambah', 'objek_id' => null, 'nilai_baru' => ['pos_asal' => 'pendaftaran', 'pos_tujuan' => 'igd', 'izin_perintah' => 'igd.disposisi.input', 'keterangan' => 'PRD 7.00.3: bila gawat']],
        ]))->assertCreated()->assertJsonPath('data.jenis_data', 'matriks_perpindahan')
            ->assertJsonPath('data.perubahan.0.objek_label', 'IGD → APS (pulang atas permintaan sendiri)')
            ->assertJsonPath('data.perubahan.0.tampilan.0.lama', 'Aktif')
            ->assertJsonPath('data.perubahan.2.objek_label', 'Sel baru Pendaftaran → IGD')
            ->assertJsonPath('data.ringkasan', 'Tutup 1 sel matriks perpindahan, Ubah 1 sel matriks perpindahan, Tambah 1 sel matriks perpindahan');
        $this->assertSesuaiKontrak($ajukan, 'POST', '/usulan-data-induk');
        $usulan = (string) $ajukan->json('data.id');
        // Belum disetujui: matriks belum berubah.
        $this->assertSame('aktif', DB::table('kunjungan.aturan_perpindahan')->where('id', $aps->id)->value('status'));
        $this->masukSebagai($pemverifikasi);
        $this->minta('POST', "/api/v1/usulan-data-induk/$usulan/verifikasi")->assertOk();
        $this->masukSebagai($penyetuju);
        $this->minta('POST', "/api/v1/usulan-data-induk/$usulan/setujui")->assertOk()->assertJsonPath('data.tahap', 'berlaku');

        $this->assertSame('nonaktif', DB::table('kunjungan.aturan_perpindahan')->where('id', $aps->id)->value('status'));
        $this->assertTrue(DB::table('kunjungan.aturan_perpindahan')->where('pos_asal', 'pendaftaran')->where('pos_tujuan', 'igd')->where('status', 'aktif')->exists());
        $versi = DB::table('inti.data_induk_versi')->where('objek_jenis', 'aturan_perpindahan')->where('objek_id', $pulang->id)->first();
        $this->assertSame(['igd.disposisi.input', 'rawat_jalan.pemeriksaan.sahkan', $usulan], [json_decode((string) $versi->sebelum, true)['izin_perintah'], json_decode((string) $versi->sesudah, true)['izin_perintah'], $versi->usulan_id]);
        $audit = $this->assertAuditTerakhir('master_data.matriks_perpindahan_diubah', $pulang->id);
        $this->assertSame($penyetuju->id, $audit->pengguna_id);
        $this->assertStringContainsString(self::DASAR, (string) $audit->sesudah);

        // Tujuan yang selnya nonaktif → 422 kode jelas; izin sel yang tidak dipegang dokter → 403. Kunjungan tidak berubah.
        $this->masukSebagai($this->dokter);
        $luar = $this->disposisi($kunjungan, ['tujuan' => 'aps'])->assertStatus(422)->assertJsonPath('error.kode', 'perpindahan_di_luar_matriks')
            ->assertJsonPath('error.rincian.0.pos_tujuan', 'aps');
        $this->assertSesuaiKontrak($luar, 'POST', '/kunjungan/{id}/disposisi');
        $this->assertSesuaiKontrak($this->disposisi($kunjungan, ['tujuan' => 'pulang'])->assertForbidden(), 'POST', '/kunjungan/{id}/disposisi');
        $this->assertSame('terdaftar', Kunjungan::query()->findOrFail($kunjungan)->status);
        $this->assertFalse(DB::table('kunjungan.perpindahan_pos')->where('dari_kunjungan_id', $kunjungan)->exists());
        // Sel lain tetap berlaku.
        $this->disposisi($kunjungan, ['tujuan' => 'rujuk_keluar'])->assertCreated()->assertJsonPath('data.status', 'selesai');
    }

    public function test_tampilan_tutup_memakai_status_lama_sel(): void
    {
        $doa = DB::table('kunjungan.aturan_perpindahan')->where('pos_asal', 'igd')->where('pos_tujuan', 'doa')->value('id');
        DB::table('kunjungan.aturan_perpindahan')->where('id', $doa)->update(['status' => 'nonaktif']);
        [$pengusul] = $this->pengelolaUsulan();
        $this->masukSebagai($pengusul);
        $this->minta('POST', '/api/v1/usulan-data-induk', $this->usulan([
            ['objek_jenis' => 'aturan_perpindahan', 'aksi' => 'tutup', 'objek_id' => $doa, 'nilai_baru' => ['alasan' => 'Penegasan sel tetap nonaktif']],
        ]))->assertCreated()->assertJsonPath('data.perubahan.0.nilai_lama', ['status' => 'nonaktif'])
            ->assertJsonPath('data.perubahan.0.tampilan.0.lama', 'Nonaktif');
    }

    public function test_hanya_dokter_berwenang_selain_itu_403(): void
    {
        $this->masukSebagai($this->dokter);
        $kunjungan = (string) $this->daftarIgd($this->pasien())->assertCreated()->json('data.id');
        $url = "/api/v1/kunjungan/$kunjungan/disposisi";

        // Tanpa igd.disposisi: 403 sebelum isian dibaca.
        $pendaftaran = $this->buatPengguna();
        $this->tugaskan($pendaftaran, $this->buatPeran(['igd.pendaftaran', 'igd.triase']));
        foreach ([$this->buatPengguna(), $pendaftaran] as $akun) {
            $this->masukSebagai($akun);
            $this->minta('POST', $url, [])->assertForbidden();
            $this->minta('GET', $url)->assertForbidden();
        }

        // Perawat dengan peran disposisi tetapi tanpa SIP/SPK berlaku: lapis 5 menolak.
        /** @var Pegawai $pegawaiPerawat */
        $pegawaiPerawat = Pegawai::factory()->create(['profesi' => 'perawat']);
        $perawat = $this->buatPengguna(['pegawai_id' => $pegawaiPerawat->id]);
        $this->tugaskan($perawat, $this->buatPeran(['igd.disposisi']));
        $this->masukSebagai($perawat);
        $tolak = $this->disposisi($kunjungan)->assertForbidden()->assertJsonPath('error.kode', 'izin_praktik_tidak_berlaku');
        $this->assertSesuaiKontrak($tolak, 'POST', '/kunjungan/{id}/disposisi');
        $this->minta('GET', $url)->assertOk();

        // SIP dokter kedaluwarsa: ditolak juga.
        DB::table('sdm.izin_praktik')->where('pegawai_id', $this->dokter->pegawai_id)->update(['berlaku_sampai' => '2026-01-01']);
        $this->masukSebagai($this->dokter);
        $this->disposisi($kunjungan)->assertForbidden()->assertJsonPath('error.kode', 'izin_praktik_tidak_berlaku');
        DB::table('sdm.izin_praktik')->where('pegawai_id', $this->dokter->pegawai_id)->update(['berlaku_sampai' => '2040-01-01']);

        // Cakupan unit, bukan rs → 403.
        $unit = $this->akunDokter($this->dokter());
        $this->tugaskan($unit, $this->buatPeran(['igd.disposisi']), cakupan: new Cakupan('unit', $this->poli->unit_organisasi_id));
        $this->masukSebagai($unit);
        $this->disposisi($kunjungan)->assertForbidden();

        // Lihat saja: riwayat boleh, perintah tidak.
        $lihat = $this->akunDokter($this->dokter());
        $peran = $this->buatPeran([]);
        PeranMenu::query()->create(['peran_id' => $peran->id, 'menu_id' => Menu::query()->where('kode', 'igd.disposisi')->value('id')]);
        PeranIzin::query()->create(['peran_id' => $peran->id, 'izin_id' => Izin::query()->where('kode', 'igd.disposisi.lihat')->value('id')]);
        $this->tugaskan($lihat, $peran);
        $this->masukSebagai($lihat);
        $this->minta('GET', $url)->assertOk()->assertJsonCount(0, 'data');
        $this->disposisi($kunjungan)->assertForbidden();
        $this->assertSame('terdaftar', Kunjungan::query()->findOrFail($kunjungan)->status);
        $this->assertFalse(DB::table('kunjungan.perpindahan_pos')->where('dari_kunjungan_id', $kunjungan)->exists());
    }

    public function test_hanya_kunjungan_igd_aktif_selain_itu_409_dan_404(): void
    {
        $this->masukSebagai($this->dokter);
        $batal = (string) $this->daftarIgd($this->pasien())->assertCreated()->json('data.id');
        Kunjungan::query()->whereKey($batal)->update(['status' => 'batal', 'alasan_batal' => 'Salah pilih pasien', 'dibatalkan_pada' => now()]);
        $this->disposisi($batal)->assertStatus(409)->assertJsonPath('error.kode', 'status_tidak_sesuai')->assertJsonPath('error.rincian.0.status', 'batal');

        $dilayani = (string) $this->daftarIgd($this->pasien())->assertCreated()->json('data.id');
        Kunjungan::query()->whereKey($dilayani)->update(['status' => 'dilayani']);
        $this->disposisi($dilayani, ['tujuan' => 'doa'])->assertCreated();

        // Kunjungan poli bukan IGD.
        $this->masukSebagai($this->petugasPendaftaran());
        $poli = (string) $this->daftarKunjungan($this->pasien(), $this->jadwal($this->dokter()))->assertCreated()->json('data.id');
        $this->masukSebagai($this->dokter);
        $tolak = $this->disposisi($poli)->assertStatus(409)->assertJsonPath('error.rincian.0.jenis_kunjungan', 'poliklinik');
        $this->assertSesuaiKontrak($tolak, 'POST', '/kunjungan/{id}/disposisi');
        $this->assertFalse(DB::table('kunjungan.perpindahan_pos')->whereIn('dari_kunjungan_id', [$batal, $poli])->exists());

        $tidakAda = (string) Str::uuid7();
        $this->assertSesuaiKontrak($this->disposisi($tidakAda)->assertNotFound(), 'POST', '/kunjungan/{id}/disposisi');
        $this->minta('GET', "/api/v1/kunjungan/$tidakAda/disposisi")->assertNotFound();
        $this->disposisi('bukan-uuid')->assertNotFound();

        $aktif = (string) $this->daftarIgd($this->pasien())->assertCreated()->json('data.id');
        $this->tandaiInstalasiSiap(false);
        $this->disposisi($aktif)->assertStatus(409)->assertJsonPath('error.kode', 'instalasi_belum_siap');
    }

    /** @return array{0: Pengguna, 1: Pengguna, 2: Pengguna} pengusul, pemverifikasi, penyetuju */
    private function pengelolaUsulan(): array
    {
        $peran = $this->buatPeran(['master_data.usulan', 'master_data.matriks_perpindahan']);
        $akun = [];
        foreach (range(1, 3) as $_) {
            $a = $this->buatPengguna();
            $this->tugaskan($a, $peran);
            $akun[] = $a;
        }

        return [$akun[0], $akun[1], $akun[2]];
    }

    /**
     * @param  list<array<string, mixed>>  $perubahan
     * @return array<string, mixed>
     */
    private function usulan(array $perubahan): array
    {
        return ['jenis_data' => 'matriks_perpindahan', 'perubahan' => $perubahan, 'berlaku_dari' => SuntinganBerversi::hariIni(),
            'alasan' => 'Penyesuaian alur perpindahan RS', 'dasar' => self::DASAR];
    }

    /** @param  array<string, mixed>  $ganti */
    private function daftarIgd(Pasien $pasien, array $ganti = []): TestResponse
    {
        return $this->minta('POST', '/api/v1/kunjungan-igd', ['pasien_id' => $pasien->id, 'cara_bayar' => 'non_jkn', ...$ganti]);
    }

    /** @param  array<string, mixed>  $ganti */
    private function disposisi(string $kunjunganId, array $ganti = []): TestResponse
    {
        return $this->minta('POST', "/api/v1/kunjungan/$kunjunganId/disposisi", ['tujuan' => 'pulang', 'alasan' => 'Kondisi stabil (uji sintetis)', ...$ganti]);
    }
}
