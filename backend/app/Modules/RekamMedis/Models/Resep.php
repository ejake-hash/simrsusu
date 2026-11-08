<?php

namespace App\Modules\RekamMedis\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Resep elektronik (PRD 11.2 `farmasi.resep`, tiket 2.17). Butir di farmasi.resep_item (yang aktif: `diganti_pada` null),
 * peringatan yang ditanggapi saat sah di farmasi.peringatan_resep. `catatan` dan `alasan_batal` data klinis: jangan dilog.
 *
 * @property string $id
 * @property string $no_resep
 * @property string $kunjungan_id
 * @property string $pasien_id
 * @property string $depo_id
 * @property string $jenis
 * @property bool $racikan
 * @property string|null $catatan
 * @property string $status draf | sah | batal
 * @property string $penulis_id
 * @property string|null $dokter_id
 * @property Carbon|null $masuk_pada
 * @property string|null $antrean_id
 * @property string|null $tanda_tangan_id
 * @property string|null $alasan_batal
 * @property string|null $dibatalkan_oleh
 * @property Carbon|null $dibatalkan_pada
 * @property int $revisi
 * @property Carbon $dibuat_pada
 * @property Carbon $diubah_pada
 */
final class Resep extends Model
{
    use HasUuids;

    public const CREATED_AT = 'dibuat_pada';

    public const UPDATED_AT = 'diubah_pada';

    protected $table = 'farmasi.resep';

    protected $guarded = ['id'];

    protected $attributes = ['revisi' => 0, 'status' => 'draf', 'jenis' => 'rj', 'racikan' => false];

    protected function casts(): array
    {
        return ['racikan' => 'boolean', 'masuk_pada' => 'datetime', 'dibatalkan_pada' => 'datetime', 'revisi' => 'integer'];
    }
}
