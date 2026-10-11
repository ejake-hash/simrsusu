<?php

namespace App\Modules\Sdm\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Izin praktik (STR/SIP/SIK/SPK; tiket 1.8d). Tidak diubah setelah dicatat.
 *
 * @property string $id
 * @property string $pegawai_id
 * @property string $jenis str | sip | sik | spk
 * @property string $nomor
 * @property Carbon $terbit
 * @property Carbon|null $berlaku_sampai
 * @property string|null $dokumen_jalur
 * @property string|null $dokumen_mime
 * @property int|null $dokumen_ukuran
 * @property string $status aktif | batal
 * @property string|null $alasan_batal
 * @property string|null $dibatalkan_oleh
 * @property Carbon|null $dibatalkan_pada
 * @property string|null $dibuat_oleh
 * @property Carbon $dibuat_pada
 */
final class IzinPraktik extends Model
{
    use HasUuids;

    public const CREATED_AT = 'dibuat_pada';

    public const UPDATED_AT = 'diubah_pada';

    protected $table = 'sdm.izin_praktik';

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'aktif', 'revisi' => 0];

    protected function casts(): array
    {
        return ['terbit' => 'date', 'berlaku_sampai' => 'date', 'dibatalkan_pada' => 'datetime', 'dokumen_ukuran' => 'integer'];
    }
}
