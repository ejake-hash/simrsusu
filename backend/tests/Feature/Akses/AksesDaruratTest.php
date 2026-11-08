<?php

namespace Tests\Feature\Akses;

use App\Modules\Akses\Domain\Cakupan;
use App\Modules\Akses\Domain\Totp;
use App\Modules\Akses\Models\Izin;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\Akses\Models\PeranIzin;
use App\Modules\MasterData\Models\JadwalPraktik;
use App\Modules\MasterData\Models\UnitOrganisasi;
use App\Modules\Pendaftaran\Models\Pasien;
use App\Modules\Sdm\Models\IzinPraktik;
use App\Modules\Sdm\Models\Pegawai;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\AksesUji;
use Tests\Support\KlienSpa;
use Tests\Support\KontrakApi;
use Tests\Support\KunjunganUji;
use Tests\TestCase;

/** Tiket 2.21-be: akses darurat (break-glass), audit.log_akses, tinjauan pasca-akses (kontrak v0.29.0). Data sintetis. */
class AksesDaruratTest extends TestCase
{
    use AksesUji, KlienSpa, KontrakApi, KunjunganUji;

    private const PIN = '482916';

    private const ALASAN = 'Pasien rujukan IGD, riwayat poli dibutuhkan segera';

    private const SOAP = ['subjektif' => 'Keluhan cemas berulang', 'objektif' => 'Tampak gelisah', 'asesmen' => 'Gangguan cemas', 'plan' => 'Konseling'];

