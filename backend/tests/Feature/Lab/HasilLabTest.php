<?php

namespace Tests\Feature\Lab;

use App\Modules\Akses\Domain\Cakupan;
use App\Modules\Akses\Models\Izin;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\Akses\Models\Peran;
use App\Modules\Akses\Models\PeranIzin;
use App\Modules\Akses\Models\PeranMenu;
use App\Modules\MasterData\Models\Bangsal;
use App\Modules\MasterData\Models\JadwalPraktik;
use App\Modules\MasterData\Models\Kamar;
use App\Modules\MasterData\Models\KelasRawat;
use App\Modules\MasterData\Models\Lokasi;
use App\Modules\MasterData\Models\TempatTidur;
use App\Modules\MasterData\Models\UnitOrganisasi;
use App\Modules\Pendaftaran\Models\Pasien;
use App\Modules\Sdm\Models\Pegawai;
use Illuminate\Database\QueryException;
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
 * Tiket 2.31-be: hasil lab, nilai rujukan data induk, validasi, hasil mengikuti pasien (kontrak v0.41.0). Butir tarif,
 * pasien, dan data induk SINTETIS: semua angka rujukan/kritis di sini contoh sintetis, bukan nilai klinis.
 */
class HasilLabTest extends TestCase
{
    use AksesUji, KlienSpa, KontrakApi, KunjunganUji;

    private const PERAN_DOKTER = ['rawat_jalan.pemeriksaan', 'rawat_jalan.pemeriksaan.order', 'master_data.tarif'];

    private JadwalPraktik $jadwal;

    private Pengguna $dokter;

    private Pengguna $analis;

    private ?string $sesi = null;

    /** @var array<string, string> kode tarif => id */
    private array $tarif = [];

    /** @var array<string, string> kode tarif => id lab.pemeriksaan */
    private array $periksa = [];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        // Waktu dibekukan (travelTo) sehingga jendela batas masuk tidak bergeser; tes ini masuk berulang dengan banyak akun.
        config(['autentikasi.rate_limit.per_nama_pengguna_per_menit' => 1000, 'autentikasi.rate_limit.per_ip_per_menit' => 1000]);
        $this->siapkanKunjungan();
        $pegawai = $this->dokter();
        $this->jadwal = $this->jadwal($pegawai);
        $this->dokter = $this->buatPengguna(['pegawai_id' => $pegawai->id]);
        $this->tugaskan($this->dokter, $this->buatPeran(self::PERAN_DOKTER));
        $this->analis = $this->buatPengguna();
        $this->tugaskan($this->analis, $this->peranIzin(['penunjang.laboratorium.lihat', 'penunjang.laboratorium.input',
            'penunjang.laboratorium.verifikasi', 'penunjang.laboratorium.sahkan']));

