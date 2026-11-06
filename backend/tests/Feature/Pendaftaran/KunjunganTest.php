<?php

namespace Tests\Feature\Pendaftaran;

use App\Modules\MasterData\Models\JadwalPengecualian;
use App\Modules\Pendaftaran\Application\KelolaKunjungan;
use App\Modules\Pendaftaran\Events\KunjunganDibuat;
use App\Modules\Pendaftaran\Http\Resources\PasienResource;
use App\Modules\Pendaftaran\Models\Kunjungan;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\Support\AksesUji;
use Tests\Support\KlienSpa;
use Tests\Support\KontrakApi;
use Tests\Support\KunjunganUji;
use Tests\TestCase;

/** Tiket 2.6-be: kunjungan poli, episode harian, kuota, batal manual dan otomatis (kontrak v0.20.0). Data sintetis saja. */
class KunjunganTest extends TestCase
{
    use AksesUji, KlienSpa, KontrakApi, KunjunganUji;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanKunjungan();
    }

    public function test_episode_harian_terbentuk_dan_tergabung_lalu_event_terbit(): void
    {
        Event::fake([KunjunganDibuat::class]);
        $pagi = $this->jadwal($this->dokter());
        $siang = $this->jadwal($this->dokter(), ['jam_mulai' => '13:00', 'jam_selesai' => '16:00']);
        $pasien = $this->pasien();
        $this->masukSebagai($this->petugasPendaftaran());

        $pertama = $this->daftarKunjungan($pasien, $pagi, ['keluhan' => 'Kontrol rutin'])->assertCreated()
            ->assertJsonPath('data.status', 'terdaftar')->assertJsonPath('data.nomor', 'RJ-20261102-0001')
            ->assertJsonPath('data.pasien.no_rm', $pasien->no_rm)->assertJsonMissingPath('data.pasien.nik')
            ->assertJsonPath('data.episode.jenis', 'rawat_jalan_harian')->assertJsonPath('data.episode.mulai_tanggal', self::SENIN)
            ->assertJsonPath('data.dokter.id', $pagi->pegawai_id)->assertJsonPath('data.poliklinik.id', $this->poli->id)
            ->assertJsonPath('data.penjamin', null)->assertJsonPath('data.didaftarkan_oleh.id', fn ($id) => is_string($id));
        $this->assertSesuaiKontrak($pertama, 'POST', '/kunjungan');
        $this->assertStringContainsString('no-store', (string) $pertama->headers->get('Cache-Control'));
        $episode = $pertama->json('data.episode.id');
        $audit = $this->assertAuditTerakhir('kunjungan.dibuat', $pertama->json('data.id'));
        $this->assertStringNotContainsString($pasien->nama, (string) $audit->sesudah);
        $this->assertStringNotContainsString('Kontrol rutin', (string) $audit->sesudah);

        // Kunjungan poli kedua di hari yang sama → episode yang sama (7.00.2).
        $this->daftarKunjungan($pasien, $siang)->assertCreated()->assertJsonPath('data.episode.id', $episode)
            ->assertJsonPath('data.nomor', 'RJ-20261102-0002');
        // Tanggal lain → episode baru.
        $this->daftarKunjungan($pasien, $pagi, ['tanggal' => '2026-11-09'])->assertCreated()
            ->assertJsonPath('data.episode.mulai_tanggal', '2026-11-09')->assertJsonPath('data.episode.id', fn ($id) => $id !== $episode);
        // Jadwal + tanggal yang sama → ganda.
        $this->daftarKunjungan($pasien, $pagi)->assertStatus(409)->assertJsonPath('error.kode', 'kunjungan_ganda');

        Event::assertDispatchedTimes(KunjunganDibuat::class, 3);
        Event::assertDispatched(KunjunganDibuat::class, fn (KunjunganDibuat $e) => $e->kunjunganId === $pertama->json('data.id')
            && $e->episodeId === $episode && $e->pegawaiId === $pagi->pegawai_id && $e->tanggal === self::SENIN && $e->caraBayar === 'non_jkn');
        $this->assertSame(3, DB::table('kunjungan.kunjungan')->where('pasien_id', $pasien->id)->count());
    }

    public function test_kuota_habis_409_dengan_slot_lain_dan_batal_mengembalikan_kuota(): void
    {
        $penuh = $this->jadwal($this->dokter(), ['kuota_non_jkn' => 1]);
        $lain = $this->jadwal($this->dokter(), ['jam_mulai' => '13:00', 'jam_selesai' => '15:00', 'kuota_non_jkn' => 3, 'kuota_jkn' => 0]);
        $habisJuga = $this->jadwal($this->dokter(), ['jam_mulai' => '09:00', 'jam_selesai' => '10:00', 'kuota_non_jkn' => 0]);
        $petugas = $this->petugasPendaftaran();
        $this->masukSebagai($petugas);

        $id = $this->daftarKunjungan($this->pasien(), $penuh)->assertCreated()->json('data.id');
        $respons = $this->daftarKunjungan($this->pasien(), $penuh)->assertStatus(409)->assertJsonPath('error.kode', 'kuota_habis')
            ->assertJsonCount(1, 'error.rincian')->assertJsonPath('error.rincian.0.jadwal_praktik_id', $lain->id)
            ->assertJsonPath('error.rincian.0.sisa_non_jkn', 3)->assertJsonPath('error.rincian.0.sisa_jkn', 0)
            ->assertJsonPath('error.rincian.0.jam_mulai', '13:00')->assertJsonPath('error.rincian.0.pegawai.id', $lain->pegawai_id);
        $this->assertSesuaiKontrak($respons, 'POST', '/kunjungan');
        $this->assertNotContains($habisJuga->id, array_column($respons->json('error.rincian'), 'jadwal_praktik_id'));

        // Keterisian jadwal (2.4) kini dihitung dari kunjungan.
        $this->tugaskan($petugas, $this->buatPeran(['master_data.jadwal_praktik']));
        $this->minta('GET', '/api/v1/jadwal-praktik?tanggal='.self::SENIN.'&poli_id='.$this->poli->id)->assertOk()
            ->assertJsonPath('data.0.pada_tanggal.terisi_non_jkn', 1)->assertJsonPath('data.0.pada_tanggal.sisa_non_jkn', 0);

        $this->minta('POST', "/api/v1/kunjungan/$id/batal", ['alasan_batal' => 'Pasien minta pindah hari'])->assertOk();
        $this->daftarKunjungan($this->pasien(), $penuh)->assertCreated();
    }

    public function test_aturan_422_dan_404(): void
    {
        $dokter = $this->dokter();
        $jadwal = $this->jadwal($dokter);
        $pasien = $this->pasien();
        $bpjs = $this->penjamin('bpjs');
        $umum = $this->penjamin('umum');
        $this->masukSebagai($this->petugasPendaftaran());

        $this->daftarKunjungan($pasien, $jadwal, ['jenis_kunjungan' => 'igd'])->assertStatus(422)->assertJsonPath('error.kode', 'jenis_belum_didukung');
        foreach (['2026-11-03', '2026-10-26', '2027-03-01'] as $tanggal) {
            $this->daftarKunjungan($pasien, $jadwal, ['tanggal' => $tanggal])->assertStatus(422)->assertJsonPath('error.kode', 'tanggal_di_luar_jadwal');
        }
        $this->daftarKunjungan($pasien, $jadwal, ['cara_bayar' => 'jkn'])->assertStatus(422)->assertJsonPath('error.kode', 'penjamin_wajib');
        $this->daftarKunjungan($pasien, $jadwal, ['cara_bayar' => 'jkn', 'penjamin_id' => $umum->id])->assertStatus(422)->assertJsonPath('error.kode', 'penjamin_tidak_sesuai');
        $this->daftarKunjungan($pasien, $jadwal, ['penjamin_id' => $bpjs->id])->assertStatus(422)->assertJsonPath('error.kode', 'penjamin_tidak_sesuai');
        $this->daftarKunjungan($pasien, $jadwal, ['pasien_id' => (string) Str::uuid7()])->assertNotFound();
        $this->daftarKunjungan($pasien, $jadwal, ['jadwal_praktik_id' => (string) Str::uuid7()])->assertNotFound();

        JadwalPengecualian::query()->forceCreate(['jenis' => 'cuti', 'pegawai_id' => $dokter->id, 'dari_tanggal' => '2026-11-09',
            'sampai_tanggal' => '2026-11-09', 'alasan' => 'Cuti tahunan']);
        $this->daftarKunjungan($pasien, $jadwal, ['tanggal' => '2026-11-09'])->assertStatus(422)
            ->assertJsonPath('error.kode', 'jadwal_tidak_tersedia')->assertJsonPath('error.rincian.0.alasan', 'cuti');
        $jadwal->forceFill(['status' => 'nonaktif'])->save();
        $this->daftarKunjungan($pasien, $jadwal)->assertStatus(422)->assertJsonPath('error.rincian.0.alasan', 'nonaktif');

        $jkn = $this->daftarKunjungan($pasien, $this->jadwal($this->dokter()), ['cara_bayar' => 'jkn', 'penjamin_id' => $bpjs->id])
            ->assertCreated()->assertJsonPath('data.penjamin.jenis', 'bpjs');
        $this->assertSesuaiKontrak($jkn, 'POST', '/kunjungan');
    }

    public function test_batal_manual_dan_daftar_papan_kunjungan(): void
    {
        $jadwal = $this->jadwal($this->dokter());
        $petugas = $this->petugasPendaftaran();
        $this->masukSebagai($petugas);
        $a = $this->daftarKunjungan($this->pasien(), $jadwal)->assertCreated()->json('data.id');
        $b = $this->daftarKunjungan($this->pasien(), $jadwal)->assertCreated()->json('data.id');

        $batal = $this->minta('POST', "/api/v1/kunjungan/$a/batal", ['alasan_batal' => 'Pasien tidak jadi berobat'])->assertOk()
            ->assertJsonPath('data.status', 'batal')->assertJsonPath('data.alasan_batal', 'Pasien tidak jadi berobat')
            ->assertJsonPath('data.dibatalkan_oleh.id', $petugas->id);
        $this->assertSesuaiKontrak($batal, 'POST', '/kunjungan/{id}/batal');
        $this->assertAuditTerakhir('kunjungan.dibatalkan', $a);
        $this->assertSame('batal', DB::table('kunjungan.episode')->where('id', $batal->json('data.episode.id'))->value('status'));
        $this->minta('POST', "/api/v1/kunjungan/$a/batal", ['alasan_batal' => 'Ulang batal'])->assertStatus(409)->assertJsonPath('error.kode', 'sudah_batal');
        $this->minta('POST', "/api/v1/kunjungan/$b/batal", ['alasan_batal' => 'x'])->assertStatus(422);
        Kunjungan::query()->whereKey($b)->update(['status' => 'dilayani']);
        $this->minta('POST', "/api/v1/kunjungan/$b/batal", ['alasan_batal' => 'Terlambat batal'])->assertStatus(409)->assertJsonPath('error.kode', 'sudah_dilayani');
        $this->minta('POST', '/api/v1/kunjungan/'.Str::uuid7().'/batal', ['alasan_batal' => 'Tidak ada'])->assertNotFound();

        $url = '/api/v1/kunjungan?tanggal='.self::SENIN.'&poli_id='.$this->poli->id;
        $daftar = $this->minta('GET', $url)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $b);
        $this->assertSesuaiKontrak($daftar, 'GET', '/kunjungan');
        $this->assertStringContainsString('no-store', (string) $daftar->headers->get('Cache-Control'));
        $this->minta('GET', "$url&status=batal")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $a);
        $this->minta('GET', '/api/v1/kunjungan?tanggal='.self::SENIN)->assertStatus(422);
        $this->minta('GET', "$url&batas=201")->assertStatus(422);
    }

    public function test_batal_otomatis_setelah_jam_tutup(): void
    {
        $pagi = $this->jadwal($this->dokter());
        $sore = $this->jadwal($this->dokter(), ['jam_mulai' => '14:00', 'jam_selesai' => '17:00']);
        $this->masukSebagai($this->petugasPendaftaran());
        $tutup = $this->daftarKunjungan($this->pasien(), $pagi)->assertCreated()->json('data.id');
        $dilayani = $this->daftarKunjungan($this->pasien(), $pagi)->assertCreated()->json('data.id');
        $buka = $this->daftarKunjungan($this->pasien(), $sore)->assertCreated()->json('data.id');
        Kunjungan::query()->whereKey($dilayani)->update(['status' => 'dilayani']);

        // Sebelum jam tutup tidak ada yang dibatalkan.
        $this->assertSame(0, app(KelolaKunjungan::class)->batalkanTakDilayani());
        $this->travelTo(Carbon::parse(self::SENIN.' 12:30', (string) config('instalasi.zona_waktu')));
        $this->artisan('pendaftaran:batalkan-kunjungan-tak-dilayani')->assertSuccessful();

        $k = Kunjungan::query()->findOrFail($tutup);
        $this->assertSame(['batal', KelolaKunjungan::ALASAN_BATAL_OTOMATIS, null], [$k->status, $k->alasan_batal, $k->dibatalkan_oleh]);
        $this->assertSame('dilayani', Kunjungan::query()->findOrFail($dilayani)->status);
        $this->assertSame('terdaftar', Kunjungan::query()->findOrFail($buka)->status);
        $this->assertSame('batal', DB::table('akses.relasi_perawatan')->where('sumber_id', $tutup)->value('status'));
        $this->assertAuditTerakhir('kunjungan.dibatalkan', $tutup);

        // Hari berikutnya: sisa kunjungan kemarin yang belum dilayani ikut batal.
        $this->travelTo(now()->addDay());
        $this->assertSame(1, app(KelolaKunjungan::class)->batalkanTakDilayani());
        $this->assertSame('batal', Kunjungan::query()->findOrFail($buka)->status);
    }

    public function test_izin_diperiksa_sebelum_objek_dicari(): void
    {
        $jadwal = $this->jadwal($this->dokter());
        $this->masukSebagai($this->petugasPendaftaran());
        $id = $this->daftarKunjungan($this->pasien(), $jadwal)->assertCreated()->json('data.id');

        // Tanpa aksi batal: 403 baik untuk id yang ada maupun tidak (tidak membocorkan keberadaan).
        $this->masukSebagai($this->petugasPendaftaran(['pendaftaran.pasien.batal']));
        $this->minta('POST', "/api/v1/kunjungan/$id/batal", ['alasan_batal' => 'Coba batalkan'])->assertForbidden();
        $this->minta('POST', '/api/v1/kunjungan/'.Str::uuid7().'/batal', ['alasan_batal' => 'Coba batalkan'])->assertForbidden();
        $this->assertSame('terdaftar', Kunjungan::query()->findOrFail($id)->status);

        // Akun tanpa pendaftaran.pasien sama sekali.
        $this->masukSebagai($this->buatPengguna());
        $this->minta('GET', '/api/v1/kunjungan?tanggal='.self::SENIN.'&poli_id='.$this->poli->id)->assertForbidden();
        $this->daftarKunjungan($this->pasien(), $jadwal)->assertForbidden();
        $this->minta('POST', "/api/v1/kunjungan/$id/batal", ['alasan_batal' => 'Coba batalkan'])->assertForbidden();
    }

    public function test_jumlah_kunjungan_pasien_dan_kuota_di_bawah_terisi(): void
    {
        $jadwal = $this->jadwal($this->dokter());
        $pasien = $this->pasien();
        $petugas = $this->petugasPendaftaran();
        $this->masukSebagai($petugas);
        $this->daftarKunjungan($pasien, $jadwal)->assertCreated();
        $this->daftarKunjungan($this->pasien(), $jadwal)->assertCreated();

        $this->assertSame(1, app(PasienResource::class)->satu($pasien, $petugas)['jumlah_kunjungan']);

        $this->tugaskan($petugas, $this->buatPeran(['master_data.jadwal_praktik']));
        $versi = (string) $jadwal->revisi;
        $this->mintaDenganVersi('PATCH', "/api/v1/jadwal-praktik/{$jadwal->id}", $versi, ['kuota_non_jkn' => 1, 'dasar_perubahan' => 'SK uji kuota'])
            ->assertStatus(422)->assertJsonPath('error.kode', 'kuota_di_bawah_terisi');
        $this->mintaDenganVersi('PATCH', "/api/v1/jadwal-praktik/{$jadwal->id}", $versi, ['kuota_non_jkn' => 2, 'dasar_perubahan' => 'SK uji kuota'])
            ->assertOk();
    }

    public function test_instalasi_belum_siap_menolak_pasien_baru_dan_kunjungan_tanpa_memakai_no_rm(): void
    {
        $jadwal = $this->jadwal($this->dokter());
        $pasien = $this->pasien();
        $this->masukSebagai($this->petugasPendaftaran());
        $this->tandaiInstalasiSiap(false);
        $nomor = DB::table('pasien.nomor_rm')->value('terakhir');

        $r = $this->minta('POST', '/api/v1/pasien', ['nama' => 'Contoh Belum Siap', 'tanggal_lahir' => '1990-01-01', 'jenis_kelamin' => 'laki_laki'])
            ->assertStatus(409)->assertJsonPath('error.kode', 'instalasi_belum_siap');
        $this->assertSesuaiKontrak($r, 'POST', '/pasien');
        $this->assertSame($nomor, DB::table('pasien.nomor_rm')->value('terakhir'));
        $k = $this->daftarKunjungan($pasien, $jadwal)->assertStatus(409)->assertJsonPath('error.kode', 'instalasi_belum_siap');
        $this->assertSesuaiKontrak($k, 'POST', '/kunjungan');
        $this->assertFalse(Kunjungan::query()->where('pasien_id', $pasien->id)->exists());
    }

    public function test_dokter_keluar_atau_sip_tidak_berlaku_pada_tanggal_kunjungan_tidak_bisa_dipesan(): void
    {
        $sipHabis = $this->dokter();
        DB::table('sdm.izin_praktik')->where('pegawai_id', $sipHabis->id)->update(['berlaku_sampai' => '2026-11-05']);
        $keluar = $this->dokter();
        $keluar->forceFill(['tanggal_keluar' => '2026-11-09', 'alasan_keluar' => 'Pindah tugas'])->save();
        $nonaktif = $this->dokter();
        $nonaktif->forceFill(['status' => 'nonaktif', 'tanggal_keluar' => '2026-10-30', 'alasan_keluar' => 'Pensiun'])->save();
        $pasien = $this->pasien();
        $this->masukSebagai($this->petugasPendaftaran());

        $this->daftarKunjungan($pasien, $this->jadwal($sipHabis))->assertCreated();
        foreach ([[$sipHabis, '2026-11-09'], [$keluar, '2026-11-09'], [$nonaktif, self::SENIN]] as $i => [$dokter, $tanggal]) {
            $jadwal = $this->jadwal($dokter, ['jam_mulai' => sprintf('%02d:00', 13 + $i), 'jam_selesai' => sprintf('%02d:30', 13 + $i)]);
            $r = $this->daftarKunjungan($pasien, $jadwal, ['tanggal' => $tanggal])->assertStatus(422)
                ->assertJsonPath('error.kode', 'jadwal_tidak_tersedia')->assertJsonPath('error.rincian.0.alasan', 'nonaktif');
            $this->assertSesuaiKontrak($r, 'POST', '/kunjungan');
        }
        $this->daftarKunjungan($pasien, $this->jadwal($keluar), ['tanggal' => self::SENIN])->assertCreated();
        $this->assertSame(0, DB::table('akses.relasi_perawatan')->where('pegawai_id', $nonaktif->id)->count());
    }

    public function test_ubah_jadwal_dan_cuti_tidak_meninggalkan_kunjungan_terdaftar(): void
    {
        $jadwal = $this->jadwal($this->dokter());
        $petugas = $this->petugasPendaftaran();
        $this->tugaskan($petugas, $this->buatPeran(['master_data.jadwal_praktik']));
        $this->masukSebagai($petugas);
        $kunjungan = $this->daftarKunjungan($this->pasien(), $jadwal, ['tanggal' => '2026-11-09'])->assertCreated()->json('data.id');
        $ubah = fn (array $isi) => $this->mintaDenganVersi('PATCH', "/api/v1/jadwal-praktik/{$jadwal->id}", '0', [...$isi, 'dasar_perubahan' => 'SK uji jadwal']);

        foreach ([['status' => 'nonaktif'], ['hari' => 2], ['berlaku_sampai' => '2026-11-08'], ['berlaku_dari' => '2026-11-10']] as $isi) {
            $r = $ubah($isi)->assertStatus(422)->assertJsonPath('error.kode', 'jadwal_sudah_terisi')->assertJsonPath('error.rincian.0.tanggal', '2026-11-09');
            $this->assertSesuaiKontrak($r, 'PATCH', '/jadwal-praktik/{id}');
        }
        $cuti = ['jenis' => 'cuti', 'pegawai_id' => $jadwal->pegawai_id, 'dari_tanggal' => '2026-11-09', 'sampai_tanggal' => '2026-11-10', 'alasan' => 'Cuti tahunan'];
        $r = $this->minta('POST', '/api/v1/jadwal-pengecualian', $cuti)->assertStatus(422)->assertJsonPath('error.kode', 'jadwal_sudah_terisi');
        $this->assertSesuaiKontrak($r, 'POST', '/jadwal-pengecualian');
        $this->minta('POST', '/api/v1/jadwal-pengecualian', ['jenis' => 'libur', 'dari_tanggal' => '2026-11-09', 'sampai_tanggal' => '2026-11-09', 'alasan' => 'Libur uji'])
            ->assertStatus(422)->assertJsonPath('error.kode', 'jadwal_sudah_terisi');
        $awal = $this->minta('POST', '/api/v1/jadwal-pengecualian', [...$cuti, 'dari_tanggal' => '2026-11-10'])->assertCreated();
        $this->mintaDenganVersi('PATCH', '/api/v1/jadwal-pengecualian/'.$awal->json('data.id'), '0', ['dari_tanggal' => '2026-11-09'])
            ->assertStatus(422)->assertJsonPath('error.kode', 'jadwal_sudah_terisi');

        $this->minta('POST', "/api/v1/kunjungan/$kunjungan/batal", ['alasan_batal' => 'Pasien minta pindah jadwal'])->assertOk();
        $ubah(['status' => 'nonaktif'])->assertOk();
    }

    public function test_jenis_penjamin_tidak_bisa_diubah_sesudah_dipakai_kunjungan(): void
    {
        $bpjs = $this->penjamin('bpjs');
        $petugas = $this->petugasPendaftaran();
        $this->tugaskan($petugas, $this->buatPeran(['master_data.penjamin']));
        $this->masukSebagai($petugas);
        $ubah = fn (string $versi, array $isi) => $this->mintaDenganVersi('PATCH', "/api/v1/data-induk/penjamin/{$bpjs->id}", $versi, [...$isi, 'dasar_perubahan' => 'SK uji penjamin']);
        $ubah('0', ['nama' => 'BPJS Kesehatan'])->assertOk();
        $this->daftarKunjungan($this->pasien(), $this->jadwal($this->dokter()), ['cara_bayar' => 'jkn', 'penjamin_id' => $bpjs->id])->assertCreated();

        $r = $ubah('1', ['jenis' => 'asuransi'])->assertStatus(422)->assertJsonPath('error.kode', 'validasi_gagal')->assertJsonPath('error.rincian.0.kolom', 'jenis');
        $this->assertSesuaiKontrak($r, 'PATCH', '/data-induk/penjamin/{id}');
        $this->assertSame('bpjs', $bpjs->refresh()->jenis);
        $ubah('1', ['nama' => 'BPJS Kesehatan Pusat'])->assertOk();
    }
}
