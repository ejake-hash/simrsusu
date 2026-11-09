<?php

namespace App\Modules\MasterData\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Area IGD yang ditetapkan RS (tiket 2.25, FR-03-03; mis. Resusitasi, Observasi, Hijau). Tidak dihapus: nonaktif = tidak
 * bisa dipilih untuk penempatan baru, penempatan lama tetap terbaca.
 *
 * @property string $id
 * @property string $kode
 * @property string $nama
 * @property int $urutan
 * @property string $status aktif | nonaktif
 * @property int $revisi
 * @property Carbon $diubah_pada
 */
final class AreaIgd extends Model
{
    use HasUuids;

    public const CREATED_AT = 'dibuat_pada';

    public const UPDATED_AT = 'diubah_pada';

    protected $table = 'inti.area_igd';

    protected $fillable = ['kode', 'nama', 'urutan', 'status'];

    protected $attributes = ['revisi' => 0, 'urutan' => 0, 'status' => 'aktif'];

    protected function casts(): array
    {
        return ['urutan' => 'integer', 'revisi' => 'integer'];
    }
}
