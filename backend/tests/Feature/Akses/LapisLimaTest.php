<?php

namespace Tests\Feature\Akses;

use App\Modules\Akses\Domain\Cakupan;
use App\Modules\Akses\Domain\TindakanKlinis;
use App\Modules\Akses\Domain\Totp;
use App\Modules\Akses\Http\Middleware\SesiPengguna;
use App\Modules\Akses\Models\Izin;
use App\Modules\Akses\Models\Menu;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\Sdm\Models\IzinPraktik;
use App\Modules\Sdm\Models\KewenanganKlinis;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Tests\Support\AksesUji;
use Tests\Support\KlienSpa;
use Tests\Support\KontrakApi;
use Tests\TestCase;

/**
 * Lapis 5 hak akses (tiket 1.5; PRD 9.2.2 "lapis 5 mengalahkan semuanya", 9.2.6; K11; kontrak v0.14.0). Endpoint uji
 * dengan izin butuh_kewenangan_klinis / butuh_mfa hanya didaftarkan di tes ini, tidak di kontrak.
 */
class LapisLimaTest extends TestCase
{
    use AksesUji, KlienSpa, KontrakApi;

    private const KLINIS = 'igd.papan.sahkan';

    private const MFA = 'igd.papan.verifikasi';

    protected function setUp(): void
    {
        parent::setUp();

        $menu = Menu::query()->where('kode', 'igd.papan')->firstOrFail();
        foreach ([self::KLINIS => ['sahkan', false, true], self::MFA => ['verifikasi', true, false]] as $kode => [$aksi, $mfa, $klinis]) {
            Izin::query()->updateOrCreate(['kode' => $kode], ['menu_id' => $menu->id, 'jenis_aksi' => $aksi, 'butuh_mfa' => $mfa, 'butuh_kewenangan_klinis' => $klinis]);
        }
        Route::middleware(['api', 'auth:web', SesiPengguna::class])->group(function () {
            Route::post('api/v1/uji-lapis-lima/klinis', fn () => ['ok' => true])->middleware('can:'.self::KLINIS);
            Route::post('api/v1/uji-lapis-lima/mfa', fn () => ['ok' => true])->middleware('can:'.self::MFA);
        });
    }

    public function test_sip_habis_kemarin_aksi_klinis_ditolak_walau_perannya_memberi(): void
    {
        $dokter = $this->dokter();
        $sip = $this->sip($dokter, now()->subDay()->toDateString());
        $this->masukSebagai($dokter);

        $this->minta('POST', '/api/v1/uji-lapis-lima/klinis')->assertForbidden()->assertJsonPath('error.kode', 'izin_praktik_tidak_berlaku');
        // Lapis 1–3 tetap memberi: tombol tidak hilang, penolakannya yang menjelaskan.
        $this->minta('GET', '/api/v1/saya/menu')->assertOk();

        // STR seumur hidup bukan izin praktik; SIP baru yang berlaku membuka lagi tanpa admin mengubah peran.
        IzinPraktik::query()->create(['pegawai_id' => $dokter->pegawai_id, 'jenis' => 'str', 'nomor' => 'STR/UJI/1', 'terbit' => '2020-01-01']);
        $this->minta('POST', '/api/v1/uji-lapis-lima/klinis')->assertForbidden()->assertJsonPath('error.kode', 'izin_praktik_tidak_berlaku');
        $sip->forceFill(['status' => 'batal', 'alasan_batal' => 'Diganti', 'dibatalkan_pada' => now()])->save();
        $this->sip($dokter, now()->addYear()->toDateString());
        $this->minta('POST', '/api/v1/uji-lapis-lima/klinis')->assertOk();
    }

    public function test_sip_yang_berakhir_hari_ini_masih_berlaku_dan_besok_terkunci_tanpa_tindakan_admin(): void
    {
        $dokter = $this->dokter();
        $this->sip($dokter, now((string) config('instalasi.zona_waktu'))->toDateString());
        $boleh = fn () => Gate::forUser($dokter->refresh())->inspect(self::KLINIS, Cakupan::rs());

        $this->assertTrue($boleh()->allowed());
        $this->travel(1)->days();
        $this->assertSame('izin_praktik_tidak_berlaku', $boleh()->code());
    }

