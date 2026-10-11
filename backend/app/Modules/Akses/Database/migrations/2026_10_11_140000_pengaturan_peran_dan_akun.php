<?php

use App\Modules\Akses\Application\CacheHakAkses;
use App\Modules\Akses\Database\MenuBawaan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pengaturan → Peran & izin, Akun & akses (tiket 1.2c-be).
 * - Izin dan sub-menu yang dicabut dari peran tidak dihapus (simrs_app tanpa DELETE di skema domain):
 *   barisnya berstatus batal dengan alasan, pelaku, dan waktu; diberikan lagi = baris yang sama aktif kembali.
 * - akses.pengguna.sesi_dicabut_pada: sesi yang dimulai sebelum waktu ini diakhiri (reset MFA, wajib MFA
 *   baru untuk akun tanpa MFA; ADR 0002).
 * - Kode menu peran/akun/audit pindah dari master_data.* ke pengaturan.* (MenuBawaan::KODE_PINDAH), id tetap.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['peran_izin', 'peran_menu'] as $tabel) {
            Schema::table("akses.$tabel", function (Blueprint $table) {
                $table->text('status')->default('aktif')->comment('aktif | batal (dicabut dari peran; tidak dihapus)');
                $table->text('alasan_batal')->nullable()->comment('Dasar perubahan saat dicabut');
                $table->uuid('dibatalkan_oleh')->nullable()->comment('akses.pengguna.id');
                $table->timestampTz('dibatalkan_pada')->nullable();
            });
            DB::statement("ALTER TABLE akses.$tabel ADD CONSTRAINT {$tabel}_status_cek CHECK (status IN ('aktif', 'batal'))");
        }

        Schema::table('akses.pengguna', function (Blueprint $table) {
            $table->timestampTz('sesi_dicabut_pada', 6)->nullable()
                ->comment('Sesi yang dimulai sebelum waktu ini diakhiri pada permintaan berikutnya (ADR 0002)');
            // Paginasi daftar akun (PRD 14.1.1): keyset (dibuat_pada, id).
            $table->index(['dibuat_pada', 'id'], 'pengguna_dibuat_pada_id_index');
        });

        MenuBawaan::pasang();
        app(CacheHakAkses::class)->lupakanSemua();
    }

    public function down(): void
    {
        foreach (array_flip(MenuBawaan::KODE_PINDAH) as $baru => $lama) {
            if (DB::table('akses.menu')->where('kode', $lama)->exists()) {
                continue;
            }
            DB::update('UPDATE akses.menu SET kode = ? || substr(kode, ?) WHERE kode = ? OR starts_with(kode, ?)', [$lama, strlen($baru) + 1, $baru, $baru.'.']);
            DB::update('UPDATE akses.izin SET kode = ? || substr(kode, ?) WHERE starts_with(kode, ?)', [$lama, strlen($baru) + 1, $baru.'.']);
            DB::table('akses.menu')->where('kode', $lama)->update([
                'induk_id' => DB::table('akses.menu')->where('kode', 'master_data')->value('id'),
                'rute' => '/master-data/'.substr($lama, strlen('master_data.')),
            ]);
        }

        // Nama indeks berskema: dropIndex() mencarinya di search_path (sistem, public).
        DB::statement('DROP INDEX akses.pengguna_dibuat_pada_id_index');
        Schema::table('akses.pengguna', fn (Blueprint $table) => $table->dropColumn('sesi_dicabut_pada'));
        foreach (['peran_izin', 'peran_menu'] as $tabel) {
            Schema::table("akses.$tabel", fn (Blueprint $table) => $table->dropColumn(['status', 'alasan_batal', 'dibatalkan_oleh', 'dibatalkan_pada']));
        }
        app(CacheHakAkses::class)->lupakanSemua();
    }
};
