<?php

namespace App\Modules\Sdm\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Rincian kewenangan klinis minimal (tiket 1.8d). Tidak diubah setelah dicatat.
 *
 * @property string $id
 * @property string $pegawai_id
 * @property string $kategori
 * @property list<string> $kode_tindakan
 * @property Carbon $berlaku_dari
 * @property Carbon $berlaku_sampai
 * @property string $komite
 * @property string|null $dasar
 * @property string $status aktif | batal
 * @property string|null $alasan_batal
 * @property string|null $dibatalkan_oleh
 * @property Carbon|null $dibatalkan_pada
 * @property string|null $dibuat_oleh
 * @property Carbon $dibuat_pada
 */
final class KewenanganKlinis extends Model
{
    use HasUuids;

    public const CREATED_AT = 'dibuat_pada';

    public const UPDATED_AT = 'diubah_pada';

    protected $table = 'sdm.kewenangan_klinis';

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'aktif', 'revisi' => 0];

    protected function casts(): array
    {
        return ['kode_tindakan' => 'array', 'berlaku_dari' => 'date', 'berlaku_sampai' => 'date', 'dibatalkan_pada' => 'datetime'];
    }
}
