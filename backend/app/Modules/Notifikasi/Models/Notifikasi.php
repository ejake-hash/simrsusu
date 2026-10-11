<?php

namespace App\Modules\Notifikasi\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Notifikasi in-app per akun (tiket 1.7). Tabel berpartisi per bulan; kunci utama (id, dibuat_pada).
 *
 * @property string $id
 * @property string $pengguna_id
 * @property string $jenis
 * @property string $judul
 * @property string $isi
 * @property string|null $tautan
 * @property string $prioritas biasa | penting | kritis
 * @property string|null $tugas_id
 * @property Carbon|null $dibaca_pada
 * @property Carbon $dibuat_pada
 */
final class Notifikasi extends Model
{
    use HasUuids;

    public const CREATED_AT = 'dibuat_pada';

    public const UPDATED_AT = null;

    protected $table = 'notifikasi.notifikasi';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['dibaca_pada' => 'datetime', 'dibuat_pada' => 'datetime'];
    }
}
