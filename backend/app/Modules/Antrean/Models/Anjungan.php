<?php

namespace App\Modules\Antrean\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;

/**
 * Perangkat anjungan (PRD 11.2 `anjungan`, 7.01.1; tiket 2.20): perangkat, bukan akun orang, tanpa izin data pasien.
 * Masuk dengan token perangkat berkemampuan `anjungan` (Sanctum, tersimpan sebagai hash).
 *
 * @property string $id
 * @property string $kode
 * @property string $lokasi_id
 * @property string $mode pendaftaran | farmasi
 * @property string $area_loket
 * @property string|null $lokasi_loket_id
 * @property int $batas_diam_detik
 * @property string $status aktif | nonaktif
 * @property Carbon|null $terakhir_aktif_pada
 */
final class Anjungan extends Model
{
    use HasApiTokens, HasUuids;

    public const CREATED_AT = 'dibuat_pada';

    public const UPDATED_AT = 'diubah_pada';

    public const KEMAMPUAN = 'anjungan';

    protected $table = 'kunjungan.anjungan';

    protected $guarded = ['id'];

    protected $attributes = ['mode' => 'pendaftaran', 'area_loket' => 'loket', 'batas_diam_detik' => 60, 'status' => 'aktif', 'revisi' => 0];

    protected function casts(): array
    {
        return ['batas_diam_detik' => 'integer', 'terakhir_aktif_pada' => 'datetime'];
    }
}
