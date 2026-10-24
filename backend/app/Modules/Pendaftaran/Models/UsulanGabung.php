<?php

namespace App\Modules\Pendaftaran\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Usulan gabung RM ganda (tiket 2.9). Tahap: diusulkan → digabung | ditolak.
 *
 * @property string $id
 * @property string $sumber_id
 * @property string $tujuan_id
 * @property string $alasan
 * @property string $tahap
 * @property string $diusulkan_oleh
 * @property Carbon $diusulkan_pada
 * @property string|null $diverifikasi_oleh
 * @property Carbon|null $diverifikasi_pada
 * @property string|null $catatan_verifikasi
 * @property array<string, list<string>>|null $rincian_pindah
 * @property int $revisi
 * @property Carbon $dibuat_pada
 */
final class UsulanGabung extends Model
{
    use HasUuids;

    public const CREATED_AT = 'dibuat_pada';

    public const UPDATED_AT = 'diubah_pada';

    protected $table = 'pasien.usulan_gabung';

    protected $guarded = [];

    protected $attributes = ['tahap' => 'diusulkan', 'revisi' => 0];

    protected function casts(): array
    {
        return ['diusulkan_pada' => 'datetime', 'diverifikasi_pada' => 'datetime', 'rincian_pindah' => 'array', 'revisi' => 'integer'];
    }
}
