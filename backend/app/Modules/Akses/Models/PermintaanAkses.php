<?php

namespace App\Modules\Akses\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Permintaan akses dari pengguna (layar Akses ditolak; tiket 1.2d, PRD 9.3).
 *
 * @property string $id
 * @property string $nomor
 * @property string $pengguna_id
 * @property string|null $menu_id
 * @property string|null $izin_id
 * @property string $alasan
 * @property string $status menunggu | ditindaklanjuti | ditolak
 * @property string|null $diputuskan_oleh
 * @property Carbon|null $diputuskan_pada
 * @property string|null $catatan_keputusan
 * @property Carbon $dibuat_pada
 */
final class PermintaanAkses extends Model
{
    use HasUuids;

    public const CREATED_AT = 'dibuat_pada';

    public const UPDATED_AT = 'diubah_pada';

    protected $table = 'akses.permintaan_akses';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['diputuskan_pada' => 'datetime'];
    }
}
