<?php

namespace App\Modules\MasterData\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $kode
 * @property string $nama
 * @property string|null $kode_aplicares
 * @property Carbon $berlaku_dari
 * @property string $status aktif | nonaktif
 * @property int $revisi
 * @property Carbon $diubah_pada
 */
final class KelasRawat extends Model
{
    use HasUuids;

    public const CREATED_AT = 'dibuat_pada';

    public const UPDATED_AT = 'diubah_pada';

    protected $table = 'inti.kelas_rawat';

    protected $fillable = ['kode', 'nama', 'kode_aplicares', 'berlaku_dari', 'status'];

    protected $attributes = ['revisi' => 0];

    protected function casts(): array
    {
        return ['berlaku_dari' => 'date', 'revisi' => 'integer'];
    }
}