    public function test_rkk_harus_memuat_kode_tindakan(): void
    {
        $dokter = $this->dokter();
        $this->sip($dokter, now()->addYear()->toDateString());
        KewenanganKlinis::query()->create([
            'pegawai_id' => $dokter->pegawai_id, 'kategori' => 'Uji', 'kode_tindakan' => ['UJI-01', 'UJI-02'],
            'berlaku_dari' => now()->subMonth()->toDateString(), 'berlaku_sampai' => now()->addYear()->toDateString(), 'komite' => 'Komite medik',
        ]);
        $gate = Gate::forUser($dokter);

        $this->assertTrue($gate->inspect(self::KLINIS, [Cakupan::rs(), new TindakanKlinis('UJI-02')])->allowed());
        $this->assertSame('kewenangan_klinis_tidak_mencakup', $gate->inspect(self::KLINIS, [Cakupan::rs(), new TindakanKlinis('UJI-99')])->code());
        // Argumen yang tidak dikenal tetap ditolak (tolak kecuali diberikan).
        $this->assertTrue($gate->denies(self::KLINIS, [Cakupan::rs(), 'UJI-02']));
    }

    public function test_aksi_butuh_mfa_20_menit_setelah_mfa_minta_mfa_ulang_lalu_permintaan_diulang(): void
    {
        $rahasia = Totp::rahasiaBaru();
        $pengguna = $this->buatPengguna();
        $pengguna->forceFill(['mfa_rahasia_enkripsi' => $rahasia, 'mfa_aktif_pada' => now(), 'wajib_mfa' => true])->save();
        $this->tugaskan($pengguna, $this->buatPeran(['igd.papan']));
        $this->masukSebagai($pengguna, $rahasia);

        $this->minta('POST', '/api/v1/uji-lapis-lima/mfa')->assertOk();
        $this->travel(10)->minutes();
        $this->minta('POST', '/api/v1/uji-lapis-lima/mfa')->assertOk();
        $this->travel(10)->minutes();
        $this->minta('POST', '/api/v1/uji-lapis-lima/mfa')->assertForbidden()->assertJsonPath('error.kode', 'mfa_ulang_diperlukan');

        // Kode salah: 422 (bukan 401, sesi tetap masuk) dan dihitung ke penguncian yang sama dengan masuk.
        $this->minta('POST', '/api/v1/saya/mfa/verifikasi-ulang', ['metode' => 'totp', 'kode' => '000000'])
            ->assertStatus(422)->assertJsonPath('error.kode', 'kode_mfa_salah');
        $this->assertSame(1, (int) $pengguna->refresh()->getAttribute('percobaan_gagal'));
        $this->minta('GET', '/api/v1/saya')->assertOk();

        $respons = $this->minta('POST', '/api/v1/saya/mfa/verifikasi-ulang', [
            'metode' => 'totp', 'kode' => Totp::kode($rahasia, Totp::langkah(now()->getTimestamp())),
        ])->assertOk();
        $this->assertSesuaiKontrak($respons, 'POST', '/saya/mfa/verifikasi-ulang');
        $this->assertSame(now()->addMinutes(15)->getTimestamp(), strtotime((string) $respons->json('data.berlaku_sampai')));
        $this->assertAuditTerakhir('autentikasi.mfa_ulang_berhasil', $pengguna->id);
        $this->minta('POST', '/api/v1/uji-lapis-lima/mfa')->assertOk();
    }

    public function test_akun_tanpa_mfa_tidak_bisa_aksi_butuh_mfa(): void
    {
        $pengguna = $this->buatPengguna();
        $this->tugaskan($pengguna, $this->buatPeran(['igd.papan']));
        $this->masukSebagai($pengguna);

        $this->minta('POST', '/api/v1/uji-lapis-lima/mfa')->assertForbidden()->assertJsonPath('error.kode', 'mfa_belum_terdaftar');
        $this->minta('POST', '/api/v1/saya/mfa/verifikasi-ulang', ['metode' => 'totp', 'kode' => '123456'])
            ->assertForbidden()->assertJsonPath('error.kode', 'mfa_belum_terdaftar');
    }

    public function test_verifikasi_ulang_hanya_untuk_sesi_yang_sudah_masuk(): void
    {
        $this->mulaiSesiSpa();
        $this->minta('POST', '/api/v1/saya/mfa/verifikasi-ulang', ['metode' => 'totp', 'kode' => '123456'])
            ->assertUnauthorized()->assertJsonPath('error.kode', 'belum_masuk');
    }

    private function dokter(): Pengguna
    {
        $dokter = $this->buatPengguna();
        $this->tugaskan($dokter, $this->buatPeran(['igd.papan']));

        return $dokter;
    }

    private function sip(Pengguna $dokter, string $sampai): IzinPraktik
    {
        return IzinPraktik::query()->create([
            'pegawai_id' => $dokter->pegawai_id, 'jenis' => 'sip', 'nomor' => 'SIP/UJI/'.uniqid(), 'terbit' => '2021-01-01', 'berlaku_sampai' => $sampai,
        ]);
    }
}
