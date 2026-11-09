<?php

namespace Tests\Feature\Pendaftaran;

use App\Modules\Akses\Domain\Cakupan;
use App\Modules\Akses\Models\Izin;
use App\Modules\Akses\Models\Menu;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\Akses\Models\PeranIzin;
use App\Modules\Akses\Models\PeranMenu;
use App\Modules\Pendaftaran\Events\DisposisiDicatat;
use App\Modules\Pendaftaran\Events\DisposisiRawatInap;
use App\Modules\Pendaftaran\Models\Kunjungan;
use App\Modules\Pendaftaran\Models\Pasien;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\AksesUji;
use Tests\Support\KlienSpa;
use Tests\Support\KontrakApi;
use Tests\Support\KunjunganUji;
use Tests\TestCase;

/**
 * Tiket 2.25: papan pasien IGD /igd/papan (polling ETag) dan penempatan area /kunjungan/{id}/area-igd (kontrak v0.34.0).
 * Area dan pasien sintetis berlabel contoh. Jam dibekukan (KunjunganUji: Senin 2 Nov 2026 07.00 zona RS); sesudah
 * memajukan jam lebih dari batas diam sesi (15 menit) tes masuk ulang.
 */
class PapanIgdTest extends TestCase
{
    use AksesUji, KlienSpa, KontrakApi, KunjunganUji;

    private const URL = '/api/v1/igd/papan';

