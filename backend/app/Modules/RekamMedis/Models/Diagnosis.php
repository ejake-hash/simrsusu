<?php

namespace App\Modules\RekamMedis\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Diagnosis kunjungan (PRD 11.2 `klinis.diagnosis`, tiket 2.15). `kode_icd` dan `alasan_batal` data klinis: jangan dilog.
 *
 * @property string $id
 * @property string $kunjungan_id
 * @property string $pasien_id
 * @property string $sistem_icd
 * @property string $kode_icd
 * @property string $jenis utama | sekunder | komplikasi | komorbid
 * @property string $sumber dokter | koder
 * @property string $status aktif | batal
 * @property string|null $alasan_batal
 * @property string|null $dibatalkan_oleh
 * @property Carbon|null $dibatalkan_pada
 * @property string|null $dibuat_oleh
 * @property int $revisi
 * @property Carbon $dibuat_pada
 * @property Carbon $diubah_pada
 */
final class Diagnosis extends Model
{
    use HasUuids;

    public const CREATED_AT = 'dibuat_pada';

    public const UPDATED_AT = 'diubah_pada';

    protected $table = 'klinis.diagnosis';

    protected $guarded = ['id'];

    protected $attributes = ['revisi' => 0, 'status' => 'aktif', 'sistem_icd' => 'icd10', 'sumber' => 'dokter'];

    protected function casts(): array
    {
        return ['dibatalkan_pada' => 'datetime', 'revisi' => 'integer'];
    }
}