    private UnitOrganisasi $unitB;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->siapkanKunjungan();
        DB::table('inti.modul_aktif')->insert(['id' => (string) Str::uuid7(), 'modul_no' => 7]);
        $this->unitB = UnitOrganisasi::query()->create(['kode' => 'POLI-B', 'nama' => 'Poli B', 'jenis' => 'poli', 'berlaku_dari' => '2026-01-01']);
    }

    public function test_izin_darurat_sensitif_klinis_mfa_dan_pemberiannya_menunggu_pemilik_data(): void
    {
        $izin = Izin::query()->where('kode', 'rawat_jalan.cppt.darurat')->firstOrFail();
        $this->assertTrue($izin->sensitif && $izin->butuh_mfa && $izin->butuh_kewenangan_klinis);
        $this->assertNull($izin->peran_penyetuju_id);
        $this->assertTrue(Izin::query()->where('kode', 'rekam_medis.akses_darurat.tinjau')->exists());

        $peran = $this->buatPeran(['rawat_jalan.cppt']);
        $this->masukSebagai($this->pengelola());
        $kode = Izin::query()->whereIn('id', PeranIzin::query()->where('peran_id', $peran->id)->select('izin_id'))->pluck('kode')->all();
        $this->assertNotContains('rawat_jalan.cppt.darurat', $kode);
        $this->mintaDenganVersi('PUT', "/api/v1/peran/{$peran->id}/izin", (string) $peran->refresh()->revisi, [
            'izin' => [...$kode, 'rawat_jalan.cppt.darurat'], 'dasar_perubahan' => 'SK uji akses darurat',
        ])->assertStatus(422)->assertJsonPath('error.kode', 'penyetuju_belum_ditetapkan');
    }

    public function test_akses_darurat_membuka_baca_pasien_itu_saja_tercatat_lalu_tertutup_sendiri_dan_ditinjau(): void
    {
        $pasien = $this->pasien();
        $lain = $this->pasien();
        [$kunjungan, $berlabel] = $this->cpptBerlabel($pasien);
        $this->cpptBerlabel($lain);
        $kepalaRm = $this->buatPengguna();
        $peranTinjau = $this->buatPeran(['rekam_medis.akses_darurat']);
        $this->tugaskan($kepalaRm, $peranTinjau);
        [$klinisi, $rahasia] = $this->klinisiPoliB();

        $this->masukSebagai($klinisi, $rahasia);
        $this->minta('GET', "/api/v1/pasien/{$pasien->id}/cppt")->assertForbidden()->assertJsonPath('error.kode', 'akses_ditolak');
        $this->minta('POST', "/api/v1/pasien/{$pasien->id}/akses-darurat", ['kategori_alasan' => 'pelayanan_lanjutan', 'alasan' => 'pendek'])
            ->assertStatus(422);
        $this->minta('POST', '/api/v1/pasien/'.Str::uuid7().'/akses-darurat', ['kategori_alasan' => 'pelayanan_lanjutan', 'alasan' => self::ALASAN])
            ->assertNotFound();

        $buka = $this->minta('POST', "/api/v1/pasien/{$pasien->id}/akses-darurat", ['kategori_alasan' => 'pelayanan_lanjutan', 'alasan' => self::ALASAN])
            ->assertCreated()->assertJsonPath('data.aktif', true)->assertJsonPath('data.tinjauan', null)->assertJsonPath('data.dapat.tinjau', false)
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSesuaiKontrak($buka, 'POST', '/pasien/{id}/akses-darurat');
        $id = $buka->json('data.id');
        $berakhir = $buka->json('data.berakhir_pada');
        $this->assertSame(240, (int) now()->parse($buka->json('data.mulai_pada'))->diffInMinutes(now()->parse($berakhir)));
        $this->assertAuditTerakhir('akses.akses_darurat_dibuka', $id);
        $this->assertSame(1, DB::table('inti.tugas')->where('objek_id', $id)->where('pelaksana_id', $peranTinjau->id)->where('status', 'terbuka')->count());

        // Masih aktif: 200 dengan akses yang sama, tidak diperpanjang.
        $this->travel(10)->minutes();
        $ulang = $this->minta('POST', "/api/v1/pasien/{$pasien->id}/akses-darurat", ['kategori_alasan' => 'klaim', 'alasan' => 'Alasan kedua yang diabaikan'])
            ->assertOk()->assertJsonPath('data.id', $id)->assertJsonPath('data.berakhir_pada', $berakhir)->assertJsonPath('data.kategori_alasan', 'pelayanan_lanjutan');
        $this->assertSesuaiKontrak($ulang, 'POST', '/pasien/{id}/akses-darurat');

        // Baca terbuka, entri berlabel tidak Terbatas; tulis tetap ditolak.
        $linimasa = $this->minta('GET', "/api/v1/pasien/{$pasien->id}/cppt")->assertOk();
        $this->assertSame('Gangguan cemas', collect($linimasa->json('data'))->firstWhere('id', $berlabel)['isi']['asesmen'] ?? null);
        $episode = DB::table('kunjungan.kunjungan')->where('id', $kunjungan)->value('episode_id');
        $this->minta('GET', "/api/v1/episode/$episode/cppt")->assertOk();
        $this->minta('GET', "/api/v1/cppt/$berlabel")->assertOk()->assertJsonPath('data.isi.plan', 'Konseling');
        $this->minta('GET', "/api/v1/pasien/{$pasien->id}/ringkasan-klinis")->assertOk();
        $this->minta('POST', "/api/v1/kunjungan/$kunjungan/cppt", ['subjektif' => 'Tulis'])->assertForbidden();

        // IDOR: pasien lain tetap tertutup dan tidak tercatat.
        $this->minta('GET', "/api/v1/pasien/{$lain->id}/cppt")->assertForbidden();
        $lainEpisode = DB::table('kunjungan.kunjungan')->where('pasien_id', $lain->id)->value('episode_id');
        $this->minta('GET', "/api/v1/episode/$lainEpisode/cppt")->assertForbidden();

        $log = DB::table('audit.log_akses')->where('akses_darurat_id', $id)->orderBy('terjadi_pada')->get();
        $this->assertSame(['cppt', 'cppt', 'cppt', 'ringkasan_klinis'], $log->pluck('layar')->all());
        $this->assertSame([$pasien->id], $log->pluck('pasien_id')->unique()->values()->all());
        $this->assertSame($kunjungan, $log[2]->kunjungan_id);
        $this->assertSame(['pelayanan_lanjutan'], $log->pluck('alasan')->unique()->values()->all());
        $this->assertSame([$klinisi->id], $log->pluck('pengguna_id')->unique()->values()->all());
        $isiLog = $log->toJson().DB::table('audit.log_aktivitas')->where('aksi', 'like', 'akses.akses_darurat%')->get()->toJson();
        $this->assertStringNotContainsString('cemas', $isiLog);
        $this->assertStringNotContainsString('rujukan', $isiLog);
        $this->assertSame(0, DB::table('audit.log_akses')->where('pasien_id', $lain->id)->count());

        // Lewat berakhir_pada: tertutup lagi tanpa tindakan.
        $this->travelTo(now()->parse($berakhir)->addMinute());
        $this->masukSebagai($klinisi, $rahasia);
        $this->minta('GET', "/api/v1/pasien/{$pasien->id}/cppt")->assertForbidden();
        $this->assertSame(4, DB::table('audit.log_akses')->where('akses_darurat_id', $id)->count());

        // Akses milik sendiri tidak boleh ditinjau sendiri.
        $this->tugaskan($klinisi, $peranTinjau);
        $this->masukSebagai($klinisi, $rahasia);
        $this->minta('POST', "/api/v1/akses-darurat/$id/tinjau", ['hasil' => 'wajar', 'catatan' => 'Tinjau sendiri'])
            ->assertForbidden()->assertJsonPath('error.kode', 'diri_sendiri');

        $this->masukSebagai($kepalaRm);
        $daftar = $this->minta('GET', '/api/v1/akses-darurat')->assertOk()->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.0.jumlah_akses', 4)->assertJsonPath('data.0.aktif', false)->assertJsonPath('data.0.dapat.tinjau', true)
            ->assertJsonPath('data.0.pengguna.id', $klinisi->id);
        $this->assertSesuaiKontrak($daftar, 'GET', '/akses-darurat');
        $this->assertSame([], $this->minta('GET', '/api/v1/akses-darurat?status=ditinjau')->assertOk()->json('data'));
        $tinjau = $this->minta('POST', "/api/v1/akses-darurat/$id/tinjau", ['hasil' => 'wajar', 'catatan' => 'Sesuai rujukan IGD'])->assertOk()
            ->assertJsonPath('data.tinjauan.hasil', 'wajar')->assertJsonPath('data.tinjauan.oleh.id', $kepalaRm->id)->assertJsonPath('data.dapat.tinjau', false);
        $this->assertSesuaiKontrak($tinjau, 'POST', '/akses-darurat/{id}/tinjau');
        $this->minta('POST', "/api/v1/akses-darurat/$id/tinjau", ['hasil' => 'tidak_wajar', 'catatan' => 'Ubah hasil'])
            ->assertStatus(409)->assertJsonPath('error.kode', 'sudah_ditinjau');
        $this->assertSame('wajar', DB::table('akses.akses_darurat')->where('id', $id)->value('hasil_tinjauan'));
        $this->assertSame(0, DB::table('inti.tugas')->where('objek_id', $id)->where('status', 'terbuka')->count());
        $this->assertSame(['hasil' => 'wajar'], json_decode($this->assertAuditTerakhir('akses.akses_darurat_ditinjau', $id)->sesudah, true));
        $this->assertSame([$id], $this->minta('GET', '/api/v1/akses-darurat?status=ditinjau')->json('data.*.id'));
        $this->minta('POST', '/api/v1/akses-darurat/'.Str::uuid7().'/tinjau', ['hasil' => 'wajar', 'catatan' => 'Tidak ada'])->assertNotFound();

        // Tanpa izin tinjauan: daftar ditolak.
        $this->masukSebagai($this->akunKlinis($this->dokter()));
        $this->minta('GET', '/api/v1/akses-darurat')->assertForbidden();
    }

    public function test_superadmin_non_klinis_sip_habis_dan_mfa_basi_ditolak(): void
    {
        $pasien = $this->pasien();
        $isi = ['kategori_alasan' => 'pelayanan_lanjutan', 'alasan' => self::ALASAN];

        [$superadmin, $rahasiaSa] = $this->superadminBerMfa();
        Pegawai::query()->whereKey($superadmin->pegawai_id)->update(['profesi' => 'dokter']);
        $this->masukSebagai($superadmin, $rahasiaSa);
        $this->minta('POST', "/api/v1/pasien/{$pasien->id}/akses-darurat", $isi)->assertForbidden()->assertJsonPath('error.kode', 'akses_ditolak');

        /** @var Pegawai $nonKlinis */
        $nonKlinis = Pegawai::factory()->create(['profesi' => 'non_kesehatan']);
        [$admin, $rahasia] = $this->klinisiPoliB($nonKlinis);
        $this->masukSebagai($admin, $rahasia);
        $this->minta('POST', "/api/v1/pasien/{$pasien->id}/akses-darurat", $isi)->assertForbidden()->assertJsonPath('error.kode', 'akses_ditolak');

        $dokter = $this->dokter();
        IzinPraktik::query()->where('pegawai_id', $dokter->id)->update(['berlaku_sampai' => '2026-10-31']);
        [$sipHabis, $rahasia] = $this->klinisiPoliB($dokter);
        $this->masukSebagai($sipHabis, $rahasia);
        $this->minta('POST', "/api/v1/pasien/{$pasien->id}/akses-darurat", $isi)->assertForbidden()->assertJsonPath('error.kode', 'izin_praktik_tidak_berlaku');

        [$klinisi, $rahasia] = $this->klinisiPoliB();
        $this->masukSebagai($klinisi, $rahasia);
        // Sesi tetap hidup (batas menganggur), MFA sesi sudah lewat 15 menit.
        $this->travel(10)->minutes();
        $this->minta('GET', '/api/v1/saya')->assertOk();
        $this->travel(10)->minutes();
        $this->minta('POST', "/api/v1/pasien/{$pasien->id}/akses-darurat", $isi)->assertForbidden()->assertJsonPath('error.kode', 'mfa_ulang_diperlukan');
        $this->assertSame(0, DB::table('akses.akses_darurat')->count());
    }

    /**
     * Klinisi berMFA di Poli B (unit lain) dengan izin CPPT/pemeriksaan dan izin darurat (diberikan langsung, seperti
     * sesudah pemilik data menyetujui).
     *
     * @return array{0: Pengguna, 1: string}
     */
    private function klinisiPoliB(?Pegawai $pegawai = null): array
    {
        $rahasia = Totp::rahasiaBaru();
        $akun = $this->buatPengguna(['pegawai_id' => ($pegawai ?? $this->dokter())->id]);
        $akun->forceFill(['mfa_rahasia_enkripsi' => $rahasia, 'mfa_aktif_pada' => now()])->save();
        $peran = $this->buatPeran(['rawat_jalan.cppt', 'rawat_jalan.pemeriksaan']);
        PeranIzin::query()->create(['peran_id' => $peran->id, 'izin_id' => Izin::query()->where('kode', 'rawat_jalan.cppt.darurat')->value('id')]);
        $this->tugaskan($akun, $peran, cakupan: new Cakupan('unit', $this->unitB->id));

        return [$akun, $rahasia];
    }

    private function akunKlinis(Pegawai $pegawai): Pengguna
    {
        $akun = $this->buatPengguna(['pegawai_id' => $pegawai->id]);
        $this->tugaskan($akun, $this->buatPeran(['rawat_jalan.cppt', 'rawat_jalan.pemeriksaan']), cakupan: new Cakupan('unit', $this->poli->unit_organisasi_id));

        return $akun;
    }

    /** @return array{0: string, 1: string} kunjungan di Poli A dan id CPPT sah berlabel psikiatri */
    private function cpptBerlabel(Pasien $pasien): array
    {
        $this->masukSebagai($this->petugasPendaftaran());
        $jadwal = $this->jadwalPoliA();
        $kunjungan = $this->daftarKunjungan($pasien, $jadwal)->assertCreated()->json('data.id');
        $penulis = $this->akunKlinis(Pegawai::query()->findOrFail($jadwal->pegawai_id));
        $this->masukSebagai($penulis);
        $this->minta('POST', '/api/v1/saya/ttd/aktivasi', ['sandi' => self::SANDI_UJI, 'pin' => self::PIN, 'pin_ulang' => self::PIN])->assertSuccessful();
        $id = $this->minta('POST', "/api/v1/kunjungan/$kunjungan/cppt", [...self::SOAP, 'label' => 'psikiatri'])->assertCreated()->json('data.id');
        $this->mintaDenganVersi('POST', "/api/v1/cppt/$id/sahkan", '0', ['pin' => self::PIN, 'pernyataan_disetujui' => true])->assertOk();

        return [$kunjungan, $id];
    }

    private function jadwalPoliA(): JadwalPraktik
    {
        return $this->jadwal($this->dokter(), ['poli_id' => $this->poli->id]);
    }
}
