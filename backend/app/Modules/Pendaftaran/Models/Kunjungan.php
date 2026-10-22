<?php

namespace App\Modules\Pendaftaran\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Kunjungan poli (PRD 7.00.2; tiket 2.6, kontrak v0.20.0). Tidak dihapus: batal = status beserta alasan dan pelaku.
 *
 * @property string $id
 * @property string $nomor
 * @property string $pasien_id
 * @property string $episode_id
 * @property string $jenis_kunjungan
 * @property string $jadwal_praktik_id
 * @property string $poli_id
 * @property string $pegawai_id
 * @property Carbon $tanggal
 * @property string $cara_bayar jkn | non_jkn
 * @property string|null $penjamin_id
 * @property string $status terdaftar | dilayani | selesai | batal
 * @property string|null $keluhan
 * @property string|null $alasan_batal
 * @property string|null $dibatalkan_oleh
 * @property Carbon|null $dibatalkan_pada
 * @property string|null $dibuat_oleh
 * @property int $revisi
 * @property Carbon $dibuat_pada
 * @property Carbon $diubah_pada
 * @property-read Pasien $pasien
 * @property-read Episode $episode
 */
final class Kunjungan extends Model
{
    use HasUuids;

    public const CREATED_AT = 'dibuat_pada';

    public const UPDATED_AT = 'diubah_pada';

    public const STATUS = ['terdaftar', 'dilayani', 'selesai', 'batal'];

    protected $table = 'kunjungan.kunjungan';

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'terdaftar', 'jenis_kunjungan' => 'poliklinik', 'revisi' => 0];

    protected function casts(): array
    {
        return ['tanggal' => 'date', 'dibatalkan_pada' => 'datetime', 'revisi' => 'integer'];
    }

    /** @return BelongsTo<Pasien, $this> */
    public function pasien(): BelongsTo
    {
        return $this->belongsTo(Pasien::class, 'pasien_id');
    }

    /** @return BelongsTo<Episode, $this> */
    public function episode(): BelongsTo
    {
        return $this->belongsTo(Episode::class, 'episode_id');
    }
}
