<?php

namespace Tests\Feature\Akses;

use App\Modules\Akses\Application\AccessEvaluator;
use App\Modules\Akses\Application\AlurPersetujuan;
use App\Modules\Akses\Database\MenuBawaan;
use App\Modules\Akses\Models\Izin;
use App\Modules\Akses\Models\Menu;
use App\Modules\Akses\Models\PenggunaIzinKhusus;
use App\Modules\Akses\Models\PenggunaPeran;
use App\Modules\Akses\Models\Peran;
use App\Modules\Akses\Models\PermintaanAkses;
use App\Modules\Akses\Models\PersetujuanAkses;
use Illuminate\Support\Facades\DB;
use Tests\Support\AksesUji;
use Tests\Support\KlienSpa;
use Tests\Support\KontrakApi;
use Tests\TestCase;

/** Persetujuan dan permintaan akses (tiket 1.2d; PRD 9.2.3–9.2.4, 9.3; K3, K4, K13). */
class PersetujuanAksesTest extends TestCase
{
    use AksesUji, KlienSpa, KontrakApi;

    private const DASAR = 'Surat tugas 9/2026';

    public function test_k13_penyetuju_bawaan_izin_pengelola_akses_adalah_superadmin(): void
    {
        $this->assertNotEmpty(MenuBawaan::IZIN_SENSITIF);
        foreach (MenuBawaan::IZIN_SENSITIF as $kode) {
            $this->assertSame($this->superadmin()->id, Izin::query()->where('kode', $kode)->value('peran_penyetuju_id'), $kode);
        }

        // Uji penerimaan K13: A mengajukan, A tidak bisa menyetujui, B menyetujui, pemegang peran mendapat aksinya.
        [$a, $rahasiaA] = $this->superadminBerMfa();
        [$b, $rahasiaB] = $this->superadminBerMfa();
        $x = $this->buatPeran(['pengaturan.akun']);
        DB::table('akses.peran_izin')->where('peran_id', $x->id)
            ->whereIn('izin_id', Izin::query()->whereIn('kode', MenuBawaan::IZIN_SENSITIF)->select('id'))
            ->update(['status' => 'batal', 'alasan_batal' => 'Uji', 'dibatalkan_pada' => now()]);
        $pemegang = $this->buatPengguna();
        $this->tugaskan($pemegang, $x);
        $this->masukSebagai($a, $rahasiaA);
        $id = $this->mintaDenganVersi('PUT', "/api/v1/peran/{$x->id}/izin", (string) $x->refresh()->revisi, [
            'izin' => ['pengaturan.akun.lihat', 'pengaturan.akun.perbaiki'], 'dasar_perubahan' => self::DASAR,
        ])->assertOk()->assertJsonPath('perubahan.menunggu_persetujuan.0.penyetuju_peran.kode', MenuBawaan::PERAN_SUPERADMIN)
            ->json('perubahan.menunggu_persetujuan.0.id');
        $this->minta('POST', "/api/v1/persetujuan-akses/$id/setujui")->assertForbidden()->assertJsonPath('error.kode', 'pengaju_sendiri');
        $boleh = fn () => app(AccessEvaluator::class)->boleh($pemegang, 'pengaturan.akun.perbaiki');
        $this->assertFalse($boleh());

        $this->masukSebagai($b, $rahasiaB);
        $this->minta('POST', "/api/v1/persetujuan-akses/$id/setujui")->assertOk()->assertJsonPath('data.diterapkan', true);
        // Hak akses tersimpan di cache sebelum disetujui; penerapan menghapusnya.
        $this->assertTrue($boleh());
    }

