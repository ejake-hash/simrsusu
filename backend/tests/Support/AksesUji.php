<?php

namespace Tests\Support;

use App\Modules\Akses\Database\MenuBawaan;
use App\Modules\Akses\Domain\Cakupan;
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

    /** Peramban baru lalu masuk dengan sandi (tanpa MFA). */
    protected function masukSebagai(Pengguna $pengguna): void
    {
        $this->mulaiSesiSpa();
        $this->minta('POST', '/api/v1/autentikasi/masuk', ['nama_pengguna' => $pengguna->nama_pengguna, 'sandi' => self::SANDI_UJI])
            ->assertOk()->assertJsonPath('status', 'masuk');
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
