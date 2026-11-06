<?php

namespace Tests\Feature\Pendaftaran;

use App\Modules\Akses\Models\Izin;
use App\Modules\Akses\Models\Menu;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\Akses\Models\PeranIzin;
use App\Modules\Akses\Models\PeranMenu;
use App\Modules\Pendaftaran\Models\IdentitasPasien;
use App\Modules\Pendaftaran\Models\Pasien;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\AksesUji;
use Tests\Support\KlienSpa;
use Tests\Support\KontrakApi;
use Tests\Support\KunjunganUji;
use Tests\TestCase;

/** Tiket 2.9: peringatan duplikat dan usulan gabung RM berjenjang (kontrak v0.22.0). Data sintetis saja. */
class GabungPasienTest extends TestCase
{
    use AksesUji, KlienSpa, KontrakApi, KunjunganUji;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanKunjungan();
    }

    public function test_cek_duplikat_menemukan_nama_mirip_tanggal_lahir_sama_dan_melewatkan_yang_berbeda(): void
    {
        $ada = $this->pasienBernama('Contoh Siti Aminah', '1985-05-05');
        $this->pasienBernama('Contoh Siti Aminah', '1990-01-01');
        $this->pasienBernama('Budi Santoso Lain', '1985-05-05');
        $digabung = $this->pasienBernama('Contoh Siti Aminah', '1985-05-05');
        $digabung->forceFill(['digabung_ke_id' => $ada->id, 'digabung_pada' => now()])->save();
        $this->masukSebagai($this->petugasPendaftaran());

        $r = $this->minta('POST', '/api/v1/pasien/cek-duplikat', ['nama' => 'Contoh Siti Aminh', 'tanggal_lahir' => '1985-05-05'])->assertOk();
        $this->assertSesuaiKontrak($r, 'POST', '/pasien/cek-duplikat');
        $this->assertSame([$ada->id], array_column($r->json('data'), 'id'));
        $this->minta('POST', '/api/v1/pasien/cek-duplikat', ['nama' => 'Xy', 'tanggal_lahir' => '1985-05-05'])->assertStatus(422);
    }

    public function test_cek_duplikat_butuh_izin_input_pasien(): void
    {
        $this->masukSebagai($this->petugasPendaftaran(['pendaftaran.pasien.input']));
        $this->minta('POST', '/api/v1/pasien/cek-duplikat', ['nama' => 'Contoh Siti', 'tanggal_lahir' => '1985-05-05'])->assertForbidden();
    }

    public function test_usul_lalu_verifikasi_memindahkan_kunjungan_dan_menandai_rm_lama(): void
    {
        $lama = $this->pasienBernama('Contoh Pasien Ganda', '1980-02-02');
        $baru = $this->pasienBernama('Contoh Pasien Ganda', '1980-02-02');
        $jadwal = $this->jadwal($this->dokter());
        $this->masukSebagai($this->petugasPendaftaran());
        $kunjunganId = $this->daftarKunjungan($lama, $jadwal)->assertCreated()->json('data.id');
        $pengusul = $this->petugasGabung(['verifikasi']);
        $this->masukSebagai($pengusul);

        $usul = $this->minta('POST', '/api/v1/gabung-pasien', ['pasien_sumber_id' => $lama->id, 'pasien_tujuan_id' => $baru->id, 'alasan' => 'Pasien terdaftar dua kali'])
            ->assertCreated()->assertJsonPath('data.tahap', 'diusulkan')->assertJsonPath('data.dapat_verifikasi', false);
        $this->assertSesuaiKontrak($usul, 'POST', '/gabung-pasien');
        $id = $usul->json('data.id');
        $this->assertSame($lama->id, DB::table('kunjungan.kunjungan')->where('id', $kunjunganId)->value('pasien_id'));

        // Pengusul tidak boleh memverifikasi usulannya sendiri.
        $pengusulPenuh = $this->petugasGabung();
        $this->masukSebagai($pengusulPenuh);
        $dua = $this->minta('POST', '/api/v1/gabung-pasien', ['pasien_sumber_id' => $this->pasienBernama('Orang Lain', '1970-01-01')->id, 'pasien_tujuan_id' => $this->pasienBernama('Orang Lain', '1970-01-01')->id, 'alasan' => 'Uji pemisahan tugas'])->assertCreated();
        $this->minta('POST', '/api/v1/gabung-pasien/'.$dua->json('data.id').'/verifikasi', ['keputusan' => 'setuju'])
            ->assertForbidden()->assertJsonPath('error.kode', 'bukan_pemverifikasi');

        $this->masukSebagai($this->petugasGabung());
        $r = $this->minta('POST', "/api/v1/gabung-pasien/$id/verifikasi", ['keputusan' => 'setuju', 'catatan' => 'Sama orangnya'])->assertOk()
            ->assertJsonPath('data.tahap', 'digabung')->assertJsonPath('data.jumlah_dipindah.kunjungan', 1);
        $this->assertSesuaiKontrak($r, 'POST', '/gabung-pasien/{id}/verifikasi');

        $this->assertSame($baru->id, DB::table('kunjungan.kunjungan')->where('id', $kunjunganId)->value('pasien_id'));
        $this->assertSame(1, DB::table('kunjungan.episode')->where('pasien_id', $baru->id)->count());
        $this->assertSame(1, DB::table('akses.relasi_perawatan')->where('pasien_id', $baru->id)->count());
        $this->assertSame($baru->id, $lama->refresh()->digabung_ke_id);
        $this->assertDatabaseHas('pasien.pasien', ['id' => $lama->id]);
        $this->assertAuditTerakhir('pasien.gabung_diterapkan', $id);

        // RM lama bisa ditelusuri dari kedua arah, dan tidak dipakai lagi.
        $cari = function (Pasien $p) {
            $this->headerTambahan = ['X-Cari' => $p->no_rm];

            return $this->minta('GET', '/api/v1/pasien')->assertOk()->json('data.0');
        };
        $this->masukSebagai($this->petugasPendaftaran());
        $this->assertSame($baru->no_rm, $cari($lama)['digabung_ke']['no_rm']);
        $this->assertSame([$lama->no_rm], array_column($cari($baru)['digabung_dari'], 'no_rm'));
        $this->daftarKunjungan($lama, $jadwal)->assertStatus(409)->assertJsonPath('error.kode', 'pasien_digabung');
        $this->mintaDenganVersi('PATCH', '/api/v1/pasien/'.$lama->id, (string) $lama->refresh()->revisi, ['nama' => 'Ubah', 'alasan_perubahan' => 'Uji sesudah gabung'])
            ->assertStatus(409)->assertJsonPath('error.kode', 'pasien_digabung');
    }

    public function test_tolak_tanpa_catatan_gagal_dan_ditolak_tidak_memindahkan_apa_pun(): void
    {
        $lama = $this->pasienBernama('Contoh Satu', '1980-02-02');
        $baru = $this->pasienBernama('Contoh Satu', '1980-02-02');
        $this->masukSebagai($this->petugasGabung());
        $id = $this->minta('POST', '/api/v1/gabung-pasien', ['pasien_sumber_id' => $lama->id, 'pasien_tujuan_id' => $baru->id, 'alasan' => 'Dugaan ganda'])->assertCreated()->json('data.id');
        $this->masukSebagai($this->petugasGabung());
        $this->minta('POST', "/api/v1/gabung-pasien/$id/verifikasi", ['keputusan' => 'tolak'])->assertStatus(422);
        $this->minta('POST', "/api/v1/gabung-pasien/$id/verifikasi", ['keputusan' => 'tolak', 'catatan' => 'Orang berbeda'])->assertOk()->assertJsonPath('data.tahap', 'ditolak');
        $this->assertNull($lama->refresh()->digabung_ke_id);
        $this->minta('POST', "/api/v1/gabung-pasien/$id/verifikasi", ['keputusan' => 'setuju'])->assertStatus(409)->assertJsonPath('error.kode', 'tahap_tidak_sesuai');
        $this->minta('GET', '/api/v1/gabung-pasien?tahap=ditolak')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_usul_ditolak_bila_sama_sudah_digabung_atau_sudah_ada_usulan_terbuka(): void
    {
        $a = $this->pasienBernama('Contoh A', '1980-02-02');
        $b = $this->pasienBernama('Contoh A', '1980-02-02');
        $c = $this->pasienBernama('Contoh A', '1980-02-02');
        $this->masukSebagai($this->petugasGabung());
        $kirim = fn (Pasien $s, Pasien $t) => $this->minta('POST', '/api/v1/gabung-pasien', ['pasien_sumber_id' => $s->id, 'pasien_tujuan_id' => $t->id, 'alasan' => 'Dugaan ganda']);
        $kirim($a, $a)->assertStatus(422);
        $d = $this->pasienBernama('Contoh A', '1980-02-02');
        $d->forceFill(['digabung_ke_id' => $c->id, 'digabung_pada' => now()])->save();
        $kirim($d, $b)->assertStatus(409)->assertJsonPath('error.kode', 'pasien_sudah_digabung');
        $kirim($b, $d)->assertStatus(409)->assertJsonPath('error.kode', 'pasien_sudah_digabung');
        $kirim($a, $b)->assertCreated();
        $kirim($c, $b)->assertStatus(409)->assertJsonPath('error.kode', 'usulan_gabung_terbuka');
        $kirim($b, $c)->assertStatus(409)->assertJsonPath('error.kode', 'usulan_gabung_terbuka');
    }

    public function test_gabung_bentrok_bila_kunjungan_kedua_rm_bertumpuk(): void
    {
        $lama = $this->pasienBernama('Contoh Bentrok', '1980-02-02');
        $baru = $this->pasienBernama('Contoh Bentrok', '1980-02-02');
        $jadwal = $this->jadwal($this->dokter());
        $this->masukSebagai($this->petugasPendaftaran());
        $this->daftarKunjungan($lama, $jadwal)->assertCreated();
        $this->daftarKunjungan($baru, $jadwal)->assertCreated();
        $this->masukSebagai($this->petugasGabung());
        $id = $this->minta('POST', '/api/v1/gabung-pasien', ['pasien_sumber_id' => $lama->id, 'pasien_tujuan_id' => $baru->id, 'alasan' => 'Dugaan ganda'])->json('data.id');
        $this->masukSebagai($this->petugasGabung());
        $this->minta('POST', "/api/v1/gabung-pasien/$id/verifikasi", ['keputusan' => 'setuju'])->assertStatus(409)->assertJsonPath('error.kode', 'gabung_bentrok');
        $this->assertNull($lama->refresh()->digabung_ke_id);
        $this->assertSame('diusulkan', DB::table('pasien.usulan_gabung')->where('id', $id)->value('tahap'));
    }

    public function test_episode_harian_di_tanggal_sama_disatukan_tanpa_membatalkan_kunjungan_dilayani(): void
    {
        $lama = $this->pasienBernama('Contoh Episode', '1980-02-02');
        $baru = $this->pasienBernama('Contoh Episode', '1980-02-02');
        $pagi = $this->jadwal($this->dokter());
        $siang = $this->jadwal($this->dokter(), ['jam_mulai' => '13:00', 'jam_selesai' => '16:00']);
        $this->masukSebagai($this->petugasPendaftaran());
        $kunjunganLama = $this->daftarKunjungan($lama, $pagi)->assertCreated();
        $episodeBaru = $this->daftarKunjungan($baru, $siang)->assertCreated()->json('data.episode.id');
        DB::table('kunjungan.kunjungan')->where('id', $kunjunganLama->json('data.id'))->update(['status' => 'dilayani']);
        $id = $this->usulDanSetujui($lama, $baru)->assertJsonPath('data.tahap', 'digabung')->json('data.id');

        $this->assertSame([$baru->id, $episodeBaru, 'dilayani'], array_values((array) DB::table('kunjungan.kunjungan')
            ->where('id', $kunjunganLama->json('data.id'))->first(['pasien_id', 'episode_id', 'status'])));
        $episodeLama = DB::table('kunjungan.episode')->where('id', $kunjunganLama->json('data.episode.id'))->first();
        $this->assertSame(['batal', 'Digabung ke RM '.$baru->no_rm], [$episodeLama->status, $episodeLama->alasan_batal]);
        $this->assertSame(1, DB::table('kunjungan.episode')->where('pasien_id', $baru->id)->where('status', '<>', 'batal')->count());
        $this->assertAuditTerakhir('pasien.gabung_diterapkan', $id);
    }

    public function test_nomor_identitas_rm_sumber_pindah_ke_tujuan_tanpa_dihapus_dan_tanpa_bocor_ke_audit(): void
    {
        $lama = $this->pasienBernama('Contoh Identitas', '1980-02-02');
        $baru = $this->pasienBernama('Contoh Identitas', '1980-02-02');
        foreach ([[$lama, 'nik', '9999010101800001'], [$lama, 'kartu_bpjs', '9999000000001'], [$baru, 'nik', '9999010101800002']] as [$p, $jenis, $nilai]) {
            $i = new IdentitasPasien(['pasien_id' => $p->id, 'jenis' => $jenis]);
            $i->aturNilai($nilai);
            $i->save();
        }
        $id = $this->usulDanSetujui($lama, $baru)->assertJsonPath('data.tahap', 'digabung')->json('data.id');

        $nilai = fn (Pasien $p) => IdentitasPasien::query()->where('pasien_id', $p->id)->get()->mapWithKeys(fn ($i) => [$i->jenis => $i->nilai_enkripsi])->sortKeys()->all();
        $this->assertSame(['kartu_bpjs' => '9999000000001', 'nik' => '9999010101800002'], $nilai($baru));
        $this->assertSame(['kartu_bpjs' => null, 'nik' => null], $nilai($lama));
        $versi = DB::table('pasien.pasien_versi')->where('pasien_id', $lama->id)->value('isi_enkripsi');
        $this->assertEquals(['nik' => '9999010101800001', 'no_kartu_bpjs' => '9999000000001'], json_decode(Crypt::decryptString((string) $versi), true));
        $audit = $this->assertAuditTerakhir('pasien.gabung_diterapkan', $id);
        foreach (['9999010101800001', '9999000000001', $lama->no_rm] as $rahasia) {
            $this->assertStringNotContainsString($rahasia, (string) $audit->sesudah);
        }
    }

    private function usulDanSetujui(Pasien $sumber, Pasien $tujuan): TestResponse
    {
        $this->masukSebagai($this->petugasGabung());
        $id = $this->minta('POST', '/api/v1/gabung-pasien', ['pasien_sumber_id' => $sumber->id, 'pasien_tujuan_id' => $tujuan->id, 'alasan' => 'Dugaan ganda'])
            ->assertCreated()->json('data.id');
        $this->masukSebagai($this->petugasGabung());

        return $this->minta('POST', "/api/v1/gabung-pasien/$id/verifikasi", ['keputusan' => 'setuju']);
    }

    public function test_usul_dan_verifikasi_butuh_izin_masing_masing(): void
    {
        $a = $this->pasienBernama('Contoh B', '1980-02-02');
        $b = $this->pasienBernama('Contoh B', '1980-02-02');
        $this->masukSebagai($this->petugasGabung(['pendaftaran.master_pasien.usul']));
        $this->minta('POST', '/api/v1/gabung-pasien', ['pasien_sumber_id' => $a->id, 'pasien_tujuan_id' => $b->id, 'alasan' => 'Dugaan ganda'])->assertForbidden();
        $this->masukSebagai($this->petugasGabung(['pendaftaran.master_pasien.verifikasi']));
        $id = $this->minta('POST', '/api/v1/gabung-pasien', ['pasien_sumber_id' => $a->id, 'pasien_tujuan_id' => $b->id, 'alasan' => 'Dugaan ganda'])->assertCreated()->json('data.id');
        $this->masukSebagai($this->petugasGabung(['pendaftaran.master_pasien.verifikasi']));
        $this->minta('POST', "/api/v1/gabung-pasien/$id/verifikasi", ['keputusan' => 'setuju'])->assertForbidden();
    }

    private function pasienBernama(string $nama, string $lahir): Pasien
    {
        $pasien = $this->pasien();
        $pasien->forceFill(['nama' => $nama, 'tanggal_lahir' => $lahir])->save();

        return $pasien;
    }

    /** Petugas Rekam Medis uji: semua aksi master pasien kecuali $tanpa (kode izin penuh atau aksi saja). */
    private function petugasGabung(array $tanpa = []): Pengguna
    {
        $tanpa = array_map(fn ($k) => str_contains($k, '.') ? $k : "pendaftaran.master_pasien.$k", $tanpa);
        $pengguna = $this->buatPengguna();
        $peran = $this->buatPeran([]);
        $menu = Menu::query()->where('kode', 'pendaftaran.master_pasien')->firstOrFail();
        PeranMenu::query()->create(['peran_id' => $peran->id, 'menu_id' => $menu->id]);
        foreach (Izin::query()->where('menu_id', $menu->id)->whereNotIn('kode', $tanpa)->pluck('id') as $izinId) {
            PeranIzin::query()->create(['peran_id' => $peran->id, 'izin_id' => $izinId]);
        }
        $this->tugaskan($pengguna, $peran);

        return $pengguna;
    }
}
