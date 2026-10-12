<?php

namespace Tests\Feature\Akses;

use App\Modules\Akses\Application\AccessEvaluator;
use App\Modules\Akses\Application\PemeriksaLabel;
use App\Modules\Akses\Database\MenuBawaan;
use App\Modules\Akses\Domain\RelasiPerawatan;
use App\Modules\Akses\Models\LabelSensitivitas;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\Akses\Models\PenggunaLabel;
use App\Modules\Akses\Models\Peran;
use Illuminate\Testing\TestResponse;
use Tests\Support\AksesUji;
use Tests\Support\KlienSpa;
use Tests\Support\KontrakApi;
use Tests\TestCase;

/** Lapis 4 hak akses: label sensitivitas dan izin label per akun (tiket 1.5; PRD 9.2.2–9.2.4; kontrak v0.14.0). */
class LabelSensitivitasTest extends TestCase
{
    use AksesUji, KlienSpa, KontrakApi;

    private const DASAR = 'SK Direktur 12/2026';

    public function test_data_awal_lima_label_butuh_relasi_perawatan_dan_tanpa_penyetuju(): void
    {
        $label = LabelSensitivitas::query()->orderBy('kode')->get();
        $this->assertSame(['genetik', 'hiv', 'kekerasan_seksual', 'privasi_tinggi', 'psikiatri'], $label->pluck('kode')->all());
        // Keputusan pemilik 12 Okt 2026: true untuk semua label, termasuk privasi_tinggi.
        $this->assertTrue($label->every(fn (LabelSensitivitas $l) => $l->butuh_relasi_perawatan && $l->peran_penyetuju_id === null));

        $this->masukSebagai($this->pengelola());
        $respons = $this->minta('GET', '/api/v1/label-sensitivitas')->assertOk()->assertJsonCount(5, 'data')
            ->assertJsonPath('data.0.penyetuju_peran', null);
        $this->assertSesuaiKontrak($respons, 'GET', '/label-sensitivitas');
    }

    public function test_penyetuju_label_lewat_persetujuan_superadmin_lain_k13(): void
    {
        [$a, $rahasiaA] = $this->superadminBerMfa();
        [$b, $rahasiaB] = $this->superadminBerMfa();
        $pdp = $this->buatPeran(['beranda']);
        $this->masukSebagai($a, $rahasiaA);

        $respons = $this->minta('PUT', '/api/v1/label-sensitivitas/hiv/penyetuju', ['peran_id' => $pdp->id, 'dasar_perubahan' => self::DASAR])
            ->assertStatus(202)->assertJsonPath('data.objek.jenis', 'penyetuju_label')->assertJsonPath('data.objek.label.kode', 'hiv')
            ->assertJsonPath('data.penyetuju_peran.kode', MenuBawaan::PERAN_SUPERADMIN);
        $this->assertSesuaiKontrak($respons, 'PUT', '/label-sensitivitas/{kode}/penyetuju');
        $id = $respons->json('data.id');
        $this->minta('PUT', '/api/v1/label-sensitivitas/hiv/penyetuju', ['peran_id' => $pdp->id, 'dasar_perubahan' => self::DASAR])
            ->assertStatus(409)->assertJsonPath('error.kode', 'persetujuan_ganda');
        $this->minta('POST', "/api/v1/persetujuan-akses/$id/setujui")->assertForbidden()->assertJsonPath('error.kode', 'pengaju_sendiri');
        $this->minta('PUT', '/api/v1/label-sensitivitas/tidak-ada/penyetuju', ['peran_id' => $pdp->id, 'dasar_perubahan' => self::DASAR])->assertNotFound();

        $this->masukSebagai($b, $rahasiaB);
        $this->minta('POST', "/api/v1/persetujuan-akses/$id/setujui")->assertOk()->assertJsonPath('data.diterapkan', true);
        $this->assertSame($pdp->id, LabelSensitivitas::query()->where('kode', 'hiv')->value('peran_penyetuju_id'));
        $this->assertAuditTerakhir('akses.penyetuju_label_diubah', (string) LabelSensitivitas::query()->where('kode', 'hiv')->value('id'));

        // K13: peran pejabat PDP kini setara kuasa memberi akses, jadi penugasannya butuh superadmin lain.
        $this->minta('POST', '/api/v1/akun/'.$this->buatPengguna()->id.'/penugasan', [
            'peran_id' => $pdp->id, 'cakupan_jenis' => 'rs', 'dasar_pemberian' => self::DASAR, 'alasan' => 'Penunjukan pejabat PDP',
        ])->assertStatus(202)->assertJsonPath('data.penyetuju_peran.kode', MenuBawaan::PERAN_SUPERADMIN);
    }

