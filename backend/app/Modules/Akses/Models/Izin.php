<?php

namespace App\Modules\Akses\Models;

use App\Modules\Akses\Application\CacheHakAkses;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Aksi pada sub-menu atau bagian (lapis 2). Kode <menu>.<sub_menu>.<aksi>.
 *
 * @property string $id
 * @property string $kode
 * @property string $menu_id
 * @property string $jenis_aksi
 * @property bool $sensitif
 * @property string|null $peran_penyetuju_id pemilik data izin sensitif (K3, tiket 1.2d)
 */
final class Izin extends Model
{
    use HasUuids;

    public const CREATED_AT = 'dibuat_pada';

    public const UPDATED_AT = 'diubah_pada';

    protected $table = 'akses.izin';

    protected $fillable = ['kode', 'menu_id', 'jenis_aksi', 'uraian', 'sensitif', 'butuh_mfa', 'butuh_kewenangan_klinis'];

    protected function casts(): array
    {
        return ['sensitif' => 'boolean', 'butuh_mfa' => 'boolean', 'butuh_kewenangan_klinis' => 'boolean'];
    }

    protected static function booted(): void
    {
        self::saved(fn () => app(CacheHakAkses::class)->lupakanSemua());
    }
}
