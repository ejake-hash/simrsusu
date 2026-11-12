<?php

namespace Tests\Feature\RawatInap;

use App\Modules\Akses\Application\CacheHakAkses;
use App\Modules\Akses\Domain\Cakupan;
use App\Modules\Akses\Models\Izin;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\Akses\Models\PeranIzin;
use App\Modules\Akses\Models\PeranMenu;
use App\Modules\MasterData\Models\Bangsal;
use App\Modules\MasterData\Models\JadwalPraktik;
use App\Modules\MasterData\Models\Kamar;
use App\Modules\MasterData\Models\KelasRawat;
use App\Modules\MasterData\Models\Lokasi;
use App\Modules\MasterData\Models\TempatTidur;
use App\Modules\MasterData\Models\UnitOrganisasi;
use App\Modules\Notifikasi\Models\Tugas;
use App\Modules\Pendaftaran\Models\Pasien;
use App\Modules\Sdm\Models\Pegawai;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\AksesUji;
use Tests\Support\KlienSpa;
use Tests\Support\KontrakApi;
use Tests\Support\KunjunganUji;
use Tests\TestCase;

/**
 * Tiket 2.40 (kontrak v0.39.0; FR-05-01..05, keputusan pemilik 13 Nov 2026): rencana pulang GET/PUT
 * /kunjungan/{id}/rencana-pulang, pulang POST /kunjungan/{id}/pulang lewat matriks, resume medis GET/POST
 * /kunjungan/{id}/resume-medis dan POST /resume-medis/{id}/sahkan (PIN T1 DPJP), status TT turunan di peta, data induk
 * daftar periksa pulang. Data sintetis; jam dibekukan Senin 2 Nov 2026 07.00 zona RS (KunjunganUji). Bangsal UJI40-BA
 * (unit RA): TT 101-1..101-3; bangsal UJI40-BB (unit RB): TT 201-1, 201-2. DPJP = dokter poli pemberi SPRI.
 */
class PemulanganTest extends TestCase
{
    use AksesUji, KlienSpa, KontrakApi, KunjunganUji;

    private const PIN = '482916';

    /** Butir manual wajib isi awal FR-05-03 (TAGIHAN_JAMINAN bersumber kejadian). */
    private const BUTIR_MANUAL = ['OBAT_EDUKASI', 'SURAT_KONTROL', 'RUJUK_BALIK', 'LEPAS_INFUS_GELANG'];

    private Pengguna $dpjp;

    private Pegawai $pegawaiDpjp;

    private Pengguna $admisi;

    private Pengguna $perawat;

    /** @var array<string, string> kode => id */
    private array $id = [];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->siapkanKunjungan();
        $this->siapkanTempatTidur();

