<?php

namespace App\Modules\MasterData\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Identitas butir buku tarif; isi berversi ada di {@see TarifVersi}.
 *
 * @property string $id
 * @property string $kode
 * @property int $revisi
 * @property Carbon $diubah_pada
 */
final class Tarif extends Model
{
    use HasUuids;

    public const CREATED_AT = 'dibuat_pada';

    public const UPDATED_AT = 'diubah_pada';

    protected $table = 'keuangan.tarif';

    protected $fillable = ['kode'];

    protected $attributes = ['revisi' => 0];

    protected function casts(): array
    {
        return ['revisi' => 'integer'];
    }
}
