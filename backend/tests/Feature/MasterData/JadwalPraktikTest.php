<?php

namespace Tests\Feature\MasterData;

use App\Modules\Akses\Domain\Cakupan;
use App\Modules\MasterData\Models\JadwalPraktik;
use App\Modules\MasterData\Models\Lokasi;
use App\Modules\MasterData\Models\Poliklinik;
use App\Modules\MasterData\Models\UnitOrganisasi;
use App\Modules\Sdm\Models\IzinPraktik;
use App\Modules\Sdm\Models\Pegawai;
use Tests\Support\AksesUji;
use Tests\Support\KlienSpa;
use Tests\Support\KontrakApi;
use Tests\TestCase;

/** Tiket 2.4-be: /jadwal-praktik dan /jadwal-pengecualian (kontrak v0.17.0). Izin master_data.jadwal_praktik.* cakupan `rs`. */
class JadwalPraktikTest extends TestCase
{
    use AksesUji, KlienSpa, KontrakApi;

    private const DASAR = 'SK Direktur 12/2026';

    // 2030-01-07 = Senin (hari ISO 1).
    private const SENIN = '2030-01-07';

    private Poliklinik $poli;

    protected function setUp(): void
    {
        parent::setUp();
        $unit = UnitOrganisasi::query()->create(['kode' => 'POLI', 'nama' => 'Poli', 'jenis' => 'poli', 'berlaku_dari' => '2026-01-01']);
        $this->poli = Poliklinik::query()->create(['kode' => 'ANAK', 'nama' => 'Poli Anak', 'unit_organisasi_id' => $unit->id]);
    }

    private function admin(): void
    {
        $pengguna = $this->buatPengguna();
        $this->tugaskan($pengguna, $this->buatPeran(['master_data.jadwal_praktik']));
        $this->masukSebagai($pengguna);
    }

    private function dokter(bool $denganSip = true, string $profesi = 'dokter'): Pegawai
    {
        /** @var Pegawai $pegawai */
        $pegawai = Pegawai::factory()->create(['profesi' => $profesi]);
        if ($denganSip) {
            IzinPraktik::query()->create(['pegawai_id' => $pegawai->id, 'jenis' => 'sip', 'nomor' => 'SIP/UJI/'.random_int(1, 9999), 'terbit' => '2020-01-01', 'berlaku_sampai' => '2040-01-01']);
        }

        return $pegawai;
    }

    /** @return array<string, mixed> */
    private function isi(Pegawai $dokter, array $ganti = []): array
    {
        return [
            'pegawai_id' => $dokter->id, 'poli_id' => $this->poli->id, 'hari' => 1, 'jam_mulai' => '08:00', 'jam_selesai' => '12:00',
            'kuota_jkn' => 20, 'kuota_non_jkn' => 10, 'berlaku_dari' => '2026-11-01', 'dasar_perubahan' => self::DASAR, ...$ganti,
        ];
    }

