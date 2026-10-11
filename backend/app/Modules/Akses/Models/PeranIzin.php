<?php

namespace App\Modules\Akses\Models;

use App\Modules\Akses\Application\CacheHakAkses;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $peran_id
 * @property string $status aktif | batal (dicabut dari peran, tidak dihapus)
 * @property string $izin_id
 */
final class PeranIzin extends Model
{
    use HasUuids;

    public const CREATED_AT = 'dibuat_pada';

    public const UPDATED_AT = 'diubah_pada';

    protected $table = 'akses.peran_izin';

    protected $fillable = ['peran_id', 'izin_id'];

    protected static function booted(): void
    {
        // Baris yang dicabut tetap ada sebagai riwayat; tidak pernah ikut memberi akses.
        self::addGlobalScope('aktif', fn (Builder $q) => $q->where($q->qualifyColumn('status'), 'aktif'));

        // Peran dipakai banyak akun: semua cache hak akses dianggap usang.
        self::saved(fn () => app(CacheHakAkses::class)->lupakanSemua());
    }
}
