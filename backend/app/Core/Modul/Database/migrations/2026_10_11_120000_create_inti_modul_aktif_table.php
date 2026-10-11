<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Modul yang dinyalakan per instalasi dan per unit (PRD 3.4, katalog modul 01–58 di Bagian 6).
 * Modul inti fase 1 aktif sejak instalasi: 53 (notifikasi, tugas: Beranda) dan 54 (master data
 * dan kendali akses). Modul lain dinyalakan dari wizard penyiapan (FR-54-08).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inti.modul_aktif', function (Blueprint $table) {
            $table->comment('Modul aktif per instalasi (unit_organisasi_id kosong) atau per unit.');
            $table->uuid('id')->primary();
            $table->smallInteger('modul_no')->comment('Nomor katalog modul PRD Bagian 6 (01–58)');
            $table->uuid('unit_organisasi_id')->nullable()
                ->comment('Kosong = seluruh RS; kunci tamu ke inti.unit_organisasi ditambah saat tabel itu dibuat');
            $table->timestampTz('aktif_sejak')->useCurrent();
            $table->uuid('diaktifkan_oleh')->nullable()->comment('akses.pengguna.id; kosong = bawaan instalasi');
            $table->kolomStandar();
        });

        DB::statement('ALTER TABLE inti.modul_aktif ADD CONSTRAINT modul_aktif_modul_no_cek CHECK (modul_no BETWEEN 1 AND 58)');
        DB::statement('CREATE UNIQUE INDEX modul_aktif_unik ON inti.modul_aktif (modul_no, unit_organisasi_id) NULLS NOT DISTINCT');

        foreach ([53, 54] as $modul) {
            DB::table('inti.modul_aktif')->insert(['id' => (string) Str::uuid7(), 'modul_no' => $modul]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('inti.modul_aktif');
    }
};
