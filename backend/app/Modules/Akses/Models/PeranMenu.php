<?php

namespace App\Modules\Akses\Models;

use App\Modules\Akses\Application\CacheHakAkses;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $peran_id
 * @property string $menu_id
 */
final class PeranMenu extends Model
{
    use HasUuids;

    public const CREATED_AT = 'dibuat_pada';

    public const UPDATED_AT = 'diubah_pada';

    protected $table = 'akses.peran_menu';

    protected $fillable = ['peran_id', 'menu_id'];

    protected static function booted(): void
    {
        // Peran dipakai banyak akun: semua cache hak akses dianggap usang.
        self::saved(fn () => app(CacheHakAkses::class)->lupakanSemua());
    }
}
