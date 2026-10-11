<?php

namespace App\Modules\Akses\Models;

/**
 * Pemberian (tambah) atau pencabutan (cabut) per akun atas sub-menu/bagian (menu_id) atau aksi
 * (izin_id). Cabut selalu menang (PRD 9.2.3).
 *
 * @property string|null $menu_id
 * @property string|null $izin_id
 * @property string $efek tambah | cabut
 */
final class PenggunaIzinKhusus extends PemberianAkses
{
    protected $table = 'akses.pengguna_izin_khusus';

    protected $fillable = [
        'pengguna_id', 'menu_id', 'izin_id', 'efek', 'cakupan_jenis', 'cakupan_id', 'berlaku_dari', 'berlaku_sampai',
        'dasar_pemberian', 'alasan', 'diberikan_oleh', 'disetujui_oleh',
    ];
}