    public function test_izin_sensitif_ke_peran_berlaku_setelah_pemilik_data_menyetujui(): void
    {
        $pemilik = $this->buatPeran(['igd.papan']);
        $this->jadikanSensitif('kasir.deposit.perbaiki', $pemilik);
        $kasir = $this->buatPeran(['kasir.tagihan']);
        $pengelola = $this->pengelola();
        $pemegangKasir = $this->buatPengguna();
        $this->tugaskan($pemegangKasir, $kasir);
        $this->tugaskan($pemegangKasir, $pemilik);
        $pemberiPersetujuan = $this->buatPengguna();
        $this->tugaskan($pemberiPersetujuan, $pemilik);
        $this->masukSebagai($pengelola);

        $izinBaru = ['kasir.tagihan.input', 'kasir.tagihan.lihat', 'kasir.tagihan.perbaiki', 'kasir.deposit.lihat', 'kasir.deposit.perbaiki'];
        $respons = $this->mintaDenganVersi('PUT', "/api/v1/peran/{$kasir->id}/izin", (string) $kasir->refresh()->revisi, ['izin' => $izinBaru, 'dasar_perubahan' => self::DASAR])
            ->assertOk()->assertJsonPath('perubahan.ditambah', ['kasir.deposit.lihat'])
            ->assertJsonPath('perubahan.menunggu_persetujuan.0.status', 'menunggu')
            ->assertJsonPath('perubahan.menunggu_persetujuan.0.objek.izin.kode', 'kasir.deposit.perbaiki')
            ->assertJsonPath('perubahan.menunggu_persetujuan.0.akun_terdampak', 1)
            ->assertJsonPath('data.izin_menunggu.0.kode', 'kasir.deposit.perbaiki');
        $this->assertSesuaiKontrak($respons, 'PUT', '/peran/{id}/izin');
        $this->assertNotContains('kasir.deposit.perbaiki', $respons->json('data.izin'));
        $id = $respons->json('perubahan.menunggu_persetujuan.0.id');

        // Dikirim ulang selagi menunggu: tidak diajukan ganda.
        $this->mintaDenganVersi('PUT', "/api/v1/peran/{$kasir->id}/izin", (string) $kasir->refresh()->revisi, ['izin' => $izinBaru, 'dasar_perubahan' => self::DASAR])
            ->assertOk()->assertJsonPath('perubahan.menunggu_persetujuan.0.id', $id);
        $this->assertSame(1, PersetujuanAkses::query()->where('peran_id', $kasir->id)->count());

        // Pengaju melihatnya di "saya ajukan", tetapi tidak bisa memutuskan.
        $this->assertSesuaiKontrak($this->minta('GET', '/api/v1/persetujuan-akses?tab=saya_ajukan')->assertOk()
            ->assertJsonPath('data.0.id', $id)->assertJsonPath('data.0.dapat_diputuskan', false), 'GET', '/persetujuan-akses');
        $this->minta('POST', "/api/v1/persetujuan-akses/$id/setujui")->assertForbidden()->assertJsonPath('error.kode', 'bukan_penyetuju');

        // Pemegang peran sasaran tidak bisa menyetujui walau memegang peran penyetuju.
        $this->masukSebagai($pemegangKasir);
        $this->minta('GET', '/api/v1/persetujuan-akses?tab=perlu_saya_putuskan')->assertOk()->assertJsonCount(0, 'data');
        $this->minta('POST', "/api/v1/persetujuan-akses/$id/setujui")->assertForbidden()->assertJsonPath('error.kode', 'penerima_sendiri');

        // Akun yang tidak terkait tidak melihatnya sama sekali.
        $this->masukSebagai($this->buatPengguna());
        $this->minta('GET', "/api/v1/persetujuan-akses/$id")->assertNotFound();

        $this->masukSebagai($pemberiPersetujuan);
        $this->minta('GET', '/api/v1/persetujuan-akses?tab=perlu_saya_putuskan')->assertOk()
            ->assertJsonPath('data.0.id', $id)->assertJsonPath('data.0.dapat_diputuskan', true);
        $disetujui = $this->minta('POST', "/api/v1/persetujuan-akses/$id/setujui", ['catatan' => 'Sesuai SK'])->assertOk()
            ->assertJsonPath('data.status', 'disetujui')->assertJsonPath('data.diterapkan', true)->assertJsonPath('data.grup.disetujui', 1);
        $this->assertSesuaiKontrak($disetujui, 'POST', '/persetujuan-akses/{id}/setujui');
        $this->assertSame($pemberiPersetujuan->id, DB::table('akses.peran_izin')->where('peran_id', $kasir->id)
            ->where('izin_id', Izin::query()->where('kode', 'kasir.deposit.perbaiki')->value('id'))->where('status', 'aktif')->value('disetujui_oleh'));
        $this->assertAuditTerakhir('akses.izin_peran_disetujui', $kasir->id);
        $this->minta('POST', "/api/v1/persetujuan-akses/$id/setujui")->assertStatus(409)->assertJsonPath('error.kode', 'sudah_diputuskan');
    }

