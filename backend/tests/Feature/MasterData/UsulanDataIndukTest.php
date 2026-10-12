<?php

namespace Tests\Feature\MasterData;

use App\Core\Http\ApiError;
use App\Core\Instalasi\StatusInstalasi;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\Akses\Models\Peran;
use App\Modules\MasterData\Application\KelolaTempatTidur;
use App\Modules\MasterData\Application\MesinUsulan;
use App\Modules\MasterData\Application\SuntinganBerversi;
use App\Modules\MasterData\Models\TempatTidur;
use App\Modules\MasterData\Models\UnitOrganisasi;
use App\Modules\MasterData\Models\UsulanDataInduk;
use App\Modules\Notifikasi\Models\Tugas;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Support\AksesUji;
use Tests\Support\KlienSpa;
use Tests\Support\KontrakApi;
use Tests\TestCase;

/** Tiket 1.8c-be: usulan perubahan data induk berjenjang (kontrak v0.9.0). */
class UsulanDataIndukTest extends TestCase
{
    use AksesUji, KlienSpa, KontrakApi;

    private const DASAR = 'Keputusan Direktur 12/2026';

    private const PIN = '482916';

    private Pengguna $admin;

    private Pengguna $pengusul;

    private Pengguna $pemverifikasi;

    private Pengguna $penyetuju;

    private Peran $peranUsulan;

