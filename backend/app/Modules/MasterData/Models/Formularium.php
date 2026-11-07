<?php

namespace App\Modules\MasterData\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Formularium Fornas/RS/penjamin (tiket 2.11, `farmasi.formularium`).
 *
 * @property string $id
 * @property string $kode
 * @property string $nama
 * @property string $jenis fornas | rs | penjamin
 * @property string|null $penjamin_id
 * @property string $berlaku_dari
 * @property string|null $berlaku_sampai
 * @property int $revisi
 * @property Carbon $diubah_pada
 * @property-read Penjamin|null $penjamin
 */
final class Formularium extends Model
{
    use HasUuids;

    public const CREATED_AT = 'dibuat_pada';

    public const UPDATED_AT = 'diubah_pada';

    public const JENIS = ['fornas', 'rs', 'penjamin'];

    protected $table = 'farmasi.formularium';

    protected $fillable = ['kode', 'nama', 'jenis', 'penjamin_id', 'berlaku_dari', 'berlaku_sampai'];

    protected $attributes = ['revisi' => 0];

    protected function casts(): array
    {
        return ['revisi' => 'integer'];
    }

    /** @return BelongsTo<Penjamin, $this> */
    public function penjamin(): BelongsTo
    {
        return $this->belongsTo(Penjamin::class);
    }

    /** @return HasMany<ItemFormularium, $this> */
    public function item(): HasMany
    {
        return $this->hasMany(ItemFormularium::class);
    }
}
