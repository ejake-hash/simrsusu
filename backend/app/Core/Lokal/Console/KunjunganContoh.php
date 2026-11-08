<?php

namespace App\Core\Lokal\Console;

use App\Modules\Akses\Models\Pengguna;
use App\Modules\Integrasi\Application\KelolaKredensial;
use App\Modules\Integrasi\Domain\SistemIntegrasi;
use App\Modules\MasterData\Models\JadwalPraktik;
use App\Modules\MasterData\Models\Penjamin;
use App\Modules\MasterData\Models\Poliklinik;
use App\Modules\MasterData\Models\UnitOrganisasi;
use App\Modules\Sdm\Models\IzinPraktik;
use App\Modules\Sdm\Models\Pegawai;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * `php artisan lokal:kunjungan-contoh` (e2e pendaftaran): poli (berkode poli BPJS, prefiks antrean `PD`, area display `poli-pd`), penjamin BPJS, kredensial VClaim dan kode PPK sintetis (SEP, tiket 2.7), dokter ber-SIP, dan jadwal praktik sintetis setiap hari
 * 00.00–23.59, lalu instalasi ditandai siap melayani. Penanda itu biasanya terkunci lewat wizard penyiapan langkah 1–6,
 * yang tidak bisa dilengkapi data sintetis, jadi perintah ini memasang nilai tetap `SEJAK`. `--batal` mengosongkannya lagi
 * (hanya bila nilainya masih `SEJAK`, bukan hasil wizard) agar spec penyiapan tetap bisa diulang, dan menonaktifkan poli
 * `POLI-PD` beserta jadwalnya (data induk dirujuk kunjungan: tidak dihapus). Paket ICD-10 sintetis (2.15) dan butir tarif penunjang sintetis (2.16), dan obat sintetis (2.17) tetap tinggal.
 * Hanya APP_ENV=local.
 */
final class KunjunganContoh extends Command
{
    /** Penanda siap melayani yang dipasang perintah ini; wizard memasang waktu selesai sebenarnya. */
    public const SEJAK = '2026-01-01 00:00:00+00';

    protected $signature = 'lokal:kunjungan-contoh {--batal : Kosongkan lagi penanda siap melayani dan nonaktifkan poli contoh}';

    protected $description = 'Menyiapkan poli, dokter, dan jadwal sintetis lalu menandai instalasi siap melayani (hanya lokal)';

