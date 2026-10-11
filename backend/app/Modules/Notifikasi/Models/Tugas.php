<?php

namespace App\Modules\Notifikasi\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Tugas di kotak kerja (tiket 1.7, FR-53-01…03).
 *
 * @property string $id
 * @property string $jenis_tugas_id
 * @property string $objek_jenis
 * @property string $objek_id
 * @property string $judul
 * @property string $tautan
 * @property string $pelaksana_jenis pengguna | peran | unit
 * @property string $pelaksana_id
 * @property string|null $unit_id
 * @property string|null $unit_eskalasi_id
 * @property Carbon $tenggat_pada
 * @property Carbon $pengingat_pada
 * @property Carbon $teguran_pada
 * @property Carbon $eskalasi_pada
 * @property int $tingkat_saat_ini
 * @property bool $kritis
 * @property string $status terbuka | selesai | batal
 * @property Carbon|null $selesai_pada
 * @property string|null $alasan_selesai
 * @property Carbon $dibuat_pada
 * @property-read JenisTugas $jenis
 */
final class Tugas extends Model
{
    use HasUuids;

    public const CREATED_AT = 'dibuat_pada';

    public const UPDATED_AT = 'diubah_pada';

    protected $table = 'inti.tugas';

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'terbuka', 'tingkat_saat_ini' => 0, 'revisi' => 0];

    protected function casts(): array
    {
        return ['tenggat_pada' => 'datetime', 'pengingat_pada' => 'datetime', 'teguran_pada' => 'datetime', 'eskalasi_pada' => 'datetime',
            'selesai_pada' => 'datetime', 'tingkat_saat_ini' => 'integer', 'kritis' => 'boolean'];
    }

    /** @return BelongsTo<JenisTugas, $this> */
    public function jenis(): BelongsTo
    {
        return $this->belongsTo(JenisTugas::class, 'jenis_tugas_id');
    }
}