    /** @var array{ruang: string, bangsal: string, kelas: string, kamar: string, tt: list<string>, lantai: string} */
    private array $data;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->admin = $this->buatPengguna();
        $this->tugaskan($this->admin, $this->buatPeran(['master_data.organisasi']));
        $this->peranUsulan = $this->buatPeran(['master_data.usulan', 'master_data.organisasi']);
        foreach (['pengusul', 'pemverifikasi', 'penyetuju'] as $p) {
            $this->$p = $this->buatPengguna();
            $this->tugaskan($this->$p, $this->peranUsulan);
        }
        $this->data = $this->icu();
    }

    public function test_icu_ditambah_tt_berlaku_besok_dengan_pemisahan_tugas_dan_penerapan_idempoten(): void
    {
        $besok = Carbon::parse(SuntinganBerversi::hariIni())->addDay()->toDateString();
        $this->masukSebagai($this->pengusul);
        $ajukan = $this->minta('POST', '/api/v1/usulan-data-induk', $this->isi([
            $this->ttBaru('3'), $this->ttBaru('4'),
        ], $besok))->assertCreated()->assertJsonPath('data.tahap', 'diusulkan')->assertJsonPath('data.ringkasan', 'Bangsal ICU: 2 → 4 TT')
            ->assertJsonPath('data.dapat.verifikasi', false);
        $this->assertSesuaiKontrak($ajukan, 'POST', '/usulan-data-induk');
        $id = $ajukan->json('data.id');
        $tampilan = collect($ajukan->json('data.perubahan.0.tampilan'))->keyBy('isian');
        $this->assertSame(['Kelas rawat', 'Kelas I'], [$tampilan['kelas_rawat_id']['label_isian'], $tampilan['kelas_rawat_id']['baru']]);
        $this->assertSame('Kamar C-4-401', $tampilan['kamar_id']['baru']);

        // Pengusul tidak bisa memverifikasi atau menyetujui usulannya sendiri.
        $this->minta('POST', "/api/v1/usulan-data-induk/$id/verifikasi")->assertStatus(403)->assertJsonPath('error.kode', 'pengusul_sendiri');
        $this->minta('POST', "/api/v1/usulan-data-induk/$id/setujui")->assertStatus(403)->assertJsonPath('error.kode', 'pengusul_sendiri');
        $this->assertTrue(Tugas::query()->where('objek_id', $id)->where('status', 'terbuka')->where('pelaksana_jenis', 'peran')->exists());

        $this->masukSebagai($this->pemverifikasi);
        $this->assertSesuaiKontrak($this->minta('GET', '/api/v1/usulan-data-induk?tahap=terbuka&perlu_saya=true')->assertOk()
            ->assertJsonPath('data.0.id', $id)->assertJsonPath('meta.jumlah.diusulkan', 1), 'GET', '/usulan-data-induk');
        $this->minta('POST', "/api/v1/usulan-data-induk/$id/setujui")->assertStatus(409)->assertJsonPath('error.kode', 'tahap_tidak_sesuai');
        $verif = $this->minta('POST', "/api/v1/usulan-data-induk/$id/verifikasi", ['catatan' => 'Sesuai renovasi HCU'])->assertOk()->assertJsonPath('data.tahap', 'diverifikasi');
        $this->assertSesuaiKontrak($verif, 'POST', '/usulan-data-induk/{id}/verifikasi');
        $this->minta('POST', "/api/v1/usulan-data-induk/$id/setujui")->assertStatus(403)->assertJsonPath('error.kode', 'sudah_memverifikasi');

        $this->masukSebagai($this->penyetuju);
        $setuju = $this->minta('POST', "/api/v1/usulan-data-induk/$id/setujui")->assertOk()->assertJsonPath('data.tahap', 'disetujui');
        $this->assertSesuaiKontrak($setuju, 'POST', '/usulan-data-induk/{id}/setujui');
        $this->assertSame(2, TempatTidur::query()->where('kamar_id', $this->data['kamar'])->count());

        // Besok 00.00: diterapkan sekali walau tugas terjadwal berjalan dua kali.
        $this->travelTo(Carbon::parse($besok.' 00:01', (string) config('instalasi.zona_waktu')));
        $this->artisan('master-data:terapkan-usulan')->assertSuccessful();
        $this->artisan('master-data:terapkan-usulan')->assertSuccessful();
        $this->assertSame(4, TempatTidur::query()->where('kamar_id', $this->data['kamar'])->count());
        // Kapasitas berubah mulai besok (hari usulan berlaku); hari sebelumnya tetap 2.
        $this->assertSame(4, KelolaTempatTidur::jumlahDihitung($this->data['kamar'], $besok));
        $this->assertSame(2, KelolaTempatTidur::jumlahDihitung($this->data['kamar'], Carbon::parse($besok)->subDay()->toDateString()));
        $u = UsulanDataInduk::query()->findOrFail($id);
        $this->assertSame('berlaku', $u->tahap);
        $this->assertSame('Bangsal ICU: 2 → 4 TT', $u->ringkasan);
        $baru = (string) $u->perubahan[0]['objek_id_hasil'];
        $this->assertSame($besok, TempatTidur::query()->findOrFail($baru)->berlaku_dari->toDateString());

        $this->masukSebagai($this->admin);
        $versi = $this->minta('GET', "/api/v1/data-induk/tempat_tidur/$baru/riwayat-versi")->assertOk()
            ->assertJsonPath('data.0.usulan.nomor', $u->nomor)->assertJsonPath('data.0.sebelum', null);
        $this->assertSesuaiKontrak($versi, 'GET', '/data-induk/{jenis}/{id}/riwayat-versi');
        $this->masukSebagai($this->pengusul);
        $rinci = $this->minta('GET', "/api/v1/usulan-data-induk/$id")->assertOk()->assertJsonPath('data.perubahan.0.objek_id', $baru);
        $this->assertSesuaiKontrak($rinci, 'GET', '/usulan-data-induk/{id}');
        $this->assertSame(['diajukan', 'diverifikasi', 'disetujui', 'diterapkan'], array_column($rinci->json('data.riwayat'), 'aksi'));
        $this->assertTrue(DB::table('notifikasi.notifikasi')->where('pengguna_id', $this->pengusul->id)->where('jenis', 'USULAN_DATA_INDUK')->exists());
    }

    public function test_kembalikan_kirim_ulang_dan_konflik_objek(): void
    {
        $tt = $this->data['tt'][0];
        $this->masukSebagai($this->pengusul);
        $id = $this->minta('POST', '/api/v1/usulan-data-induk', $this->isi([$this->ttUbah($tt, ['isolasi' => true])]))->assertCreated()->json('data.id');
        $this->minta('POST', '/api/v1/usulan-data-induk', $this->isi([$this->ttUbah($tt, ['ekstra' => true])]))
            ->assertStatus(409)->assertJsonPath('error.kode', 'objek_sedang_diusulkan')->assertJsonPath('error.rincian.0.kolom', 'perubahan.0');

        $this->masukSebagai($this->pemverifikasi);
        $this->minta('POST', "/api/v1/usulan-data-induk/$id/kembalikan", ['catatan' => 'x'])->assertStatus(422);
        $kembali = $this->minta('POST', "/api/v1/usulan-data-induk/$id/kembalikan", ['catatan' => 'Lampirkan dasar dari komite PPI'])
            ->assertOk()->assertJsonPath('data.tahap', 'dikembalikan');
        $this->assertSesuaiKontrak($kembali, 'POST', '/usulan-data-induk/{id}/kembalikan');
        $this->minta('POST', "/api/v1/usulan-data-induk/$id/kirim-ulang", $this->isiUlang([$this->ttUbah($tt, ['isolasi' => true])]))
            ->assertStatus(403)->assertJsonPath('error.kode', 'bukan_pengusul');

        // Usulan dikembalikan tidak mengunci objek; kirim ulang memeriksa konflik lagi.
        $this->masukSebagai($this->pengusul);
        $this->minta('GET', "/api/v1/usulan-data-induk/$id")->assertJsonPath('data.dapat.kirim_ulang', true);
        $lain = $this->minta('POST', '/api/v1/usulan-data-induk', $this->isi([$this->ttUbah($tt, ['ekstra' => true])]))->assertCreated()->json('data.nomor');
        $this->minta('POST', "/api/v1/usulan-data-induk/$id/kirim-ulang", $this->isiUlang([$this->ttUbah($tt, ['isolasi' => true])]))
            ->assertStatus(409)->assertJsonPath('error.rincian.0.usulan.nomor', $lain);
        $this->masukSebagai($this->pemverifikasi);
        $idLain = UsulanDataInduk::query()->where('nomor', $lain)->value('id');
        $this->minta('POST', "/api/v1/usulan-data-induk/$idLain/kembalikan", ['catatan' => 'Tidak perlu TT ekstra'])->assertOk();

        $this->masukSebagai($this->pengusul);
        $ulang = $this->minta('POST', "/api/v1/usulan-data-induk/$id/kirim-ulang", $this->isiUlang([$this->ttUbah($tt, ['isolasi' => true, 'aturan_jenis_kelamin' => 'perempuan'])]))
            ->assertOk()->assertJsonPath('data.tahap', 'diusulkan')->assertJsonPath('data.putaran', 2);
        $this->assertSesuaiKontrak($ulang, 'POST', '/usulan-data-induk/{id}/kirim-ulang');
        $this->minta('POST', "/api/v1/usulan-data-induk/$id/kirim-ulang", $this->isiUlang([$this->ttUbah($tt, ['isolasi' => true])]))
            ->assertStatus(409)->assertJsonPath('error.kode', 'tahap_tidak_sesuai');
        $this->assertSame([1, 2], DB::table('inti.usulan_data_induk_putaran')->where('usulan_id', $id)->orderBy('putaran')->pluck('putaran')->all());
        $this->assertTrue(Tugas::query()->where('objek_id', $id)->where('pelaksana_jenis', 'pengguna')->where('pelaksana_id', $this->pengusul->id)->where('status', 'selesai')->exists());
    }

    public function test_validasi_aturan_1_8b_dan_gagal_diterapkan_bila_objek_berubah(): void
    {
        $versiAwal = DB::table('inti.data_induk_versi')->count();
        $auditAwal = DB::table('audit.log_aktivitas')->where('aksi', 'like', 'master_data.tempat_tidur%')->count();
        $this->masukSebagai($this->pengusul);
        $salah = $this->minta('POST', '/api/v1/usulan-data-induk', $this->isi([
            $this->ttBaru('1'),
            ['objek_jenis' => 'tempat_tidur', 'aksi' => 'ubah', 'objek_id' => $this->data['tt'][0], 'nilai_baru' => ['warna' => 'biru']],
            ['objek_jenis' => 'kamar', 'aksi' => 'tutup', 'objek_id' => $this->data['kamar'], 'nilai_baru' => []],
        ]))->assertStatus(422)->assertJsonPath('error.kode', 'perubahan_tidak_valid');
        $this->assertSesuaiKontrak($salah, 'POST', '/usulan-data-induk');
        $this->assertEqualsCanonicalizing(['isian_tidak_dikenal', 'aksi_tidak_dikenal'], array_column($salah->json('error.rincian'), 'kode'));
        // Uji kering dengan aturan 1.8b: kode TT yang sudah dipakai.
        $this->minta('POST', '/api/v1/usulan-data-induk', $this->isi([$this->ttBaru('1')]))
            ->assertStatus(422)->assertJsonPath('error.rincian.0.kode', 'kode_sudah_dipakai')->assertJsonPath('error.rincian.0.kolom', 'perubahan.0.kode');
        $this->minta('POST', '/api/v1/usulan-data-induk', $this->isi([$this->ttBaru('5')], '2020-01-01'))->assertStatus(422)->assertJsonPath('error.kode', 'tanggal_tidak_valid');
        // Uji kering yang lolos pun tidak meninggalkan versi, audit perubahan, maupun TT.
        $this->minta('POST', '/api/v1/usulan-data-induk', $this->isi([$this->ttBaru('5')]))->assertCreated();
        $this->assertSame($versiAwal, DB::table('inti.data_induk_versi')->count());
        $this->assertSame($auditAwal, DB::table('audit.log_aktivitas')->where('aksi', 'like', 'master_data.tempat_tidur%')->count());
        $this->assertFalse(TempatTidur::query()->where('kode', 'C-4-401-5')->exists());

        $tt = $this->data['tt'][1];
        $id = $this->minta('POST', '/api/v1/usulan-data-induk', $this->isi([
            ['objek_jenis' => 'tempat_tidur', 'aksi' => 'tutup', 'objek_id' => $tt, 'nilai_baru' => ['alasan' => 'Dipindah ke HCU']],
        ]))->assertCreated()->assertJsonPath('data.perubahan.0.tampilan.0.lama', 'Aktif')->json('data.id');
        // Objek diubah langsung sesudah diusulkan (sebelum siap melayani masih boleh) → ditandai, lalu gagal diterapkan.
        $this->masukSebagai($this->admin);
        $versi = (string) TempatTidur::query()->findOrFail($tt)->revisi;
        $this->mintaDenganVersi('PATCH', "/api/v1/tempat-tidur/$tt", $versi, ['isolasi' => true, 'dasar_perubahan' => self::DASAR])->assertOk();
        $this->masukSebagai($this->pemverifikasi);
        $this->minta('GET', "/api/v1/usulan-data-induk/$id")->assertJsonPath('data.perubahan.0.objek_berubah', true);
        $this->minta('POST', "/api/v1/usulan-data-induk/$id/verifikasi")->assertOk();
        $this->masukSebagai($this->penyetuju);
        $this->minta('POST', "/api/v1/usulan-data-induk/$id/setujui")->assertOk()->assertJsonPath('data.tahap', 'gagal_diterapkan')
            ->assertJsonPath('data.gagal_alasan', 'Objek berubah sejak diusulkan.');
        $this->assertNull(TempatTidur::query()->findOrFail($tt)->berlaku_sampai);
    }

    public function test_ruang_diubah_dan_ditambah_terjadwal_dengan_riwayat_versi(): void
    {
        $besok = Carbon::parse(SuntinganBerversi::hariIni())->addDay()->toDateString();
        $this->masukSebagai($this->pengusul);
        $id = $this->minta('POST', '/api/v1/usulan-data-induk', $this->isi([
            ['objek_jenis' => 'ruang', 'aksi' => 'ubah', 'objek_id' => $this->data['ruang'], 'nilai_baru' => ['nama' => 'ICU Dewasa']],
            ['objek_jenis' => 'ruang', 'aksi' => 'tambah', 'objek_id' => null, 'nilai_baru' => ['induk_id' => $this->data['lantai'], 'kode' => '402', 'nama' => 'HCU', 'fungsi' => 'intensif']],
        ], $besok))->assertCreated()->json('data.id');
        $this->setujuiOlehDuaOrang($id);
        $this->travelTo(Carbon::parse($besok.' 00:01', (string) config('instalasi.zona_waktu')));
        $this->artisan('master-data:terapkan-usulan')->assertSuccessful();
        $this->assertSame('berlaku', UsulanDataInduk::query()->findOrFail($id)->tahap);
        $this->assertSame('ICU Dewasa', DB::table('inti.lokasi')->where('id', $this->data['ruang'])->value('nama'));
        $this->assertTrue(DB::table('inti.lokasi')->where('kode_lengkap', 'C-4-402')->exists());
        $this->masukSebagai($this->admin);
        $this->minta('GET', '/api/v1/data-induk/ruang/'.$this->data['ruang'].'/riwayat-versi')->assertOk()
            ->assertJsonPath('data.0.tampilan.0.baru', 'ICU Dewasa')->assertJsonPath('data.0.tampilan.0.lama', 'ICU');
    }

    public function test_sesudah_siap_melayani_perubahan_langsung_harus_lewat_usulan_kecuali_tt_ekstra(): void
    {
        $this->app->bind(StatusInstalasi::class, fn () => new class implements StatusInstalasi
        {
            public function siapMelayani(): bool
            {
                return true;
            }
        });
        $this->masukSebagai($this->admin);
        $tt = fn (array $isi) => $this->minta('POST', '/api/v1/tempat-tidur', ['kamar_id' => $this->data['kamar'], 'kelas_rawat_id' => $this->data['kelas'], 'dasar_perubahan' => self::DASAR, ...$isi]);
        $tolak = $tt(['kode' => '9'])->assertStatus(409)->assertJsonPath('error.kode', 'harus_lewat_usulan');
        $this->assertSesuaiKontrak($tolak, 'POST', '/tempat-tidur');
        $ekstra = $tt(['kode' => 'E1', 'ekstra' => true])->assertCreated();
        $this->mintaDenganVersi('POST', '/api/v1/tempat-tidur/'.$ekstra->json('data.id').'/tutup', $ekstra->json('data.versi'), [
            'berlaku_dari' => SuntinganBerversi::hariIni(), 'alasan' => 'Lonjakan selesai', 'dasar_perubahan' => self::DASAR,
        ])->assertOk();
        $this->mintaDenganVersi('PATCH', '/api/v1/kamar/'.$this->data['kamar'], '0', ['kapasitas_maksimum' => 6, 'dasar_perubahan' => self::DASAR])
            ->assertStatus(409)->assertJsonPath('error.kode', 'harus_lewat_usulan');
        $this->minta('POST', '/api/v1/lokasi', ['induk_id' => $this->data['lantai'], 'jenis' => 'ruang', 'kode' => '409', 'nama' => 'Ruang baru', 'fungsi' => 'intensif', 'dasar_perubahan' => self::DASAR])
            ->assertStatus(409);
    }

    public function test_usulan_ditandatangani_pelaku_dan_tanda_tangan_ulang_sesudah_kirim_ulang(): void
    {
        $this->masukSebagai($this->pengusul);
        $this->minta('POST', '/api/v1/saya/ttd/aktivasi', ['sandi' => self::SANDI_UJI, 'pin' => self::PIN, 'pin_ulang' => self::PIN])->assertCreated();
        $id = $this->minta('POST', '/api/v1/usulan-data-induk', $this->isi([$this->ttUbah($this->data['tt'][0], ['isolasi' => true])]))
            ->assertCreated()->assertJsonPath('data.dapat.tandatangani', true)->json('data.id');
        $objek = [['jenis' => 'usulan_data_induk', 'id' => $id]];
        $this->minta('POST', '/api/v1/ttd/tandatangani', ['objek' => $objek, 'pin' => self::PIN, 'pernyataan_disetujui' => true])->assertOk()->assertJsonPath('data.0.baru', true);
        $this->minta('GET', "/api/v1/usulan-data-induk/$id")->assertJsonPath('data.dapat.tandatangani', false);

        // Akun lain tanpa peran pelaku: bukan_pemutus.
        $this->masukSebagai($this->pemverifikasi);
        $this->minta('POST', '/api/v1/saya/ttd/aktivasi', ['sandi' => self::SANDI_UJI, 'pin' => self::PIN, 'pin_ulang' => self::PIN])->assertCreated();
        $this->minta('POST', '/api/v1/ttd/tandatangani', ['objek' => $objek, 'pin' => self::PIN, 'pernyataan_disetujui' => true])
            ->assertStatus(422)->assertJsonPath('error.rincian.0.kode', 'bukan_pemutus');
        $this->minta('POST', "/api/v1/usulan-data-induk/$id/kembalikan", ['catatan' => 'Tambahkan alasan klinis'])->assertOk();

        $this->masukSebagai($this->pengusul);
        $this->minta('POST', "/api/v1/usulan-data-induk/$id/kirim-ulang", $this->isiUlang([$this->ttUbah($this->data['tt'][0], ['isolasi' => true, 'ekstra' => false])]))->assertOk();
        $this->minta('POST', '/api/v1/ttd/tandatangani', ['objek' => $objek, 'pin' => self::PIN, 'pernyataan_disetujui' => true])->assertOk()->assertJsonPath('data.0.baru', true);
        $status = array_column(array_column($this->minta('GET', "/api/v1/ttd/verifikasi/usulan_data_induk/$id")->assertOk()->json('data'), 'verifikasi'), 'status');
        $this->assertSame(['isi_berubah', 'sah'], $status);
    }

    public function test_pemisahan_tugas_dicek_ulang_sesudah_baris_dikunci(): void
    {
        $this->masukSebagai($this->pengusul);
        $id = $this->minta('POST', '/api/v1/usulan-data-induk', $this->isi([$this->ttUbah($this->data['tt'][0], ['isolasi' => true])]))->assertCreated()->json('data.id');
        // Baris dibaca sebelum verifikasi (seperti permintaan setujui yang datang bersamaan), lalu orang yang sama memverifikasi.
        $basi = UsulanDataInduk::query()->findOrFail($id);
        $mesin = app(MesinUsulan::class);
        $this->actingAs($this->pemverifikasi);
        $mesin->verifikasi($this->pemverifikasi, UsulanDataInduk::query()->findOrFail($id), null);
        try {
            $mesin->setujui($this->pemverifikasi, $basi, null);
            $this->fail('Pemverifikasi tidak boleh menyetujui lewat data basi.');
        } catch (ApiError $e) {
            $this->assertSame('sudah_memverifikasi', $e->kode);
        }
        $this->assertSame('diverifikasi', UsulanDataInduk::query()->findOrFail($id)->tahap);
    }

    private function setujuiOlehDuaOrang(string $id): void
    {
        $this->masukSebagai($this->pemverifikasi);
        $this->minta('POST', "/api/v1/usulan-data-induk/$id/verifikasi")->assertOk();
        $this->masukSebagai($this->penyetuju);
        $this->minta('POST', "/api/v1/usulan-data-induk/$id/setujui")->assertOk();
    }

    /**
     * @param  list<array<string, mixed>>  $perubahan
     * @return array<string, mixed>
     */
    private function isi(array $perubahan, ?string $berlakuDari = null): array
    {
        return ['jenis_data' => 'ruang_tt', ...$this->isiUlang($perubahan, $berlakuDari)];
    }

    /**
     * @param  list<array<string, mixed>>  $perubahan
     * @return array<string, mixed>
     */
    private function isiUlang(array $perubahan, ?string $berlakuDari = null): array
    {
        return ['perubahan' => $perubahan, 'berlaku_dari' => $berlakuDari ?? SuntinganBerversi::hariIni(),
            'alasan' => 'Penambahan TT dari HCU yang direnovasi', 'dasar' => self::DASAR];
    }

    /** @return array<string, mixed> */
    private function ttBaru(string $kode): array
    {
        return ['objek_jenis' => 'tempat_tidur', 'aksi' => 'tambah', 'objek_id' => null,
            'nilai_baru' => ['kamar_id' => $this->data['kamar'], 'kode' => $kode, 'kelas_rawat_id' => $this->data['kelas']]];
    }

    /**
     * @param  array<string, mixed>  $nilai
     * @return array<string, mixed>
     */
    private function ttUbah(string $id, array $nilai): array
    {
        return ['objek_jenis' => 'tempat_tidur', 'aksi' => 'ubah', 'objek_id' => $id, 'nilai_baru' => $nilai];
    }

    /** @return array{ruang: string, bangsal: string, kelas: string, kamar: string, tt: list<string>, lantai: string} */
    private function icu(): array
    {
        $this->masukSebagai($this->admin);
        $unit = UnitOrganisasi::query()->create(['kode' => 'ICU', 'nama' => 'Instalasi Intensif', 'jenis' => 'instalasi', 'berlaku_dari' => '2026-01-01']);
        $lokasi = fn (?string $induk, string $jenis, string $kode, string $nama, ?string $fungsi = null): TestResponse => $this->minta('POST', '/api/v1/lokasi', [
            'induk_id' => $induk, 'jenis' => $jenis, 'kode' => $kode, 'nama' => $nama, 'fungsi' => $fungsi, 'dasar_perubahan' => self::DASAR,
        ])->assertCreated();
        $gedung = $lokasi(null, 'gedung', 'C', 'Gedung C')->json('data.id');
        $lantai = $lokasi($gedung, 'lantai', '4', 'Lantai 4')->json('data.id');
        $ruang = $lokasi($lantai, 'ruang', '401', 'ICU', 'intensif')->json('data.id');
        $bangsal = $this->minta('POST', '/api/v1/bangsal', ['kode' => 'ICU', 'nama' => 'Bangsal ICU', 'unit_organisasi_id' => $unit->id, 'dasar_perubahan' => self::DASAR])->assertCreated()->json('data.id');
        $kelas = $this->minta('POST', '/api/v1/kelas-rawat', ['kode' => 'I', 'nama' => 'Kelas I', 'dasar_perubahan' => self::DASAR])->assertCreated()->json('data.id');
        $kamar = $this->minta('POST', '/api/v1/kamar', ['bangsal_id' => $bangsal, 'lokasi_id' => $ruang, 'dasar_perubahan' => self::DASAR])->assertCreated()->json('data.id');
        $tt = [];
        foreach (['1', '2'] as $kode) {
            $tt[] = $this->minta('POST', '/api/v1/tempat-tidur', ['kamar_id' => $kamar, 'kode' => $kode, 'kelas_rawat_id' => $kelas, 'dasar_perubahan' => self::DASAR])->assertCreated()->json('data.id');
        }

        return ['ruang' => $ruang, 'bangsal' => $bangsal, 'kelas' => $kelas, 'kamar' => $kamar, 'tt' => $tt, 'lantai' => $lantai];
    }
}
