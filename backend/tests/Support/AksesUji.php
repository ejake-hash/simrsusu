<?php

namespace Tests\Support;

use App\Modules\Akses\Database\MenuBawaan;
use App\Modules\Akses\Domain\Cakupan;
use App\Modules\Akses\Domain\Totp;
use App\Modules\Akses\Models\Izin;
use App\Modules\Akses\Models\Menu;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\Akses\Models\PenggunaPeran;
use App\Modules\Akses\Models\Peran;
use App\Modules\Akses\Models\PeranIzin;
use App\Modules\Akses\Models\PeranMenu;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/** Data akses sintetis dan masuk lewat alur SPA (dipakai tes Pengaturan, tiket 1.2c). Butuh KlienSpa. */
trait AksesUji
{
    protected const SANDI_UJI = 'kuda-lumping-biru-29';

    protected function buatPengguna(array $atribut = []): Pengguna
    {
        return Pengguna::factory()->denganSandi(self::SANDI_UJI)->create($atribut);
    }

    /** @param  list<string>  $subMenu  diberikan beserta semua aksinya */
    protected function buatPeran(array $subMenu, array $atribut = []): Peran
    {
        $peran = Peran::query()->create(['kode' => 'uji_'.Str::lower(Str::random(8)), 'nama' => 'Peran uji '.Str::random(4), ...$atribut]);
        foreach ($subMenu as $kode) {
            $menu = Menu::query()->where('kode', $kode)->firstOrFail();
            PeranMenu::query()->create(['peran_id' => $peran->id, 'menu_id' => $menu->id]);
            foreach (Izin::query()->where('menu_id', $menu->id)->pluck('id') as $izinId) {
                PeranIzin::query()->create(['peran_id' => $peran->id, 'izin_id' => $izinId]);
            }
        }

        return $peran;
    }

    protected function tugaskan(Pengguna $pengguna, Peran $peran, mixed $sampai = null): PenggunaPeran
    {
        return PenggunaPeran::query()->create(array_filter([
            'pengguna_id' => $pengguna->id, 'peran_id' => $peran->id, 'cakupan_jenis' => Cakupan::rs()->jenis,
            'berlaku_sampai' => $sampai, 'dasar_pemberian' => 'SK uji',
        ], fn ($nilai) => $nilai !== null));
    }

    protected function superadmin(): Peran
    {
        return Peran::query()->where('kode', MenuBawaan::PERAN_SUPERADMIN)->firstOrFail();
    }

    /** Pengelola akses tanpa MFA wajib (peran biasa yang memberi Pengaturan → Peran & izin, Akun & akses). */
    protected function pengelola(): Pengguna
    {
        $pengguna = $this->buatPengguna();
        $this->tugaskan($pengguna, $this->buatPeran(['pengaturan.peran', 'pengaturan.akun']));

        return $pengguna;
    }

    /** Peramban baru lalu masuk dengan sandi, ditambah kode TOTP bila $rahasiaMfa diisi. */
    protected function masukSebagai(Pengguna $pengguna, ?string $rahasiaMfa = null): void
    {
        $this->mulaiSesiSpa();
        $sandi = $this->minta('POST', '/api/v1/autentikasi/masuk', ['nama_pengguna' => $pengguna->nama_pengguna, 'sandi' => self::SANDI_UJI])->assertOk();
        if ($rahasiaMfa === null) {
            $sandi->assertJsonPath('status', 'masuk');

            return;
        }

        $sandi->assertJsonPath('status', 'perlu_mfa');
        $this->minta('POST', '/api/v1/autentikasi/mfa', [
            'challenge_id' => $sandi->json('challenge_id'), 'metode' => 'totp', 'kode' => Totp::kode($rahasiaMfa, Totp::langkah(now()->getTimestamp())),
        ])->assertOk()->assertJsonPath('status', 'masuk');
    }

    /** @return array{0: Pengguna, 1: string} superadmin ber-MFA dan rahasia TOTP-nya */
    protected function superadminBerMfa(): array
    {
        $rahasia = Totp::rahasiaBaru();
        $pengguna = $this->buatPengguna();
        $pengguna->forceFill(['mfa_rahasia_enkripsi' => $rahasia, 'mfa_aktif_pada' => now()])->save();
        $this->tugaskan($pengguna, $this->superadmin());

        return [$pengguna, $rahasia];
    }

    /** @param  array<string, mixed>  $data */
    protected function mintaDenganVersi(string $metode, string $uri, string $versi, array $data = []): TestResponse
    {
        $this->headerTambahan = ['If-Match' => '"'.$versi.'"'];
        try {
            return $this->minta($metode, $uri, $data);
        } finally {
            $this->headerTambahan = [];
        }
    }

    protected function assertAuditTerakhir(string $aksi, string $subjekId): object
    {
        $baris = DB::table('audit.log_aktivitas')->where('aksi', $aksi)->where('subjek_id', $subjekId)
            ->orderByDesc('urutan')->first();
        $this->assertNotNull($baris, "Audit $aksi untuk $subjekId tidak ada.");

        return $baris;
    }
}
