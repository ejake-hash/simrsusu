<?php

namespace Tests\Feature\Notifikasi;

use App\Modules\Akses\Events\PersetujuanDiajukan;
use App\Modules\Akses\Events\PersetujuanDitutup;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\MasterData\Models\UnitOrganisasi;
use App\Modules\Notifikasi\Jobs\KirimEmailNotifikasi;
use App\Modules\Notifikasi\Models\JenisTugas;
use App\Modules\Notifikasi\Models\Tugas;
use App\Modules\Sdm\Models\IzinPraktik;
use App\Modules\Sdm\Models\Pegawai;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\AksesUji;
use Tests\Support\KlienSpa;
use Tests\Support\KontrakApi;
use Tests\TestCase;

/** Tiket 1.7-be: notifikasi, kotak kerja, eskalasi (kontrak v0.7.0). */
class KotakKerjaTest extends TestCase
{
    use AksesUji, KlienSpa, KontrakApi;

    private const DASAR = 'SK Direktur 7/2026';

    public function test_akun_terkunci_memberi_tahu_pemilik_in_app_dan_email_tanpa_sandi(): void
    {
        Queue::fake();
        $akun = $this->buatPengguna(['email' => 'pemilik@contoh.invalid']);
        $this->mulaiSesiSpa();
        foreach (range(1, (int) config('autentikasi.penguncian.maks_gagal')) as $_) {
            $this->minta('POST', '/api/v1/autentikasi/masuk', ['nama_pengguna' => $akun->nama_pengguna, 'sandi' => 'sandi-salah-sekali']);
        }

        $notif = DB::table('notifikasi.notifikasi')->where('pengguna_id', $akun->id)->where('jenis', 'AKUN_TERKUNCI')->first();
        $this->assertNotNull($notif);
        $this->assertSame('kritis', $notif->prioritas);
        $this->assertStringNotContainsString('sandi-salah', $notif->isi);
        $this->assertDoesNotMatchRegularExpression('/\d+\.\d+\.\d+\.\d+/', $notif->isi);
        Queue::assertPushed(KirimEmailNotifikasi::class, fn (KirimEmailNotifikasi $j) => $j->penggunaId === $akun->id);

        // Masuk lagi sesudah kunci berakhir: notifikasinya terlihat, kritis tidak ikut "semua dibaca".
        $this->travel(20)->minutes();
        $this->masukSebagai($akun);
        $daftar = $this->minta('GET', '/api/v1/saya/notifikasi?status=belum_dibaca')->assertOk()->assertJsonPath('meta.jumlah_belum_dibaca', 1)
            ->assertJsonPath('data.0.jenis', 'AKUN_TERKUNCI');
        $this->assertSesuaiKontrak($daftar, 'GET', '/saya/notifikasi');
        $this->assertSesuaiKontrak($this->minta('POST', '/api/v1/saya/notifikasi/semua-dibaca')->assertOk()->assertJsonPath('data.jumlah', 0),
            'POST', '/saya/notifikasi/semua-dibaca');
        $this->assertSesuaiKontrak($this->minta('POST', "/api/v1/saya/notifikasi/{$notif->id}/dibaca")->assertOk()->assertJsonPath('data.id', $notif->id),
            'POST', '/saya/notifikasi/{id}/dibaca');
        $this->minta('GET', '/api/v1/saya/notifikasi?status=belum_dibaca')->assertJsonPath('meta.jumlah_belum_dibaca', 0);
    }

