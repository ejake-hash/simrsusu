<?php

namespace App\Modules\Akses\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pemberian (tambah) atau pencabutan (cabut) per akun atas sub-menu/bagian (menu_id) atau aksi
 * (izin_id). Cabut selalu menang (PRD 9.2.3).
 *
 * @property string|null $menu_id
 * @property string|null $izin_id
 * @property string $efek tambah | cabut
 * @property-read Menu|null $menu
 * @property-read Izin|null $izin
 */
final class PenggunaIzinKhusus extends PemberianAkses
{
    protected $table = 'akses.pengguna_izin_khusus';

    protected $fillable = [
        'pengguna_id', 'menu_id', 'izin_id', 'efek', 'cakupan_jenis', 'cakupan_id', 'berlaku_dari', 'berlaku_sampai',
        'dasar_pemberian', 'alasan', 'diberikan_oleh', 'disetujui_oleh',
    ];

    /** @return BelongsTo<Menu, $this> */
    public function menu(): BelongsTo
    {
        return $this->belongsTo(Menu::class);
    }

    /** @return BelongsTo<Izin, $this> */
    public function izin(): BelongsTo
    {
        return $this->belongsTo(Izin::class);
    }
}
