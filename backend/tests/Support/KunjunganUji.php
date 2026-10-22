<?php

namespace Tests\Support;

use App\Modules\Akses\Models\Izin;
use App\Modules\Akses\Models\Menu;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\Akses\Models\PeranIzin;
use App\Modules\Akses\Models\PeranMenu;
use App\Modules\MasterData\Models\JadwalPraktik;
use App\Modules\MasterData\Models\Penjamin;
use App\Modules\MasterData\Models\Poliklinik;
use App\Modules\MasterData\Models\UnitOrganisasi;
use App\Modules\Pendaftaran\Models\Pasien;
use App\Modules\Sdm\Models\Pegawai;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;

/**
 * Data sintetis kunjungan poli (tiket 2.6): poli, dokter, jadwal Senin, pasien, penjamin. "Hari ini" dipatok Senin
 * 2 Nov 2026 07.00 zona RS agar aturan tanggal dan jam tutup deterministik.
 */
trait KunjunganUji
{
    protected const SENIN = '2026-11-02';

    protected Poliklinik $poli;

    protected function siapkanKunjungan(): void
    {
        $this->travelTo(Carbon::parse(self::SENIN.' 07:00', (string) config('instalasi.zona_waktu')));
        $unit = UnitOrganisasi::query()->create(['kode' => 'POLI-UJI', 'nama' => 'Poli Uji', 'jenis' => 'poli', 'berlaku_dari' => '2026-01-01']);
        $this->poli = Poliklinik::query()->create(['kode' => 'UJI', 'nama' => 'Poli Contoh', 'unit_organisasi_id' => $unit->id]);
    }

    protected function dokter(): Pegawai
    {
        /** @var Pegawai $pegawai */
        $pegawai = Pegawai::factory()->create(['profesi' => 'dokter']);

        return $pegawai;
    }

    protected function akunDokter(Pegawai $dokter): Pengguna
    {
        return $this->buatPengguna(['pegawai_id' => $dokter->id]);
    }

    protected function jadwal(Pegawai $dokter, array $ganti = []): JadwalPraktik
    {
        $jadwal = new JadwalPraktik;
        $jadwal->forceFill([
            'pegawai_id' => $dokter->id, 'poli_id' => $this->poli->id, 'hari' => 1, 'jam_mulai' => '08:00', 'jam_selesai' => '12:00',
            'kuota_jkn' => 5, 'kuota_non_jkn' => 5, 'berlaku_dari' => '2026-10-01', ...$ganti,
        ])->save();

        return $jadwal->refresh();
    }

    protected function pasien(): Pasien
    {
        static $urut = 0;
        $pasien = new Pasien;
        $pasien->forceFill([
            'no_rm' => '99'.str_pad((string) (++$urut + random_int(0, 99999) * 100), 10, '0', STR_PAD_LEFT), 'nama' => 'Pasien Sintetis '.$urut,
            'tanggal_lahir' => '1990-01-01', 'jenis_kelamin' => 'perempuan',
        ])->save();

        return $pasien;
    }

    protected function penjamin(string $jenis): Penjamin
    {
        return Penjamin::query()->create(['kode' => 'UJI-'.strtoupper($jenis), 'nama' => 'Penjamin Uji '.$jenis, 'jenis' => $jenis, 'status' => 'aktif']);
    }

    /** Petugas pendaftaran dengan semua aksi pendaftaran.pasien, kecuali $tanpa. */
    protected function petugasPendaftaran(array $tanpa = []): Pengguna
    {
        $pengguna = $this->buatPengguna();
        $peran = $this->buatPeran([]);
        $menu = Menu::query()->where('kode', 'pendaftaran.pasien')->firstOrFail();
        PeranMenu::query()->create(['peran_id' => $peran->id, 'menu_id' => $menu->id]);
        foreach (Izin::query()->where('menu_id', $menu->id)->whereNotIn('kode', $tanpa)->pluck('id') as $izinId) {
            PeranIzin::query()->create(['peran_id' => $peran->id, 'izin_id' => $izinId]);
        }
        $this->tugaskan($pengguna, $peran);

        return $pengguna;
    }

    protected function daftarKunjungan(Pasien $pasien, JadwalPraktik $jadwal, array $ganti = []): TestResponse
    {
        return $this->minta('POST', '/api/v1/kunjungan', [
            'pasien_id' => $pasien->id, 'jadwal_praktik_id' => $jadwal->id, 'tanggal' => self::SENIN, 'cara_bayar' => 'non_jkn', ...$ganti,
        ]);
    }
}
