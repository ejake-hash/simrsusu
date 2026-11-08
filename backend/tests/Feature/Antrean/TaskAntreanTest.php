<?php

namespace Tests\Feature\Antrean;

use App\Modules\Antrean\Models\Antrean;
use App\Modules\MasterData\Models\JadwalPraktik;
use App\Modules\MasterData\Models\Penjamin;
use App\Modules\Pendaftaran\Events\KunjunganDibuat;
use App\Modules\RekamMedis\Events\AsesmenPerawatSelesai;
use App\Modules\RekamMedis\Events\PemeriksaanDimulai;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\AksesUji;
use Tests\Support\KlienSpa;
use Tests\Support\KontrakApi;
use Tests\Support\KunjunganUji;
use Tests\TestCase;

/** Tiket 2.18: jam task ID antrean dari kejadian sistem, pemetaan sebagai data, hanya kunjungan JKN, idempoten. */
class TaskAntreanTest extends TestCase
{
    use AksesUji, KlienSpa, KontrakApi, KunjunganUji;

    private JadwalPraktik $jadwal;

    private ?Penjamin $bpjs = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanKunjungan();
        $this->jadwal = $this->jadwal($this->dokter());
        $petugas = $this->petugasPendaftaran();
        $this->tugaskan($petugas, $this->buatPeran(['pendaftaran.antrean', 'integrasi.bpjs']));
        $this->masukSebagai($petugas);
    }

    private function antrean(string $caraBayar): Antrean
    {
        $ganti = $caraBayar === 'jkn' ? ['cara_bayar' => 'jkn', 'penjamin_id' => ($this->bpjs ??= $this->penjamin('bpjs'))->id] : [];
        $id = $this->daftarKunjungan($this->pasien(), $this->jadwal, $ganti)->assertCreated()->json('data.id');

        return Antrean::query()->where('kunjungan_id', $id)->sole();
    }

    private function task(Antrean $antrean): array
    {
        return DB::table('kunjungan.antrean_task')->where('antrean_id', $antrean->id)->orderBy('task_no')->pluck('kejadian', 'task_no')->all();
    }

    public function test_kunjungan_jkn_mencatat_task_dari_pemetaan_dan_idempoten(): void
    {
        $antrean = $this->antrean('jkn');
        $this->assertSame([3 => 'kunjungan_dibuat'], $this->task($antrean));
        $this->assertSame('tertahan', DB::table('kunjungan.antrean_task')->where('antrean_id', $antrean->id)->value('status_kirim'));

        $e = new PemeriksaanDimulai($antrean->kunjungan_id, $antrean->dibuat_oleh ?? (string) Str::uuid(), now()->toIso8601String());
        event($e);
        event($e);
        event(new KunjunganDibuat($antrean->kunjungan_id, '', '', '', '', '', 'jkn'));
        $this->assertSame([3 => 'kunjungan_dibuat', 4 => 'pemeriksaan_dimulai'], $this->task($antrean));
    }

    public function test_pemetaan_adalah_data_dan_asesmen_perawat_tanpa_pemetaan_tidak_dicatat(): void
    {
        $antrean = $this->antrean('jkn');
        $olehId = (string) Str::uuid();
        event(new AsesmenPerawatSelesai($antrean->kunjungan_id, $olehId, now()->toIso8601String()));
        $this->assertSame([3 => 'kunjungan_dibuat'], $this->task($antrean));

        DB::table('kunjungan.pemetaan_task_antrean')->where('kejadian', 'pemeriksaan_dimulai')->update(['task_no' => 6]);
        DB::table('kunjungan.pemetaan_task_antrean')->where('kejadian', 'asesmen_perawat_selesai')->doesntExist()
            && DB::table('kunjungan.pemetaan_task_antrean')->insert(['id' => (string) Str::uuid7(), 'kejadian' => 'asesmen_perawat_selesai', 'task_no' => 2]);
        event(new PemeriksaanDimulai($antrean->kunjungan_id, $olehId, now()->toIso8601String()));
        event(new AsesmenPerawatSelesai($antrean->kunjungan_id, $olehId, now()->toIso8601String()));
        $this->assertSame([2 => 'asesmen_perawat_selesai', 3 => 'kunjungan_dibuat', 6 => 'pemeriksaan_dimulai'], $this->task($antrean));
    }

    public function test_kunjungan_non_jkn_tanpa_task(): void
    {
        $antrean = $this->antrean('non_jkn');
        event(new PemeriksaanDimulai($antrean->kunjungan_id, (string) Str::uuid(), now()->toIso8601String()));
        $this->assertSame([], $this->task($antrean));
    }

    public function test_endpoint_daftar_task_dengan_izin(): void
    {
        $antrean = $this->antrean('jkn');
        $r = $this->minta('GET', "/api/v1/kunjungan/{$antrean->kunjungan_id}/task-antrean")->assertOk()
            ->assertJsonPath('data.0.task_no', 3)->assertJsonPath('data.0.titik', 'poli')->assertJsonPath('data.0.status_kirim', 'tertahan');
        $this->assertSame('no-store, private', $r->headers->get('Cache-Control'));
        $this->assertSesuaiKontrak($r, 'GET', '/kunjungan/{id}/task-antrean');

        $this->minta('GET', '/api/v1/kunjungan/'.Str::uuid().'/task-antrean')->assertNotFound();

        $tanpa = $this->buatPengguna();
        $this->tugaskan($tanpa, $this->buatPeran(['pendaftaran.antrean']));
        $this->masukSebagai($tanpa);
        $this->minta('GET', "/api/v1/kunjungan/{$antrean->kunjungan_id}/task-antrean")->assertForbidden();
    }
}
