<?php

namespace App\Modules\Akses\Models;

use App\Modules\Akses\Application\CacheHakAkses;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Grup, menu, sub-menu, atau bagian (PRD 9.2.1).
 *
 * @property string $id
 * @property string|null $induk_id
 * @property string $tingkat grup | menu | sub_menu | bagian
 * @property string $kode
 * @property string|null $label
 * @property string|null $ikon
 * @property string|null $rute
 * @property int|null $modul_no
 * @property int|null $fase
 * @property int $urutan
 * @property bool $tersedia
 * @property bool $selalu_tampil
 * @property bool $ikut_induk
 * @property bool $aktif
 */
final class Menu extends Model
{
    use HasUuids;

    public const CREATED_AT = 'dibuat_pada';

    public const UPDATED_AT = 'diubah_pada';

    protected $table = 'akses.menu';

    protected $fillable = ['induk_id', 'tingkat', 'kode', 'label', 'ikon', 'rute', 'modul_no', 'fase', 'urutan', 'tersedia', 'selalu_tampil', 'ikut_induk', 'aktif'];

    protected function casts(): array
    {
        return [
            'modul_no' => 'integer', 'fase' => 'integer', 'urutan' => 'integer',
            'tersedia' => 'boolean', 'selalu_tampil' => 'boolean', 'ikut_induk' => 'boolean', 'aktif' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        self::saved(fn () => app(CacheHakAkses::class)->lupakanSemua());
    }
}