    public function test_izin_label_aktif_hanya_setelah_pejabat_pdp_menyetujui_lalu_dicabut_langsung(): void
    {
        [$pdp, $pejabat] = $this->pejabatPdp('psikiatri');
        $x = $this->buatPengguna();
        $evaluator = app(AccessEvaluator::class);
        $pengelola = $this->pengelola();
        $this->masukSebagai($pengelola);

        $respons = $this->ajukan($x, 'psikiatri')->assertStatus(202)
            ->assertJsonPath('data.jenis', 'izin_label')->assertJsonPath('data.objek.label.kode', 'psikiatri')
            ->assertJsonPath('data.objek.akun.id', $x->id)->assertJsonPath('data.penyetuju_peran.id', $pdp->id);
        $this->assertSesuaiKontrak($respons, 'POST', '/akun/{id}/label');
        $persetujuanId = $respons->json('data.id');
        $this->ajukan($x, 'psikiatri')->assertStatus(409)->assertJsonPath('error.kode', 'persetujuan_ganda');
        $daftar = $this->minta('GET', "/api/v1/akun/{$x->id}/label")->assertOk()->assertJsonPath('data.0.status', 'menunggu')
            ->assertJsonPath('data.0.berlaku_dari', null)->assertJsonPath('data.0.berlaku_sekarang', false);
        $this->assertSesuaiKontrak($daftar, 'GET', '/akun/{id}/label');
        $izinId = $daftar->json('data.0.id');
        // Uji penerimaan: izin label tanpa persetujuan PDP tidak aktif.
        $this->assertFalse($evaluator->punyaIzinLabel($x, 'psikiatri'));
        $this->minta('POST', "/api/v1/akun/{$x->id}/label/$izinId/batal", ['alasan_batal' => 'Salah pilih akun'])
            ->assertStatus(409)->assertJsonPath('error.kode', 'sudah_tidak_berlaku');

        $this->masukSebagai($pejabat);
        $this->minta('POST', "/api/v1/persetujuan-akses/$persetujuanId/setujui")->assertOk()->assertJsonPath('data.diterapkan', true);
        $this->assertTrue($evaluator->punyaIzinLabel($x, 'psikiatri'));
        $this->assertFalse($evaluator->punyaIzinLabel($x, 'hiv'));
        $this->assertAuditTerakhir('akses.label_diberikan', $x->id);

        // Fase 1: tidak ada yang merawat pasien, jadi label (semua butuh relasi perawatan) tetap tertutup.
        $this->assertFalse($evaluator->bolehLabel($x, 'psikiatri', null));
        $this->assertSame(PemeriksaLabel::TERBATAS, app(PemeriksaLabel::class)->bentuk($x, 'psikiatri', null, fn () => ['isi' => 'rahasia']));
        $this->app->bind(RelasiPerawatan::class, fn () => new class implements RelasiPerawatan
        {
            public function merawat(Pengguna $pengguna, ?string $pasienId): bool
            {
                return $pasienId === 'pasien-dirawat';
            }
        });
        $evaluator = app(AccessEvaluator::class);
        $this->assertTrue($evaluator->bolehLabel($x, 'psikiatri', 'pasien-dirawat'));
        $this->assertFalse($evaluator->bolehLabel($x, 'psikiatri', 'pasien-lain'));
        $this->assertFalse($evaluator->bolehLabel($x, 'hiv', 'pasien-dirawat'));
        $this->assertSame(['isi' => 'rahasia'], app(PemeriksaLabel::class)->bentuk($x, 'psikiatri', 'pasien-dirawat', fn () => ['isi' => 'rahasia']));

        $this->masukSebagai($pengelola);
        $this->ajukan($x, 'psikiatri')->assertStatus(409)->assertJsonPath('error.kode', 'label_masih_berlaku');
        $batal = $this->minta('POST', "/api/v1/akun/{$x->id}/label/$izinId/batal", ['alasan_batal' => 'Tugas di poli jiwa selesai'])
            ->assertOk()->assertJsonPath('data.status', 'batal')->assertJsonPath('data.dibatalkan_oleh.id', $pengelola->id);
        $this->assertSesuaiKontrak($batal, 'POST', '/akun/{id}/label/{labelAkunId}/batal');
        $this->assertFalse($evaluator->bolehLabel($x, 'psikiatri', 'pasien-dirawat'));
        $this->assertAuditTerakhir('akses.label_dicabut', $x->id);
    }

