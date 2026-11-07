<?php

namespace App\Modules\RekamMedis\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Alergi pasien (PRD 11.2 `klinis.alergi`, tiket 2.13). `zat`, `reaksi`, `alasan_batal` data klinis: jangan dilog.
 *
 * @property string $id
 * @property string $pasien_id
 * @property string $zat
 * @property string $jenis
 * @property string|null $barang_id
 * @property string|null $reaksi
 * @property string|null $tingkat
 * @property string $status aktif | batal
 * @property string|null $alasan_batal
 * @property string|null $dibatalkan_oleh
 * @property Carbon|null $dibatalkan_pada
 * @property string|null $dibuat_oleh
 * @property int $revisi
 * @property Carbon $dibuat_pada
 */
final class Alergi extends Model
{
    use HasUuids;

    public const CREATED_AT = 'dibuat_pada';

    public const UPDATED_AT = 'diubah_pada';

    public const JENIS = ['obat', 'makanan', 'lingkungan', 'lainnya'];

    protected $table = 'klinis.alergi';

    protected $guarded = ['id'];

    protected $attributes = ['revisi' => 0, 'status' => 'aktif'];

    protected function casts(): array
    {
        return ['dibatalkan_pada' => 'datetime', 'revisi' => 'integer'];
    }
}
