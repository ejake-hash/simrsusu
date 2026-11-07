<?php

namespace App\Modules\MasterData\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Barang dalam formularium (tiket 2.11, `farmasi.formularium_item`).
 *
 * @property string $id
 * @property string $formularium_id
 * @property string $barang_id
 * @property string|null $restriksi
 * @property int|null $maks_per_resep
 * @property bool $kronis
 * @property string $status aktif | nonaktif
 * @property int $revisi
 * @property Carbon $diubah_pada
 * @property-read Barang $barang
 */
final class ItemFormularium extends Model
{
    use HasUuids;

    public const CREATED_AT = 'dibuat_pada';

    public const UPDATED_AT = 'diubah_pada';

    protected $table = 'farmasi.formularium_item';

    protected $fillable = ['formularium_id', 'barang_id', 'restriksi', 'maks_per_resep', 'kronis', 'status'];

    protected $attributes = ['revisi' => 0, 'status' => 'aktif', 'kronis' => false];

    protected function casts(): array
    {
        return ['kronis' => 'boolean', 'maks_per_resep' => 'integer', 'revisi' => 'integer'];
    }

    /** @return BelongsTo<Barang, $this> */
    public function barang(): BelongsTo
    {
        return $this->belongsTo(Barang::class);
    }
}