    public function test_tugas_dibuat_dieskalasi_tiga_tingkat_lalu_selesai_oleh_kejadian(): void
    {
        Queue::fake();
        // Unit dan kepala: IGD (kepala A) di bawah Yanmed (kepala B).
        [$kepalaBidang, $akunBidang] = $this->pegawaiBerakun('dr. Bidang');
        [$kepalaUnit, $akunUnit] = $this->pegawaiBerakun('dr. Unit');
        $yanmed = UnitOrganisasi::query()->create(['kode' => 'YANMED', 'nama' => 'Bidang Yanmed', 'jenis' => 'bidang', 'berlaku_dari' => '2026-01-01', 'kepala_pegawai_id' => $kepalaBidang->id]);
        $igd = UnitOrganisasi::query()->create(['kode' => 'IGD', 'nama' => 'IGD', 'jenis' => 'instalasi', 'induk_id' => $yanmed->id, 'berlaku_dari' => '2026-01-01', 'kepala_pegawai_id' => $kepalaUnit->id]);

        // Jenis tugas: unit teguran = IGD (pelaksana peran tanpa unit).
        $admin = $this->buatPengguna();
        $this->tugaskan($admin, $this->buatPeran(['master_data.jenis_tugas']));
        $this->masukSebagai($admin);
        $jenis = JenisTugas::query()->where('kode', 'PERSETUJUAN_AKSES_MENUNGGU')->firstOrFail();
        $this->mintaDenganVersi('PATCH', "/api/v1/jenis-tugas/{$jenis->id}", '0', [
            'eskalasi' => ['pengingat_menit' => -240, 'teguran_menit' => 60, 'eskalasi_menit' => 30], 'dasar_perubahan' => self::DASAR,
        ])->assertStatus(422)->assertJsonPath('error.kode', 'eskalasi_tidak_urut');
        $ubah = $this->mintaDenganVersi('PATCH', "/api/v1/jenis-tugas/{$jenis->id}", '0', ['unit_teguran_id' => $igd->id, 'dasar_perubahan' => self::DASAR])
            ->assertOk()->assertJsonPath('data.unit_teguran.kode', 'IGD');
        $this->assertSesuaiKontrak($ubah, 'PATCH', '/jenis-tugas/{id}');
        $this->assertSesuaiKontrak($this->minta('GET', '/api/v1/jenis-tugas')->assertOk(), 'GET', '/jenis-tugas');

        // Persetujuan diajukan untuk peran penyetuju yang dipegang pelaksana.
        $peran = $this->buatPeran(['igd.papan']);
        $pelaksana = $this->buatPengguna();
        $this->tugaskan($pelaksana, $peran);
        $persetujuanId = (string) Str::uuid7();
        DB::transaction(fn () => PersetujuanDiajukan::dispatch($persetujuanId, 'PSA-2026-000001', $peran->id));
        $tugas = Tugas::query()->where('objek_id', $persetujuanId)->firstOrFail();
        $this->assertSame([0, 'terbuka'], [$tugas->tingkat_saat_ini, $tugas->status]);

        $this->masukSebagai($pelaksana);
        $saya = $this->minta('GET', '/api/v1/saya/tugas?tab=saya')->assertOk()->assertJsonPath('data.0.id', $tugas->id)
            ->assertJsonPath('meta.jumlah.saya', 1)->assertJsonPath('data.0.tautan', "/pengaturan/persetujuan/$persetujuanId");
        $this->assertSesuaiKontrak($saya, 'GET', '/saya/tugas');
        $this->minta('GET', '/api/v1/saya/menu')->assertJsonPath('data.grup.0.menu.0.jumlah_tugas', 1);

        // Tingkat 1: 4 jam sebelum tenggat (2 hari).
        $this->travel(2 * 24 * 60 - 239)->minutes();
        $this->artisan('notifikasi:proses-eskalasi')->assertSuccessful();
        $this->assertSame(1, $tugas->refresh()->tingkat_saat_ini);
        // Tingkat 2: tenggat lewat → kepala IGD; tugas pindah ke tab dieskalasi pemiliknya.
        $this->travel(240)->minutes();
        $this->artisan('notifikasi:proses-eskalasi')->assertSuccessful();
        $this->assertSame(2, $tugas->refresh()->tingkat_saat_ini);
        $this->assertTrue(DB::table('notifikasi.notifikasi')->where('pengguna_id', $akunUnit->id)->where('tugas_id', $tugas->id)->exists());
        // Tingkat 3: 1 hari sesudah tenggat → kepala unit induk (Yanmed).
        $this->travel(24 * 60)->minutes();
        $this->artisan('notifikasi:proses-eskalasi')->assertSuccessful();
        $this->assertSame(3, $tugas->refresh()->tingkat_saat_ini);
        $this->assertTrue(DB::table('notifikasi.notifikasi')->where('pengguna_id', $akunBidang->id)->where('tugas_id', $tugas->id)->exists());

        $this->masukSebagai($pelaksana);
        $this->minta('GET', '/api/v1/saya/tugas?tab=dieskalasi')->assertOk()->assertJsonPath('data.0.id', $tugas->id)
            ->assertJsonPath('data.0.terlambat', true)->assertJsonPath('meta.jumlah.saya', 0);
        $this->masukSebagai($akunUnit);
        $this->minta('GET', '/api/v1/saya/tugas?tab=unit')->assertOk()->assertJsonPath('data.0.id', $tugas->id);
        $this->masukSebagai($akunBidang);
        $this->minta('GET', '/api/v1/saya/tugas?tab=unit')->assertOk()->assertJsonPath('data.0.id', $tugas->id);
        $this->masukSebagai($this->buatPengguna());
        $this->minta('GET', "/api/v1/tugas/{$tugas->id}")->assertNotFound();

        // Kejadian penyelesai menutup tugas; riwayat lengkap.
        DB::transaction(fn () => PersetujuanDitutup::dispatch([$persetujuanId], 'disetujui'));
        $this->assertSame('selesai', $tugas->refresh()->status);
        $this->masukSebagai($pelaksana);
        $rinci = $this->minta('GET', "/api/v1/tugas/{$tugas->id}")->assertOk()->assertJsonPath('data.status', 'selesai');
        $this->assertSesuaiKontrak($rinci, 'GET', '/tugas/{id}');
        $this->assertSame(['dibuat', 'pengingat', 'teguran', 'eskalasi', 'selesai'], array_column($rinci->json('data.riwayat'), 'jenis'));
        $this->minta('GET', '/api/v1/saya/tugas?tab=selesai')->assertOk()->assertJsonPath('data.0.id', $tugas->id);
    }

