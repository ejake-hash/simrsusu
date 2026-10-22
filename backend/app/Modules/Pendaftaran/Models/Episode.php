<?php

namespace App\Modules\Pendaftaran\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Episode perawatan (PRD 7.00.2; tiket 2.6). Dibentuk dan digabung otomatis oleh KelolaKunjungan.
 *
 * @property string $id
 * @property string $pasien_id
 * @property string $jenis rawat_jalan_harian
 * @property Carbon $mulai_tanggal
 * @property string $status aktif | selesai | batal
 * @property int $revisi
 */
final class Episode extends Model
{
    use HasUuids;

    public const CREATED_AT = 'dibuat_pada';

    public const UPDATED_AT = 'diubah_pada';

    public const RAWAT_JALAN_HARIAN = 'rawat_jalan_harian';

    protected $table = 'kunjungan.episode';

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'aktif', 'revisi' => 0];

    protected function casts(): array
    {
        return ['mulai_tanggal' => 'date', 'dibatalkan_pada' => 'datetime', 'revisi' => 'integer'];
    }
}