    public function test_tambah_ubah_dan_daftar_dengan_keterisian(): void
    {
        $this->admin();
        $dokter = $this->dokter();
        $ruang = Lokasi::query()->create(['jenis' => 'ruang', 'fungsi' => 'rawat_jalan', 'kode' => 'R1', 'kode_lengkap' => 'R1', 'nama' => 'Ruang Anak 1', 'status' => 'aktif', 'status_berlaku_dari' => '2026-01-01']);

        $buat = $this->minta('POST', '/api/v1/jadwal-praktik', $this->isi($dokter, ['ruang_id' => $ruang->id]))->assertCreated();
        $this->assertSesuaiKontrak($buat, 'POST', '/jadwal-praktik');
        $buat->assertJsonPath('data.jam_mulai', '08:00')->assertJsonPath('data.ruang.nama', 'Ruang Anak 1')->assertJsonPath('data.pada_tanggal', null)->assertJsonPath('data.versi', '0');
        $id = $buat->json('data.id');
        $this->assertAuditTerakhir('master_data.jadwal_praktik_dibuat', $id);

        $daftar = $this->minta('GET', '/api/v1/jadwal-praktik?tanggal='.self::SENIN.'&poli_id='.$this->poli->id)->assertOk();
        $this->assertSesuaiKontrak($daftar, 'GET', '/jadwal-praktik');
        $daftar->assertJsonPath('data.0.pada_tanggal.sisa_jkn', 20)->assertJsonPath('data.0.pada_tanggal.tersedia', true);
        // Selasa: tidak ada jadwal.
        $this->assertSame([], $this->minta('GET', '/api/v1/jadwal-praktik?tanggal=2030-01-08')->json('data'));
        $this->minta('GET', '/api/v1/jadwal-praktik?tanggal=2020-01-01')->assertStatus(422);

        $ubah = $this->mintaDenganVersi('PATCH', "/api/v1/jadwal-praktik/$id", '0', ['kuota_jkn' => 5, 'dasar_perubahan' => self::DASAR])->assertOk();
        $this->assertSesuaiKontrak($ubah, 'PATCH', '/jadwal-praktik/{id}');
        $ubah->assertJsonPath('data.kuota_jkn', 5)->assertJsonPath('data.versi', '1');
        $this->mintaDenganVersi('PATCH', "/api/v1/jadwal-praktik/$id", '0', ['kuota_jkn' => 6, 'dasar_perubahan' => self::DASAR])->assertStatus(412);
        $this->minta('PATCH', "/api/v1/jadwal-praktik/$id", ['kuota_jkn' => 6, 'dasar_perubahan' => self::DASAR])->assertStatus(428);

        $this->mintaDenganVersi('PATCH', "/api/v1/jadwal-praktik/$id", '1', ['status' => 'nonaktif', 'dasar_perubahan' => self::DASAR])->assertOk();
        $this->assertSame([], $this->minta('GET', '/api/v1/jadwal-praktik')->json('data'));
        $this->assertCount(1, $this->minta('GET', '/api/v1/jadwal-praktik?status=nonaktif&tanggal='.self::SENIN)->assertJsonPath('data.0.pada_tanggal.alasan_tidak_tersedia', 'nonaktif')->json('data'));
    }

    public function test_dokter_tanpa_sip_aktif_dan_bukan_dokter_ditolak(): void
    {
        $this->admin();
        $tanpaSip = $this->minta('POST', '/api/v1/jadwal-praktik', $this->isi($this->dokter(false)))->assertStatus(422)->assertJsonPath('error.kode', 'sip_tidak_aktif');
        $this->assertSesuaiKontrak($tanpaSip, 'POST', '/jadwal-praktik');

        // SIP dibatalkan, atau belum terbit pada tanggal mulai.
        $batal = $this->dokter();
        IzinPraktik::query()->where('pegawai_id', $batal->id)->update(['status' => 'batal', 'alasan_batal' => 'uji']);
        $this->minta('POST', '/api/v1/jadwal-praktik', $this->isi($batal))->assertStatus(422)->assertJsonPath('error.kode', 'sip_tidak_aktif');
        $belum = $this->dokter();
        IzinPraktik::query()->where('pegawai_id', $belum->id)->update(['terbit' => '2027-01-01']);
        $this->minta('POST', '/api/v1/jadwal-praktik', $this->isi($belum))->assertStatus(422)->assertJsonPath('error.kode', 'sip_tidak_aktif');

        $this->minta('POST', '/api/v1/jadwal-praktik', $this->isi($this->dokter(true, 'perawat')))->assertStatus(422)->assertJsonPath('error.kode', 'validasi_gagal');
        $this->minta('POST', '/api/v1/jadwal-praktik', $this->isi($this->dokter(), ['jam_selesai' => '07:00']))->assertStatus(422)->assertJsonPath('error.kode', 'validasi_gagal');
        $this->assertSame(0, JadwalPraktik::query()->count());
    }