    public function test_superadmin_ditambah_dan_diakhiri_hanya_dengan_superadmin_lain(): void
    {
        [$a, $rahasiaA] = $this->superadminBerMfa();
        [$b, $rahasiaB] = $this->superadminBerMfa();
        $calon = $this->buatPengguna();
        $this->masukSebagai($a, $rahasiaA);

        $isi = ['peran_id' => $this->superadmin()->id, 'cakupan_jenis' => 'rs', 'dasar_pemberian' => self::DASAR];
        $diajukan = $this->minta('POST', "/api/v1/akun/{$calon->id}/penugasan", $isi)->assertStatus(202)
            ->assertJsonPath('data.jenis', 'peran_superadmin')->assertJsonPath('data.penyetuju_peran.kode', MenuBawaan::PERAN_SUPERADMIN);
        $this->assertSesuaiKontrak($diajukan, 'POST', '/akun/{id}/penugasan');
        $id = $diajukan->json('data.id');
        $this->assertSame(0, PenggunaPeran::query()->where('pengguna_id', $calon->id)->count());
        $this->minta('POST', "/api/v1/akun/{$calon->id}/penugasan", $isi)->assertStatus(409)->assertJsonPath('error.kode', 'persetujuan_ganda');
        $this->assertSesuaiKontrak($this->minta('GET', "/api/v1/akun/{$calon->id}")->assertOk()
            ->assertJsonPath('data.menunggu_persetujuan.0.id', $id), 'GET', '/akun/{id}');
        $this->minta('POST', "/api/v1/persetujuan-akses/$id/setujui")->assertForbidden()->assertJsonPath('error.kode', 'pengaju_sendiri');

        $this->masukSebagai($calon);
        $this->minta('POST', "/api/v1/persetujuan-akses/$id/setujui")->assertForbidden()->assertJsonPath('error.kode', 'bukan_penyetuju');

        $this->masukSebagai($b, $rahasiaB);
        $this->minta('POST', "/api/v1/persetujuan-akses/$id/setujui")->assertOk()->assertJsonPath('data.diterapkan', true);
        $penugasan = PenggunaPeran::query()->where('pengguna_id', $calon->id)->firstOrFail();
        $this->assertSame([$a->id, $b->id], [$penugasan->getAttribute('diberikan_oleh'), $penugasan->getAttribute('disetujui_oleh')]);

        // Tiga superadmin: mengakhiri salah satunya butuh superadmin lain.
        $akhiri = $this->minta('POST', "/api/v1/akun/{$calon->id}/penugasan/{$penugasan->id}/batal", ['alasan_batal' => 'Pindah tugas'])
            ->assertStatus(202)->assertJsonPath('data.objek.jenis', 'pengakhiran_penugasan')->assertJsonPath('data.objek.penugasan_id', $penugasan->id);
        $this->assertSesuaiKontrak($akhiri, 'POST', '/akun/{id}/penugasan/{penugasanId}/batal');
        $this->assertSame('aktif', $penugasan->refresh()->status);
        $this->masukSebagai($a, $rahasiaA);
        $this->minta('POST', '/api/v1/persetujuan-akses/'.$akhiri->json('data.id').'/setujui')->assertOk()->assertJsonPath('data.diterapkan', true);
        $this->assertSame('batal', $penugasan->refresh()->status);

        // Tinggal dua: ditolak sebelum diajukan.
        $penugasanB = PenggunaPeran::query()->where('pengguna_id', $b->id)->firstOrFail();
        $this->minta('POST', "/api/v1/akun/{$b->id}/penugasan/{$penugasanB->id}/batal", ['alasan_batal' => 'Pindah tugas'])
            ->assertStatus(409)->assertJsonPath('error.kode', 'superadmin_minimal');
    }

