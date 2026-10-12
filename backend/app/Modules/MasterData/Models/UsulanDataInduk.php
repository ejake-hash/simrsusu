<?php

namespace App\Modules\MasterData\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Usulan perubahan data induk berjenjang (tiket 1.8c, FR-54-02). Kolom `perubahan` = putaran terakhir; putaran lama di
 * inti.usulan_data_induk_putaran.
 *
 * @property string $id
 * @property string $nomor
 * @property string $jenis_data
 * @property list<array<string, mixed>> $perubahan
 * @property string $ringkasan
 * @property Carbon $berlaku_dari
 * @property string $alasan
 * @property string $dasar
 * @property string $tahap diusulkan | diverifikasi | disetujui | berlaku | dikembalikan | gagal_diterapkan
 * @property int $putaran
 * @property string $diusulkan_oleh
 * @property Carbon $diusulkan_pada
 * @property string|null $diverifikasi_oleh
 * @property Carbon|null $diverifikasi_pada
 * @property string|null $disetujui_oleh
 * @property Carbon|null $disetujui_pada
 * @property Carbon|null $diterapkan_pada
 * @property string|null $gagal_alasan
 * @property int $revisi
 * @property Carbon $dibuat_pada
 */
final class UsulanDataInduk extends Model
{
    use HasUuids;

    public const CREATED_AT = 'dibuat_pada';

    public const UPDATED_AT = 'diubah_pada';

    /** Tahap yang mengunci objeknya dari usulan lain. */
    public const BERJALAN = ['diusulkan', 'diverifikasi', 'disetujui'];

    /** Tahap terbuka (daftar `tahap=terbuka`). */
    public const TERBUKA = ['diusulkan', 'diverifikasi', 'disetujui', 'dikembalikan'];

    protected $table = 'inti.usulan_data_induk';

    protected $guarded = ['id'];

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected $attributes = ['tahap' => 'diusulkan', 'putaran' => 1, 'revisi' => 0];

    protected function casts(): array
    {
        return ['perubahan' => 'array', 'berlaku_dari' => 'date', 'putaran' => 'integer', 'diusulkan_pada' => 'datetime',
            'diverifikasi_pada' => 'datetime', 'disetujui_pada' => 'datetime', 'diterapkan_pada' => 'datetime'];
    }
}
