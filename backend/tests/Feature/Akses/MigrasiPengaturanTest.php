<?php

namespace Tests\Feature\Akses;

use App\Modules\Akses\Application\AccessEvaluator;
use App\Modules\Akses\Application\CacheHakAkses;
use App\Modules\Akses\Database\MenuBawaan;
use App\Modules\Akses\Models\Izin;
use App\Modules\Akses\Models\Menu;
use App\Modules\Akses\Models\PenggunaIzinKhusus;
use Illuminate\Support\Facades\DB;
use Tests\Support\AksesUji;
use Tests\Support\KlienSpa;
use Tests\TestCase;

/**
 * Pemindahan kode peran/akun/audit dari Master Data ke Pengaturan (tiket 1.2c-be, MenuBawaan::KODE_PINDAH).
 * DB baru sudah memakai pengaturan.*, jadi keadaan lama (sesudah 1.0g) dibentuk ulang di dalam transaksi tes.
 */
class MigrasiPengaturanTest extends TestCase
{
    use AksesUji, KlienSpa;

    public function test_db_lama_pindah_ke_pengaturan_dengan_id_tetap_dan_aman_diulang(): void
    {
        $this->kembalikanKeKodeLama();
        $peran = $this->buatPeran(['master_data.peran', 'master_data.akun']);
        $pemegang = $this->buatPengguna();
        $this->tugaskan($pemegang, $peran);
        PenggunaIzinKhusus::query()->create([
            'pengguna_id' => $pemegang->id, 'efek' => 'cabut', 'dasar_pemberian' => 'SK uji', 'alasan' => 'Uji',
            'izin_id' => Izin::query()->where('kode', 'master_data.akun.perbaiki')->value('id'),
        ]);
        $id = Menu::query()->whereIn('kode', ['master_data.peran', 'master_data.akun', 'master_data.audit'])->pluck('id', 'kode');
        $jumlah = fn () => [Menu::query()->count(), Izin::query()->count(), DB::table('akses.peran_izin')->count(), DB::table('akses.peran_menu')->count()];
        $sebelum = $jumlah();

        MenuBawaan::pasang();
        MenuBawaan::pasang();
        app(CacheHakAkses::class)->lupakanSemua();

        $this->assertSame($sebelum, $jumlah(), 'Tidak ada baris baru maupun ganda.');
        $pengaturan = Menu::query()->where('kode', 'pengaturan')->value('id');
        foreach (['peran' => 'Peran & izin', 'akun' => 'Akun & akses', 'audit' => 'Jejak audit'] as $kode => $label) {
            $menu = Menu::query()->findOrFail($id["master_data.$kode"]);
            $this->assertSame(["pengaturan.$kode", $pengaturan, "/pengaturan/$kode", $label], [$menu->kode, $menu->induk_id, $menu->rute, $menu->label]);
        }
        $this->assertSame(0, Izin::query()->where('kode', 'like', 'master\_data.peran%')->count());
        $this->assertEqualsCanonicalizing(['master_data.organisasi', 'master_data.jenis_tugas', 'master_data.katalog', 'master_data.penjamin', 'master_data.jadwal_praktik', 'master_data.usulan'],
            Menu::query()->where('induk_id', Menu::query()->where('kode', 'master_data')->value('id'))->pluck('kode')->all());

        // Peran, pengecualian, dan superadmin ikut menunjuk kode baru.
        $evaluator = app(AccessEvaluator::class);
        $this->assertTrue($evaluator->boleh($pemegang, 'pengaturan.peran.perbaiki'));
        $this->assertTrue($evaluator->boleh($pemegang, 'pengaturan.akun.lihat'));
        $this->assertFalse($evaluator->boleh($pemegang, 'pengaturan.akun.perbaiki'), 'Cabut per akun tetap berlaku.');
        $superadmin = $this->buatPengguna();
        $this->tugaskan($superadmin, $this->superadmin());
        $this->assertTrue($evaluator->boleh($superadmin, 'pengaturan.akun.perbaiki'));
        $this->assertFalse($evaluator->bolehMenu($superadmin, 'master_data.katalog'));
    }

    public function test_kode_baru_yang_sudah_ada_tidak_ditimpa(): void
    {
        $baru = Menu::query()->where('kode', 'pengaturan.peran')->firstOrFail();
        $grup = Menu::query()->where('kode', 'master_data')->value('id');
        $lama = Menu::query()->create(['induk_id' => $grup, 'tingkat' => 'sub_menu', 'kode' => 'master_data.peran', 'label' => 'Sisa lama']);

        MenuBawaan::pasang();

        $this->assertSame('master_data.peran', $lama->refresh()->kode);
        $this->assertSame('pengaturan.peran', $baru->refresh()->kode);
    }

    /** Keadaan sesudah 1.0g: peran/akun/audit di bawah master_data dengan kode dan rute lama. */
    private function kembalikanKeKodeLama(): void
    {
        $masterData = Menu::query()->where('kode', 'master_data')->value('id');
        foreach (MenuBawaan::KODE_PINDAH as $lama => $baru) {
            DB::update('UPDATE akses.izin SET kode = ? || substr(kode, ?) WHERE starts_with(kode, ?)', [$lama, strlen($baru) + 1, $baru.'.']);
            DB::table('akses.menu')->where('kode', $baru)->update([
                'kode' => $lama, 'induk_id' => $masterData, 'rute' => '/master-data/'.substr($lama, strlen('master_data.')), 'label' => 'Label lama',
            ]);
        }
        app(CacheHakAkses::class)->lupakanSemua();
    }
}
