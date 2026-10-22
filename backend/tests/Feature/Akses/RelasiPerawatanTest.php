<?php

namespace Tests\Feature\Akses;

use App\Modules\Akses\Application\AccessEvaluator;
use App\Modules\Akses\Application\PemeriksaLabel;
use App\Modules\Akses\Models\LabelSensitivitas;
use App\Modules\Akses\Models\Pengguna;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\AksesUji;
use Tests\Support\KlienSpa;
use Tests\Support\KunjunganUji;
use Tests\TestCase;

/**
 * Lapis 4 dengan relasi perawatan tercatat (tiket 2.6; PRD 9.2.2): data berlabel terbuka hanya bagi akun berizin label
 * yang pegawainya dokter jadwal kunjungan pasien itu pada hari kunjungannya. Termasuk uji IDOR: mengganti id pasien,
 * memakai akun dokter lain, atau menyelipkan pegawai_id di isian tidak membuka data.
 */
class RelasiPerawatanTest extends TestCase
{
    use AksesUji, KlienSpa, KunjunganUji;

    private const ISI = ['catatan' => 'isi berlabel'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanKunjungan();
    }

    public function test_dokter_jadwal_membuka_data_berlabel_dokter_lain_tetap_terbatas(): void
    {
        $dokterA = $this->dokter();
        $dokterB = $this->dokter();
        $jadwalA = $this->jadwal($dokterA);
        $this->jadwal($dokterB, ['jam_mulai' => '13:00', 'jam_selesai' => '16:00']);
        [$akunA, $akunB] = $this->berizinLabel([$this->akunDokter($dokterA), $this->akunDokter($dokterB)], 'psikiatri');
        $akunATanpaLabel = $this->akunDokter($dokterA);
        $pasien = $this->pasien();
        $pasienLain = $this->pasien();

        $petugas = $this->petugasPendaftaran();
        $this->masukSebagai($petugas);
        // IDOR isian: pegawai_id yang diselipkan klien diabaikan; dokter selalu dari jadwal.
        $kunjungan = $this->daftarKunjungan($pasien, $jadwalA, ['pegawai_id' => $dokterB->id])->assertCreated()
            ->assertJsonPath('data.dokter.id', $dokterA->id)->json('data.id');
        $this->daftarKunjungan($pasienLain, $jadwalA, ['tanggal' => '2026-11-09'])->assertCreated();
        $this->assertSame([$dokterA->id], DB::table('akses.relasi_perawatan')->where('pasien_id', $pasien->id)->pluck('pegawai_id')->all());

        $evaluator = app(AccessEvaluator::class);
        $label = app(PemeriksaLabel::class);
        $this->assertTrue($evaluator->bolehLabel($akunA, 'psikiatri', $pasien->id));
        $this->assertSame(self::ISI, $label->bentuk($akunA, 'psikiatri', $pasien->id, fn () => self::ISI));

        // Dokter lain dengan izin label yang sama: Terbatas.
        $this->assertFalse($evaluator->bolehLabel($akunB, 'psikiatri', $pasien->id));
        $this->assertSame(PemeriksaLabel::TERBATAS, $label->bentuk($akunB, 'psikiatri', $pasien->id, fn () => self::ISI));
        // Merawat tanpa izin label, atau label lain yang tidak diberikan: tetap tertutup.
        $this->assertFalse($evaluator->bolehLabel($akunATanpaLabel, 'psikiatri', $pasien->id));
        $this->assertFalse($evaluator->bolehLabel($akunA, 'hiv', $pasien->id));
        // Mengganti id pasien: pasien yang kunjungannya pekan depan, pasien acak, id rusak, atau tanpa pasien.
        $this->assertFalse($evaluator->bolehLabel($akunA, 'psikiatri', $pasienLain->id));
        $this->assertFalse($evaluator->bolehLabel($akunA, 'psikiatri', (string) Str::uuid7()));
        $this->assertFalse($evaluator->bolehLabel($akunA, 'psikiatri', "{$pasien->id}' OR '1'='1"));
        $this->assertFalse($evaluator->bolehLabel($akunA, 'psikiatri', null));

        // Relasi hanya untuk hari kunjungan (zona RS): besok tertutup lagi.
        $this->travelTo(Carbon::parse('2026-11-03 00:00', (string) config('instalasi.zona_waktu')));
        $this->assertFalse($evaluator->bolehLabel($akunA, 'psikiatri', $pasien->id));
        $this->travelTo(Carbon::parse(self::SENIN.' 23:59', (string) config('instalasi.zona_waktu')));
        $this->assertTrue($evaluator->bolehLabel($akunA, 'psikiatri', $pasien->id));

        // Kunjungan dibatalkan: relasi berakhir saat itu juga (tidak di-cache).
        $this->travelTo(Carbon::parse(self::SENIN.' 09:00', (string) config('instalasi.zona_waktu')));
        $this->masukSebagai($petugas);
        $this->minta('POST', "/api/v1/kunjungan/$kunjungan/batal", ['alasan_batal' => 'Pasien pulang sebelum dipanggil'])->assertOk();
        $this->assertFalse($evaluator->bolehLabel($akunA, 'psikiatri', $pasien->id));
    }

    public function test_akun_dokter_tidak_bisa_memakai_endpoint_kunjungan_tanpa_izin_pendaftaran(): void
    {
        $dokter = $this->dokter();
        $jadwal = $this->jadwal($dokter);
        $this->masukSebagai($this->petugasPendaftaran());
        $id = $this->daftarKunjungan($this->pasien(), $jadwal)->assertCreated()->json('data.id');

        // Merawat pasien tidak memberi izin menu/aksi (lapis 1–3 tetap berlaku).
        $this->masukSebagai($this->akunDokter($dokter));
        $this->minta('GET', '/api/v1/kunjungan?tanggal='.self::SENIN.'&poli_id='.$this->poli->id)->assertForbidden();
        $this->minta('POST', "/api/v1/kunjungan/$id/batal", ['alasan_batal' => 'Batal oleh dokter'])->assertForbidden();
        $this->daftarKunjungan($this->pasien(), $jadwal)->assertForbidden();
    }

    /**
     * Izin label lewat alur sebenarnya: diajukan pengelola akses, disetujui pejabat PDP (tiket 1.5).
     *
     * @param  list<Pengguna>  $akun
     * @return list<Pengguna>
     */
    private function berizinLabel(array $akun, string $label): array
    {
        $pdp = $this->buatPeran(['beranda']);
        LabelSensitivitas::query()->where('kode', $label)->update(['peran_penyetuju_id' => $pdp->id]);
        $pejabat = $this->buatPengguna();
        $this->tugaskan($pejabat, $pdp);
        $pengelola = $this->pengelola();
        foreach ($akun as $a) {
            $this->masukSebagai($pengelola);
            $id = $this->minta('POST', "/api/v1/akun/{$a->id}/label", [
                'label_kode' => $label, 'berlaku_sampai' => now()->addDays(30)->toIso8601String(),
                'dasar_pemberian' => 'SK uji', 'alasan' => 'Tugas di poli terkait',
            ])->assertStatus(202)->json('data.id');
            $this->masukSebagai($pejabat);
            $this->minta('POST', "/api/v1/persetujuan-akses/$id/setujui")->assertOk();
        }

        return $akun;
    }
}
