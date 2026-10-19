<?php

namespace App\Modules\MasterData\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $pegawai_id
 * @property string $poli_id
 * @property string|null $ruang_id
 * @property int $hari ISO 1–7
 * @property string $jam_mulai HH:MM:SS
 * @property string $jam_selesai HH:MM:SS
 * @property int $kuota_jkn
 * @property int $kuota_non_jkn
 * @property Carbon $berlaku_dari
 * @property Carbon|null $berlaku_sampai
 * @property string $status aktif | nonaktif
 * @property int $revisi
 * @property Carbon $diubah_pada
 */
final class JadwalPraktik extends Model
{
    use HasUuids;

    public const CREATED_AT = 'dibuat_pada';

    public const UPDATED_AT = 'diubah_pada';

    protected $table = 'kunjungan.jadwal_praktik';

    protected $fillable = ['pegawai_id', 'poli_id', 'ruang_id', 'hari', 'jam_mulai', 'jam_selesai', 'kuota_jkn', 'kuota_non_jkn', 'berlaku_dari', 'berlaku_sampai', 'status'];

    protected $attributes = ['status' => 'aktif', 'revisi' => 0];

    protected function casts(): array
    {
        return ['hari' => 'integer', 'kuota_jkn' => 'integer', 'kuota_non_jkn' => 'integer', 'berlaku_dari' => 'date', 'berlaku_sampai' => 'date', 'revisi' => 'integer'];
    }
}