    public function test_aturan_pengajuan_izin_label(): void
    {
        $x = $this->buatPengguna();
        $pengelola = $this->pengelola();
        $this->masukSebagai($pengelola);

        $this->ajukan($x, 'hiv')->assertStatus(422)->assertJsonPath('error.kode', 'penyetuju_label_belum_ditetapkan');
        [, $pejabat] = $this->pejabatPdp('hiv');
        $this->ajukan($x, 'hiv', now()->addDays(366)->toIso8601String())->assertStatus(422)->assertJsonPath('error.kode', 'tanggal_tidak_valid');
        $this->ajukan($x, 'hiv', now()->subDay()->toIso8601String())->assertStatus(422)->assertJsonPath('error.kode', 'tanggal_tidak_valid');
        $this->ajukan($x, 'tidak_ada')->assertNotFound();
        $this->ajukan($pengelola, 'hiv')->assertForbidden()->assertJsonPath('error.kode', 'diri_sendiri');
        $this->ajukan($x, 'hiv', now()->addDays(365)->toIso8601String())->assertStatus(202);

        // Penerima akses tidak bisa menyetujui izin label untuk dirinya sendiri walau memegang peran pejabat PDP.
        $persetujuanId = PenggunaLabel::query()->where('pengguna_id', $x->id)->value('persetujuan_akses_id');
        $this->tugaskan($x, Peran::query()->findOrFail(LabelSensitivitas::query()->where('kode', 'hiv')->value('peran_penyetuju_id')));
        $this->masukSebagai($x);
        $this->minta('POST', "/api/v1/persetujuan-akses/$persetujuanId/setujui")->assertForbidden()->assertJsonPath('error.kode', 'penerima_sendiri');

        // Ditolak pejabat PDP → status ditolak; boleh diajukan ulang.
        $this->masukSebagai($pejabat);
        $this->minta('POST', "/api/v1/persetujuan-akses/$persetujuanId/tolak", ['alasan' => 'Tidak ada kebutuhan layanan'])->assertOk();
        $this->masukSebagai($pengelola);
        $this->minta('GET', "/api/v1/akun/{$x->id}/label")->assertOk()->assertJsonPath('data.0.status', 'ditolak');
        $this->ajukan($x, 'hiv')->assertStatus(202);
    }

    public function test_persetujuan_sesudah_masa_berlaku_lewat_tidak_diterapkan(): void
    {
        [, $pejabat] = $this->pejabatPdp('genetik');
        $x = $this->buatPengguna();
        $this->masukSebagai($this->pengelola());
        $id = $this->ajukan($x, 'genetik', now()->addDays(2)->toIso8601String())->assertStatus(202)->json('data.id');

        $this->travel(3)->days();
        $this->masukSebagai($pejabat);
        $this->minta('POST', "/api/v1/persetujuan-akses/$id/setujui")->assertStatus(409)->assertJsonPath('error.kode', 'tidak_bisa_diterapkan');
        $this->assertFalse(app(AccessEvaluator::class)->punyaIzinLabel($x, 'genetik'));
    }

    public function test_label_berakhir_menutup_akses_walau_cache_masih_hangat(): void
    {
        [, $pejabat] = $this->pejabatPdp('hiv');
        $x = $this->buatPengguna();
        $this->masukSebagai($this->pengelola());
        $id = $this->ajukan($x, 'hiv', now()->addDay()->toIso8601String())->json('data.id');
        $this->masukSebagai($pejabat);
        $this->minta('POST', "/api/v1/persetujuan-akses/$id/setujui")->assertOk();
        $evaluator = app(AccessEvaluator::class);
        $this->assertTrue($evaluator->punyaIzinLabel($x, 'hiv'));

        $this->travel(1)->days();
        $this->travel(1)->seconds();
        $this->assertFalse($evaluator->punyaIzinLabel($x, 'hiv'));
    }