    public function test_permintaan_akses_membuat_tugas_untuk_superadmin_dan_selesai_saat_ditolak(): void
    {
        Queue::fake();
        [$superadmin, $rahasia] = $this->superadminBerMfa();
        $pemohon = $this->buatPengguna();
        $this->masukSebagai($pemohon);
        $id = $this->minta('POST', '/api/v1/permintaan-akses', ['sasaran_jenis' => 'menu', 'sasaran_kode' => 'kasir.tagihan', 'alasan' => 'Butuh untuk tugas'])
            ->assertCreated()->json('data.id');

        $this->masukSebagai($superadmin, $rahasia);
        $this->minta('GET', '/api/v1/saya/tugas?tab=saya')->assertOk()->assertJsonPath('data.0.objek.id', $id)->assertJsonPath('data.0.jenis.kode', 'PERMINTAAN_AKSES');
        $this->minta('POST', "/api/v1/permintaan-akses/$id/tolak", ['alasan' => 'Bukan tugas unitnya'])->assertOk();
        $this->minta('GET', '/api/v1/saya/tugas?tab=saya')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_etag_polling_latar_dan_preferensi(): void
    {
        $akun = $this->buatPengguna(['email' => null]);
        $this->masukSebagai($akun);
        $pertama = $this->minta('GET', '/api/v1/saya/notifikasi')->assertOk();
        $etag = (string) $pertama->headers->get('ETag');
        $this->headerTambahan = ['If-None-Match' => $etag, 'X-Permintaan-Latar' => '1'];
        $this->minta('GET', '/api/v1/saya/notifikasi')->assertStatus(304);
        $this->headerTambahan = [];

        // Polling latar tidak memperpanjang sesi: tanpa aktivitas pengguna, sesi berakhir sesudah batas diam.
        $this->travel(10)->minutes();
        $this->headerTambahan = ['X-Permintaan-Latar' => '1'];
        $this->minta('GET', '/api/v1/saya/tugas?tab=saya')->assertOk();
        $this->travel(6)->minutes();
        $this->minta('GET', '/api/v1/saya/tugas?tab=saya')->assertUnauthorized()->assertJsonPath('error.kode', 'sesi_kedaluwarsa');
        $this->headerTambahan = [];

        // Preferensi: kritis tidak bisa dimatikan; email tanpa alamat tidak tersedia.
        $this->masukSebagai($akun);
        $pref = $this->minta('GET', '/api/v1/saya/preferensi-notifikasi')->assertOk()->assertJsonPath('data.0.jenis', 'AKUN_TERKUNCI')
            ->assertJsonPath('data.0.email.dapat_diubah', false);
        $this->assertSesuaiKontrak($pref, 'GET', '/saya/preferensi-notifikasi');
        $this->minta('PUT', '/api/v1/saya/preferensi-notifikasi', ['preferensi' => [['jenis' => 'PERMINTAAN_AKSES', 'email' => true]]])
            ->assertStatus(422)->assertJsonPath('error.kode', 'kanal_tidak_tersedia');
        $akun->forceFill(['email' => 'saya@contoh.invalid'])->save();
        $this->minta('PUT', '/api/v1/saya/preferensi-notifikasi', ['preferensi' => [['jenis' => 'AKUN_TERKUNCI', 'email' => false]]])
            ->assertStatus(422)->assertJsonPath('error.kode', 'notifikasi_kritis');
        $ubah = $this->minta('PUT', '/api/v1/saya/preferensi-notifikasi', ['preferensi' => [['jenis' => 'PERMINTAAN_AKSES', 'email' => false]]])->assertOk();
        $this->assertSesuaiKontrak($ubah, 'PUT', '/saya/preferensi-notifikasi');
        $this->assertFalse(collect($ubah->json('data'))->firstWhere('jenis', 'PERMINTAAN_AKSES')['email']['aktif']);
    }

    public function test_izin_praktik_berakhir_dan_selesai_saat_izin_pengganti_dicatat(): void
    {
        Queue::fake();
        [$pegawai, $akun] = $this->pegawaiBerakun('dr. Sip');
        $berakhir = now(config('instalasi.zona_waktu'))->addDays(10)->toDateString();
        $izin = IzinPraktik::query()->create(['pegawai_id' => $pegawai->id, 'jenis' => 'sip', 'nomor' => 'SIP/1', 'terbit' => '2021-01-01', 'berlaku_sampai' => $berakhir]);
        $this->artisan('notifikasi:periksa-izin-praktik')->assertSuccessful();
        $this->artisan('notifikasi:periksa-izin-praktik')->assertSuccessful();
        $tugas = Tugas::query()->where('objek_id', $izin->id)->sole();
        $this->assertSame('pengguna', $tugas->pelaksana_jenis);
        $this->assertSame($akun->id, $tugas->pelaksana_id);

        $sdm = $this->buatPengguna();
        $this->tugaskan($sdm, $this->buatPeran(['sdm.kredensial', 'sdm.pegawai']));
        $this->masukSebagai($sdm);
        $this->minta('POST', "/api/v1/pegawai/{$pegawai->id}/izin-praktik", ['jenis' => 'sip', 'nomor' => 'SIP/2', 'terbit' => '2026-01-01', 'berlaku_sampai' => '2031-01-01'])
            ->assertCreated();
        $this->assertSame('selesai', $tugas->refresh()->status);
    }

    public function test_sepuluh_ribu_notifikasi_tetap_cepat(): void
    {
        $akun = $this->buatPengguna();
        DB::statement("INSERT INTO notifikasi.notifikasi (id, pengguna_id, jenis, judul, isi, prioritas, dibuat_pada)
            SELECT gen_random_uuid(), ?, 'UJI', 'Judul uji', 'Isi uji', 'biasa', now() - (g || ' seconds')::interval FROM generate_series(1, 10000) g", [$akun->id]);
        $this->masukSebagai($akun);
        $waktu = [];
        $kursor = null;
        foreach (range(1, 20) as $_) {
            $mulai = microtime(true);
            $respons = $this->minta('GET', '/api/v1/saya/notifikasi?batas=50'.($kursor ? '&kursor='.urlencode($kursor) : ''))->assertOk();
            $waktu[] = (microtime(true) - $mulai) * 1000;
            $kursor = $respons->json('meta.kursor_berikutnya');
        }
        sort($waktu);
        $this->assertLessThan(200, $waktu[(int) ceil(0.95 * count($waktu)) - 1], 'P95 (ms): '.json_encode($waktu));
    }

    /** @return array{0: Pegawai, 1: Pengguna} */
    private function pegawaiBerakun(string $nama): array
    {
        $pegawai = Pegawai::query()->create(['nama' => $nama]);

        return [$pegawai, $this->buatPengguna(['pegawai_id' => $pegawai->id])];
    }
}
