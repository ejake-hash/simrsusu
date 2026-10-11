<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Keamanan akun di Beranda (tiket 1.2e): "masuk sebelumnya" untuk pertanyaan "apakah ini Anda?".
 * login_terakhir_pada selalu = sesi ini, jadi nilai lamanya disalin ke masuk_sebelumnya_* sebelum ditimpa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('akses.pengguna', function (Blueprint $table) {
            $table->boolean('login_terakhir_dari_jaringan_rs')->nullable()->comment('Masuk terakhir dari jaringan RS (AUTENTIKASI_MFA_JARINGAN_RS); kosong = belum diketahui');
            $table->timestampTz('masuk_sebelumnya_pada')->nullable()->comment('login_terakhir_pada sebelum masuk yang sekarang; kosong = masuk pertama');
            $table->boolean('masuk_sebelumnya_dari_jaringan_rs')->nullable()->comment('login_terakhir_dari_jaringan_rs sebelum masuk yang sekarang');
        });
    }

    public function down(): void
    {
        Schema::table('akses.pengguna', fn (Blueprint $table) => $table->dropColumn([
            'login_terakhir_dari_jaringan_rs', 'masuk_sebelumnya_pada', 'masuk_sebelumnya_dari_jaringan_rs',
        ]));
    }
};
