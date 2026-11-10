<?php

namespace App\Core\Lokal\Console;

use App\Modules\Akses\Models\Pengguna;
use App\Modules\Antrean\Models\Anjungan;
use App\Modules\Integrasi\Application\KelolaKredensial;
use App\Modules\Integrasi\Domain\SistemIntegrasi;
use App\Modules\MasterData\Models\Bangsal;
use App\Modules\MasterData\Models\JadwalPraktik;
use App\Modules\MasterData\Models\Kamar;
use App\Modules\MasterData\Models\KelasRawat;
use App\Modules\MasterData\Models\Lokasi;
use App\Modules\MasterData\Models\Penjamin;
use App\Modules\MasterData\Models\Poliklinik;
use App\Modules\MasterData\Models\TempatTidur;
use App\Modules\MasterData\Models\UnitOrganisasi;
use App\Modules\RawatInap\Application\UbahStatusTempatTidur;
use App\Modules\RawatInap\Domain\StatusTempatTidur;
use App\Modules\Sdm\Models\IzinPraktik;
use App\Modules\Sdm\Models\Pegawai;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * `php artisan lokal:kunjungan-contoh` (e2e pendaftaran): poli (berkode poli BPJS, prefiks antrean `PD`, area display `poli-pd`), penjamin BPJS, kredensial VClaim dan kode PPK sintetis (SEP, tiket 2.7), dokter ber-SIP, dan jadwal praktik sintetis setiap hari
 * 00.00–23.59, anjungan sintetis `apm-contoh` (tiket 2.20), instrumen triase sintetis `CONTOH-TRIASE` (tiket 2.24), area IGD sintetis `CONTOH-*` (tiket 2.25), lalu instalasi ditandai siap melayani. Penanda itu biasanya terkunci lewat wizard penyiapan langkah 1–6,
 * yang tidak bisa dilengkapi data sintetis, jadi perintah ini memasang nilai tetap `SEJAK`. `--batal` mengosongkannya lagi
 * (hanya bila nilainya masih `SEJAK`, bukan hasil wizard) agar spec penyiapan tetap bisa diulang, dan menonaktifkan poli
 * `POLI-PD` beserta jadwalnya (data induk dirujuk kunjungan: tidak dihapus). Paket ICD-10 sintetis (2.15) dan butir tarif penunjang sintetis (2.16), dan obat sintetis (2.17) tetap tinggal.
 * Hanya APP_ENV=local.
 */
final class KunjunganContoh extends Command
{
    /** Penanda siap melayani yang dipasang perintah ini; wizard memasang waktu selesai sebenarnya. */
    public const SEJAK = '2026-01-01 00:00:00+00';

    /** Peta TT contoh (2.27): kode ruang => kode TT => status. Semua status peta kecuali TT ditutup (tampil tidak_dipakai). */
    public const STATUS_CONTOH = [
        '201' => [1 => StatusTempatTidur::TERISI, 2 => StatusTempatTidur::DIBERSIHKAN, 3 => StatusTempatTidur::DIPESAN],
        '202' => [1 => StatusTempatTidur::RENCANA_PULANG, 2 => StatusTempatTidur::TIDAK_DIPAKAI, 3 => StatusTempatTidur::KOSONG_SIAP],
    ];

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

            // Akses darurat (2.21): `dokter.darurat` tanpa relasi pasien, tetapi SIP-nya berlaku (lapis 5).
            $darurat = Pengguna::query()->where('nama_pengguna', 'dokter.darurat')->first()?->pegawai_id;
            if ($darurat) {
                IzinPraktik::query()->updateOrCreate(
                    ['pegawai_id' => $darurat, 'jenis' => 'sip', 'nomor' => 'SIP/CONTOH/DARURAT'],
                    ['terbit' => '2020-01-01', 'berlaku_sampai' => '2040-01-01', 'status' => 'aktif'],
                );
            }

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

            // Triase IGD (2.24): instrumen SINTETIS berlabel contoh (bukan instrumen klinis RS), hanya bila kodenya belum ada.
            if (! DB::table('inti.instrumen_triase')->where('kode', 'CONTOH-TRIASE')->exists()) {
                DB::table('inti.instrumen_triase')->insert(['id' => (string) Str::uuid7(), 'kode' => 'CONTOH-TRIASE', 'nama' => 'Instrumen triase contoh',
                    'skala' => json_encode(array_map(fn (int $n) => ['kode' => "C$n", 'label' => "Tingkat contoh $n"], range(1, 3)))]);
            }

