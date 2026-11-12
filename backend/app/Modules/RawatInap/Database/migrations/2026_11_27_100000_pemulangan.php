<?php

use App\Modules\Akses\Application\CacheHakAkses;
use App\Modules\Akses\Database\MenuBawaan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Pemulangan rawat inap (tiket 2.40; FR-05-01..05, PRD 11.2 `rencana_pulang` dan `resume_medis`, keputusan pemilik 13 Nov 2026).
 * - inti.daftar_periksa_pulang: data induk daftar periksa pulang (FR-05-03). Isi awal 5 butir PRD; diubah langsung oleh
 *   pemegang master_data.daftar_periksa_pulang.* (pola area IGD). Sumber `kejadian` = diselesaikan kejadian modul lain
 *   (tagihan/jaminan, Tahap D), tidak menahan pulang.
 * - kunjungan.rencana_pulang: satu per kunjungan rawat inap; daftar periksa disalin (kode + nama) saat rencana dibuat.
 * - klinis.resume_medis: satu per kunjungan rawat inap; potret sumber + isian DPJP di `ringkasan` (KLINIS, tidak ke
 *   log/audit), sah sekali (adendum menyusul).
 * - Sel matriks rawat_inap → pulang | aps | rujuk_keluar | meninggal, izin sel rawat_inap.pemulangan.input (IZIN_KLINIS).
 * - Jenis tugas RESUME_BELUM_TTE_48J (FR-05-04, PRD 17.2 #8): tenggat 2 × 24 jam sejak tugas dibuat saat pulang.
 */
return new class extends Migration
{
    private const TUJUAN = ['pulang' => 'PRD 7.00.3: pulang', 'aps' => 'FR-05-07: pulang atas permintaan sendiri',
        'rujuk_keluar' => 'PRD 7.00.3: rujuk keluar', 'meninggal' => 'PRD 7.00.3: meninggal'];

    public function up(): void
    {
        Schema::create('inti.daftar_periksa_pulang', function (Blueprint $table) {
            $table->comment('Data induk daftar periksa pulang rawat inap (FR-05-03). Tidak dihapus: nonaktif. Kode disalin ke rencana pulang.');
            $table->uuid('id')->primary();
            $table->text('kode')->unique();
            $table->text('nama');
            $table->text('sumber')->default('manual')->comment('manual = dicentang petugas; kejadian = diselesaikan kejadian modul lain (kasir, Tahap D)');
            $table->boolean('wajib')->default(true)->comment('Wajib selesai sebelum pulang, atau diberi alasan per butir');
            $table->integer('urutan')->default(0);
            $table->text('status')->default('aktif');
            $table->kolomStandar();
        });
        DB::statement("ALTER TABLE inti.daftar_periksa_pulang ADD CONSTRAINT daftar_periksa_pulang_cek CHECK (sumber IN ('manual', 'kejadian') AND status IN ('aktif', 'nonaktif') AND urutan BETWEEN 0 AND 999)");

        Schema::create('kunjungan.rencana_pulang', function (Blueprint $table) {
            $table->comment('Rencana pulang kunjungan rawat inap (PRD 11.2 `rencana_pulang`, FR-05-01, FR-05-03). Satu per kunjungan; tidak dihapus.');
            $table->uuid('id')->primary();
            $table->foreignUuid('kunjungan_id')->unique()->comment('Kunjungan jenis rawat_inap')->constrained('kunjungan.kunjungan')->restrictOnDelete();
            $table->date('rencana_pulang_pada')->comment('Tanggal rencana pulang, zona RS');
            $table->integer('rencana_lama_rawat')->nullable()->comment('Rencana lama rawat (hari); kosong = belum ditetapkan');
            $table->jsonb('daftar_periksa')->default('[]')->comment('Salinan butir data induk + status selesai/alasan per butir');
            $table->text('status')->default('direncanakan');
            $table->kolomStandar();
        });
        DB::statement("ALTER TABLE kunjungan.rencana_pulang ADD CONSTRAINT rencana_pulang_cek CHECK (status IN ('direncanakan', 'pulang', 'batal') AND (rencana_lama_rawat IS NULL OR rencana_lama_rawat > 0) AND jsonb_typeof(daftar_periksa) = 'array')");

        Schema::create('klinis.resume_medis', function (Blueprint $table) {
            $table->comment('Resume medis pulang rawat inap (PRD 11.2 `resume_medis`, FR-05-02). Satu per kunjungan; sah = final (adendum menyusul).');
            $table->uuid('id')->primary();
            $table->foreignUuid('kunjungan_id')->unique()->comment('Kunjungan jenis rawat_inap')->constrained('kunjungan.kunjungan')->restrictOnDelete();
            $table->foreignUuid('pasien_id')->constrained('pasien.pasien')->restrictOnDelete();
            $table->jsonb('ringkasan')->comment('KLINIS: potret sumber (CPPT, diagnosis, resep, alergi) + isian DPJP; tidak pernah ke log/audit');
            $table->text('status')->default('draf');
            $table->foreignUuid('disusun_oleh')->comment('akses.pengguna.id penyusun terakhir')->constrained('akses.pengguna')->restrictOnDelete();
            $table->timestampTz('disusun_pada', 6);
            $table->uuid('disahkan_oleh')->nullable()->comment('akses.pengguna.id DPJP');
            $table->timestampTz('disahkan_pada', 6)->nullable();
            $table->foreignUuid('tanda_tangan_id')->nullable()->constrained('ttd.tanda_tangan')->restrictOnDelete();
            $table->text('satusehat_composition_id')->nullable()->comment('Tahap D');
            $table->timestampTz('dikirim_ke_pasien_pada')->nullable()->comment('FR-05-08, menunggu portal 7.48');
            $table->text('kanal_kirim')->nullable()->comment('FR-05-08, menunggu portal 7.48');
            $table->kolomStandar();
        });
        DB::statement("ALTER TABLE klinis.resume_medis ADD CONSTRAINT resume_medis_cek CHECK (status IN ('draf', 'sah') AND ((status = 'sah') = (disahkan_pada IS NOT NULL AND disahkan_oleh IS NOT NULL)) AND (kanal_kirim IS NULL OR kanal_kirim IN ('portal', 'whatsapp', 'cetak')))");

        $sekarang = now();
        foreach ([
            ['OBAT_EDUKASI', 'Obat pulang dan edukasi', 'manual', 1],
            ['SURAT_KONTROL', 'Surat kontrol', 'manual', 2],
            ['RUJUK_BALIK', 'Rujuk balik', 'manual', 3],
            ['TAGIHAN_JAMINAN', 'Tagihan lunas atau jaminan', 'kejadian', 4],
            ['LEPAS_INFUS_GELANG', 'Lepas infus dan gelang', 'manual', 5],
        ] as [$kode, $nama, $sumber, $urutan]) {
            DB::table('inti.daftar_periksa_pulang')->insertOrIgnore([
                'id' => (string) Str::uuid7(), 'kode' => $kode, 'nama' => $nama, 'sumber' => $sumber, 'wajib' => true, 'urutan' => $urutan,
                'status' => 'aktif', 'dibuat_pada' => $sekarang, 'diubah_pada' => $sekarang,
            ]);
        }

        DB::table('inti.jenis_tugas')->insertOrIgnore([
            'id' => (string) Str::uuid7(), 'kode' => 'RESUME_BELUM_TTE_48J', 'nama' => 'Resume medis belum disahkan',
            'uraian' => 'Resume medis pasien pulang rawat inap belum disahkan DPJP dengan TTE (FR-05-04).', 'modul' => 'rawat_inap',
            'objek_jenis' => 'kunjungan', 'tautan_layar' => '/rawat-inap/pemulangan?kunjungan={objek_id}', 'tenggat_dari' => 'dibuat',
            'batas_menit' => 2880, 'pengingat_menit' => -240, 'teguran_menit' => 0, 'eskalasi_menit' => 1440,
            'kritis' => false, 'aktif' => true, 'dibuat_pada' => $sekarang, 'diubah_pada' => $sekarang,
        ]);

        MenuBawaan::pasang();
        app(CacheHakAkses::class)->lupakanSemua();

        foreach (self::TUJUAN as $tujuan => $keterangan) {
            DB::table('kunjungan.aturan_perpindahan')->insertOrIgnore([
                'id' => (string) Str::uuid7(), 'pos_asal' => 'rawat_inap', 'pos_tujuan' => $tujuan, 'izin_perintah' => 'rawat_inap.pemulangan.input',
                'keterangan' => $keterangan, 'status' => 'aktif', 'dibuat_pada' => $sekarang, 'diubah_pada' => $sekarang,
            ]);
        }
    }

    /** Hanya untuk basis data tanpa pemulangan (lokal/uji); produksi maju saja. */
    public function down(): void
    {
        DB::table('kunjungan.aturan_perpindahan')->where('pos_asal', 'rawat_inap')->whereIn('pos_tujuan', array_keys(self::TUJUAN))->delete();
        DB::table('inti.jenis_tugas')->where('kode', 'RESUME_BELUM_TTE_48J')->delete();
        Schema::dropIfExists('klinis.resume_medis');
        Schema::dropIfExists('kunjungan.rencana_pulang');
        Schema::dropIfExists('inti.daftar_periksa_pulang');
    }
};
