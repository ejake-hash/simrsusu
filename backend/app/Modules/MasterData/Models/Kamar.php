<?php

namespace App\Modules\MasterData\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $bangsal_id
 * @property string $lokasi_id ruang
 * @property int|null $kapasitas_maksimum
 * @property array<string, bool|int|float|string> $atribut_standar
 * @property int $revisi
 * @property Carbon $diubah_pada
 */
final class Kamar extends Model
{
    use HasUuids;

    public const CREATED_AT = 'dibuat_pada';

    public const UPDATED_AT = 'diubah_pada';

    protected $table = 'inti.kamar';

    protected $fillable = ['bangsal_id', 'lokasi_id', 'kapasitas_maksimum', 'atribut_standar'];

    protected $attributes = ['revisi' => 0];

    protected function casts(): array
    {
        return ['atribut_standar' => 'array', 'kapasitas_maksimum' => 'integer', 'revisi' => 'integer'];
    }
}
