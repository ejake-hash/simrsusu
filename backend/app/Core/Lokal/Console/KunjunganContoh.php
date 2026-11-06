<?php

namespace App\Core\Lokal\Console;

use App\Modules\MasterData\Models\JadwalPraktik;
use App\Modules\MasterData\Models\Poliklinik;
use App\Modules\MasterData\Models\UnitOrganisasi;
use App\Modules\Sdm\Models\IzinPraktik;
use App\Modules\Sdm\Models\Pegawai;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * `php artisan lokal:kunjungan-contoh` (e2e pendaftaran): poli, dokter ber-SIP, dan jadwal praktik sintetis setiap hari
 * 00.00–23.59, lalu instalasi ditandai siap melayani. Penanda itu biasanya terkunci lewat wizard penyiapan langkah 1–6,
 * yang tidak bisa dilengkapi data sintetis; `--batal` mengosongkannya lagi agar spec penyiapan tetap bisa diulang.
 * Hanya APP_ENV=local.
 */
final class KunjunganContoh extends Command
{
    protected $signature = 'lokal:kunjungan-contoh {--batal : Kosongkan lagi penanda siap melayani}';

    protected $description = 'Menyiapkan poli, dokter, dan jadwal sintetis lalu menandai instalasi siap melayani (hanya lokal)';

    public function handle(): int
    {
        if (! $this->laravel->environment('local')) {
            $this->error('Ditolak: hanya untuk APP_ENV=local.');

            return self::FAILURE;
        }

        if ($this->option('batal')) {
            DB::table('inti.profil_rs')->update(['siap_melayani_sejak' => null]);
            $this->info('Instalasi tidak lagi ditandai siap melayani.');

            return self::SUCCESS;
        }

        $unit = UnitOrganisasi::query()->where('kode', 'POLI-PD')->first();
        if ($unit === null) {
            $this->error('Unit POLI-PD belum ada: jalankan php artisan db:seed lebih dulu.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($unit) {
            $poli = Poliklinik::query()->updateOrCreate(['kode' => 'POLI-PD'], ['nama' => 'Poli Penyakit Dalam (contoh)', 'unit_organisasi_id' => $unit->id, 'status' => 'aktif']);
            $dokter = Pegawai::query()->updateOrCreate(['nip' => 'SINTETIS-POLI'], [
                'nama' => 'Contoh Dokter Poli', 'profesi' => 'dokter', 'jabatan' => 'Dokter poli (contoh)', 'jenis_kepegawaian' => 'Sintetis',
                'tanggal_keluar' => null, 'alasan_keluar' => null,
            ]);
            $dokter->forceFill(['status' => 'aktif'])->save();
            IzinPraktik::query()->updateOrCreate(
                ['pegawai_id' => $dokter->id, 'jenis' => 'sip', 'nomor' => 'SIP/CONTOH/POLI'],
                ['terbit' => '2020-01-01', 'berlaku_sampai' => '2040-01-01', 'status' => 'aktif'],
            );
            foreach (range(1, 7) as $hari) {
                JadwalPraktik::query()->updateOrCreate(['pegawai_id' => $dokter->id, 'poli_id' => $poli->id, 'hari' => $hari], [
                    'jam_mulai' => '00:00', 'jam_selesai' => '23:59', 'kuota_jkn' => 1000, 'kuota_non_jkn' => 1000,
                    'berlaku_dari' => '2026-01-01', 'berlaku_sampai' => null, 'status' => 'aktif',
                ]);
            }

            $profil = DB::table('inti.profil_rs')->first(['id', 'siap_melayani_sejak']);
            if ($profil === null) {
                DB::table('inti.profil_rs')->insert(['id' => (string) Str::uuid7(), 'paket_identitas' => '{}', 'siap_melayani_sejak' => now()]);
            } elseif ($profil->siap_melayani_sejak === null) {
                DB::table('inti.profil_rs')->update(['siap_melayani_sejak' => now()]);
            }
        });
        $this->info('Poli, dokter, dan jadwal contoh siap; instalasi ditandai siap melayani.');

        return self::SUCCESS;
    }
}