    public function handle(): int
    {
        if (! $this->laravel->environment('local')) {
            $this->error('Ditolak: hanya untuk APP_ENV=local.');

            return self::FAILURE;
        }

        if ($this->option('batal')) {
            DB::transaction(function () {
                DB::table('inti.profil_rs')->where('siap_melayani_sejak', self::SEJAK)->update(['siap_melayani_sejak' => null]);
                $poliId = Poliklinik::query()->where('kode', 'POLI-PD')->value('id');
                JadwalPraktik::query()->where('poli_id', $poliId)->update(['status' => 'nonaktif']);
                Poliklinik::query()->whereKey($poliId)->update(['status' => 'nonaktif']);
            });
            $this->info('Penanda siap melayani dari perintah ini dikosongkan; poli contoh dinonaktifkan.');

            return self::SUCCESS;
        }

        $unit = UnitOrganisasi::query()->where('kode', 'POLI-PD')->first();
        if ($unit === null) {
            $this->error('Unit POLI-PD belum ada: jalankan php artisan db:seed lebih dulu.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($unit) {
            $poli = Poliklinik::query()->updateOrCreate(['kode' => 'POLI-PD'], ['nama' => 'Poli Penyakit Dalam (contoh)', 'unit_organisasi_id' => $unit->id, 'status' => 'aktif']);
            $poli->forceFill(['antrean_prefiks' => 'PD'])->save();
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

            // Pemeriksaan (2.15): worklist dokter hanya memuat pasien jadwalnya sendiri (7.07.1), jadi akun sintetis `dokter.poli`
            // ditautkan ke dokter jadwal poli contoh.
            Pengguna::query()->where('nama_pengguna', 'dokter.poli')->update(['pegawai_id' => $dokter->id]);

            // SEP (2.7): pemetaan BPJS, kode PPK sintetis, penjamin BPJS, dan kredensial VClaim sintetis (dijawab simulator).
            $poli->forceFill(['kode_poli_bpjs' => 'INT'])->save();
            Penjamin::query()->updateOrCreate(['kode' => 'CONTOH-BPJS'], ['nama' => 'BPJS Kesehatan (contoh)', 'jenis' => 'bpjs', 'status' => 'aktif']);
            $oleh = Pengguna::query()->orderBy('id')->value('id');
            if ($oleh !== null && ! DB::table('integrasi.kredensial_api')->where('tujuan', 'bpjs_vclaim')->exists()) {
                app(KelolaKredensial::class)->simpan(SistemIntegrasi::BpjsVclaim, 'sandbox',
                    ['cons_id' => '1234', 'secret_key' => 'rahasia-sintetis', 'user_key' => 'kunci-sintetis'], true, false, $oleh);
            }

            // Modul 07 Rawat Jalan (worklist, asesmen perawat; 2.13) dan 26 RME (CPPT; 2.14) dinyalakan RS lewat penyiapan; di lokal langsung aktif.
            foreach ([7, 26] as $modul) {
                DB::table('inti.modul_aktif')->insertOrIgnore(['id' => (string) Str::uuid7(), 'modul_no' => $modul]);
            }

            // Pemeriksaan dokter (2.15): paket ICD-10 SINTETIS (kode ZZC*, bukan kode resmi) hanya bila belum ada paket ICD-10
            // sama sekali; paket resmi yang dimuat RS (K7) tidak pernah ditimpa.
            if (! DB::table('inti.paket_referensi')->where('sistem', 'icd10')->exists()) {
                DB::table('inti.paket_referensi')->insert(['id' => (string) Str::uuid7(), 'sistem' => 'icd10', 'versi' => 'contoh-sintetis',
                    'sumber' => 'Data sintetis lokal (bukan ICD-10 resmi)', 'jumlah_kode' => 2, 'sidik_isi' => str_repeat('0', 64), 'dimuat_pada' => now()]);
                foreach (['ZZC01' => 'Diagnosis contoh sintetis satu', 'ZZC02' => 'Diagnosis contoh sintetis dua'] as $kode => $tampilan) {
                    DB::table('inti.terminologi')->insert(['id' => (string) Str::uuid7(), 'sistem' => 'icd10', 'kode' => $kode, 'tampilan' => $tampilan, 'versi_terakhir' => 'contoh-sintetis']);
                }
            }

            // Order penunjang (2.16): butir tarif SINTETIS ber-kategori lab/rad (tanpa harga; bukan tarif RS), hanya bila kodenya belum ada.
            foreach (['CONTOH-LAB-01' => ['Pemeriksaan lab contoh satu', 'lab'], 'CONTOH-LAB-02' => ['Pemeriksaan lab contoh dua', 'lab'],
                'CONTOH-RAD-01' => ['Pemeriksaan radiologi contoh', 'rad']] as $kode => [$nama, $kategori]) {
                if (! DB::table('keuangan.tarif')->where('kode', $kode)->exists()) {
                    $tarifId = (string) Str::uuid7();
                    DB::table('keuangan.tarif')->insert(['id' => $tarifId, 'kode' => $kode]);
                    DB::table('keuangan.tarif_versi')->insert(['id' => (string) Str::uuid7(), 'tarif_id' => $tarifId, 'versi' => 1, 'nama' => $nama,
                        'kelompok' => 'Contoh penunjang', 'kategori' => $kategori, 'status' => 'aktif', 'berlaku_dari' => '2026-01-01', 'dasar' => 'Data sintetis lokal']);
                }
            }

            // E-resep (2.17): obat SINTETIS (bukan katalog RS) di formularium RS contoh, hanya bila kodenya belum ada.
            foreach (['CONTOH-OBAT-01' => ['Obat contoh satu', 'Zat contoh satu'], 'CONTOH-OBAT-02' => ['Obat contoh dua', 'Zat contoh dua']] as $kode => [$nama, $zat]) {
                if (! DB::table('logistik.barang')->where('kode', $kode)->exists()) {
                    DB::table('logistik.barang')->insert(['id' => (string) Str::uuid7(), 'kode' => $kode, 'nama' => $nama, 'jenis' => 'obat',
                        'generik_paten' => 'generik', 'zat_aktif' => $zat, 'satuan_terkecil' => 'tablet']);
                }
            }

            $profil = DB::table('inti.profil_rs')->first(['id', 'siap_melayani_sejak']);
            if ($profil === null) {
                DB::table('inti.profil_rs')->insert(['id' => (string) Str::uuid7(), 'paket_identitas' => '{}', 'siap_melayani_sejak' => self::SEJAK]);
            } elseif ($profil->siap_melayani_sejak === null) {
                DB::table('inti.profil_rs')->update(['siap_melayani_sejak' => self::SEJAK]);
            }
            DB::table('inti.profil_rs')->whereNull('kode_ppk_bpjs')->update(['kode_ppk_bpjs' => 'SIM0RS01']);
        });
        $this->info('Poli, dokter, dan jadwal contoh siap; instalasi ditandai siap melayani.');

        return self::SUCCESS;
    }
}