            // Papan IGD (2.25): area SINTETIS berlabel contoh (nama area FR-03-03; bukan data induk RS), hanya bila kodenya belum ada.
            foreach ([['CONTOH-RESUSITASI', 'Resusitasi (contoh)', 1], ['CONTOH-OBSERVASI', 'Observasi (contoh)', 2], ['CONTOH-HIJAU', 'Hijau (contoh)', 3]] as [$kode, $nama, $urutan]) {
                if (! DB::table('inti.area_igd')->where('kode', $kode)->exists()) {
                    DB::table('inti.area_igd')->insert(['id' => (string) Str::uuid7(), 'kode' => $kode, 'nama' => $nama, 'urutan' => $urutan]);
                }
            }
            // Ambang mendekati 6 jam SINTETIS (60 menit) hanya bila RS belum mengisinya; produksi kosong sampai RS mengisi.
            DB::table('inti.aturan_papan_igd')->whereNull('peringatan_lama_igd_menit')->update(['peringatan_lama_igd_menit' => 60]);

            // Anjungan (2.20): perangkat SINTETIS `apm-contoh` di lobi contoh, nomor loket ke loket contoh (area display `loket`).
            // Token tidak disimpan di repo: php artisan antrean:token-anjungan apm-contoh.
            [$lobi, $loket] = array_map(fn (array $l) => Lokasi::query()->updateOrCreate(['kode_lengkap' => $l[0]], ['jenis' => 'ruang', 'fungsi' => 'administrasi',
                'kode' => $l[0], 'nama' => $l[1], 'status' => 'aktif', 'status_berlaku_dari' => '2026-01-01'])->id,
                [['CTHLOBI', 'Lobi Rawat Jalan (contoh)'], ['CTHLOKET', 'Loket Pendaftaran 1 (contoh)']]);
            Anjungan::query()->updateOrCreate(['kode' => 'apm-contoh'], ['lokasi_id' => $lobi, 'lokasi_loket_id' => $loket]);

            $this->petaTempatTidurContoh($oleh);

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

    /**
     * Peta TT (2.27): gedung `CTHG`, lantai 2, kamar 201/202 di bangsal SINTETIS `CONTOH-MELATI` (unit RUANG-MELATI), kelas
     * `CONTOH-KELAS`, dan enam TT berlabel contoh (bukan data induk RS). Setiap kali dijalankan status TT dikembalikan ke
     * STATUS_CONTOH lewat layanan status (riwayat + audit), agar spec peta yang mengubah status bisa diulang.
     */
    private function petaTempatTidurContoh(?string $oleh): void
    {
        $unit = UnitOrganisasi::query()->where('kode', 'RUANG-MELATI')->value('id');
        if ($unit === null) {
            return;
        }
        $lokasi = fn (?string $induk, string $jenis, string $kode, string $kodeLengkap, string $nama, ?string $fungsi = null) => Lokasi::query()
            ->firstOrCreate(['kode_lengkap' => $kodeLengkap], ['induk_id' => $induk, 'jenis' => $jenis, 'kode' => $kode, 'nama' => $nama, 'fungsi' => $fungsi,
                'unit_organisasi_id' => $jenis === 'gedung' || $jenis === 'lantai' ? null : $unit, 'status' => 'aktif', 'status_berlaku_dari' => '2026-01-01'])->id;

        $lantai = $lokasi($lokasi(null, 'gedung', 'CTHG', 'CTHG', 'Gedung Rawat Inap (contoh)'), 'lantai', '2', 'CTHG-2', 'Lantai 2 (contoh)');
        $kelas = KelasRawat::query()->firstOrCreate(['kode' => 'CONTOH-KELAS'], ['nama' => 'Kelas contoh', 'berlaku_dari' => '2026-01-01', 'status' => 'aktif'])->id;
        $bangsal = Bangsal::query()->firstOrCreate(['kode' => 'CONTOH-MELATI'], ['nama' => 'Bangsal Melati (contoh)', 'unit_organisasi_id' => $unit, 'status' => 'aktif'])->id;
        $layanan = app(UbahStatusTempatTidur::class);
        foreach (self::STATUS_CONTOH as $ruang => $tempatTidur) {
            $ruangId = $lokasi($lantai, 'ruang', (string) $ruang, "CTHG-2-$ruang", "Kamar $ruang (contoh)", 'rawat_inap');
            $kamar = Kamar::query()->firstOrCreate(['lokasi_id' => $ruangId], ['bangsal_id' => $bangsal])->id;
            foreach ($tempatTidur as $kode => $status) {
                $kodeLengkap = "CTHG-2-$ruang-$kode";
                $tt = TempatTidur::query()->where('kode', $kodeLengkap)->first() ?? TempatTidur::query()->create([
                    'kamar_id' => $kamar, 'lokasi_id' => $lokasi($ruangId, 'tempat_tidur', (string) $kode, $kodeLengkap, "TT $kode"), 'kode' => $kodeLengkap,
                    'kelas_rawat_id' => $kelas, 'berlaku_dari' => '2026-01-01',
                ]);
                $sekarang = (string) DB::table('inti.tempat_tidur')->where('id', $tt->id)->value('status_saat_ini');
                if ($sekarang !== $status) {
                    $layanan->ubah($tt->id, $status, StatusTempatTidur::SUMBER_PETUGAS, $oleh, [$sekarang]);
                }
            }
        }
    }
}
