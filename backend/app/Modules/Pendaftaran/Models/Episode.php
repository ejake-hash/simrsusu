<?php

namespace App\Modules\Pendaftaran\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Episode perawatan (PRD 7.00.2; tiket 2.6, 2.23, 2.29). Dibentuk dan digabung otomatis oleh KelolaKunjungan,
 * BentukEpisodeIgd, dan BentukEpisodeRawatInap.
 *
 * @property string $id
 * @property string $pasien_id
 * @property string $jenis rawat_jalan_harian | igd_lebih_6_jam | rawat_inap
 * @property Carbon $mulai_tanggal
 * @property string $status aktif | selesai | batal
 * @property Carbon|null $selesai_pada diisi saat episode selesai (mis. IGD > 6 jam karena disposisi, tiket 2.26)
 * @property int $revisi
 */
final class Episode extends Model
{
    use HasUuids;

    public const CREATED_AT = 'dibuat_pada';

    public const UPDATED_AT = 'diubah_pada';

    public const RAWAT_JALAN_HARIAN = 'rawat_jalan_harian';

    public const IGD_LEBIH_6_JAM = 'igd_lebih_6_jam';

    public const RAWAT_INAP = 'rawat_inap';

    protected $table = 'kunjungan.episode';

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'aktif', 'revisi' => 0];

    protected function casts(): array
    {
        return ['mulai_tanggal' => 'date', 'dibatalkan_pada' => 'datetime', 'selesai_pada' => 'datetime', 'revisi' => 'integer'];
    }
}