    public function test_jadwal_tumpang_tindih_untuk_dokter_yang_sama_ditolak(): void
    {
        $this->admin();
        $dokter = $this->dokter();
        $pertama = $this->minta('POST', '/api/v1/jadwal-praktik', $this->isi($dokter))->assertCreated()->json('data.id');

        $tumpang = $this->minta('POST', '/api/v1/jadwal-praktik', $this->isi($dokter, ['jam_mulai' => '11:00', 'jam_selesai' => '14:00']))
            ->assertStatus(422)->assertJsonPath('error.kode', 'jadwal_bertumpuk')->assertJsonPath('error.rincian.0.jadwal_id', $pertama);
        $this->assertSesuaiKontrak($tumpang, 'POST', '/jadwal-praktik');

        // Berimpit di batas jam, hari lain, dan dokter lain boleh.
        $sambung = $this->minta('POST', '/api/v1/jadwal-praktik', $this->isi($dokter, ['jam_mulai' => '12:00', 'jam_selesai' => '14:00']))->assertCreated()->json('data');
        $this->minta('POST', '/api/v1/jadwal-praktik', $this->isi($dokter, ['hari' => 2]))->assertCreated();
        $this->minta('POST', '/api/v1/jadwal-praktik', $this->isi($this->dokter()))->assertCreated();

        // PATCH yang membuat jam bertumpuk ditolak; pindah ke hari lain boleh.
        $this->mintaDenganVersi('PATCH', "/api/v1/jadwal-praktik/{$sambung['id']}", '0', ['jam_mulai' => '11:00', 'dasar_perubahan' => self::DASAR])
            ->assertStatus(422)->assertJsonPath('error.kode', 'jadwal_bertumpuk');
        $this->mintaDenganVersi('PATCH', "/api/v1/jadwal-praktik/{$sambung['id']}", '0', ['jam_mulai' => '11:00', 'hari' => 3, 'dasar_perubahan' => self::DASAR])->assertOk();
        $this->minta('POST', '/api/v1/jadwal-praktik', $this->isi($dokter, ['berlaku_dari' => '2026-01-01', 'berlaku_sampai' => '2026-10-31']))->assertCreated();
    }

    public function test_cuti_dan_libur_menutup_slot_dan_pembatalan_membukanya(): void
    {
        $this->admin();
        $dokter = $this->dokter();
        $lain = $this->dokter();
        $this->minta('POST', '/api/v1/jadwal-praktik', $this->isi($dokter))->assertCreated();
        $this->minta('POST', '/api/v1/jadwal-praktik', $this->isi($lain))->assertCreated();
        $url = '/api/v1/jadwal-praktik?tanggal='.self::SENIN;

        $cuti = $this->minta('POST', '/api/v1/jadwal-pengecualian', ['jenis' => 'cuti', 'pegawai_id' => $dokter->id, 'dari_tanggal' => self::SENIN, 'sampai_tanggal' => '2030-01-09', 'alasan' => 'Cuti tahunan'])->assertCreated();
        $this->assertSesuaiKontrak($cuti, 'POST', '/jadwal-pengecualian');
        $this->assertAuditTerakhir('master_data.jadwal_pengecualian_dicatat', $cuti->json('data.id'));

        $daftar = $this->assertSesuaiKontrak($this->minta('GET', $url)->assertOk(), 'GET', '/jadwal-praktik');
        $status = collect($daftar->json('data'))->mapWithKeys(fn ($j) => [$j['pegawai']['id'] => $j['pada_tanggal']]);
        $this->assertFalse($status[$dokter->id]['tersedia']);
        $this->assertSame('cuti', $status[$dokter->id]['alasan_tidak_tersedia']);
        $this->assertTrue($status[$lain->id]['tersedia']);
        // Tanggal setelah cuti tersedia lagi.
        $this->assertTrue($this->minta('GET', '/api/v1/jadwal-praktik?tanggal=2030-01-14&pegawai_id='.$dokter->id)->json('data.0.pada_tanggal.tersedia'));

        $libur = $this->minta('POST', '/api/v1/jadwal-pengecualian', ['jenis' => 'libur', 'dari_tanggal' => self::SENIN, 'sampai_tanggal' => self::SENIN, 'alasan' => 'Libur nasional'])->assertCreated();
        $this->assertSame([false, false], collect($this->minta('GET', $url)->json('data'))->pluck('pada_tanggal.tersedia')->all());

        $this->mintaDenganVersi('PATCH', '/api/v1/jadwal-pengecualian/'.$libur->json('data.id'), '0', ['status' => 'batal', 'alasan_batal' => 'Salah catat'])
            ->assertOk()->assertJsonPath('data.status', 'batal');
        $this->assertSame([false, true], collect($this->minta('GET', $url)->json('data'))->sortBy('pegawai.id')->pluck('pada_tanggal.tersedia')->sortKeys()->values()->map(fn ($b) => $b)->sort()->values()->all());
        $this->mintaDenganVersi('PATCH', '/api/v1/jadwal-pengecualian/'.$libur->json('data.id'), '1', ['alasan' => 'Ubah lagi'])->assertStatus(422);

        $ubah = $this->mintaDenganVersi('PATCH', '/api/v1/jadwal-pengecualian/'.$cuti->json('data.id'), '0', ['sampai_tanggal' => self::SENIN])->assertOk();
        $this->assertSesuaiKontrak($ubah, 'PATCH', '/jadwal-pengecualian/{id}');
        $ubah->assertJsonPath('data.sampai_tanggal', self::SENIN);
    }