    public function test_perubahan_data_label_langsung_terbaca_walau_cache_hangat(): void
    {
        [, $pejabat] = $this->pejabatPdp('hiv');
        $x = $this->buatPengguna();
        $this->masukSebagai($this->pengelola());
        $id = $this->ajukan($x, 'hiv')->json('data.id');
        $this->masukSebagai($pejabat);
        $this->minta('POST', "/api/v1/persetujuan-akses/$id/setujui")->assertOk();
        $evaluator = app(AccessEvaluator::class);
        $this->assertFalse($evaluator->bolehLabel($x, 'hiv', null));

        $label = LabelSensitivitas::query()->where('kode', 'hiv')->firstOrFail();
        $label->forceFill(['butuh_relasi_perawatan' => false])->save();
        $this->assertTrue($evaluator->bolehLabel($x, 'hiv', null));
        $label->forceFill(['aktif' => false])->save();
        $this->assertFalse($evaluator->bolehLabel($x, 'hiv', null));
    }

    public function test_label_akun_superadmin_idor_dan_superadmin_pemegang_peran_sasaran(): void
    {
        $this->pejabatPdp('hiv');
        [$sa] = $this->superadminBerMfa();
        $pengelola = $this->pengelola();
        $this->masukSebagai($pengelola);
        $this->ajukan($sa, 'hiv')->assertForbidden()->assertJsonPath('error.kode', 'akun_superadmin');

        // labelAkunId milik akun lain → 404, bukan pencabutan izin akun itu.
        $x = $this->buatPengguna();
        $y = $this->buatPengguna();
        $this->ajukan($x, 'hiv')->assertStatus(202);
        $milikX = PenggunaLabel::query()->where('pengguna_id', $x->id)->value('id');
        $this->minta('POST', "/api/v1/akun/{$y->id}/label/$milikX/batal", ['alasan_batal' => 'Uji IDOR akun lain'])->assertNotFound();

        // Superadmin yang memegang peran yang akan ditunjuk tidak bisa menyetujui penunjukannya (penerima_sendiri).
        [$a, $rahasiaA] = $this->superadminBerMfa();
        [$b, $rahasiaB] = $this->superadminBerMfa();
        $calon = $this->buatPeran(['beranda']);
        $this->tugaskan($b, $calon);
        $this->masukSebagai($a, $rahasiaA);
        $id = $this->minta('PUT', '/api/v1/label-sensitivitas/genetik/penyetuju', ['peran_id' => $calon->id, 'dasar_perubahan' => self::DASAR])
            ->assertStatus(202)->json('data.id');
        $this->masukSebagai($b, $rahasiaB);
        $this->minta('POST', "/api/v1/persetujuan-akses/$id/setujui")->assertForbidden()->assertJsonPath('error.kode', 'penerima_sendiri');
        $this->assertNotSame($calon->id, LabelSensitivitas::query()->where('kode', 'genetik')->value('peran_penyetuju_id'));
    }

    public function test_tanpa_izin_akun_ditolak(): void
    {
        $tamu = $this->buatPengguna();
        $this->tugaskan($tamu, $this->buatPeran(['beranda']));
        $this->masukSebagai($tamu);

        $this->minta('GET', '/api/v1/label-sensitivitas')->assertForbidden();
        $this->minta('POST', "/api/v1/akun/{$this->buatPengguna()->id}/label", [])->assertForbidden();
    }

    /** @return array{0: Peran, 1: Pengguna} peran pejabat PDP yang sudah jadi penyetuju label, dan pemegangnya */
    private function pejabatPdp(string $label): array
    {
        $peran = $this->buatPeran(['beranda']);
        LabelSensitivitas::query()->where('kode', $label)->update(['peran_penyetuju_id' => $peran->id]);
        $pejabat = $this->buatPengguna();
        $this->tugaskan($pejabat, $peran);

        return [$peran, $pejabat];
    }

    private function ajukan(Pengguna $akun, string $label, ?string $sampai = null): TestResponse
    {
        return $this->minta('POST', "/api/v1/akun/{$akun->id}/label", [
            'label_kode' => $label, 'berlaku_sampai' => $sampai ?? now()->addDays(90)->toIso8601String(),
            'dasar_pemberian' => self::DASAR, 'alasan' => 'Tugas di layanan terkait',
        ]);
    }
}