        $this->pegawaiDpjp = $this->dokter();
        $this->id['jadwal'] = $this->jadwal($this->pegawaiDpjp)->id;
        $this->dpjp = $this->akunDokter($this->pegawaiDpjp);
        $this->tugaskan($this->dpjp, $this->buatPeran(['rawat_jalan.pemeriksaan', 'rawat_inap.pemulangan', 'rawat_inap.peta_tempat_tidur']));
        $this->admisi = $this->buatPengguna();
        $this->tugaskan($this->admisi, $this->buatPeran(['pendaftaran.admisi', 'rawat_inap.peta_tempat_tidur', 'rawat_inap.penempatan']));
        /** @var Pegawai $pegawaiPerawat */
        $pegawaiPerawat = Pegawai::factory()->create(['profesi' => 'perawat']);
        $this->perawat = $this->buatPengguna(['pegawai_id' => $pegawaiPerawat->id]);
        $this->tugaskan($this->perawat, $this->buatPeran(['rawat_inap.pemulangan', 'rawat_inap.peta_tempat_tidur']));
    }

    public function test_pulang_menutup_penempatan_tt_dibersihkan_episode_selesai_relasi_diakhiri_dan_tugas_resume(): void
    {
        $pasien = $this->pasien();
        [$ri, $tt] = $this->rawatInap($pasien, 'UJI40-101-1');
        $penempatan = (string) DB::table('kunjungan.penempatan_tempat_tidur')->where('kunjungan_id', $ri)->value('id');
        $episode = (string) DB::table('kunjungan.kunjungan')->where('id', $ri)->value('episode_id');

        $this->travel(1)->days();
        $this->masukSebagai($this->dpjp);
        $respons = $this->pulang($ri)->assertOk()->assertJsonPath('data.kunjungan.status', 'selesai')->assertJsonPath('data.penempatan', null);
        $this->assertSesuaiKontrak($respons, 'POST', '/kunjungan/{id}/pulang');
        $this->assertStringContainsString('no-store', (string) $respons->headers->get('Cache-Control'));

        // Penempatan selesai (pulang), TT dibersihkan (FR-05-05) dengan riwayat sumber pulang, episode selesai.
        $p = DB::table('kunjungan.penempatan_tempat_tidur')->where('id', $penempatan)->first();
        $this->assertSame(['pulang', $this->dpjp->id], [$p->alasan_selesai, $p->diakhiri_oleh]);
        $this->assertTrue(Carbon::parse((string) $p->selesai_pada)->equalTo(now()));
        $this->assertSame('dibersihkan', DB::table('inti.tempat_tidur')->where('id', $tt)->value('status_saat_ini'));
        $riwayat = DB::table('kunjungan.riwayat_status_tempat_tidur')->where('tempat_tidur_id', $tt)->where('sumber_kejadian', 'pulang')->first();
        $this->assertNotNull($riwayat);
        $this->assertSame(['terisi', 'dibersihkan', $this->dpjp->id], [$riwayat->status_sebelum, $riwayat->status, $riwayat->oleh]);
        $e = DB::table('kunjungan.episode')->where('id', $episode)->first();
        $this->assertSame('selesai', $e->status);
        $this->assertNotNull($e->selesai_pada);

        // Perintah matriks rawat_inap → pulang tercatat selesai; relasi DPJP rawat inap diakhiri (catatan wajib 2.29).
        $perintah = DB::table('kunjungan.perpindahan_pos')->where('dari_kunjungan_id', $ri)->first();
        $this->assertSame(['rawat_inap', 'pulang', 'selesai', 'rawat_inap.pemulangan.input', 'selesai'],
            [$perintah->pos_asal, $perintah->pos_tujuan, $perintah->status, $perintah->izin_perintah, $perintah->episode_status]);
        $this->assertSame('batal', DB::table('akses.relasi_perawatan')->where('sumber_id', $ri)->value('status'));

        // Tanpa rencana: rencana dibuat otomatis berstatus pulang dengan alasan per butir.
        $rencana = DB::table('kunjungan.rencana_pulang')->where('kunjungan_id', $ri)->first();
        $this->assertSame(['pulang', '2026-11-03'], [$rencana->status, substr((string) $rencana->rencana_pulang_pada, 0, 10)]);
        $butir = collect(json_decode((string) $rencana->daftar_periksa, true))->keyBy('kode');
        $this->assertSame('Belum sempat (uji sintetis)', $butir['RUJUK_BALIK']['alasan_tidak_selesai']);
        $this->assertNull($butir['TAGIHAN_JAMINAN']['alasan_tidak_selesai']);

        // Tugas resume untuk akun DPJP, tenggat 2 × 24 jam sejak pulang, judul tanpa data pasien.
        $tugas = Tugas::query()->where('objek_id', $ri)->firstOrFail();
        $this->assertSame(['pengguna', $this->dpjp->id, 'kunjungan', 'terbuka'], [$tugas->pelaksana_jenis, $tugas->pelaksana_id, $tugas->objek_jenis, $tugas->status]);
        $this->assertTrue($tugas->tenggat_pada->equalTo(now()->addMinutes(2880)));
        $this->assertStringNotContainsString($pasien->nama, $tugas->judul);
        $this->assertStringContainsString("pemulangan?kunjungan=$ri", (string) $tugas->tautan);

        // Audit tanpa alasan maupun data pasien.
        $audit = (string) $this->assertAuditTerakhir('rawat_inap.pasien_pulang', (string) $perintah->id)->sesudah;
        $this->assertStringNotContainsString('uji sintetis', $audit);
        $this->assertStringNotContainsString($pasien->nama, $audit);

        // Pulang kedua → 409; GET rencana sesudah pulang: status pulang, alasan butir terbaca.
        $dua = $this->pulang($ri)->assertStatus(409)->assertJsonPath('error.kode', 'status_tidak_sesuai')->assertJsonPath('error.rincian.0.status', 'selesai');
        $this->assertSesuaiKontrak($dua, 'POST', '/kunjungan/{id}/pulang');
        $baca = $this->minta('GET', "/api/v1/kunjungan/$ri/rencana-pulang")->assertOk()->assertJsonPath('data.status', 'pulang')
            ->assertJsonPath('data.hari_rawat', null)->assertJsonPath('data.kunjungan_status', 'selesai');
        $this->assertSesuaiKontrak($baca, 'GET', '/kunjungan/{id}/rencana-pulang');
    }

    public function test_tiap_tujuan_matriks_dan_tujuan_di_luar_matriks(): void
    {
        foreach (['aps' => 'UJI40-101-1', 'rujuk_keluar' => 'UJI40-101-2', 'meninggal' => 'UJI40-101-3'] as $tujuan => $kodeTt) {
            [$ri, $tt] = $this->rawatInap($this->pasien(), $kodeTt);
            $this->masukSebagai($this->dpjp);
            $this->assertSesuaiKontrak($this->pulang($ri, ['tujuan' => $tujuan])->assertOk(), 'POST', '/kunjungan/{id}/pulang');
            $this->assertSame($tujuan, DB::table('kunjungan.perpindahan_pos')->where('dari_kunjungan_id', $ri)->value('pos_tujuan'));
            $this->assertSame('dibersihkan', DB::table('inti.tempat_tidur')->where('id', $tt)->value('status_saat_ini'));
        }

        // Tujuan bukan pemulangan → 422 validasi_gagal; sel matriks nonaktif → 422 perpindahan_di_luar_matriks.
        [$ri] = $this->rawatInap($this->pasien(), 'UJI40-201-1');
        $this->masukSebagai($this->dpjp);
        $this->assertSesuaiKontrak($this->pulang($ri, ['tujuan' => 'doa'])->assertStatus(422)->assertJsonPath('error.kode', 'validasi_gagal'),
            'POST', '/kunjungan/{id}/pulang');
        DB::table('kunjungan.aturan_perpindahan')->where('pos_asal', 'rawat_inap')->where('pos_tujuan', 'pulang')->update(['status' => 'nonaktif']);
        $luar = $this->pulang($ri)->assertStatus(422)->assertJsonPath('error.kode', 'perpindahan_di_luar_matriks')
            ->assertJsonPath('error.rincian.0.pos_tujuan', 'pulang');
        $this->assertSesuaiKontrak($luar, 'POST', '/kunjungan/{id}/pulang');
        $this->assertNull(DB::table('kunjungan.penempatan_tempat_tidur')->where('kunjungan_id', $ri)->value('selesai_pada'));
        // Isi awal matriks: empat sel rawat_inap → tujuan pemulangan dengan izin klinis yang sama.
        $this->assertSame(['aps', 'meninggal', 'pulang', 'rujuk_keluar'], DB::table('kunjungan.aturan_perpindahan')->where('pos_asal', 'rawat_inap')
            ->where('izin_perintah', 'rawat_inap.pemulangan.input')->orderBy('pos_tujuan')->pluck('pos_tujuan')->all());
    }

    public function test_izin_cakupan_dan_status_kunjungan_pulang(): void
    {
        [$ri, , $poli] = $this->rawatInap($this->pasien(), 'UJI40-101-1');
        $this->assertTrue((bool) DB::table('akses.izin')->where('kode', 'rawat_inap.pemulangan.input')->value('butuh_kewenangan_klinis'));
        $this->assertTrue((bool) DB::table('akses.izin')->where('kode', 'rawat_inap.pemulangan.sahkan')->value('butuh_kewenangan_klinis'));
        $this->assertFalse((bool) DB::table('akses.izin')->where('kode', 'rawat_inap.pemulangan.lihat')->value('butuh_kewenangan_klinis'));
        $this->assertSame(0, DB::table('akses.izin')->where('kode', 'like', 'rawat_inap.pemulangan.%')->where('sensitif', true)->count());

        // Tanpa izin sel → 403 sebelum kunjungan dibaca.
        $this->masukSebagai($this->buatPengguna());
        $this->assertSesuaiKontrak($this->pulang($ri)->assertForbidden(), 'POST', '/kunjungan/{id}/pulang');
        $this->pulang((string) Str::uuid7())->assertForbidden();

        // Izin sel klinis: perawat tanpa SIP → 403 izin_praktik_tidak_berlaku.
        $this->masukSebagai($this->perawat);
        $this->pulang($ri)->assertForbidden()->assertJsonPath('error.kode', 'izin_praktik_tidak_berlaku');

        // Cakupan unit bangsal: dokter ruang RB tidak bisa memulangkan pasien bangsal BA; id tidak ada / bukan UUID → 404.
        $ruangB = $this->akunDokter($this->dokter());
        $this->tugaskan($ruangB, $this->buatPeran(['rawat_inap.pemulangan']), cakupan: new Cakupan('unit', $this->id['UJI40-RB']));
        $this->masukSebagai($ruangB);
        $this->pulang($ri)->assertForbidden();
        $this->assertSesuaiKontrak($this->pulang((string) Str::uuid7())->assertNotFound(), 'POST', '/kunjungan/{id}/pulang');
        $this->pulang('bukan-uuid')->assertNotFound();

        // Bukan kunjungan rawat inap → 409; isian kurang → 422; penempatan tetap aktif.
        $this->masukSebagai($this->dpjp);
        $bukan = $this->pulang($poli)->assertStatus(409)->assertJsonPath('error.kode', 'status_tidak_sesuai')->assertJsonPath('error.rincian.0.jenis_kunjungan', 'poliklinik');
        $this->assertSesuaiKontrak($bukan, 'POST', '/kunjungan/{id}/pulang');
        $this->pulang($ri, ['alasan' => 'abc'])->assertStatus(422);
        $this->assertNull(DB::table('kunjungan.penempatan_tempat_tidur')->where('kunjungan_id', $ri)->value('selesai_pada'));
    }

    public function test_daftar_periksa_wajib_tanpa_alasan_422_dan_butir_kejadian_tidak_menahan(): void
    {
        [$ri] = $this->rawatInap($this->pasien(), 'UJI40-101-1');

        // Perawat tanpa SIP membuat rencana (non-klinis); butir disalin dari data induk, tagihan/jaminan menunggu kasir.
        $this->masukSebagai($this->perawat);
        $buat = $this->rencana($ri, ['rencana_pulang_pada' => '2026-11-04'])->assertCreated()->assertJsonPath('data.status', 'direncanakan')
            ->assertJsonPath('data.versi', '0');
        $this->assertSesuaiKontrak($buat, 'PUT', '/kunjungan/{id}/rencana-pulang');
        $status = collect($buat->json('data.daftar_periksa'))->pluck('status', 'kode')->all();
        $this->assertSame(['OBAT_EDUKASI' => 'belum', 'SURAT_KONTROL' => 'belum', 'RUJUK_BALIK' => 'belum', 'TAGIHAN_JAMINAN' => 'menunggu_kasir',
            'LEPAS_INFUS_GELANG' => 'belum'], $status);
        $centang = $this->rencanaVersi($ri, '0', ['rencana_pulang_pada' => '2026-11-04',
            'daftar_periksa' => [['kode' => 'OBAT_EDUKASI', 'selesai' => true], ['kode' => 'SURAT_KONTROL', 'selesai' => true]]])->assertOk()
            ->assertJsonPath('data.daftar_periksa.0.selesai_oleh.id', $this->perawat->id);
        $this->assertSesuaiKontrak($centang, 'PUT', '/kunjungan/{id}/rencana-pulang');

        // Pulang tanpa alasan butir wajib yang belum selesai → 422 dengan rincian butir; butir kejadian tidak disebut.
        $this->masukSebagai($this->dpjp);
        $kurang = $this->pulang($ri, ['tanggapan' => []])->assertStatus(422)->assertJsonPath('error.kode', 'daftar_periksa_belum_lengkap');
        $this->assertSesuaiKontrak($kurang, 'POST', '/kunjungan/{id}/pulang');
        $this->assertSame(['RUJUK_BALIK', 'LEPAS_INFUS_GELANG'], array_column($kurang->json('error.rincian'), 'kode'));
        $this->pulang($ri, ['tanggapan' => [['kode' => 'RUJUK_BALIK', 'alasan' => 'Pasien tidak perlu rujuk balik (uji)']]])->assertStatus(422)
            ->assertJsonPath('error.rincian.0.kode', 'LEPAS_INFUS_GELANG');
        $this->assertNull(DB::table('kunjungan.penempatan_tempat_tidur')->where('kunjungan_id', $ri)->value('selesai_pada'));

        $this->pulang($ri, ['tanggapan' => [['kode' => 'RUJUK_BALIK', 'alasan' => 'Pasien tidak perlu rujuk balik (uji)'],
            ['kode' => 'LEPAS_INFUS_GELANG', 'alasan' => 'Dilepas di rumah oleh perawat homecare (uji)']]])->assertOk();
        $baca = $this->minta('GET', "/api/v1/kunjungan/$ri/rencana-pulang")->assertOk()->assertJsonPath('data.status', 'pulang');
        $butir = collect($baca->json('data.daftar_periksa'))->keyBy('kode');
        $this->assertSame('Pasien tidak perlu rujuk balik (uji)', $butir['RUJUK_BALIK']['alasan_tidak_selesai']);
        $this->assertSame('selesai', $butir['OBAT_EDUKASI']['status']);
        $this->assertNull($butir['OBAT_EDUKASI']['alasan_tidak_selesai']);
    }

    public function test_rencana_pulang_buat_ubah_if_match_dan_validasi(): void
    {
        [$ri, , $poli] = $this->rawatInap($this->pasien(), 'UJI40-101-1');
        $url = "/api/v1/kunjungan/$ri/rencana-pulang";

        // Belum ada rencana: id null, pratinjau butir data induk, dua penanda identitas.
        $this->masukSebagai($this->perawat);
        $kosong = $this->minta('GET', $url)->assertOk()->assertJsonPath('data.id', null)->assertJsonPath('data.versi', null)
            ->assertJsonPath('data.hari_rawat', 1)->assertJsonCount(5, 'data.daftar_periksa');
        $this->assertSesuaiKontrak($kosong, 'GET', '/kunjungan/{id}/rencana-pulang');
        $pasien = DB::table('pasien.pasien')->where('id', DB::table('kunjungan.kunjungan')->where('id', $ri)->value('pasien_id'))->first();
        $this->assertSame([(string) $pasien->nama, (string) $pasien->no_rm, '1990-01-01'],
            [$kosong->json('data.pasien.nama'), $kosong->json('data.pasien.no_rm'), $kosong->json('data.pasien.tanggal_lahir')]);
        $this->assertStringContainsString('no-store', (string) $kosong->headers->get('Cache-Control'));

        // Tanggal sebelum penempatan pertama → 422; butir kejadian/tak dikenal → 422.
        $this->assertSesuaiKontrak($this->rencana($ri, ['rencana_pulang_pada' => '2026-11-01'])->assertStatus(422)
            ->assertJsonPath('error.rincian.0.kolom', 'rencana_pulang_pada'), 'PUT', '/kunjungan/{id}/rencana-pulang');
        $this->rencana($ri, ['rencana_pulang_pada' => '2026-11-05', 'daftar_periksa' => [['kode' => 'TAGIHAN_JAMINAN', 'selesai' => true]]])->assertStatus(422);
        $this->rencana($ri, ['rencana_pulang_pada' => '2026-11-05', 'daftar_periksa' => [['kode' => 'NGAWUR', 'selesai' => true]]])->assertStatus(422);
        $this->rencana($ri, ['rencana_pulang_pada' => '2026-11-05', 'rencana_lama_rawat' => 0])->assertStatus(422);
        $this->assertFalse(DB::table('kunjungan.rencana_pulang')->where('kunjungan_id', $ri)->exists());

        // Buat (201, ETag) lalu ubah: tanpa If-Match 428, basi 412, benar 200; lama rawat tidak dikirim = tetap.
        $buat = $this->rencana($ri, ['rencana_pulang_pada' => '2026-11-05', 'rencana_lama_rawat' => 4])->assertCreated()
            ->assertJsonPath('data.rencana_lama_rawat', 4)->assertJsonPath('data.rencana_pulang_pada', '2026-11-05');
        $this->assertSame('"0"', $buat->headers->get('ETag'));
        $this->assertAuditTerakhir('rawat_inap.rencana_pulang_dibuat', (string) $buat->json('data.id'));
        $this->assertSesuaiKontrak($this->rencana($ri, ['rencana_pulang_pada' => '2026-11-06'])->assertStatus(428), 'PUT', '/kunjungan/{id}/rencana-pulang');
        $this->assertSesuaiKontrak($this->rencanaVersi($ri, '7', ['rencana_pulang_pada' => '2026-11-06'])->assertStatus(412), 'PUT', '/kunjungan/{id}/rencana-pulang');
        $this->rencanaVersi($ri, '0', ['rencana_pulang_pada' => '2026-11-06'])->assertOk()->assertJsonPath('data.rencana_lama_rawat', 4)
            ->assertJsonPath('data.versi', '1');
        $this->assertAuditTerakhir('rawat_inap.rencana_pulang_diubah', (string) $buat->json('data.id'));

        // Izin: hanya lihat → PUT 403, GET boleh; cakupan unit lain → 403 (GET dan PUT); id tidak ada → 404.
        $lihat = $this->akunIzin(['rawat_inap.pemulangan.lihat']);
        $this->masukSebagai($lihat);
        $this->minta('GET', $url)->assertOk();
        $this->assertSesuaiKontrak($this->rencanaVersi($ri, '1', ['rencana_pulang_pada' => '2026-11-06'])->assertForbidden(), 'PUT', '/kunjungan/{id}/rencana-pulang');
        $ruangB = $this->buatPengguna();
        $this->tugaskan($ruangB, $this->buatPeran(['rawat_inap.pemulangan']), cakupan: new Cakupan('unit', $this->id['UJI40-RB']));
        $this->masukSebagai($ruangB);
        $this->assertSesuaiKontrak($this->minta('GET', $url)->assertForbidden(), 'GET', '/kunjungan/{id}/rencana-pulang');
        $this->rencanaVersi($ri, '1', ['rencana_pulang_pada' => '2026-11-06'])->assertForbidden();
        $this->assertSesuaiKontrak($this->minta('GET', '/api/v1/kunjungan/'.Str::uuid7().'/rencana-pulang')->assertNotFound(), 'GET', '/kunjungan/{id}/rencana-pulang');
        $this->rencana((string) Str::uuid7(), ['rencana_pulang_pada' => '2026-11-06'])->assertNotFound();
        $this->masukSebagai($this->buatPengguna());
        $this->minta('GET', $url)->assertForbidden();
        $this->rencana($ri, ['rencana_pulang_pada' => '2026-11-06'])->assertForbidden();

        // Bukan kunjungan rawat inap → 409.
        $this->masukSebagai($this->perawat);
        $this->assertSesuaiKontrak($this->rencana($poli, ['rencana_pulang_pada' => '2026-11-06'])->assertStatus(409)
            ->assertJsonPath('error.kode', 'status_tidak_sesuai'), 'PUT', '/kunjungan/{id}/rencana-pulang');
    }

    public function test_status_tt_turunan_dihitung_saat_peta_dibaca(): void
    {
        [$ri1, $tt1] = $this->rawatInap($this->pasien(), 'UJI40-101-1');
        [$ri2] = $this->rawatInap($this->pasien(), 'UJI40-101-2');
        $url = '/api/v1/peta-tempat-tidur?bangsal_id='.$this->id['UJI40-BA'];

        // Senin: TT1 rencana pulang hari ini; TT2 rencana besok, lama rawat 1 hari (hari rawat 1 → belum lewat).
        $this->masukSebagai($this->perawat);
        $this->rencana($ri1, ['rencana_pulang_pada' => self::SENIN])->assertCreated();
        $this->rencana($ri2, ['rencana_pulang_pada' => '2026-11-03', 'rencana_lama_rawat' => 1])->assertCreated();
        $senin = $this->minta('GET', $url)->assertOk()->assertJsonPath('data.0.status', 'rencana_pulang')->assertJsonPath('data.1.status', 'terisi');
        $this->assertSesuaiKontrak($senin, 'GET', '/peta-tempat-tidur');
        $etag = (string) $senin->headers->get('ETag');
        $this->assertFalse((bool) $senin->json('data.0.dapat_dipilih'));

        // Selasa: TT1 rencana kemarin → terisi; TT2 rencana hari ini diutamakan walau hari rawat 2 > 1.
        $this->travelTo(Carbon::parse('2026-11-03 07:00', (string) config('instalasi.zona_waktu')));
        $this->masukSebagai($this->perawat);
        $this->headerTambahan = ['If-None-Match' => $etag];
        try {
            $selasa = $this->minta('GET', $url)->assertOk();
        } finally {
            $this->headerTambahan = [];
        }
        $selasa->assertJsonPath('data.0.status', 'terisi')->assertJsonPath('data.1.status', 'rencana_pulang')->assertJsonPath('data.1.hari_rawat', 2);

        // Rabu: TT2 rencana sudah lewat, hari rawat 3 > 1 → melewati rencana lama rawat.
        $this->travelTo(Carbon::parse('2026-11-04 07:00', (string) config('instalasi.zona_waktu')));
        $this->masukSebagai($this->perawat);
        $rabu = $this->minta('GET', $url)->assertOk()->assertJsonPath('data.1.status', 'melewati_rencana_lama_rawat');
        $this->assertSesuaiKontrak($rabu, 'GET', '/peta-tempat-tidur');

        // Tidak disimpan: status tersimpan tetap terisi, riwayat tanpa status turunan.
        $this->assertSame('terisi', DB::table('inti.tempat_tidur')->where('id', $tt1)->value('status_saat_ini'));
        $this->assertFalse(DB::table('kunjungan.riwayat_status_tempat_tidur')->whereIn('status', ['rencana_pulang', 'melewati_rencana_lama_rawat'])->exists());
    }

    /** Keputusan pemilik 13 Nov 2026: kunci pasien di peta juga bagi rawat_inap.pemulangan.lihat di cakupan bangsal, lapis 5 izin itu. */
    public function test_peta_mengirim_kunci_pasien_bagi_lihat_pemulangan_di_cakupan_bangsal(): void
    {
        [$ri] = $this->rawatInap($this->pasien(), 'UJI40-101-1');
        $url = '/api/v1/peta-tempat-tidur?bangsal_id='.$this->id['UJI40-BA'];
        $nama = (string) DB::table('pasien.pasien')->where('id', DB::table('kunjungan.kunjungan')->where('id', $ri)->value('pasien_id'))->value('nama');

        // Perawat tanpa SIP: izin lihat pemulangan non-klinis → kunci dan identitas dikirim.
        $this->masukSebagai($this->perawat);
        $peta = $this->minta('GET', $url)->assertOk()->assertJsonPath('data.0.kunjungan_rawat_inap_id', $ri)->assertJsonPath('data.0.pasien_rawat_inap.nama', $nama);
        $this->assertSesuaiKontrak($peta, 'GET', '/peta-tempat-tidur');
        $this->assertArrayNotHasKey('kunjungan_rawat_inap_id', (array) $peta->json('data.1'));

        // Cakupan unit lain (RB): tidak dikirim; cakupan RA: dikirim.
        foreach (['UJI40-RB' => false, 'UJI40-RA' => true] as $unit => $ada) {
            $akun = $this->buatPengguna();
            $this->tugaskan($akun, $this->buatPeran(['rawat_inap.peta_tempat_tidur']));
            $this->tugaskan($akun, $this->buatPeran(['rawat_inap.pemulangan']), cakupan: new Cakupan('unit', $this->id[$unit]));
            $this->masukSebagai($akun);
            $baris = (array) $this->minta('GET', $url)->assertOk()->json('data.0');
            $this->assertSame($ada, array_key_exists('kunjungan_rawat_inap_id', $baris), $unit);
            $this->assertSame($ada, array_key_exists('pasien_rawat_inap', $baris), $unit);
        }

        // Tanpa izin pemulangan (admisi): tidak dikirim, nama tidak ada di respons.
        $this->masukSebagai($this->admisi);
        $admisi = $this->minta('GET', $url)->assertOk();
        $this->assertArrayNotHasKey('kunjungan_rawat_inap_id', (array) $admisi->json('data.0'));
        $this->assertStringNotContainsString($nama, (string) $admisi->getContent());

        // Lapis 5 izin lihat pemulangan (RS menandainya butuh MFA): akun tanpa MFA → kunci tidak dikirim, peta tetap 200.
        DB::table('akses.izin')->where('kode', 'rawat_inap.pemulangan.lihat')->update(['butuh_mfa' => true]);
        app(CacheHakAkses::class)->lupakanSemua();
        $this->masukSebagai($this->perawat);
        $mfa = $this->minta('GET', $url)->assertOk();
        $this->assertArrayNotHasKey('kunjungan_rawat_inap_id', (array) $mfa->json('data.0'));
        $this->assertArrayNotHasKey('pasien_rawat_inap', (array) $mfa->json('data.0'));
        $this->assertStringNotContainsString($nama, (string) $mfa->getContent());
    }

    public function test_resume_disusun_dari_sumber_lalu_disahkan_dpjp_dengan_pin(): void
    {
        $pasien = $this->pasien();
        [$ri, , $poli] = $this->rawatInap($pasien, 'UJI40-101-1');
        $this->sumberKlinis($ri, $poli, $pasien);
        $this->aktifkanTtd($this->dpjp);

        // Belum disusun → data null. Susun (201) dari sumber; label sensitivitas hanya dihitung.
        $this->minta('GET', "/api/v1/kunjungan/$ri/resume-medis")->assertOk()->assertJsonPath('data', null);
        $susun = $this->minta('POST', "/api/v1/kunjungan/$ri/resume-medis", ['ringkasan_perjalanan' => 'Membaik dengan terapi (uji sintetis)'])
            ->assertCreated()->assertJsonPath('data.status', 'draf')->assertJsonPath('data.versi', '0')
            ->assertJsonPath('data.dpjp.id', $this->pegawaiDpjp->id)->assertJsonPath('data.pasien.nama', $pasien->nama)
            ->assertJsonPath('data.sumber.cppt.0.asesmen', 'Asesmen sintetis')->assertJsonPath('data.sumber.cppt_berlabel', 1)
            ->assertJsonPath('data.sumber.diagnosis.0.kode', 'ZZP40')->assertJsonPath('data.sumber.diagnosis.0.tampilan', 'Diagnosis sintetis pulang')
            ->assertJsonPath('data.sumber.resep.0.butir.0.barang.kode', 'UJI40-OBAT')->assertJsonPath('data.sumber.alergi.0.zat', 'Zat sintetis')
            ->assertJsonPath('data.sumber.tindakan.tersedia', false)->assertJsonPath('data.isian_dpjp.ringkasan_perjalanan', 'Membaik dengan terapi (uji sintetis)');
        $this->assertSesuaiKontrak($susun, 'POST', '/kunjungan/{id}/resume-medis');
        $this->assertCount(1, (array) $susun->json('data.sumber.cppt'));
        $id = (string) $susun->json('data.id');
        $audit = (string) $this->assertAuditTerakhir('klinis.resume_medis_disusun', $id)->sesudah;
        $this->assertStringNotContainsString('sintetis', $audit);

        // Susun ulang selama draf: isian yang tidak dikirim tetap, versi naik.
        $ulang = $this->minta('POST', "/api/v1/kunjungan/$ri/resume-medis", ['kondisi_pulang' => 'Stabil (uji sintetis)'])->assertOk()
            ->assertJsonPath('data.versi', '1')->assertJsonPath('data.isian_dpjp.ringkasan_perjalanan', 'Membaik dengan terapi (uji sintetis)')
            ->assertJsonPath('data.isian_dpjp.kondisi_pulang', 'Stabil (uji sintetis)');
        $this->assertSesuaiKontrak($ulang, 'POST', '/kunjungan/{id}/resume-medis');
        $this->assertSesuaiKontrak($this->minta('GET', "/api/v1/kunjungan/$ri/resume-medis")->assertOk(), 'GET', '/kunjungan/{id}/resume-medis');

        // Dokter lain ber-SIP dengan izin sahkan → 403 bukan_pemutus; perawat tanpa SIP → 403 izin_praktik_tidak_berlaku.
        $lain = $this->akunDokter($this->dokter());
        $this->tugaskan($lain, $this->buatPeran(['rawat_inap.pemulangan']));
        $this->masukSebagai($lain);
        $this->assertSesuaiKontrak($this->sahkan($id, '1')->assertForbidden()->assertJsonPath('error.kode', 'bukan_pemutus'), 'POST', '/resume-medis/{id}/sahkan');
        $this->masukSebagai($this->perawat);
        $this->sahkan($id, '1')->assertForbidden()->assertJsonPath('error.kode', 'izin_praktik_tidak_berlaku');

        // DPJP: PIN salah → 422 (tetap draf); versi basi → 412; tanpa If-Match → 428; benar → sah.
        $this->masukSebagai($this->dpjp);
        $this->assertSesuaiKontrak($this->sahkan($id, '1', '000999')->assertStatus(422)->assertJsonPath('error.kode', 'pin_salah'), 'POST', '/resume-medis/{id}/sahkan');
        $this->assertSame('draf', DB::table('klinis.resume_medis')->where('id', $id)->value('status'));
        $this->sahkan($id, '0')->assertStatus(412);
        $this->minta('POST', "/api/v1/resume-medis/$id/sahkan", ['pin' => self::PIN, 'pernyataan_disetujui' => true])->assertStatus(428);
        $sah = $this->sahkan($id, '1')->assertOk()->assertJsonPath('data.status', 'sah')->assertJsonPath('data.disahkan_oleh.id', $this->dpjp->id);
        $this->assertSesuaiKontrak($sah, 'POST', '/resume-medis/{id}/sahkan');
        $this->assertTrue(Carbon::parse((string) $sah->json('data.disahkan_pada'))->equalTo(now()));
        $this->assertNotNull($sah->json('data.tanda_tangan_id'));
        $this->assertSame(['sah'], array_column($this->minta('GET', "/api/v1/ttd/verifikasi/resume_medis/$id")->assertOk()->json('data.*.verifikasi'), 'status'));
        $this->assertAuditTerakhir('klinis.resume_medis_disahkan', $id);

        // Sudah sah → sahkan dan susun ulang 409 sudah_disahkan; tanda tangan lewat /ttd/tandatangani ditolak.
        $this->sahkan($id, '2')->assertStatus(409)->assertJsonPath('error.kode', 'sudah_disahkan');
        $this->assertSesuaiKontrak($this->minta('POST', "/api/v1/kunjungan/$ri/resume-medis", [])->assertStatus(409)->assertJsonPath('error.kode', 'sudah_disahkan'),
            'POST', '/kunjungan/{id}/resume-medis');

        // Resume sudah sah saat pulang → tidak ada tugas resume.
        $this->pulang($ri)->assertOk();
        $this->assertFalse(Tugas::query()->where('objek_id', $ri)->exists());
    }

    public function test_resume_belum_sah_48_jam_eskalasi_lalu_sahkan_sesudah_pulang_menyelesaikan_tugas(): void
    {
        [$ri] = $this->rawatInap($this->pasien(), 'UJI40-101-1');
        $this->aktifkanTtd($this->dpjp);
        $id = (string) $this->minta('POST', "/api/v1/kunjungan/$ri/resume-medis", [])->assertCreated()->json('data.id');
        $this->pulang($ri)->assertOk();
        $tugas = Tugas::query()->where('objek_id', $ri)->firstOrFail();

        // Sebelum 2 × 24 jam: pengingat (−4 jam) saja; lewat tenggat: teguran; + 1 hari: eskalasi.
        $this->travel(2880 - 239)->minutes();
        $this->artisan('notifikasi:proses-eskalasi')->assertSuccessful();
        $this->assertSame(1, $tugas->refresh()->tingkat_saat_ini);
        $this->travel(240)->minutes();
        $this->artisan('notifikasi:proses-eskalasi')->assertSuccessful();
        $this->assertSame(2, $tugas->refresh()->tingkat_saat_ini);
        $this->travel(1)->days();
        $this->artisan('notifikasi:proses-eskalasi')->assertSuccessful();
        $this->assertSame(3, $tugas->refresh()->tingkat_saat_ini);

        // Sesi lama sudah lewat batas diam: masuk lagi, sahkan sesudah pulang → tugas selesai.
        $this->masukSebagai($this->dpjp);
        $this->sahkan($id, '0')->assertOk()->assertJsonPath('data.status', 'sah');
        $this->assertSame('selesai', $tugas->refresh()->status);
    }

    public function test_data_induk_daftar_periksa_pulang_ubah_berizin_dan_audit(): void
    {
        $admin = $this->buatPengguna();
        $this->tugaskan($admin, $this->buatPeran(['master_data.daftar_periksa_pulang']));
        $this->masukSebagai($admin);
        $daftar = $this->minta('GET', '/api/v1/data-induk/daftar-periksa-pulang')->assertOk()->assertJsonCount(5, 'data');
        $this->assertSesuaiKontrak($daftar, 'GET', '/data-induk/daftar-periksa-pulang');
        $this->assertSame('kejadian', collect($daftar->json('data'))->firstWhere('kode', 'TAGIHAN_JAMINAN')['sumber']);
        $butir = collect($daftar->json('data'))->firstWhere('kode', 'RUJUK_BALIK');
        $url = '/api/v1/data-induk/daftar-periksa-pulang/'.$butir['id'];

        $this->assertSesuaiKontrak($this->minta('PATCH', $url, ['status' => 'nonaktif', 'dasar_perubahan' => 'SK uji'])->assertStatus(428),
            'PATCH', '/data-induk/daftar-periksa-pulang/{id}');
        $this->mintaDenganVersi('PATCH', $url, '0', ['dasar_perubahan' => 'SK uji'])->assertStatus(422);
        $ubah = $this->mintaDenganVersi('PATCH', $url, '0', ['status' => 'nonaktif', 'dasar_perubahan' => 'SK uji'])->assertOk()
            ->assertJsonPath('data.status', 'nonaktif')->assertJsonPath('data.versi', '1');
        $this->assertSesuaiKontrak($ubah, 'PATCH', '/data-induk/daftar-periksa-pulang/{id}');
        $this->assertSame('"1"', $ubah->headers->get('ETag'));
        $audit = $this->assertAuditTerakhir('master_data.daftar_periksa_pulang_diubah', (string) $butir['id']);
        $this->assertStringContainsString('SK uji', (string) $audit->sesudah);
        $this->mintaDenganVersi('PATCH', $url, '0', ['nama' => 'Basi', 'dasar_perubahan' => 'SK uji'])->assertStatus(412);
        $this->mintaDenganVersi('PATCH', '/api/v1/data-induk/daftar-periksa-pulang/'.Str::uuid7(), '0', ['nama' => 'Tidak ada', 'dasar_perubahan' => 'SK uji'])->assertNotFound();

        // Butir nonaktif tidak disalin ke rencana baru.
        [$ri] = $this->rawatInap($this->pasien(), 'UJI40-101-1');
        $this->masukSebagai($this->perawat);
        $kode = array_column($this->rencana($ri, ['rencana_pulang_pada' => '2026-11-04'])->assertCreated()->json('data.daftar_periksa'), 'kode');
        $this->assertNotContains('RUJUK_BALIK', $kode);

        // Tanpa izin → 403 (juga untuk id tak dikenal).
        $this->masukSebagai($this->perawat);
        $this->assertSesuaiKontrak($this->minta('GET', '/api/v1/data-induk/daftar-periksa-pulang')->assertForbidden(), 'GET', '/data-induk/daftar-periksa-pulang');
        $this->mintaDenganVersi('PATCH', '/api/v1/data-induk/daftar-periksa-pulang/'.Str::uuid7(), '0', ['nama' => 'X', 'dasar_perubahan' => 'SK uji'])->assertForbidden();
    }

    private function siapkanTempatTidur(): void
    {
        $irna = UnitOrganisasi::query()->create(['kode' => 'UJI40-IRNA', 'nama' => 'Instalasi Rawat Inap (uji)', 'jenis' => 'instalasi', 'berlaku_dari' => '2026-01-01']);
        foreach (['UJI40-RA', 'UJI40-RB'] as $kode) {
            $this->id[$kode] = UnitOrganisasi::query()->create(['kode' => $kode, 'nama' => "Ruang $kode", 'jenis' => 'ruang', 'induk_id' => $irna->id, 'berlaku_dari' => '2026-01-01'])->id;
        }
        $this->id['UJI40-K3'] = KelasRawat::query()->create(['kode' => 'UJI40-K3', 'nama' => 'Kelas uji', 'berlaku_dari' => '2026-01-01'])->id;
        foreach (['UJI40-BA' => 'UJI40-RA', 'UJI40-BB' => 'UJI40-RB'] as $kode => $unit) {
            $this->id[$kode] = Bangsal::query()->create(['kode' => $kode, 'nama' => "Bangsal $kode", 'unit_organisasi_id' => $this->id[$unit], 'status' => 'aktif'])->id;
        }
        $gedung = $this->lokasi(null, 'gedung', 'UJI40', 'UJI40');
        foreach (['101' => ['UJI40-BA', 3], '201' => ['UJI40-BB', 2]] as $ruang => [$bangsal, $jumlah]) {
            $ruangId = $this->lokasi($gedung, 'ruang', (string) $ruang, "UJI40-$ruang", 'rawat_inap');
            $kamar = Kamar::query()->create(['bangsal_id' => $this->id[$bangsal], 'lokasi_id' => $ruangId])->id;
            foreach (range(1, $jumlah) as $n) {
                $kodeTt = "UJI40-$ruang-$n";
                $this->id[$kodeTt] = TempatTidur::query()->create([
                    'kamar_id' => $kamar, 'lokasi_id' => $this->lokasi($ruangId, 'tempat_tidur', (string) $n, $kodeTt), 'kode' => $kodeTt,
                    'kelas_rawat_id' => $this->id['UJI40-K3'], 'berlaku_dari' => '2026-01-01',
                ])->id;
            }
        }
    }

    private function lokasi(?string $induk, string $jenis, string $kode, string $kodeLengkap, ?string $fungsi = null): string
    {
        return Lokasi::query()->create([
            'induk_id' => $induk, 'jenis' => $jenis, 'kode' => $kode, 'kode_lengkap' => $kodeLengkap, 'nama' => "Lokasi $kodeLengkap",
            'fungsi' => $fungsi, 'status' => 'aktif', 'status_berlaku_dari' => '2026-01-01',
        ])->id;
    }

    /**
     * Pasien dirawat inap dari SPRI dokter poli (DPJP) di TT itu; sesudahnya masuk sebagai DPJP.
     *
     * @return array{0: string, 1: string, 2: string} id kunjungan rawat inap, id TT, id kunjungan poli asal
     */
    private function rawatInap(Pasien $pasien, string $kodeTt): array
    {
        $this->masukSebagai($this->petugasPendaftaran());
        $poli = (string) $this->daftarKunjungan($pasien, JadwalPraktik::query()->findOrFail($this->id['jadwal']))->assertCreated()->json('data.id');
        $this->masukSebagai($this->dpjp);
        $this->minta('POST', "/api/v1/kunjungan/$poli/mulai-periksa")->assertOk();
        $permintaan = (string) $this->minta('POST', "/api/v1/kunjungan/$poli/spri", [
            'alasan' => 'Observasi rawat inap (uji sintetis)', 'kelas_rawat_id' => $this->id['UJI40-K3'],
        ])->assertCreated()->json('data.id');
        $this->masukSebagai($this->admisi);
        $this->minta('POST', "/api/v1/permintaan-tempat-tidur/$permintaan/alokasi", ['tempat_tidur_id' => $this->id[$kodeTt]])->assertOk();
        $ri = (string) $this->minta('POST', "/api/v1/permintaan-tempat-tidur/$permintaan/tempatkan", [])->assertCreated()->json('data.kunjungan.id');
        $this->masukSebagai($this->dpjp);

        return [$ri, $this->id[$kodeTt], $poli];
    }

    /** Sumber resume SINTETIS: CPPT sah (satu berlabel), diagnosis, resep sah, alergi. */
    private function sumberKlinis(string $ri, string $poli, Pasien $pasien): void
    {
        $episode = (string) DB::table('kunjungan.kunjungan')->where('id', $ri)->value('episode_id');
        $unit = (string) DB::table('kunjungan.kunjungan')->where('id', $ri)->value('unit_id');
        foreach ([null, 'UJI40-LABEL'] as $label) {
            DB::table('klinis.catatan_klinis')->insert(['id' => (string) Str::uuid7(), 'episode_id' => $episode, 'kunjungan_id' => $poli, 'unit_id' => $unit,
                'pasien_id' => $pasien->id, 'penulis_id' => $this->dpjp->id, 'profesi' => 'dokter', 'tatanan' => 'rj', 'jenis' => 'soap',
                'isi' => json_encode(['subjektif' => 'Keluhan sintetis', 'asesmen' => 'Asesmen sintetis']), 'status' => 'sah', 'selesai_pada' => now(), 'label' => $label]);
        }
        DB::table('inti.terminologi')->insert(['id' => (string) Str::uuid7(), 'sistem' => 'icd10', 'kode' => 'ZZP40', 'tampilan' => 'Diagnosis sintetis pulang',
            'aktif' => true, 'versi_terakhir' => 'uji-sintetis']);
        DB::table('klinis.diagnosis')->insert(['id' => (string) Str::uuid7(), 'kunjungan_id' => $poli, 'pasien_id' => $pasien->id, 'kode_icd' => 'ZZP40', 'jenis' => 'utama']);
        $depo = UnitOrganisasi::query()->create(['kode' => 'UJI40-DEPO', 'nama' => 'Depo uji', 'jenis' => 'depo', 'berlaku_dari' => '2026-01-01'])->id;
        $barang = (string) Str::uuid7();
        DB::table('logistik.barang')->insert(['id' => $barang, 'kode' => 'UJI40-OBAT', 'nama' => 'Obat sintetis pulang', 'jenis' => 'obat',
            'generik_paten' => 'generik', 'zat_aktif' => 'Zat sintetis', 'satuan_terkecil' => 'tablet']);
        $resep = (string) Str::uuid7();
        DB::table('farmasi.resep')->insert(['id' => $resep, 'no_resep' => 'RSP-UJI40-0001', 'kunjungan_id' => $poli, 'pasien_id' => $pasien->id, 'depo_id' => $depo,
            'status' => 'sah', 'penulis_id' => $this->dpjp->id, 'dokter_id' => $this->pegawaiDpjp->id, 'masuk_pada' => now()]);
        DB::table('farmasi.resep_item')->insert(['id' => (string) Str::uuid7(), 'resep_id' => $resep, 'barang_id' => $barang, 'urutan' => 1,
            'frekuensi_per_hari' => 3, 'jumlah_per_pakai' => 1, 'hari' => 3, 'jumlah' => 9]);
        DB::table('klinis.alergi')->insert(['id' => (string) Str::uuid7(), 'pasien_id' => $pasien->id, 'zat' => 'Zat sintetis', 'jenis' => 'obat']);
    }

    private function aktifkanTtd(Pengguna $akun): void
    {
        $this->masukSebagai($akun);
        $this->minta('POST', '/api/v1/saya/ttd/aktivasi', ['sandi' => self::SANDI_UJI, 'pin' => self::PIN, 'pin_ulang' => self::PIN])->assertSuccessful();
    }

    /**
     * Akun baru dengan peran berisi izin terpilih saja (cakupan rs).
     *
     * @param  list<string>  $izin
     */
    private function akunIzin(array $izin): Pengguna
    {
        $akun = $this->buatPengguna();
        $peran = $this->buatPeran([]);
        foreach (Izin::query()->whereIn('kode', $izin)->get(['id', 'menu_id']) as $i) {
            PeranMenu::query()->firstOrCreate(['peran_id' => $peran->id, 'menu_id' => $i->menu_id]);
            PeranIzin::query()->create(['peran_id' => $peran->id, 'izin_id' => $i->id]);
        }
        $this->tugaskan($akun, $peran);

        return $akun;
    }

    /** @param  array<string, mixed>  $ganti */
    private function pulang(string $kunjungan, array $ganti = []): TestResponse
    {
        return $this->minta('POST', "/api/v1/kunjungan/$kunjungan/pulang", [
            'tujuan' => 'pulang', 'alasan' => 'Kondisi membaik (uji sintetis)',
            'tanggapan' => array_map(fn (string $kode) => ['kode' => $kode, 'alasan' => 'Belum sempat (uji sintetis)'], self::BUTIR_MANUAL), ...$ganti,
        ]);
    }

    /** @param  array<string, mixed>  $isi */
    private function rencana(string $kunjungan, array $isi): TestResponse
    {
        return $this->minta('PUT', "/api/v1/kunjungan/$kunjungan/rencana-pulang", $isi);
    }

    /** @param  array<string, mixed>  $isi */
    private function rencanaVersi(string $kunjungan, string $versi, array $isi): TestResponse
    {
        return $this->mintaDenganVersi('PUT', "/api/v1/kunjungan/$kunjungan/rencana-pulang", $versi, $isi);
    }

    private function sahkan(string $resume, string $versi, string $pin = self::PIN): TestResponse
    {
        return $this->mintaDenganVersi('POST', "/api/v1/resume-medis/$resume/sahkan", $versi, ['pin' => $pin, 'pernyataan_disetujui' => true]);
    }
}