    public function test_validasi_pengecualian(): void
    {
        $this->admin();
        $dokter = $this->dokter();
        $dasar = ['dari_tanggal' => self::SENIN, 'sampai_tanggal' => self::SENIN, 'alasan' => 'Alasan uji'];
        $this->minta('POST', '/api/v1/jadwal-pengecualian', ['jenis' => 'cuti', ...$dasar])->assertStatus(422);
        $this->minta('POST', '/api/v1/jadwal-pengecualian', ['jenis' => 'libur', 'pegawai_id' => $dokter->id, ...$dasar])->assertStatus(422);
        $this->minta('POST', '/api/v1/jadwal-pengecualian', ['jenis' => 'libur', ...$dasar, 'sampai_tanggal' => '2030-01-01'])->assertStatus(422);
        $lampau = $this->minta('POST', '/api/v1/jadwal-pengecualian', ['jenis' => 'libur', 'dari_tanggal' => '2020-01-01', 'sampai_tanggal' => '2020-01-02', 'alasan' => 'Lampau'])->assertCreated();
        $this->mintaDenganVersi('PATCH', '/api/v1/jadwal-pengecualian/'.$lampau->json('data.id'), '0', ['alasan' => 'Ubah'])->assertStatus(422);
    }

    public function test_tanpa_izin_dan_cakupan_bukan_rs_ditolak(): void
    {
        $dokter = $this->dokter();
        $pembaca = $this->buatPengguna();
        $this->tugaskan($pembaca, $this->buatPeran([]));
        $this->masukSebagai($pembaca);
        $this->minta('GET', '/api/v1/jadwal-praktik')->assertForbidden();

        $unit = UnitOrganisasi::query()->create(['kode' => 'IRNA', 'nama' => 'IRNA', 'jenis' => 'instalasi', 'berlaku_dari' => '2026-01-01']);
        $terbatas = $this->buatPengguna();
        $this->tugaskan($terbatas, $this->buatPeran(['master_data.jadwal_praktik']), cakupan: new Cakupan('unit', $unit->id));
        $this->masukSebagai($terbatas);
        $this->minta('GET', '/api/v1/jadwal-praktik')->assertForbidden();
        $this->minta('POST', '/api/v1/jadwal-praktik', $this->isi($dokter))->assertForbidden();
        $this->minta('POST', '/api/v1/jadwal-pengecualian', ['jenis' => 'libur', 'dari_tanggal' => self::SENIN, 'sampai_tanggal' => self::SENIN, 'alasan' => 'Uji'])->assertForbidden();
    }
}
