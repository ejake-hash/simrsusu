<?php

namespace App\Modules\MasterData\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Satu versi butir tarif (hanya ditambah). Harga per kelas ada di keuangan.tarif_versi_harga.
 *
 * @property string $id
 * @property string $tarif_id
 * @property int $versi
 * @property string $nama
 * @property string $kelompok
 * @property string $status aktif | nonaktif
 * @property Carbon $berlaku_dari
 * @property string|null $usulan_id
 * @property string $dasar
 * @property string|null $dibuat_oleh
 * @property Carbon $dibuat_pada
 */
final class TarifVersi extends Model
{
    use HasUuids;

    public const CREATED_AT = 'dibuat_pada';

    public const UPDATED_AT = null;

    protected $table = 'keuangan.tarif_versi';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['berlaku_dari' => 'date', 'versi' => 'integer'];
    }
}
