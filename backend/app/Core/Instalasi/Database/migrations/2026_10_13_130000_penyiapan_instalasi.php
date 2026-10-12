<?php

use App\Modules\Akses\Application\CacheHakAkses;
use App\Modules\Akses\Database\MenuBawaan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Penyiapan instalasi kosong (tiket 1.8f, FR-54-08): riwayat konfirmasi langkah (tidak pernah dihapus; bisa diulang),
 * kunci siap melayani di profil RS, dan sub-menu Pengaturan → Penyiapan instalasi (bawaan superadmin).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inti.penyiapan_konfirmasi', function (Blueprint $table) {
            $table->comment('Konfirmasi langkah wizard penyiapan (FR-54-08); satu baris per konfirmasi, yang terbaru berlaku.');
            $table->uuid('id')->primary();
            $table->text('langkah')->comment('KodeLangkahPenyiapan (kontrak v0.12.0)');
            $table->boolean('dilewati')->default(false)->comment('Hanya langkah kredensial_integrasi');
            $table->text('alasan')->nullable()->comment('Wajib bila dilewati');
            $table->uuid('dikonfirmasi_oleh')->comment('akses.pengguna.id');
            $table->timestampTz('dikonfirmasi_pada', 6)->useCurrent();
            $table->foreign('dikonfirmasi_oleh')->references('id')->on('akses.pengguna');
            $table->index(['langkah', 'dikonfirmasi_pada']);
        });
        // Dibekukan di migrasi (bukan dari konstanta kelas): langkah baru = migrasi baru.
        DB::statement("ALTER TABLE inti.penyiapan_konfirmasi ADD CONSTRAINT penyiapan_konfirmasi_langkah_cek CHECK (langkah IN ('superadmin', 'profil_rs', 'organisasi', 'paket_referensi', 'pegawai', 'penjamin_tarif', 'formularium', 'kredensial_integrasi', 'modul_aktif'))");
        DB::statement("ALTER TABLE inti.penyiapan_konfirmasi ADD CONSTRAINT penyiapan_konfirmasi_dilewati_cek CHECK (NOT dilewati OR (langkah = 'kredensial_integrasi' AND alasan IS NOT NULL))");

        Schema::table('inti.profil_rs', function (Blueprint $table) {
            $table->timestampTz('siap_melayani_sejak', 6)->nullable()
                ->comment('Langkah 1–6 pertama kali lengkap (FR-54-08); terkunci, tidak dikosongkan walau langkah mundur');
        });

        MenuBawaan::pasang();
        app(CacheHakAkses::class)->lupakanSemua();
    }

    public function down(): void
    {
        Schema::table('inti.profil_rs', fn (Blueprint $table) => $table->dropColumn('siap_melayani_sejak'));
        Schema::dropIfExists('inti.penyiapan_konfirmasi');
    }
};
