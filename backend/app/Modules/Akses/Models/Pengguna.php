<?php

namespace App\Modules\Akses\Models;

use App\Modules\Akses\Database\Factories\PenggunaFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $pegawai_id
 * @property string $nama_pengguna
 * @property string $sandi_hash
 * @property string $status
 * @property Carbon|null $terkunci_sampai
 */
final class Pengguna extends Authenticatable
{
    /** @use HasFactory<PenggunaFactory> */
    use HasFactory, HasUuids;

    public const CREATED_AT = 'dibuat_pada';

    public const UPDATED_AT = 'diubah_pada';

    protected $table = 'akses.pengguna';

    protected $authPasswordName = 'sandi_hash';

    // Tidak ada "ingat saya"; batas diam sesi berlaku untuk semua (PRD 14.2).
    protected $rememberTokenName = '';

    // sandi_hash, status, dan kolom penguncian hanya diisi lewat forceFill di kode akses.
    protected $fillable = ['pegawai_id', 'nama_pengguna', 'surel'];

    protected $hidden = ['sandi_hash'];

    protected function casts(): array
    {
        return [
            'login_terakhir_pada' => 'datetime',
            'terkunci_sampai' => 'datetime',
            'percobaan_gagal' => 'integer',
        ];
    }

    public function aktif(): bool
    {
        return $this->status === 'aktif';
    }

    public function terkunci(): bool
    {
        return $this->terkunci_sampai !== null && $this->terkunci_sampai->isFuture();
    }

    protected static function newFactory(): PenggunaFactory
    {
        return PenggunaFactory::new();
    }
}