    public function test_penyetuju_izin_dan_peran_penyetuju_tidak_bisa_diatur_satu_superadmin(): void
    {
        [$a, $rahasiaA] = $this->superadminBerMfa();
        [$b, $rahasiaB] = $this->superadminBerMfa();
        [$c, $rahasiaC] = $this->superadminBerMfa();
        $pemilik = $this->buatPeran(['igd.papan']);
        $this->tugaskan($b, $pemilik);
        $this->jadikanSensitif('kasir.deposit.perbaiki', null);
        $this->masukSebagai($a, $rahasiaA);

        $atur = fn (string $kode, string $peranId) => $this->minta('PUT', "/api/v1/izin/$kode/penyetuju", ['peran_id' => $peranId, 'dasar_perubahan' => self::DASAR]);
        $this->assertSesuaiKontrak($atur('kasir.deposit.lihat', $pemilik->id)->assertStatus(422)->assertJsonPath('error.kode', 'izin_tidak_sensitif'),
            'PUT', '/izin/{kode}/penyetuju');
        $atur('tidak.ada.perbaiki', $pemilik->id)->assertNotFound();
        $atur('kasir.deposit.perbaiki', $this->superadmin()->id)->assertForbidden()->assertJsonPath('error.kode', 'peran_sendiri');
        $diajukan = $atur('kasir.deposit.perbaiki', $pemilik->id)->assertStatus(202)
            ->assertJsonPath('data.objek.jenis', 'penyetuju_izin')->assertJsonPath('data.penyetuju_peran.kode', MenuBawaan::PERAN_SUPERADMIN);
        $this->assertSesuaiKontrak($diajukan, 'PUT', '/izin/{kode}/penyetuju');
        $atur('kasir.deposit.perbaiki', $pemilik->id)->assertStatus(409)->assertJsonPath('error.kode', 'persetujuan_ganda');
        $this->assertNull(Izin::query()->where('kode', 'kasir.deposit.perbaiki')->value('peran_penyetuju_id'));
        $id = $diajukan->json('data.id');

        // Superadmin yang memegang peran yang ditunjuk adalah penerima: tidak boleh menyetujui.
        $this->masukSebagai($b, $rahasiaB);
        $this->minta('POST', "/api/v1/persetujuan-akses/$id/setujui")->assertForbidden()->assertJsonPath('error.kode', 'penerima_sendiri');
        $this->masukSebagai($c, $rahasiaC);
        $this->minta('POST', "/api/v1/persetujuan-akses/$id/setujui")->assertOk()->assertJsonPath('data.diterapkan', true);
        $this->assertSame($pemilik->id, Izin::query()->where('kode', 'kasir.deposit.perbaiki')->value('peran_penyetuju_id'));
        $this->assertAuditTerakhir('akses.penyetuju_izin_diubah', (string) Izin::query()->where('kode', 'kasir.deposit.perbaiki')->value('id'));
        $menu = $this->minta('GET', '/api/v1/menu')->assertOk();
        $this->assertSesuaiKontrak($menu, 'GET', '/menu');
        $this->assertStringContainsString('"penyetuju_peran":{"id":"'.$pemilik->id.'"', (string) $menu->getContent());

        // Menugaskan peran penyetuju butuh superadmin lain, walau peran itu sendiri tidak memuat izin sensitif.
        $this->masukSebagai($a, $rahasiaA);
        $akun = $this->buatPengguna();
        $this->minta('POST', "/api/v1/akun/{$akun->id}/penugasan", ['peran_id' => $pemilik->id, 'cakupan_jenis' => 'rs', 'dasar_pemberian' => self::DASAR])
            ->assertStatus(202)->assertJsonPath('data.penyetuju_peran.kode', MenuBawaan::PERAN_SUPERADMIN);
        $this->assertSame(0, PenggunaPeran::query()->where('pengguna_id', $akun->id)->count());
    }

