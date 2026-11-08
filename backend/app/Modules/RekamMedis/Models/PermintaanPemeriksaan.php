<?php

namespace App\Modules\RekamMedis\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Order penunjang (PRD 11.2 `permintaan.permintaan_pemeriksaan`, tiket 2.16). Butirnya di permintaan.permintaan_item.
 * `diagnosis_kerja`, `catatan`, `alasan_ganda`, `alasan_batal` data klinis: jangan dilog.
 *
 * @property string $id
 * @property string $kunjungan_id
 * @property string $episode_id
 * @property string $pasien_id
 * @property string $jenis lab | rad
 * @property string $prioritas rutin | cito
 * @property string $pemesan_id
 * @property string $diagnosis_kerja
 * @property string|null $catatan
 * @property string|null $alasan_ganda
 * @property string $status dipesan | batal
 * @property string|null $alasan_batal
 * @property string|null $dibatalkan_oleh
 * @property Carbon|null $dibatalkan_pada
 * @property int $revisi
 * @property Carbon $dibuat_pada
 * @property Carbon $diubah_pada
 */
final class PermintaanPemeriksaan extends Model
{
    use HasUuids;

    public const CREATED_AT = 'dibuat_pada';

    public const UPDATED_AT = 'diubah_pada';

    protected $table = 'permintaan.permintaan_pemeriksaan';

    protected $guarded = ['id'];

    protected $attributes = ['revisi' => 0, 'status' => 'dipesan'];

    protected function casts(): array
    {
        return ['dibatalkan_pada' => 'datetime', 'revisi' => 'integer'];
    }
}
