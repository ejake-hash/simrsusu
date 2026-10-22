<?php

namespace App\Modules\Pendaftaran\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Kontak darurat pasien (tiket 2.5). Daftar diganti = baris lama `aktif` false, tidak dihapus.
 *
 * @property string $id
 * @property string $pasien_id
 * @property int $urutan
 * @property string $nama
 * @property string $hubungan
 * @property string $telepon_enkripsi sudah didekripsi
 * @property bool $aktif
 * @property Carbon|null $diganti_pada
 */
final class KontakDaruratPasien extends Model
{
    use HasUuids;

    public const CREATED_AT = 'dibuat_pada';

    public const UPDATED_AT = 'diubah_pada';

    protected $table = 'pasien.kontak_darurat';

    protected $fillable = ['pasien_id', 'urutan', 'nama', 'hubungan', 'telepon_enkripsi'];

    protected $hidden = ['telepon_enkripsi'];

    protected $attributes = ['aktif' => true, 'revisi' => 0];

    protected function casts(): array
    {
        return ['telepon_enkripsi' => 'encrypted', 'aktif' => 'boolean', 'urutan' => 'integer', 'diganti_pada' => 'datetime'];
    }
}