    public function test_penugasan_peran_berizin_dua_pemilik_data_berlaku_saat_seluruh_grup_setuju(): void
    {
        $pemilikTagihan = $this->buatPeran(['igd.papan']);
        $pemilikDeposit = $this->buatPeran(['igd.papan']);
        $this->jadikanSensitif('kasir.tagihan.perbaiki', $pemilikTagihan);
        $this->jadikanSensitif('kasir.deposit.perbaiki', $pemilikDeposit);
        $kasir = $this->buatPeran(['kasir.tagihan', 'kasir.deposit']);
        [$penyetujuTagihan, $penyetujuDeposit, $akun] = [$this->buatPengguna(), $this->buatPengguna(), $this->buatPengguna()];
        $this->tugaskan($penyetujuTagihan, $pemilikTagihan);
        $this->tugaskan($penyetujuDeposit, $pemilikDeposit);
        $this->masukSebagai($this->pengelola());

        $diajukan = $this->minta('POST', "/api/v1/akun/{$akun->id}/penugasan", ['peran_id' => $kasir->id, 'cakupan_jenis' => 'rs', 'dasar_pemberian' => self::DASAR])
            ->assertStatus(202)->assertJsonPath('data.jenis', 'izin_sensitif')->assertJsonPath('data.grup.jumlah', 2);
        $grup = PersetujuanAkses::query()->where('grup_id', $diajukan->json('data.grup.id'))->get()->keyBy('penyetuju_peran_id');

        $this->masukSebagai($penyetujuTagihan);
        $this->minta('POST', '/api/v1/persetujuan-akses/'.$grup[$pemilikDeposit->id]->id.'/setujui')->assertNotFound();
        $this->minta('POST', '/api/v1/persetujuan-akses/'.$grup[$pemilikTagihan->id]->id.'/setujui')->assertOk()
            ->assertJsonPath('data.diterapkan', false)->assertJsonPath('data.grup.disetujui', 1);
        $this->assertSame(0, PenggunaPeran::query()->where('pengguna_id', $akun->id)->count());

        $this->masukSebagai($penyetujuDeposit);
        $this->minta('POST', '/api/v1/persetujuan-akses/'.$grup[$pemilikDeposit->id]->id.'/setujui')->assertOk()
            ->assertJsonPath('data.diterapkan', true)->assertJsonPath('data.grup.disetujui', 2);
        $this->assertSame(1, PenggunaPeran::query()->where('pengguna_id', $akun->id)->where('peran_id', $kasir->id)->where('status', 'aktif')->count());
    }

