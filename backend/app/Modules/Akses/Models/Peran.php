<?php

namespace App\Modules\Akses\Models;

use App\Modules\Akses\Application\CacheHakAkses;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property string $id
 * @property string $kode
 * @property string $nama
 * @property string|null $uraian
 * @property bool $bawaan_sistem
 * @property bool $wajib_mfa
 * @property string $status aktif | nonaktif
 */
final class Peran extends Model
{
    use HasUuids;

    public const CREATED_AT = 'dibuat_pada';

    public const UPDATED_AT = 'diubah_pada';

    protected $table = 'akses.peran';

    protected $fillable = ['kode', 'nama', 'uraian', 'wajib_mfa', 'status'];

    protected function casts(): array
    {
        return ['bawaan_sistem' => 'boolean', 'wajib_mfa' => 'boolean'];
    }

    protected static function booted(): void
    {
        self::saved(fn () => app(CacheHakAkses::class)->lupakanSemua());
    }

    /** @return BelongsToMany<Izin, $this> */
    public function izin(): BelongsToMany
    {
        return $this->belongsToMany(Izin::class, 'akses.peran_izin');
    }

    /**
     * Peran yang membuat pemegangnya wajib MFA dan tidak bisa dibebaskan admin (PRD 10.3.2):
     * bertanda wajib_mfa (superadmin selalu, dijaga constraint) atau memuat izin sensitif.
     *
     * @param  Builder<self>  $query
     */
    public function scopeMewajibkanMfa(Builder $query): void
    {
        $query->where('status', 'aktif')
            ->where(fn (Builder $q) => $q->where('wajib_mfa', true)->orWhereHas('izin', fn (Builder $i) => $i->where('sensitif', true)));
    }
}
