<?php

namespace App\Modules\MasterData\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Kelompok Staf Medis.
 *
 * @property string $id
 * @property string $kode
 * @property string $nama
 * @property string $status aktif | nonaktif
 * @property int $revisi
 * @property Carbon $diubah_pada
 */
final class Ksm extends Model
{
    use HasUuids;

    public const CREATED_AT = 'dibuat_pada';

    public const UPDATED_AT = 'diubah_pada';

    protected $table = 'inti.ksm';

    protected $fillable = ['kode', 'nama', 'status', 'ketua_pegawai_id'];

    protected $attributes = ['status' => 'aktif', 'revisi' => 0];

    protected function casts(): array
    {
        return ['revisi' => 'integer'];
    }
}