    public function test_ditolak_dibatalkan_kedaluwarsa_dan_tidak_bisa_diterapkan(): void
    {
        $pemilik = $this->buatPeran(['igd.papan']);
        $this->jadikanSensitif('kasir.deposit.perbaiki', $pemilik);
        $pengelola = $this->pengelola();
        $penyetuju = $this->buatPengguna();
        $this->tugaskan($penyetuju, $pemilik);
        $akun = $this->buatPengguna();
        $ajukan = function () use ($akun) {
            return $this->minta('POST', "/api/v1/akun/{$akun->id}/izin-khusus", [
                'efek' => 'tambah', 'sasaran_jenis' => 'izin', 'sasaran_kode' => 'kasir.deposit.perbaiki', 'berlaku_sampai' => now()->addMonth()->toIso8601String(),
                'dasar_pemberian' => self::DASAR, 'alasan' => 'Gantikan kasir cuti',
            ])->assertStatus(202)->json('data.id');
        };

        // Ditolak: seluruh grup selesai, tidak ada yang diterapkan.
        $this->masukSebagai($pengelola);
        $id = $ajukan();
        $this->masukSebagai($penyetuju);
        $this->minta('POST', "/api/v1/persetujuan-akses/$id/tolak", ['alasan' => 'x'])->assertStatus(422);
        $this->assertSesuaiKontrak($this->minta('POST', "/api/v1/persetujuan-akses/$id/tolak", ['alasan' => 'Tidak ada surat tugas'])->assertOk()
            ->assertJsonPath('data.status', 'ditolak')->assertJsonPath('data.diterapkan', false), 'POST', '/persetujuan-akses/{id}/tolak');

        // Dibatalkan: hanya pengaju; akun lain 404.
        $this->masukSebagai($pengelola);
        $id = $ajukan();
        $this->masukSebagai($penyetuju);
        $this->minta('POST', "/api/v1/persetujuan-akses/$id/batalkan", ['alasan' => 'Salah akun'])->assertNotFound();
        $this->masukSebagai($pengelola);
        $this->assertSesuaiKontrak($this->minta('POST', "/api/v1/persetujuan-akses/$id/batalkan", ['alasan' => 'Salah akun'])->assertOk()
            ->assertJsonPath('data.status', 'dibatalkan'), 'POST', '/persetujuan-akses/{id}/batalkan');

        // Kedaluwarsa: ditolak walau tugas harian belum jalan, lalu tugas harian menandainya.
        $id = $ajukan();
        $this->travel(AlurPersetujuan::MASA_HARI + 1)->days();
        $this->masukSebagai($penyetuju);
        $this->minta('GET', "/api/v1/persetujuan-akses/$id")->assertOk()->assertJsonPath('data.status', 'kedaluwarsa')->assertJsonPath('data.dapat_diputuskan', false);
        $this->minta('POST', "/api/v1/persetujuan-akses/$id/setujui")->assertStatus(409)->assertJsonPath('error.kode', 'sudah_diputuskan');
        $this->artisan('akses:kedaluwarsakan-persetujuan')->assertSuccessful();
        $this->assertSame('kedaluwarsa', PersetujuanAkses::query()->findOrFail($id)->status);
        $this->assertSame(0, PenggunaIzinKhusus::query()->where('pengguna_id', $akun->id)->count());

        // Tidak bisa diterapkan: akun sasaran nonaktif sebelum disetujui; tetap tersimpan sebagai ditolak sistem.
        $this->masukSebagai($pengelola);
        $id = $ajukan();
        $akun->forceFill(['status' => 'nonaktif'])->save();
        $this->masukSebagai($penyetuju);
        $this->minta('POST', "/api/v1/persetujuan-akses/$id/setujui")->assertStatus(409)->assertJsonPath('error.kode', 'tidak_bisa_diterapkan');
        $this->minta('GET', "/api/v1/persetujuan-akses/$id")->assertOk()->assertJsonPath('data.status', 'ditolak')->assertJsonPath('data.diputuskan_oleh', null);
    }

