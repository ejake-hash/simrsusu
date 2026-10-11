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
 * @property int $percobaan_gagal
 * @property Carbon|null $login_terakhir_pada
 * @property string|null $email
 * @property string|null $mfa_rahasia_enkripsi sudah didekripsi oleh cast `encrypted`
 * @property Carbon|null $mfa_aktif_pada
 * @property bool $wajib_mfa
 * @property Carbon|null $sesi_dicabut_pada sesi yang dimulai sebelumnya diakhiri (ADR 0002)
 * @property Carbon $dibuat_pada
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
    protected $fillable = ['pegawai_id', 'nama_pengguna', 'email'];

    protected $hidden = ['sandi_hash', 'mfa_rahasia_enkripsi'];

    protected function casts(): array
    {
        return [
            'login_terakhir_pada' => 'datetime',
            'terkunci_sampai' => 'datetime',
            'percobaan_gagal' => 'integer',
            'mfa_rahasia_enkripsi' => 'encrypted',
            'mfa_aktif_pada' => 'datetime',
            'mfa_langkah_terakhir' => 'integer',
            'wajib_mfa' => 'boolean',
            'sesi_dicabut_pada' => 'datetime',
        ];
    }

    public function aktif(): bool
    {
        return $this->status === 'aktif';
    }

    public function mfaAktif(): bool
    {
        return $this->mfa_aktif_pada !== null && $this->mfa_rahasia_enkripsi !== null;
    }

    /**
     * Akhiri semua sesi akun ini pada permintaan berikutnya (SesiPengguna, ADR 0002). Ditulis dengan
     * mikrodetik: binding tanggal bawaan Laravel memotong ke detik, sehingga sesi yang dimulai di detik
     * yang sama tidak ikut terputus.
     */
    public function cabutSesi(): void
    {
        $sekarang = now();
        self::query()->whereKey($this->id)->update(['sesi_dicabut_pada' => $sekarang->format('Y-m-d H:i:s.uP')]);
        $this->setRawAttributes([...$this->getAttributes(), 'sesi_dicabut_pada' => $sekarang->format('Y-m-d H:i:s.uP')], true);
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