        // Contoh sintetis, bukan nilai klinis.
        $this->tarif['UJI31-NUM'] = $this->butir('UJI31-NUM');
        $this->periksa['UJI31-NUM'] = $this->pemeriksaan('UJI31-NUM', ['satuan' => 'u{contoh}']);
        $this->rujukan('UJI31-NUM', ['jenis_kelamin' => 'perempuan', 'rujukan_min' => 10, 'rujukan_maks' => 20, 'kritis_min' => 5, 'kritis_maks' => 30]);
        $this->tarif['UJI31-TEKS'] = $this->butir('UJI31-TEKS');
        $this->periksa['UJI31-TEKS'] = $this->pemeriksaan('UJI31-TEKS', ['jenis_hasil' => 'teks',
            'nilai_normal_teks' => json_encode(['Negatif contoh']), 'nilai_kritis_teks' => json_encode(['Positif contoh'])]);
        $this->tarif['UJI31-KOSONG'] = $this->butir('UJI31-KOSONG');
        $this->periksa['UJI31-KOSONG'] = $this->pemeriksaan('UJI31-KOSONG');
        $this->tarif['UJI31-SPK'] = $this->butir('UJI31-SPK');
        $this->periksa['UJI31-SPK'] = $this->pemeriksaan('UJI31-SPK', ['butuh_otorisasi_spk' => true]);
        $this->rujukan('UJI31-SPK', ['rujukan_min' => 1, 'rujukan_maks' => 2]);
        $this->tarif['UJI31-TANPA'] = $this->butir('UJI31-TANPA');
    }

    public function test_penanda_numerik_dari_data_induk_dan_input_ulang_sebelum_validasi(): void
    {
        $item = $this->siapHasil($this->pasien(), 'UJI31-NUM');

        $res = $this->input($item, ['nilai' => 15, 'catatan' => 'Catatan sintetis'])->assertCreated()
            ->assertJsonPath('data.status', 'menunggu_validasi')->assertJsonPath('data.penanda', null)->assertJsonPath('data.kritis', false)
            ->assertJsonPath('data.peringatan', null)->assertJsonPath('data.rujukan_min', 10.0)->assertJsonPath('data.kritis_maks', 30.0)
            ->assertJsonPath('data.satuan', 'u{contoh}')->assertJsonPath('data.diinput_oleh.id', $this->analis->id)
            ->assertJsonPath('data.dapat.validasi', true)->assertJsonPath('data.dapat.adendum', false);
        $this->assertSesuaiKontrak($res, 'POST', '/permintaan-item/{id}/hasil');
        $this->assertStringContainsString('no-store', (string) $res->headers->get('Cache-Control'));
        $this->assertSame('menunggu_validasi', DB::table('permintaan.permintaan_item')->where('id', $item)->value('status'));
        $this->assertArrayNotHasKey('nik', $res->json('data.pasien'));
        $hasilId = (string) $res->json('data.id');

        foreach ([[3, 'll'], [7, 'l'], [10, null], [20, null], [25, 'h'], [35, 'hh'], [5, 'l'], [4.5, 'll']] as [$nilai, $penanda]) {
            $ulang = $this->input($item, ['nilai' => $nilai])->assertOk()->assertJsonPath('data.id', $hasilId)->assertJsonPath('data.penanda', $penanda)
                ->assertJsonPath('data.kritis', in_array($penanda, ['ll', 'hh'], true));
            $this->assertSesuaiKontrak($ulang, 'POST', '/permintaan-item/{id}/hasil');
        }
        $this->assertSame(1, DB::table('lab.hasil')->where('permintaan_item_id', $item)->count());
        $this->assertSame('ll', DB::table('lab.hasil')->where('id', $hasilId)->value('penanda'));

        // Isian tidak cocok jenis hasil → 422.
        $this->input($item, ['nilai_teks' => 'Teks'])->assertStatus(422)->assertJsonPath('error.kode', 'validasi_gagal');
        $this->assertSesuaiKontrak($this->input($item, [])->assertStatus(422), 'POST', '/permintaan-item/{id}/hasil');
        $this->input($item, ['nilai' => 'abc'])->assertStatus(422);

        // Audit tanpa nilai hasil.
        $audit = DB::table('audit.log_aktivitas')->where('aksi', 'lab.hasil_diinput')->where('subjek_id', $hasilId)->pluck('sesudah')->implode(' ');
        $this->assertStringNotContainsString('"nilai', $audit);
        $this->assertStringNotContainsString('Catatan sintetis', $audit);
        $this->assertAuditTerakhir('lab.hasil_diinput', $hasilId);
    }

    public function test_teks_kritis_tanpa_rujukan_peringatan_dan_tanpa_data_induk(): void
    {
        $pasien = $this->pasien();
        $teks = $this->siapHasil($pasien, 'UJI31-TEKS');
        $this->input($teks, ['nilai_teks' => '  positif CONTOH '])->assertCreated()->assertJsonPath('data.penanda', 'kritis')
            ->assertJsonPath('data.kritis', true)->assertJsonPath('data.nilai_teks', 'positif CONTOH')->assertJsonPath('data.nilai', null);
        $this->input($teks, ['nilai_teks' => 'Negatif contoh'])->assertOk()->assertJsonPath('data.penanda', null)->assertJsonPath('data.peringatan', null);
        $this->input($teks, ['nilai' => 1])->assertStatus(422);

        $kosong = $this->siapHasil($this->pasien(), 'UJI31-KOSONG');
        $res = $this->input($kosong, ['nilai' => 999])->assertCreated()->assertJsonPath('data.penanda', null)
            ->assertJsonPath('data.peringatan', 'nilai_rujukan_tidak_ada')->assertJsonPath('data.rujukan_min', null);
        $this->assertSesuaiKontrak($res, 'POST', '/permintaan-item/{id}/hasil');
        $this->assertSame('nilai_rujukan_tidak_ada', DB::table('lab.hasil')->where('permintaan_item_id', $kosong)->value('peringatan'));

        // Butir tanpa data induk pemeriksaan → 422; butir belum diterima lab → 409.
        $tanpa = $this->siapHasil($this->pasien(), 'UJI31-TANPA');
        $this->assertSesuaiKontrak($this->input($tanpa, ['nilai' => 1])->assertStatus(422)->assertJsonPath('error.kode', 'pemeriksaan_lab_belum_diatur'),
            'POST', '/permintaan-item/{id}/hasil');
        [$belum] = $this->butirOrder($this->order($this->pasien(), ['UJI31-NUM']));
        $this->sebagai($this->analis);
        $this->assertSesuaiKontrak($this->input($belum, ['nilai' => 1])->assertStatus(409)->assertJsonPath('error.kode', 'status_tidak_sesuai'),
            'POST', '/permintaan-item/{id}/hasil');
        $this->input((string) Str::uuid7(), ['nilai' => 1])->assertNotFound();
    }

    public function test_rentang_umur_setengah_terbuka_jenis_kelamin_dan_pasien_tanpa_tanggal_lahir(): void
    {
        $this->pemeriksaanBaru('UJI31-UMUR');
        $lahir = '1990-01-01';
        $umur = (int) Carbon::parse($lahir)->diffInDays(Carbon::parse(self::SENIN), true);
        // Contoh sintetis, bukan nilai klinis: rujukan_min membedakan baris yang terpilih.
        $this->rujukan('UJI31-UMUR', ['jenis_kelamin' => 'laki_laki', 'umur_min_hari' => 0, 'umur_maks_hari' => $umur, 'rujukan_min' => 1, 'rujukan_maks' => 2]);
        $this->rujukan('UJI31-UMUR', ['jenis_kelamin' => 'laki_laki', 'umur_min_hari' => $umur, 'rujukan_min' => 100, 'rujukan_maks' => 200]);
        $this->rujukan('UJI31-UMUR', ['jenis_kelamin' => null, 'rujukan_min' => 1000, 'rujukan_maks' => 2000]);

        // Umur tepat di batas: masuk baris [umur, ∞), bukan [0, umur).
        $laki = $this->pasien();
        $laki->forceFill(['jenis_kelamin' => 'laki_laki', 'tanggal_lahir' => $lahir])->save();
        $this->input($this->siapHasil($laki, 'UJI31-UMUR'), ['nilai' => 50])->assertCreated()
            ->assertJsonPath('data.rujukan_min', 100.0)->assertJsonPath('data.penanda', 'l');
        // Sehari lebih muda: baris [0, umur).
        $muda = $this->pasien();
        $muda->forceFill(['jenis_kelamin' => 'laki_laki', 'tanggal_lahir' => Carbon::parse($lahir)->addDay()->toDateString()])->save();
        $this->input($this->siapHasil($muda, 'UJI31-UMUR'), ['nilai' => 50])->assertCreated()
            ->assertJsonPath('data.rujukan_min', 1.0)->assertJsonPath('data.penanda', 'h');
        // Perempuan tanpa baris JK sendiri: baris semua JK.
        $this->input($this->siapHasil($this->pasien(), 'UJI31-UMUR'), ['nilai' => 50])->assertCreated()
            ->assertJsonPath('data.rujukan_min', 1000.0)->assertJsonPath('data.penanda', 'l');
        // Tanpa tanggal lahir dan JK tidak diketahui: hanya baris tanpa batas umur untuk semua JK.
        $anonim = $this->pasien();
        $item = $this->siapHasil($anonim, 'UJI31-UMUR');
        $anonim->forceFill(['tanpa_identitas' => true, 'tanggal_lahir' => null, 'jenis_kelamin' => 'tidak_diketahui'])->save();
        $this->input($item, ['nilai' => 50])->assertCreated()->assertJsonPath('data.rujukan_min', 1000.0)->assertJsonPath('data.pasien.tanggal_lahir', null);
        // Baris semua JK dibatasi umur → pasien tanpa tanggal lahir tidak punya rujukan → peringatan.
        DB::table('lab.nilai_rujukan')->where('pemeriksaan_id', $this->periksa['UJI31-UMUR'])->whereNull('jenis_kelamin')->update(['status' => 'nonaktif']);
        $this->rujukan('UJI31-UMUR', ['jenis_kelamin' => null, 'umur_min_hari' => 0, 'umur_maks_hari' => 365000, 'rujukan_min' => 3000]);
        $this->input($item, ['nilai' => 50])->assertOk()->assertJsonPath('data.penanda', null)->assertJsonPath('data.peringatan', 'nilai_rujukan_tidak_ada');

        // Rentang aktif yang tumpang tindih untuk pemeriksaan + JK sama ditolak; JK lain atau baris nonaktif boleh.
        $this->assertTumpangTindihDitolak(['jenis_kelamin' => 'laki_laki', 'umur_min_hari' => $umur - 1, 'umur_maks_hari' => $umur + 1]);
        $this->rujukan('UJI31-UMUR', ['jenis_kelamin' => 'perempuan', 'umur_min_hari' => 0, 'rujukan_min' => 7]);
        $this->rujukan('UJI31-UMUR', ['jenis_kelamin' => 'laki_laki', 'umur_min_hari' => 0, 'status' => 'nonaktif']);
    }

    public function test_validasi_analis_final_tugas_ke_pemesan_poli_dan_terkunci_sesudahnya(): void
    {
        $item = $this->siapHasil($this->pasien(), 'UJI31-NUM');
        $hasil = (string) $this->input($item, ['nilai' => 12])->assertCreated()->json('data.id');

        // Penginput boleh sama dengan validator.
        $this->travel(2)->minutes();
        $res = $this->validasi($hasil)->assertOk()->assertJsonPath('data.status', 'divalidasi')
            ->assertJsonPath('data.divalidasi_oleh.id', $this->analis->id)->assertJsonPath('data.diotorisasi_oleh', null)
            ->assertJsonPath('data.dapat.adendum', true)->assertJsonPath('data.dapat.validasi', false);
        $this->assertSesuaiKontrak($res, 'POST', '/hasil-lab/{id}/validasi');
        $this->assertTrue(Carbon::parse((string) $res->json('data.dikeluarkan_pada'))->equalTo(now()));
        $this->assertSame('divalidasi', DB::table('permintaan.permintaan_item')->where('id', $item)->value('status'));
        $this->assertAuditTerakhir('lab.hasil_divalidasi', $hasil);

        // Pasien poli (tanpa TT/IGD aktif): penanggung jawab = pemesan → cukup tugas, tanpa notifikasi informasi.
        $tugas = $this->tugas($hasil);
        $this->assertCount(1, $tugas);
        $this->assertSame(['pengguna', $this->dokter->id], [$tugas[0]->pelaksana_jenis, $tugas[0]->pelaksana_id]);
        $this->assertStringNotContainsString('Pasien Sintetis', $tugas[0]->judul);
        $this->assertStringNotContainsString('12', str_replace((string) DB::table('kunjungan.kunjungan')->where('id', $this->kunjunganItem($item))->value('nomor'), '', $tugas[0]->judul));
        $this->assertSame("/penunjang/laboratorium/hasil/$hasil", $tugas[0]->tautan);
        $this->assertTrue(Carbon::parse($tugas[0]->tenggat_pada)->equalTo(now()->addMinutes(1440)));
        $this->assertSame(0, $this->notifikasiInformasi($this->dokter));

        // Sesudah divalidasi: input ulang dan validasi ulang → 409; nilai tetap.
        $this->assertSesuaiKontrak($this->input($item, ['nilai' => 99])->assertStatus(409)->assertJsonPath('error.kode', 'hasil_sudah_divalidasi'),
            'POST', '/permintaan-item/{id}/hasil');
        $this->assertSesuaiKontrak($this->validasi($hasil)->assertStatus(409)->assertJsonPath('error.kode', 'hasil_sudah_divalidasi'),
            'POST', '/hasil-lab/{id}/validasi');
        $this->assertEquals(12, (float) DB::table('lab.hasil')->where('id', $hasil)->value('nilai'));
        $this->assertSesuaiKontrak($this->validasi((string) Str::uuid7())->assertNotFound(), 'POST', '/hasil-lab/{id}/validasi');

        // Audit tanpa nilai hasil.
        $audit = (string) DB::table('audit.log_aktivitas')->where('aksi', 'like', 'lab.hasil%')->pluck('sesudah')->implode(' ');
        $this->assertStringNotContainsString('"nilai', $audit);
    }

    public function test_otorisasi_spk_empat_mata_dan_izin(): void
    {
        $spk = $this->akunSpk(['penunjang.laboratorium.lihat', 'penunjang.laboratorium.setujui']);
        $item = $this->siapHasil($this->pasien(), 'UJI31-SPK', 'cito');
        $hasil = (string) $this->input($item, ['nilai' => 9])->assertCreated()->assertJsonPath('data.butuh_otorisasi_spk', true)
            ->assertJsonPath('data.penanda', 'h')->json('data.id');

        // Tanpa izin lab (dokter pemesan) → 403 di input dan validasi.
        $this->sebagai($this->dokter);
        $this->assertSesuaiKontrak($this->input($item, ['nilai' => 1])->assertForbidden(), 'POST', '/permintaan-item/{id}/hasil');
        $this->assertSesuaiKontrak($this->validasi($hasil)->assertForbidden(), 'POST', '/hasil-lab/{id}/validasi');
        // Sp.PK tidak bisa melewati validasi analis (tahap menunggu_validasi butuh sahkan).
        $this->sebagai($spk);
        $this->validasi($hasil)->assertForbidden();

        $this->sebagai($this->analis);
        $this->validasi($hasil)->assertOk()->assertJsonPath('data.status', 'menunggu_otorisasi')->assertJsonPath('data.dikeluarkan_pada', null)
            ->assertJsonPath('data.dapat.validasi', false);
        $this->assertSame('menunggu_otorisasi', DB::table('permintaan.permintaan_item')->where('id', $item)->value('status'));
        $this->assertSame([], $this->tugas($hasil), 'Belum final: belum ada tugas tindak lanjut.');
        // Analis tanpa izin setujui → 403; input ulang menunggu otorisasi → 409.
        $this->validasi($hasil)->assertForbidden();
        $this->input($item, ['nilai' => 1])->assertStatus(409)->assertJsonPath('error.kode', 'status_tidak_sesuai');
        $this->minta('GET', '/api/v1/lab/daftar-kerja?prioritas=cito&tahap=hasil')->assertOk()->assertJsonCount(0, 'data');

        // Daftar kerja tahap otorisasi untuk Sp.PK.
        $this->sebagai($spk);
        $daftar = $this->minta('GET', '/api/v1/lab/daftar-kerja?prioritas=cito&tahap=otorisasi')->assertOk()
            ->assertJsonPath('data.0.item.0.status', 'menunggu_otorisasi')->assertJsonPath('data.0.item.0.hasil.id', $hasil)
            ->assertJsonPath('data.0.item.0.dapat.validasi', true)->assertJsonPath('data.0.item.0.dapat.input', false);
        $this->assertSesuaiKontrak($daftar, 'GET', '/lab/daftar-kerja');
        $res = $this->validasi($hasil)->assertOk()->assertJsonPath('data.status', 'divalidasi')
            ->assertJsonPath('data.divalidasi_oleh.id', $this->analis->id)->assertJsonPath('data.diotorisasi_oleh.id', $spk->id);
        $this->assertSesuaiKontrak($res, 'POST', '/hasil-lab/{id}/validasi');
        $this->assertAuditTerakhir('lab.hasil_diotorisasi', $hasil);
        $this->assertCount(1, $this->tugas($hasil));

        // Satu akun memegang sahkan dan setujui: validasi boleh, otorisasi hasil yang ia validasi sendiri → 409.
        $ganda = $this->akunSpk(['penunjang.laboratorium.lihat', 'penunjang.laboratorium.input', 'penunjang.laboratorium.sahkan', 'penunjang.laboratorium.setujui']);
        $item2 = $this->siapHasil($this->pasien(), 'UJI31-SPK');
        $this->sebagai($ganda);
        $hasil2 = (string) $this->input($item2, ['nilai' => 1.5])->assertCreated()->json('data.id');
        $this->validasi($hasil2)->assertOk()->assertJsonPath('data.status', 'menunggu_otorisasi')->assertJsonPath('data.dapat.validasi', false);
        $tolak = $this->validasi($hasil2)->assertStatus(409)->assertJsonPath('error.kode', 'sudah_memvalidasi');
        $this->assertSesuaiKontrak($tolak, 'POST', '/hasil-lab/{id}/validasi');
        $this->assertSame('menunggu_otorisasi', DB::table('lab.hasil')->where('id', $hasil2)->value('status'));
        $this->sebagai($spk);
        $this->validasi($hasil2)->assertOk()->assertJsonPath('data.status', 'divalidasi');
    }

    public function test_hasil_mengikuti_pasien_ke_dpjp_tt_unit_dan_dokter_igd_pemesan_dapat_notifikasi(): void
    {
        // Order dari poli, sesudahnya pasien ditempatkan di TT rawat inap dengan DPJP lain.
        $pasien = $this->pasien();
        $item = $this->siapHasil($pasien, 'UJI31-NUM');
        $dpjpPegawai = $this->dokter();
        $dpjp = $this->buatPengguna(['pegawai_id' => $dpjpPegawai->id]);
        [$ri, $unitRuang] = $this->rawatInap($pasien, $dpjpPegawai->id);
        $hasil = (string) $this->input($item, ['nilai' => 15])->assertCreated()->json('data.id');
        $this->validasi($hasil)->assertOk();
        $tugas = $this->tugas($hasil);
        $this->assertCount(1, $tugas);
        $this->assertSame(['pengguna', $dpjp->id, $unitRuang], [$tugas[0]->pelaksana_jenis, $tugas[0]->pelaksana_id, $tugas[0]->unit_id]);
        $this->assertSame(1, $this->notifikasiInformasi($this->dokter), 'Pemesan menerima notifikasi informasi.');
        $this->assertSame(0, $this->notifikasiInformasi($dpjp));
        $this->assertSame(1, DB::table('notifikasi.notifikasi')->where('pengguna_id', $dpjp->id)->where('jenis', 'HASIL_LAB_TINDAK_LANJUT')->count());

        // DPJP tanpa akun aktif → tugas ke unit bangsal TT saat ini.
        $tanpaAkun = Pegawai::factory()->create(['profesi' => 'dokter']);
        DB::table('kunjungan.kunjungan')->where('id', $ri)->update(['pegawai_id' => $tanpaAkun->id]);
        $item2 = $this->siapHasil($pasien, 'UJI31-TEKS');
        $hasil2 = (string) $this->input($item2, ['nilai_teks' => 'Negatif contoh'])->assertCreated()->json('data.id');
        $this->validasi($hasil2)->assertOk();
        $this->assertSame(['unit', $unitRuang], [$this->tugas($hasil2)[0]->pelaksana_jenis, $this->tugas($hasil2)[0]->pelaksana_id]);

        // Pasien lain di IGD: tugas ke dokter kunjungan IGD.
        $igdPasien = $this->pasien();
        $item3 = $this->siapHasil($igdPasien, 'UJI31-NUM');
        $dokterIgdPegawai = $this->dokter();
        $dokterIgd = $this->buatPengguna(['pegawai_id' => $dokterIgdPegawai->id]);
        $this->kunjunganIgd($igdPasien, $dokterIgdPegawai->id);
        $hasil3 = (string) $this->input($item3, ['nilai' => 15])->assertCreated()->json('data.id');
        $this->validasi($hasil3)->assertOk();
        $this->assertSame(['pengguna', $dokterIgd->id], [$this->tugas($hasil3)[0]->pelaksana_jenis, $this->tugas($hasil3)[0]->pelaksana_id]);
        $this->assertSame(3, $this->notifikasiInformasi($this->dokter));
        $judul = DB::table('notifikasi.notifikasi')->where('pengguna_id', $this->dokter->id)->where('jenis', 'HASIL_LAB_INFORMASI')->pluck('judul')->implode(' ');
        $this->assertStringNotContainsString('Pasien Sintetis', $judul);
        $this->assertStringNotContainsString('Negatif', $judul);
    }

    public function test_adendum_baris_baru_nilai_asli_tetap_penanda_dihitung_ulang(): void
    {
        $item = $this->siapHasil($this->pasien(), 'UJI31-NUM');
        $hasil = (string) $this->input($item, ['nilai' => 15])->assertCreated()->json('data.id');
        // Belum divalidasi → adendum 409.
        $this->assertSesuaiKontrak($this->adendum($hasil, ['nilai' => 3, 'alasan' => 'Koreksi salah ketik'])->assertStatus(409)
            ->assertJsonPath('error.kode', 'status_tidak_sesuai'), 'POST', '/hasil-lab/{id}/adendum');
        $this->validasi($hasil)->assertOk();
        $this->assertCount(1, $this->tugas($hasil));

        $this->travel(5)->minutes();
        $res = $this->adendum($hasil, ['nilai' => 3, 'alasan' => 'Koreksi salah ketik'])->assertCreated()
            ->assertJsonPath('data.nilai', 15.0)->assertJsonPath('data.penanda', null)
            ->assertJsonPath('data.adendum.0.nilai', 3.0)->assertJsonPath('data.adendum.0.penanda', 'll')->assertJsonPath('data.adendum.0.kritis', true)
            ->assertJsonPath('data.adendum.0.alasan', 'Koreksi salah ketik')->assertJsonPath('data.adendum.0.dibuat_oleh.id', $this->analis->id);
        $this->assertSesuaiKontrak($res, 'POST', '/hasil-lab/{id}/adendum');
        $this->assertEquals(15, (float) DB::table('lab.hasil')->where('id', $hasil)->value('nilai'));
        $this->assertSame(1, DB::table('lab.hasil_adendum')->where('hasil_id', $hasil)->count());
        $this->assertAuditTerakhir('lab.hasil_adendum', $hasil);
        // Tugas baru bertanda adendum (objek = adendum).
        $adendum = (string) $res->json('data.adendum.0.id');
        $tugas = $this->tugas($adendum);
        $this->assertCount(1, $tugas);
        $this->assertStringStartsWith('Adendum hasil lab', $tugas[0]->judul);
        $this->assertSame("/penunjang/laboratorium/hasil/$hasil", $tugas[0]->tautan);

        // Alasan wajib, isian sesuai jenis; tanpa izin sahkan → 403.
        $this->assertSesuaiKontrak($this->adendum($hasil, ['nilai' => 3])->assertStatus(422), 'POST', '/hasil-lab/{id}/adendum');
        $this->adendum($hasil, ['nilai_teks' => 'x', 'alasan' => 'Koreksi salah ketik'])->assertStatus(422);
        $this->sebagai($this->dokter);
        $this->adendum($hasil, ['nilai' => 3, 'alasan' => 'Koreksi salah ketik'])->assertForbidden();
    }

    public function test_order_dengan_butir_menunggu_validasi_atau_divalidasi_tidak_bisa_dibatalkan(): void
    {
        $item = $this->siapHasil($this->pasien(), 'UJI31-NUM');
        $permintaan = (string) DB::table('permintaan.permintaan_item')->where('id', $item)->value('permintaan_pemeriksaan_id');
        $hasil = (string) $this->input($item, ['nilai' => 15])->assertCreated()->json('data.id');

        $this->sebagai($this->dokter);
        $episode = (string) DB::table('permintaan.permintaan_pemeriksaan')->where('id', $permintaan)->value('episode_id');
        $this->minta('GET', "/api/v1/episode/$episode/permintaan-pemeriksaan")->assertOk()
            ->assertJsonPath('data.0.item.0.status', 'menunggu_validasi')->assertJsonPath('data.0.dapat.batal', false);
        $tolak = $this->minta('POST', "/api/v1/permintaan-pemeriksaan/$permintaan/batal", ['alasan_batal' => 'Tidak jadi diperiksa'])
            ->assertStatus(409)->assertJsonPath('error.kode', 'status_tidak_sesuai')->assertJsonPath('error.rincian.0.status', 'menunggu_validasi');
        $this->assertSesuaiKontrak($tolak, 'POST', '/permintaan-pemeriksaan/{id}/batal');

        $this->sebagai($this->analis);
        $this->validasi($hasil)->assertOk();
        $this->sebagai($this->dokter);
        $this->minta('POST', "/api/v1/permintaan-pemeriksaan/$permintaan/batal", ['alasan_batal' => 'Tidak jadi diperiksa'])->assertStatus(409);
        $this->assertSame('dipesan', DB::table('permintaan.permintaan_pemeriksaan')->where('id', $permintaan)->value('status'));
    }

    public function test_linimasa_episode_hanya_tervalidasi_paginasi_akses_dan_tugas_selesai_saat_dibuka(): void
    {
        $pasien = $this->pasien();
        $permintaan = $this->order($pasien, ['UJI31-NUM', 'UJI31-TEKS', 'UJI31-KOSONG']);
        [$iKosong, $iNum, $iTeks] = $this->butirOrder($permintaan);
        $this->sebagai($this->analis);
        foreach ([$iKosong, $iNum, $iTeks] as $i) {
            $this->terima($i);
        }
        $hNum = (string) $this->input($iNum, ['nilai' => 15])->assertCreated()->json('data.id');
        $this->validasi($hNum)->assertOk();
        $this->travel(1)->minutes();
        $hTeks = (string) $this->input($iTeks, ['nilai_teks' => 'Positif contoh'])->assertCreated()->json('data.id');
        $this->validasi($hTeks)->assertOk();
        $this->adendum($hTeks, ['nilai_teks' => 'Negatif contoh', 'alasan' => 'Koreksi salah ketik'])->assertCreated();
        // Belum divalidasi: tidak tampil.
        $this->input($iKosong, ['nilai' => 1])->assertCreated();
        $episode = (string) DB::table('permintaan.permintaan_pemeriksaan')->where('id', $permintaan)->value('episode_id');

        $this->assertSame(3, DB::table('inti.tugas')->whereIn('objek_id', [$hNum, $hTeks, ...$this->idAdendum($hTeks)])->where('status', 'terbuka')->count());
        $this->sebagai($this->dokter);
        $res = $this->minta('GET', "/api/v1/episode/$episode/hasil-lab")->assertOk()
            ->assertJsonPath('data.*.id', [$hTeks, $hNum])->assertJsonPath('data.0.adendum.0.penanda', null)
            ->assertJsonPath('data.0.penanda', 'kritis')->assertJsonPath('data.0.dapat.adendum', false);
        $this->assertSesuaiKontrak($res, 'GET', '/episode/{id}/hasil-lab');
        $this->assertStringContainsString('no-store', (string) $res->headers->get('Cache-Control'));
        // Penanggung jawab (pemesan poli) membuka linimasa → tugas tindak lanjut selesai.
        $this->assertSame(0, DB::table('inti.tugas')->whereIn('objek_id', [$hNum, $hTeks, ...$this->idAdendum($hTeks)])->where('status', 'terbuka')->count());

        $hal1 = $this->minta('GET', "/api/v1/episode/$episode/hasil-lab?batas=1")->assertOk()->assertJsonPath('data.*.id', [$hTeks]);
        $this->minta('GET', "/api/v1/episode/$episode/hasil-lab?batas=1&kursor=".$hal1->json('meta.kursor_berikutnya'))->assertOk()
            ->assertJsonPath('data.*.id', [$hNum]);
        $this->assertSesuaiKontrak($this->minta('GET', "/api/v1/episode/$episode/hasil-lab?batas=201")->assertStatus(422), 'GET', '/episode/{id}/hasil-lab');
        $this->assertSesuaiKontrak($this->minta('GET', '/api/v1/episode/'.Str::uuid7().'/hasil-lab')->assertNotFound(), 'GET', '/episode/{id}/hasil-lab');

        // Izin lihat pemeriksaan hanya di unit lain → 403; tanpa izin → 403.
        $unitLain = UnitOrganisasi::query()->create(['kode' => 'UJI31-LAIN', 'nama' => 'Poli lain (uji)', 'jenis' => 'poli', 'berlaku_dari' => '2026-01-01']);
        $luar = $this->buatPengguna();
        $this->tugaskan($luar, $this->buatPeran(['rawat_jalan.pemeriksaan']), cakupan: new Cakupan('unit', $unitLain->id));
        $this->sebagai($luar);
        $this->assertSesuaiKontrak($this->minta('GET', "/api/v1/episode/$episode/hasil-lab")->assertForbidden(), 'GET', '/episode/{id}/hasil-lab');
        $this->sebagai($this->analis);
        $this->minta('GET', "/api/v1/episode/$episode/hasil-lab")->assertForbidden();
    }

    public function test_daftar_kerja_tahap_hasil_memuat_ringkasan_dan_dapat(): void
    {
        $item = $this->siapHasil($this->pasien(), 'UJI31-NUM');
        $res = $this->minta('GET', '/api/v1/lab/daftar-kerja?prioritas=rutin&tahap=hasil')->assertOk()
            ->assertJsonPath('data.0.item.0.status', 'diterima_lab')->assertJsonPath('data.0.item.0.hasil', null)
            ->assertJsonPath('data.0.item.0.dapat.input', true)->assertJsonPath('data.0.item.0.dapat.validasi', false);
        $this->assertSesuaiKontrak($res, 'GET', '/lab/daftar-kerja');
        // Tahap spesimen (bawaan) tidak memuat order yang semua butirnya sudah diterima lab.
        $this->minta('GET', '/api/v1/lab/daftar-kerja?prioritas=rutin')->assertOk()->assertJsonCount(0, 'data');
        $this->minta('GET', '/api/v1/lab/daftar-kerja?prioritas=rutin&tahap=lain')->assertStatus(422);

        $hasil = (string) $this->input($item, ['nilai' => 25])->assertCreated()->json('data.id');
        $res = $this->minta('GET', '/api/v1/lab/daftar-kerja?prioritas=rutin&tahap=hasil')->assertOk()
            ->assertJsonPath('data.0.item.0.status', 'menunggu_validasi')->assertJsonPath('data.0.item.0.hasil.penanda', 'h')
            ->assertJsonPath('data.0.item.0.dapat.validasi', true);
        $this->assertSesuaiKontrak($res, 'GET', '/lab/daftar-kerja');
        $this->validasi($hasil)->assertOk();
        $this->minta('GET', '/api/v1/lab/daftar-kerja?prioritas=rutin&tahap=hasil')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_izin_sahkan_dan_setujui_terpasang(): void
    {
        $izin = DB::table('akses.izin')->whereIn('kode', ['penunjang.laboratorium.sahkan', 'penunjang.laboratorium.setujui'])
            ->get(['kode', 'jenis_aksi', 'sensitif', 'butuh_mfa', 'butuh_kewenangan_klinis'])->keyBy('kode');
        $this->assertSame('sahkan', $izin['penunjang.laboratorium.sahkan']->jenis_aksi);
        $this->assertSame('setujui', $izin['penunjang.laboratorium.setujui']->jenis_aksi);
        $this->assertTrue((bool) $izin['penunjang.laboratorium.setujui']->butuh_kewenangan_klinis);
        $this->assertFalse((bool) $izin['penunjang.laboratorium.sahkan']->butuh_kewenangan_klinis);
        foreach ($izin as $i) {
            $this->assertFalse((bool) $i->sensitif);
            $this->assertFalse((bool) $i->butuh_mfa);
        }
        $jenis = DB::table('inti.jenis_tugas')->where('kode', 'HASIL_LAB_TINDAK_LANJUT')->first();
        $this->assertNotNull($jenis);
        $this->assertSame([1440, -240, 0, 720], [(int) $jenis->batas_menit, (int) $jenis->pengingat_menit, (int) $jenis->teguran_menit, (int) $jenis->eskalasi_menit]);
    }

    private function sebagai(Pengguna $pengguna): void
    {
        if ($this->sesi !== $pengguna->id) {
            $this->masukSebagai($pengguna);
            $this->sesi = $pengguna->id;
        }
    }

    private function butir(string $kode): string
    {
        $id = (string) Str::uuid7();
        DB::table('keuangan.tarif')->insert(['id' => $id, 'kode' => $kode]);
        DB::table('keuangan.tarif_versi')->insert(['id' => (string) Str::uuid7(), 'tarif_id' => $id, 'versi' => 1, 'nama' => "Butir sintetis $kode",
            'kelompok' => 'Uji', 'kategori' => 'lab', 'status' => 'aktif', 'berlaku_dari' => '2026-01-01', 'dasar' => 'Uji']);

        return $id;
    }

    /** @param  array<string, mixed>  $isi */
    private function pemeriksaan(string $kode, array $isi = []): string
    {
        $id = (string) Str::uuid7();
        DB::table('lab.pemeriksaan')->insert(['id' => $id, 'tarif_id' => $this->tarif[$kode], 'jenis_hasil' => 'numerik', ...$isi]);

        return $id;
    }

    private function pemeriksaanBaru(string $kode): string
    {
        $this->tarif[$kode] = $this->butir($kode);

        return $this->periksa[$kode] = $this->pemeriksaan($kode);
    }

    /** @param  array<string, mixed>  $isi  contoh sintetis, bukan nilai klinis */
    private function rujukan(string $kode, array $isi): void
    {
        DB::table('lab.nilai_rujukan')->insert(['id' => (string) Str::uuid7(), 'pemeriksaan_id' => $this->periksa[$kode], ...$isi]);
    }

    /** @param  array<string, mixed>  $isi */
    private function assertTumpangTindihDitolak(array $isi): void
    {
        try {
            DB::transaction(fn () => $this->rujukan('UJI31-UMUR', $isi));
            $this->fail('Rentang tumpang tindih seharusnya ditolak.');
        } catch (QueryException $e) {
            $this->assertSame('23P01', $e->getCode());
        }
    }

    /**
     * Order dari kunjungan poli dilayani pasien itu; sesudahnya sesi dokter pemesan.
     *
     * @param  list<string>  $kode
     */
    private function order(Pasien $pasien, array $kode, string $prioritas = 'rutin'): string
    {
        $this->masukSebagai($this->petugasPendaftaran());
        $this->sesi = null;
        $k = DB::table('kunjungan.kunjungan')->where('pasien_id', $pasien->id)->where('jenis_kunjungan', 'poliklinik')->value('id')
            ?? (string) $this->daftarKunjungan($pasien, $this->jadwal)->assertCreated()->json('data.id');
        DB::table('kunjungan.kunjungan')->where('id', $k)->update(['status' => 'dilayani']);
        $this->sebagai($this->dokter);

        return (string) $this->minta('POST', "/api/v1/kunjungan/$k/permintaan-pemeriksaan", [
            'jenis' => 'lab', 'prioritas' => $prioritas, 'diagnosis_kerja' => 'Indikasi sintetis uji', 'alasan_ganda' => 'Uji sintetis berulang',
            'item' => array_map(fn ($x) => ['tarif_id' => $this->tarif[$x]], $kode),
        ])->assertCreated()->json('data.id');
    }

    /** @return list<string> id butir urut kode */
    private function butirOrder(string $permintaan): array
    {
        return DB::table('permintaan.permintaan_item')->where('permintaan_pemeriksaan_id', $permintaan)->orderBy('kode_item')
            ->pluck('id')->map(fn ($v) => (string) $v)->all();
    }

    private function terima(string $item): void
    {
        $s = (string) $this->minta('POST', "/api/v1/permintaan-item/$item/spesimen", ['jenis' => 'Darah vena', 'lokasi_pengambilan' => 'instalasi'])
            ->assertCreated()->json('data.id');
        $this->minta('POST', "/api/v1/spesimen/$s/terima")->assertOk();
    }

    /** Satu butir order sampai diterima lab; sesudahnya sesi analis. */
    private function siapHasil(Pasien $pasien, string $kode, string $prioritas = 'rutin'): string
    {
        [$item] = $this->butirOrder($this->order($pasien, [$kode], $prioritas));
        $this->sebagai($this->analis);
        $this->terima($item);

        return $item;
    }

    /** @param  array<string, mixed>  $isian */
    private function input(string $item, array $isian): TestResponse
    {
        return $this->minta('POST', "/api/v1/permintaan-item/$item/hasil", $isian);
    }

    private function validasi(string $hasil): TestResponse
    {
        return $this->minta('POST', "/api/v1/hasil-lab/$hasil/validasi");
    }

    /** @param  array<string, mixed>  $isian */
    private function adendum(string $hasil, array $isian): TestResponse
    {
        return $this->minta('POST', "/api/v1/hasil-lab/$hasil/adendum", $isian);
    }

    /** @return list<object> tugas tindak lanjut untuk objek itu */
    private function tugas(string $objek): array
    {
        return DB::table('inti.tugas AS t')->join('inti.jenis_tugas AS j', 'j.id', '=', 't.jenis_tugas_id')
            ->where('j.kode', 'HASIL_LAB_TINDAK_LANJUT')->where('t.objek_id', $objek)->get(['t.*'])->all();
    }

    /** @return list<string> */
    private function idAdendum(string $hasil): array
    {
        return DB::table('lab.hasil_adendum')->where('hasil_id', $hasil)->pluck('id')->map(fn ($v) => (string) $v)->all();
    }

    private function notifikasiInformasi(Pengguna $pengguna): int
    {
        return DB::table('notifikasi.notifikasi')->where('pengguna_id', $pengguna->id)->where('jenis', 'HASIL_LAB_INFORMASI')->count();
    }

    private function kunjunganItem(string $item): string
    {
        return (string) DB::table('permintaan.permintaan_item AS i')->join('permintaan.permintaan_pemeriksaan AS p', 'p.id', '=', 'i.permintaan_pemeriksaan_id')
            ->where('i.id', $item)->value('p.kunjungan_id');
    }

    /** @param  list<string>  $izin */
    private function peranIzin(array $izin): Peran
    {
        $peran = $this->buatPeran([]);
        foreach (Izin::query()->whereIn('kode', $izin)->get(['id', 'menu_id']) as $i) {
            PeranMenu::query()->firstOrCreate(['peran_id' => $peran->id, 'menu_id' => $i->menu_id]);
            PeranIzin::query()->create(['peran_id' => $peran->id, 'izin_id' => $i->id]);
        }

        return $peran;
    }

    /**
     * Dokter Sp.PK sintetis ber-SIP berlaku (syarat lapis 5 izin setujui).
     *
     * @param  list<string>  $izin
     */
    private function akunSpk(array $izin): Pengguna
    {
        $akun = $this->buatPengguna(['pegawai_id' => $this->dokter()->id]);
        $this->tugaskan($akun, $this->peranIzin($izin));

        return $akun;
    }

    /**
     * Kunjungan rawat inap SINTETIS (DPJP = pegawai itu) dengan penempatan TT aktif di bangsal uji.
     *
     * @return array{0: string, 1: string} id kunjungan rawat inap, id unit ruang
     */
    private function rawatInap(Pasien $pasien, string $pegawaiId): array
    {
        $irna = UnitOrganisasi::query()->create(['kode' => 'UJI31-IRNA', 'nama' => 'Instalasi Rawat Inap (uji)', 'jenis' => 'instalasi', 'berlaku_dari' => '2026-01-01']);
        $ruang = UnitOrganisasi::query()->create(['kode' => 'UJI31-RA', 'nama' => 'Ruang uji', 'jenis' => 'ruang', 'induk_id' => $irna->id, 'berlaku_dari' => '2026-01-01']);
        $kelas = KelasRawat::query()->create(['kode' => 'UJI31-K3', 'nama' => 'Kelas uji', 'berlaku_dari' => '2026-01-01'])->id;
        $bangsal = Bangsal::query()->create(['kode' => 'UJI31-BA', 'nama' => 'Bangsal UJI31', 'unit_organisasi_id' => $ruang->id, 'status' => 'aktif'])->id;
        $gedung = $this->lokasi(null, 'gedung', 'UJI31', 'UJI31');
        $ruangLokasi = $this->lokasi($gedung, 'ruang', '101', 'UJI31-101', 'rawat_inap');
        $kamar = Kamar::query()->create(['bangsal_id' => $bangsal, 'lokasi_id' => $ruangLokasi])->id;
        $tt = TempatTidur::query()->create([
            'kamar_id' => $kamar, 'lokasi_id' => $this->lokasi($ruangLokasi, 'tempat_tidur', '1', 'UJI31-101-1'), 'kode' => 'UJI31-101-1',
            'kelas_rawat_id' => $kelas, 'berlaku_dari' => '2026-01-01',
        ])->id;
        $episode = (string) Str::uuid7();
        DB::table('kunjungan.episode')->insert(['id' => $episode, 'pasien_id' => $pasien->id, 'jenis' => 'rawat_inap', 'mulai_tanggal' => self::SENIN, 'status' => 'aktif']);
        $ri = (string) Str::uuid7();
        DB::table('kunjungan.kunjungan')->insert(['id' => $ri, 'nomor' => 'RI-UJI31-'.Str::random(6), 'pasien_id' => $pasien->id, 'episode_id' => $episode,
            'jenis_kunjungan' => 'rawat_inap', 'pegawai_id' => $pegawaiId, 'unit_id' => $ruang->id, 'tanggal' => self::SENIN,
            'cara_bayar' => 'non_jkn', 'status' => 'dilayani']);
        DB::table('kunjungan.penempatan_tempat_tidur')->insert([
            'id' => (string) Str::uuid7(), 'tempat_tidur_id' => $tt, 'kunjungan_id' => $ri, 'pasien_id' => $pasien->id,
            'mulai_pada' => now(), 'ditempatkan_oleh' => $this->dokter->id,
        ]);

        return [$ri, $ruang->id];
    }

    private function kunjunganIgd(Pasien $pasien, string $pegawaiId): void
    {
        $episode = (string) Str::uuid7();
        DB::table('kunjungan.episode')->insert(['id' => $episode, 'pasien_id' => $pasien->id, 'jenis' => 'igd_lebih_6_jam', 'mulai_tanggal' => self::SENIN, 'status' => 'aktif']);
        DB::table('kunjungan.kunjungan')->insert(['id' => (string) Str::uuid7(), 'nomor' => 'IGD-UJI31-'.Str::random(6), 'pasien_id' => $pasien->id,
            'episode_id' => $episode, 'jenis_kunjungan' => 'igd', 'pegawai_id' => $pegawaiId, 'tiba_pada' => now(), 'tanggal' => self::SENIN,
            'cara_bayar' => 'non_jkn', 'status' => 'dilayani']);
    }

    private function lokasi(?string $induk, string $jenis, string $kode, string $kodeLengkap, ?string $fungsi = null): string
    {
        return Lokasi::query()->create([
            'induk_id' => $induk, 'jenis' => $jenis, 'kode' => $kode, 'kode_lengkap' => $kodeLengkap, 'nama' => "Lokasi $kodeLengkap",
            'fungsi' => $fungsi, 'status' => 'aktif', 'status_berlaku_dari' => '2026-01-01',
        ])->id;
    }
}