    public function test_permintaan_akses_dari_layar_akses_ditolak(): void
    {
        $pemohon = $this->buatPengguna();
        $this->tugaskan($pemohon, $this->buatPeran(['igd.papan']));
        $this->masukSebagai($pemohon);

        $minta = fn (string $jenis, string $kode) => $this->minta('POST', '/api/v1/permintaan-akses', ['sasaran_jenis' => $jenis, 'sasaran_kode' => $kode, 'alasan' => 'Butuh untuk tugas jaga']);
        $dibuat = $minta('menu', 'kasir.tagihan')->assertCreated()->assertJsonPath('data.status', 'menunggu')
            ->assertJsonPath('data.sasaran_kode', 'kasir.tagihan')->assertJsonPath('data.akun.id', $pemohon->id);
        $this->assertSesuaiKontrak($dibuat, 'POST', '/permintaan-akses');
        $this->assertMatchesRegularExpression('/^PMA-\d{4}-\d{6}$/', (string) $dibuat->json('data.nomor'));
        $minta('menu', 'kasir.tagihan')->assertStatus(409)->assertJsonPath('error.kode', 'permintaan_ganda');
        $minta('izin', 'igd.papan.lihat')->assertStatus(409)->assertJsonPath('error.kode', 'sudah_punya_akses');
        $minta('menu', 'kasir')->assertStatus(422)->assertJsonPath('error.kode', 'sasaran_tidak_dikenal');
        $kedua = $minta('izin', 'kasir.deposit.lihat')->assertCreated()->json('data.id');

        $this->assertSesuaiKontrak($this->minta('GET', '/api/v1/permintaan-akses?milik_saya=true')->assertOk()->assertJsonCount(2, 'data'),
            'GET', '/permintaan-akses');
        $this->minta('GET', '/api/v1/permintaan-akses')->assertForbidden();

        // Ditindaklanjuti lewat penugasan yang membawa permintaan_akses_id.
        $this->masukSebagai($this->pengelola());
        $this->minta('GET', '/api/v1/permintaan-akses?status=menunggu')->assertOk()->assertJsonCount(2, 'data');
        $tugas = ['peran_id' => $this->buatPeran(['kasir.tagihan'])->id, 'cakupan_jenis' => 'rs', 'dasar_pemberian' => $dibuat->json('data.nomor'),
            'permintaan_akses_id' => $dibuat->json('data.id')];
        $this->minta('POST', "/api/v1/akun/{$pemohon->id}/penugasan", $tugas)->assertCreated();
        $this->assertSame('ditindaklanjuti', PermintaanAkses::query()->findOrFail($dibuat->json('data.id'))->status);
        $this->minta('POST', "/api/v1/akun/{$pemohon->id}/penugasan", [...$tugas, 'peran_id' => $this->buatPeran(['igd.papan'])->id])
            ->assertStatus(422)->assertJsonPath('error.kode', 'permintaan_tidak_valid');

        $ditolak = $this->minta('POST', "/api/v1/permintaan-akses/$kedua/tolak", ['alasan' => 'Bukan tugas unitnya'])->assertOk()
            ->assertJsonPath('data.status', 'ditolak');
        $this->assertSesuaiKontrak($ditolak, 'POST', '/permintaan-akses/{id}/tolak');
        $this->minta('POST', "/api/v1/permintaan-akses/$kedua/tolak", ['alasan' => 'Bukan tugas unitnya'])->assertStatus(409)->assertJsonPath('error.kode', 'sudah_diputuskan');
    }

    public function test_saran_peran_dari_pengecualian_yang_dipakai_lima_akun(): void
    {
        $menu = Menu::query()->where('kode', 'kasir.tagihan')->firstOrFail();
        $izin = Izin::query()->where('kode', 'igd.papan.lihat')->firstOrFail();
        foreach (range(1, 5) as $_) {
            PenggunaIzinKhusus::query()->create(['pengguna_id' => $this->buatPengguna()->id, 'menu_id' => $menu->id, 'efek' => 'tambah', 'dasar_pemberian' => 'SK uji', 'alasan' => 'Uji saran']);
        }
        foreach (range(1, 4) as $_) {
            PenggunaIzinKhusus::query()->create(['pengguna_id' => $this->buatPengguna()->id, 'izin_id' => $izin->id, 'efek' => 'cabut', 'dasar_pemberian' => 'SK uji', 'alasan' => 'Uji saran']);
        }
        $this->masukSebagai($this->pengelola());

        $saran = $this->minta('GET', '/api/v1/akun/saran-peran')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.sasaran_kode', 'kasir.tagihan')->assertJsonPath('data.0.jumlah_akun', 5);
        $this->assertSesuaiKontrak($saran, 'GET', '/akun/saran-peran');
    }

    private function jadikanSensitif(string $kode, ?Peran $penyetuju): void
    {
        Izin::query()->where('kode', $kode)->update(['sensitif' => true, 'peran_penyetuju_id' => $penyetuju?->id]);
    }
}
