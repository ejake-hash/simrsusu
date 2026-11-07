<?php

namespace App\Modules\Antrean\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

/**
 * Perangkat display antrean (PRD 11.2 `display_antrean`, 7.01.1): perangkat, bukan akun orang, jadi tidak pernah memegang
 * izin data pasien. Masuk dengan token perangkat berkemampuan `display` (Sanctum, tersimpan sebagai hash).
 *
 * @property string $id
 * @property string $kode
 * @property list<string> $area [] = semua area
 * @property string $status aktif | nonaktif
 */
final class DisplayAntrean extends Model
{
    use HasApiTokens, HasUuids;

    public const CREATED_AT = 'dibuat_pada';

    public const UPDATED_AT = 'diubah_pada';

    public const KEMAMPUAN = 'display';

    protected $table = 'kunjungan.display_antrean';

    protected $guarded = ['id'];

    protected $attributes = ['area' => '[]', 'status' => 'aktif', 'revisi' => 0];

    protected function casts(): array
    {
        return ['area' => 'array'];
    }
}