    private Pengguna $dokter;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake([DisposisiDicatat::class, DisposisiRawatInap::class]);
        $this->siapkanKunjungan();
        $this->dokter = $this->akunDokter($this->dokter());
        $this->tugaskan($this->dokter, $this->buatPeran(['igd.pendaftaran', 'igd.triase', 'igd.disposisi', 'igd.papan']));
    }

    public function test_urut_triase_lalu_jam_tiba_isi_baris_dan_etag_304(): void
    {
        // Angka bawaan migrasi: batas tanggap 5 menit (PRD), ambang mendekati 6 jam kosong.
        $this->masukSebagai($this->dokter);
        $a = $this->daftarIgd($this->pasien(), 'Sesak napas (uji sintetis)');
        $this->travel(1)->minutes();
        $b = $this->daftarIgd($this->pasien());
        $this->triase($b, 'hijau');
        $this->travel(1)->minutes();
        $c = $this->daftarIgd($this->pasien(), 'Keluhan saat daftar (uji sintetis)');
        $this->triase($c, 'merah', 'Nyeri dada kiri (uji sintetis)');
        $this->travel(1)->minutes();
        $d = $this->daftarIgd($this->pasien());
        $this->triase($d, 'kuning');
        $this->travel(1)->minutes();
        $e = $this->daftarIgd($this->pasien());
        $this->triase($e, 'merah');
        $this->travel(1)->minutes();
        $f = $this->daftarIgd($this->pasien());
        $this->triase($f, 'hitam');
        // Ulang triase: yang berlaku = terbaru (hijau → kuning).
        $this->triase($b, 'kuning');
        $this->travel(10)->seconds();

        $papan = $this->minta('GET', self::URL)->assertOk()
            // Belum ditriase paling atas; merah → kuning → hijau → hitam; dalam kategori yang sama jam tiba terlama dulu.
            ->assertJsonPath('data.*.kunjungan_id', [$a, $c, $e, $b, $d, $f])
            ->assertJsonPath('data.0.triase', null)->assertJsonPath('data.1.triase.kategori', 'merah')
            ->assertJsonPath('data.3.triase.kategori', 'kuning')
            ->assertJsonPath('data.0.keluhan', 'Sesak napas (uji sintetis)')->assertJsonPath('data.1.keluhan', 'Nyeri dada kiri (uji sintetis)')
            // 07.05.10: menit dibulatkan ke atas (5 menit 10 detik → 6; 10 detik → 1).
            ->assertJsonPath('data.0.lama_di_igd_menit', 6)->assertJsonPath('data.5.lama_di_igd_menit', 1)
            // Batas bawaan 5 menit: A (5 menit 10 detik) lewat batas, F (10 detik) menunggu; tanpa ambang 6 jam semua aman.
            ->assertJsonPath('data.0.tanggap.tanda', 'lewat_batas')->assertJsonPath('data.5.tanggap.tanda', 'menunggu')
            ->assertJsonPath('data.*.tanda_lama_igd', array_fill(0, 6, 'aman'))
            ->assertJsonPath('data.0.status', 'terdaftar')->assertJsonPath('data.0.area', null)->assertJsonPath('data.0.disposisi_menunggu', null)
            ->assertJsonPath('meta.terpotong', false)->assertJsonPath('meta.area', [])
            ->assertJsonPath('meta.aturan', ['batas_tanggap_menit' => 5, 'batas_lama_igd_menit' => 360, 'peringatan_lama_igd_menit' => null]);
        $this->assertSesuaiKontrak($papan, 'GET', '/igd/papan');
        $this->assertStringContainsString('no-store', (string) $papan->headers->get('Cache-Control'));
        // Dua penanda identitas pasien untuk petugas berizin; NIK dan alamat tidak dikirim.
        $pasien = Pasien::query()->findOrFail(Kunjungan::query()->findOrFail($a)->pasien_id);
        $this->assertSame(['id' => $pasien->id, 'no_rm' => $pasien->no_rm, 'nama' => $pasien->nama, 'jenis_kelamin' => 'perempuan', 'tanggal_lahir' => '1990-01-01'],
            $papan->json('data.0.pasien'));
        $this->assertTrue(Carbon::parse((string) $papan->json('data.0.tiba_pada'))->equalTo(Carbon::parse(self::SENIN.' 07:00', (string) config('instalasi.zona_waktu'))));
        $this->assertTrue(Carbon::parse((string) $papan->json('meta.waktu_server'))->equalTo(now()));

        // Tidak berubah (07.05.20: menit dan tanda sama) → 304 tanpa isi; waktu_server tidak ikut sidik ETag.
        $etag = (string) $papan->headers->get('ETag');
        $this->travel(10)->seconds();
        $tetap = $this->mintaDenganEtag($etag)->assertStatus(304);
        $this->assertSame('', (string) $tetap->getContent());
        $this->assertSame($etag, $tetap->headers->get('ETag'));
        $this->assertStringContainsString('no-store', (string) $tetap->headers->get('Cache-Control'));
        $this->assertSesuaiKontrak($tetap, 'GET', '/igd/papan');

        // Isi berubah (triase baru, atau menit lama di IGD bertambah) → 200 dengan ETag baru.
        $this->triase($a, 'hijau');
        $baru = $this->mintaDenganEtag($etag)->assertOk()->assertJsonPath('data.*.kunjungan_id', [$c, $e, $b, $d, $a, $f]);
        $this->assertNotSame($etag, $baru->headers->get('ETag'));
        $etag = (string) $baru->headers->get('ETag');
        $this->travel(1)->minutes();
        // 07.06.20: C tiba 07.02 → 4 menit 20 detik → 5.
        $this->mintaDenganEtag($etag)->assertOk()->assertJsonPath('data.0.lama_di_igd_menit', 5);
    }

    public function test_tanda_waktu_tanggap_dari_asesmen_dokter_dan_data_induk(): void
    {
        // Angka bawaan migrasi: batas 5 menit, tanpa tahap peringatan (keputusan pemilik 9 Nov 2026).
        $this->masukSebagai($this->dokter);
        $p = $this->daftarIgd($this->pasien());
        $tanggap = fn () => $this->minta('GET', self::URL)->assertOk()->json('data.0.tanggap');

        $this->assertSame(['asesmen_dokter_pada' => null, 'menit' => null, 'menunggu_asesmen' => true, 'tanda' => 'menunggu'], $tanggap());
        // Tanda per detik: 07.05.00 tepat batas → masih menunggu; 07.05.01 → lewat batas.
        $tanda = [];
        foreach ([300, 1] as $maju) {
            $this->travelTo(now()->addSeconds($maju));
            $tanda[] = $tanggap()['tanda'];
        }
        $this->assertSame(['menunggu', 'lewat_batas'], $tanda);

        // Asesmen perawat bukan tanggap dokter.
        $this->asesmen($p, 'selesai', 'perawat');
        $this->minta('GET', self::URL)->assertOk()->assertJsonPath('data.0.tanggap.menunggu_asesmen', true)
            ->assertJsonPath('data.0.tanggap.asesmen_dokter_pada', null);

        // Dokter mulai mengisi (draf) pukul 07.12: tanggap berhenti di sini (keputusan pemilik 9 Nov 2026).
        $this->travelTo(Carbon::parse(self::SENIN.' 07:12', (string) config('instalasi.zona_waktu')));
        $this->masukSebagai($this->dokter);
        $this->asesmen($p, 'draf');
        $mulai = now()->copy();
        $selesai = (array) $this->minta('GET', self::URL)->assertOk()->json('data.0.tanggap');
        $this->assertSame([12, false, 'terlambat'], [$selesai['menit'], $selesai['menunggu_asesmen'], $selesai['tanda']]);
        $this->assertTrue(Carbon::parse((string) $selesai['asesmen_dokter_pada'])->equalTo($mulai));

        // Pasien kedua: dokter mulai mengisi tepat 5 menit sesudah tiba → tercapai. Catatan dokter berikutnya untuk
        // pasien pertama tidak menggeser jam tanggapnya (paling awal).
        $q = $this->daftarIgd($this->pasien());
        $this->travel(5)->minutes();
        $this->asesmen($q, 'draf');
        $this->asesmen($p);
        $papan = $this->minta('GET', self::URL)->assertOk();
        $this->assertSesuaiKontrak($papan, 'GET', '/igd/papan');
        $this->assertSame([12, 'terlambat', 5, 'tercapai'], [$papan->json('data.0.tanggap.menit'), $papan->json('data.0.tanggap.tanda'),
            $papan->json('data.1.tanggap.menit'), $papan->json('data.1.tanggap.tanda')]);
        $this->assertTrue(Carbon::parse((string) $papan->json('data.0.tanggap.asesmen_dokter_pada'))->equalTo($mulai));
    }

    public function test_tanda_mendekati_dan_lebih_6_jam(): void
    {
        $this->masukSebagai($this->dokter);
        $p = $this->daftarIgd($this->pasien());
        // Ambang mendekati 6 jam: kosong (bawaan migrasi, tanda mati) atau diisi RS (45 menit).
        $baris = function (string $jam, ?int $ambang) {
            DB::table('inti.aturan_papan_igd')->update(['peringatan_lama_igd_menit' => $ambang]);
            $this->travelTo(Carbon::parse(self::SENIN.' '.$jam, (string) config('instalasi.zona_waktu')));
            $this->masukSebagai($this->dokter);
            $b = (array) $this->minta('GET', self::URL)->assertOk()->json('data.0');

            return [$b['tanda_lama_igd'], $b['lebih_6_jam'], $b['lama_di_igd_menit']];
        };

        $hasil = [
            $baris('07:00', null), $baris('12:14:59', null), $baris('12:14:59', 45),
            // ≤ 45 menit sebelum 6 jam (FR-00-06); ambang kosong → tidak pernah mendekati.
            $baris('12:15', 45), $baris('12:15', null), $baris('13:00', 45), $baris('13:00', null),
            // Lewat sedetik dari 6 jam: lebih 6 jam, menit dibulatkan ke atas (361 > 360 selaras dengan tanda).
            $baris('13:00:01', 45), $baris('13:00:01', null),
        ];
        $this->assertSame([
            ['aman', false, 0], ['aman', false, 315], ['aman', false, 315],
            ['mendekati_6_jam', false, 315], ['aman', false, 315], ['mendekati_6_jam', false, 360], ['aman', false, 360],
            ['lebih_6_jam', true, 361], ['lebih_6_jam', true, 361],
        ], $hasil);
        // Sama dengan tanda di daftar kunjungan IGD (2.23).
        $this->minta('GET', '/api/v1/kunjungan-igd')->assertJsonPath('data.0.id', $p)->assertJsonPath('data.0.lebih_6_jam', true);
    }

    public function test_selesai_disposisi_hilang_menunggu_kamar_tampil_dengan_sejak(): void
    {
        $this->masukSebagai($this->dokter);
        $pulang = $this->daftarIgd($this->pasien());
        $inap = $this->daftarIgd($this->pasien());
        $batal = $this->daftarIgd($this->pasien());
        $this->assertCount(3, $this->minta('GET', self::URL)->assertOk()->json('data'));

        $this->travel(5)->minutes();
        $this->disposisi($pulang, ['tujuan' => 'pulang'])->assertCreated();
        $this->disposisi($inap, ['tujuan' => 'rawat_inap', 'serah_terima' => 'Infus terpasang (uji sintetis)'])->assertCreated();
        $sejak = now()->copy();
        Kunjungan::query()->whereKey($batal)->update(['status' => 'batal', 'alasan_batal' => 'Salah pilih pasien', 'dibatalkan_pada' => now()]);

        $this->travel(10)->minutes();
        $papan = $this->minta('GET', self::URL)->assertOk()->assertJsonPath('data.*.kunjungan_id', [$inap])
            ->assertJsonPath('data.0.disposisi_menunggu.status', 'menunggu_kamar');
        $this->assertSesuaiKontrak($papan, 'GET', '/igd/papan');
        $this->assertTrue(Carbon::parse((string) $papan->json('data.0.disposisi_menunggu.sejak'))->equalTo($sejak));
    }

    public function test_data_induk_area_dan_penempatan_pasien(): void
    {
        $admin = $this->buatPengguna();
        $this->tugaskan($admin, $this->buatPeran(['master_data.papan_igd']));
        $this->masukSebagai($admin);
        $dasar = 'SK Direktur contoh 7/2026';
        $resus = $this->minta('POST', '/api/v1/data-induk/area-igd', ['kode' => 'CONTOH-R', 'nama' => 'Resusitasi (contoh)', 'urutan' => 1, 'dasar_perubahan' => $dasar])
            ->assertCreated()->assertJsonPath('data.status', 'aktif')->assertJsonPath('data.versi', '0');
        $this->assertSesuaiKontrak($resus, 'POST', '/data-induk/area-igd');
        $r = (string) $resus->json('data.id');
        $this->assertStringContainsString($dasar, (string) $this->assertAuditTerakhir('master_data.area_igd_dibuat', $r)->sesudah);
        $o = (string) $this->minta('POST', '/api/v1/data-induk/area-igd', ['kode' => 'CONTOH-O', 'nama' => 'Observasi (contoh)', 'urutan' => 2, 'dasar_perubahan' => $dasar])
            ->assertCreated()->json('data.id');
        $h = (string) $this->minta('POST', '/api/v1/data-induk/area-igd', ['kode' => 'CONTOH-H', 'nama' => 'Hijau (contoh)', 'urutan' => 3, 'dasar_perubahan' => $dasar])
            ->assertCreated()->json('data.id');
        $this->minta('POST', '/api/v1/data-induk/area-igd', ['kode' => 'CONTOH-R', 'nama' => 'Ganda', 'dasar_perubahan' => $dasar])
            ->assertStatus(422)->assertJsonPath('error.rincian.0.kolom', 'kode');
        $this->minta('POST', '/api/v1/data-induk/area-igd', ['kode' => 'kecil', 'nama' => 'Kode huruf kecil', 'dasar_perubahan' => $dasar])->assertStatus(422);
        $daftar = $this->minta('GET', '/api/v1/data-induk/area-igd')->assertOk()->assertJsonPath('data.*.kode', ['CONTOH-H', 'CONTOH-O', 'CONTOH-R']);
        $this->assertSesuaiKontrak($daftar, 'GET', '/data-induk/area-igd');

        $ubah = $this->mintaDenganVersi('PATCH', "/api/v1/data-induk/area-igd/$h", '0', ['status' => 'nonaktif', 'dasar_perubahan' => $dasar])
            ->assertOk()->assertJsonPath('data.status', 'nonaktif')->assertJsonPath('data.versi', '1');
        $this->assertSesuaiKontrak($ubah, 'PATCH', '/data-induk/area-igd/{id}');
        $this->assertStringContainsString('aktif', (string) $this->assertAuditTerakhir('master_data.area_igd_diubah', $h)->sebelum);
        $this->mintaDenganVersi('PATCH', "/api/v1/data-induk/area-igd/$h", '0', ['nama' => 'Basi', 'dasar_perubahan' => $dasar])->assertStatus(412);
        $this->minta('PATCH', "/api/v1/data-induk/area-igd/$h", ['nama' => 'Tanpa versi', 'dasar_perubahan' => $dasar])->assertStatus(428);
        $this->mintaDenganVersi('PATCH', "/api/v1/data-induk/area-igd/$h", '1', ['dasar_perubahan' => $dasar])->assertStatus(422);
        $this->mintaDenganVersi('PATCH', '/api/v1/data-induk/area-igd/'.Str::uuid7(), '0', ['nama' => 'Tidak ada', 'dasar_perubahan' => $dasar])->assertNotFound();
        $this->minta('GET', '/api/v1/data-induk/area-igd?status=aktif')->assertOk()->assertJsonPath('data.*.kode', ['CONTOH-O', 'CONTOH-R']);

        // Penempatan pasien oleh petugas IGD.
        $this->masukSebagai($this->dokter);
        $k = $this->daftarIgd($this->pasien());
        $lain = $this->daftarIgd($this->pasien());
        $tempat = $this->tempatkan($k, $r)->assertOk()->assertJsonPath('data.kunjungan_id', $k)
            ->assertJsonPath('data.area', ['id' => $r, 'kode' => 'CONTOH-R', 'nama' => 'Resusitasi (contoh)'])
            ->assertJsonPath('data.ditempatkan_oleh.id', $this->dokter->id);
        $this->assertSesuaiKontrak($tempat, 'PUT', '/kunjungan/{id}/area-igd');
        $this->assertStringContainsString('no-store', (string) $tempat->headers->get('Cache-Control'));
        $papanId = (string) DB::table('kunjungan.papan_igd')->where('kunjungan_id', $k)->value('id');
        $audit = $this->assertAuditTerakhir('kunjungan.area_igd_ditempatkan', $papanId);
        $this->assertNull($audit->sebelum);

        $papan = $this->minta('GET', self::URL)->assertOk()->assertJsonPath('data.0.area.kode', 'CONTOH-R')->assertJsonPath('data.1.area', null)
            // Hanya area aktif, urut `urutan`.
            ->assertJsonPath('meta.area.*.kode', ['CONTOH-R', 'CONTOH-O']);
        $this->assertSesuaiKontrak($papan, 'GET', '/igd/papan');
        $this->minta('GET', self::URL."?area_id=$r")->assertOk()->assertJsonPath('data.*.kunjungan_id', [$k]);
        $this->minta('GET', self::URL."?area_id=$o")->assertOk()->assertJsonCount(0, 'data');

        // Pindah area: baris yang sama, audit menyimpan area sebelumnya; area sama tidak menambah audit.
        $this->travel(2)->minutes();
        $this->tempatkan($k, $o)->assertOk()->assertJsonPath('data.area.kode', 'CONTOH-O');
        $this->assertSame(1, DB::table('kunjungan.papan_igd')->where('kunjungan_id', $k)->count());
        $pindah = $this->assertAuditTerakhir('kunjungan.area_igd_ditempatkan', $papanId);
        $this->assertSame($r, json_decode((string) $pindah->sebelum, true)['area_igd_id'] ?? null);
        $this->assertStringNotContainsString(Pasien::query()->findOrFail(Kunjungan::query()->findOrFail($k)->pasien_id)->nama, (string) $pindah->sesudah);
        $jumlahAudit = DB::table('audit.log_aktivitas')->where('aksi', 'kunjungan.area_igd_ditempatkan')->count();
        $this->tempatkan($k, $o)->assertOk();
        $this->assertSame($jumlahAudit, DB::table('audit.log_aktivitas')->where('aksi', 'kunjungan.area_igd_ditempatkan')->count());

        // Area nonaktif atau tak dikenal → 422; kunjungan tak aktif → 409; tak ada → 404.
        $this->tempatkan($lain, $h)->assertStatus(422)->assertJsonPath('error.rincian.0.kolom', 'area_igd_id');
        $this->tempatkan($lain, (string) Str::uuid7())->assertStatus(422)->assertJsonPath('error.rincian.0.kolom', 'area_igd_id');
        $this->tempatkan($lain, 'bukan-uuid')->assertStatus(422);
        Kunjungan::query()->whereKey($lain)->update(['status' => 'selesai']);
        $this->assertSesuaiKontrak($this->tempatkan($lain, $r)->assertStatus(409)->assertJsonPath('error.kode', 'status_tidak_sesuai'), 'PUT', '/kunjungan/{id}/area-igd');
        $this->assertSesuaiKontrak($this->tempatkan((string) Str::uuid7(), $r)->assertNotFound(), 'PUT', '/kunjungan/{id}/area-igd');
        $this->tempatkan('bukan-uuid', $r)->assertNotFound();
        $this->assertFalse(DB::table('kunjungan.papan_igd')->where('kunjungan_id', $lain)->exists());
    }

    public function test_data_induk_batas_waktu_papan(): void
    {
        $admin = $this->buatPengguna();
        $this->tugaskan($admin, $this->buatPeran(['master_data.papan_igd']));
        $this->masukSebagai($admin);
        $url = '/api/v1/data-induk/aturan-papan-igd';
        $dasar = 'SK Direktur contoh 8/2026';

        // Angka awal dari migrasi: batas tanggap 5 menit (FR-03-04/05), ambang mendekati 6 jam kosong (diisi RS).
        $awal = $this->minta('GET', $url)->assertOk()->assertJsonPath('data.batas_tanggap_menit', 5)
            ->assertJsonPath('data.peringatan_lama_igd_menit', null)->assertJsonMissingPath('data.peringatan_tanggap_menit');
        $this->assertSesuaiKontrak($awal, 'GET', '/data-induk/aturan-papan-igd');
        $versi = (string) $awal->json('data.versi');

        $ubah = $this->mintaDenganVersi('PATCH', $url, $versi, ['batas_tanggap_menit' => 15, 'peringatan_lama_igd_menit' => 45, 'dasar_perubahan' => $dasar])
            ->assertOk()->assertJsonPath('data.batas_tanggap_menit', 15)->assertJsonPath('data.peringatan_lama_igd_menit', 45);
        $this->assertSesuaiKontrak($ubah, 'PATCH', '/data-induk/aturan-papan-igd');
        $id = (string) DB::table('inti.aturan_papan_igd')->value('id');
        $audit = $this->assertAuditTerakhir('master_data.aturan_papan_igd_diubah', $id);
        $this->assertSame(5, json_decode((string) $audit->sebelum, true)['batas_tanggap_menit'] ?? null);
        $this->assertStringContainsString($dasar, (string) $audit->sesudah);
        $baru = (string) $ubah->json('data.versi');

        $this->mintaDenganVersi('PATCH', $url, $versi, ['batas_tanggap_menit' => 20, 'dasar_perubahan' => $dasar])->assertStatus(412);
        $this->minta('PATCH', $url, ['batas_tanggap_menit' => 20, 'dasar_perubahan' => $dasar])->assertStatus(428);
        // Isian salah → 422. Tahap peringatan tanggap tidak ada (keputusan pemilik 9 Nov 2026): `peringatan_tanggap_menit`
        // bukan perubahan, jadi tanpa angka lain → 422.
        foreach ([['batas_tanggap_menit' => 0], ['peringatan_lama_igd_menit' => 360], ['peringatan_lama_igd_menit' => 0],
            ['batas_tanggap_menit' => 'lima'], ['batas_tanggap_menit' => null], ['peringatan_tanggap_menit' => 3], []] as $salah) {
            $this->mintaDenganVersi('PATCH', $url, $baru, [...$salah, 'dasar_perubahan' => $dasar])->assertStatus(422);
        }

        // Papan memakai angka baru; ambang bisa dikosongkan lagi (tanda mendekati 6 jam mati).
        $this->masukSebagai($this->dokter);
        $this->minta('GET', self::URL)->assertOk()->assertJsonPath('meta.aturan.batas_tanggap_menit', 15)->assertJsonPath('meta.aturan.peringatan_lama_igd_menit', 45);
        $this->masukSebagai($admin);
        $this->mintaDenganVersi('PATCH', $url, $baru, ['peringatan_lama_igd_menit' => null, 'dasar_perubahan' => $dasar])
            ->assertOk()->assertJsonPath('data.peringatan_lama_igd_menit', null);
    }

    public function test_izin_cakupan_403_sebelum_validasi_dan_data_pasien_hanya_untuk_pemegang_izin(): void
    {
        $this->masukSebagai($this->dokter);
        $k = $this->daftarIgd($this->pasien(), 'Keluhan rahasia (uji sintetis)');
        $nama = Pasien::query()->findOrFail(Kunjungan::query()->findOrFail($k)->pasien_id)->nama;
        $area = (string) Str::uuid7();
        DB::table('inti.area_igd')->insert(['id' => $area, 'kode' => 'CONTOH-A', 'nama' => 'Area contoh']);
        // Pemegang izin: isian ngawur → 422.
        $this->minta('GET', self::URL.'?area_id=ngawur')->assertStatus(422);

        // Tanpa izin papan (termasuk pendaftaran IGD saja, atau izin papan bercakupan unit): 403 sebelum isian dibaca,
        // tanpa data pasien.
        $pendaftaran = $this->buatPengguna();
        $this->tugaskan($pendaftaran, $this->buatPeran(['igd.pendaftaran', 'igd.triase']));
        $unit = $this->buatPengguna();
        $this->tugaskan($unit, $this->buatPeran(['igd.papan']), cakupan: new Cakupan('unit', $this->poli->unit_organisasi_id));
        foreach ([$this->buatPengguna(), $pendaftaran, $unit] as $akun) {
            $this->masukSebagai($akun);
            $tolak = $this->minta('GET', self::URL.'?area_id=ngawur')->assertForbidden();
            $this->assertSesuaiKontrak($tolak, 'GET', '/igd/papan');
            $this->assertStringNotContainsString($nama, (string) $tolak->getContent());
            $this->assertStringNotContainsString('rahasia', (string) $tolak->getContent());
            $this->assertSesuaiKontrak($this->minta('PUT', "/api/v1/kunjungan/$k/area-igd", [])->assertForbidden(), 'PUT', '/kunjungan/{id}/area-igd');
            $this->minta('GET', '/api/v1/data-induk/area-igd?status=ngawur')->assertForbidden();
            $this->minta('POST', '/api/v1/data-induk/area-igd', [])->assertForbidden();
            $this->minta('GET', '/api/v1/data-induk/aturan-papan-igd')->assertForbidden();
            $this->mintaDenganVersi('PATCH', '/api/v1/data-induk/aturan-papan-igd', '0', [])->assertForbidden();
            $this->mintaDenganVersi('PATCH', "/api/v1/data-induk/area-igd/$area", '0', [])->assertForbidden();
        }

        // Lihat saja: papan boleh, penempatan tidak.
        $lihat = $this->buatPengguna();
        $peran = $this->buatPeran([]);
        PeranMenu::query()->create(['peran_id' => $peran->id, 'menu_id' => Menu::query()->where('kode', 'igd.papan')->value('id')]);
        PeranIzin::query()->create(['peran_id' => $peran->id, 'izin_id' => Izin::query()->where('kode', 'igd.papan.lihat')->value('id')]);
        $this->tugaskan($lihat, $peran);
        $this->masukSebagai($lihat);
        $this->minta('GET', self::URL)->assertOk()->assertJsonPath('data.0.pasien.nama', $nama)->assertJsonPath('data.0.keluhan', 'Keluhan rahasia (uji sintetis)');
        $this->tempatkan($k, $area)->assertForbidden();
        $this->assertFalse(DB::table('kunjungan.papan_igd')->where('kunjungan_id', $k)->exists());
    }

    public function test_papan_memakai_batas_laju_layar_kerja(): void
    {
        $router = app('router');
        $rute = collect($router->getRoutes()->getRoutes())->first(fn ($r) => $r->uri() === 'api/v1/igd/papan' && in_array('GET', $r->methods(), true));
        $this->assertNotNull($rute);
        $throttle = collect($router->gatherRouteMiddleware($rute))->filter(fn ($m) => is_string($m) && str_contains($m, 'ThrottleRequests'))->values()->all();
        $this->assertCount(1, $throttle);
        $this->assertStringEndsWith(':api-layar-kerja', $throttle[0]);
    }

    private function daftarIgd(Pasien $pasien, ?string $keluhan = null): string
    {
        return (string) $this->minta('POST', '/api/v1/kunjungan-igd', ['pasien_id' => $pasien->id, 'cara_bayar' => 'non_jkn', 'keluhan' => $keluhan])
            ->assertCreated()->json('data.id');
    }

    private function triase(string $kunjunganId, string $kategori, ?string $keluhan = null): void
    {
        $this->minta('POST', "/api/v1/kunjungan/$kunjunganId/triase", ['kategori' => $kategori, 'keluhan' => $keluhan])->assertCreated();
    }

    /** @param  array<string, mixed>  $isi */
    private function disposisi(string $kunjunganId, array $isi): TestResponse
    {
        return $this->minta('POST', "/api/v1/kunjungan/$kunjunganId/disposisi", ['alasan' => 'Keputusan uji sintetis', ...$isi]);
    }

    private function tempatkan(string $kunjunganId, string $areaId): TestResponse
    {
        return $this->minta('PUT', "/api/v1/kunjungan/$kunjunganId/area-igd", ['area_igd_id' => $areaId]);
    }

    private function mintaDenganEtag(string $etag): TestResponse
    {
        $this->headerTambahan = ['If-None-Match' => $etag];
        try {
            return $this->minta('GET', self::URL);
        } finally {
            $this->headerTambahan = [];
        }
    }

    /** Catatan asesmen IGD langsung di tabel: layar asesmen awal medis IGD (FR-03-08) belum dibangun. */
    private function asesmen(string $kunjunganId, string $status = 'selesai', string $profesi = 'dokter'): void
    {
        $k = Kunjungan::query()->findOrFail($kunjunganId);
        DB::table('klinis.catatan_klinis')->insert([
            'id' => (string) Str::uuid7(), 'episode_id' => $k->episode_id, 'kunjungan_id' => $k->id, 'unit_id' => $this->poli->unit_organisasi_id,
            'pasien_id' => $k->pasien_id, 'penulis_id' => $this->dokter->id, 'profesi' => $profesi, 'tatanan' => 'igd', 'jenis' => 'asesmen',
            'status' => $status, 'selesai_pada' => $status === 'draf' ? null : now(), 'dibuat_pada' => now(), 'diubah_pada' => now(),
        ]);
    }
}
