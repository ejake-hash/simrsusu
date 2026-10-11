<?php

namespace App\Modules\MasterData\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $kode
 * @property string $nama
 * @property string $unit_organisasi_id
 * @property string $status aktif | nonaktif
 * @property int $revisi
 * @property Carbon $diubah_pada
 */
final class Bangsal extends Model
{
    use HasUuids;

    public const CREATED_AT = 'dibuat_pada';

    public const UPDATED_AT = 'diubah_pada';

    protected $table = 'inti.bangsal';

    protected $fillable = ['kode', 'nama', 'unit_organisasi_id', 'status'];

    protected $attributes = ['revisi' => 0];

    protected function casts(): array
    {
        return ['revisi' => 'integer'];
    }
}
