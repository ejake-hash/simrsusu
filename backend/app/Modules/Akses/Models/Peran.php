<?php

namespace App\Modules\Akses\Models;

use App\Modules\Akses\Application\CacheHakAkses;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $kode
 * @property string $nama
 * @property string|null $uraian
 * @property bool $bawaan_sistem
 * @property bool $wajib_mfa
 * @property string $status aktif | nonaktif
 * @property int|null $masa_percaya_hari perangkat tepercaya (1.13); null = ikut kebijakan instalasi
 * @property int $revisi naik setiap perubahan dari layar; dipakai sebagai versi/ETag
 * @property Carbon $diubah_pada
 */
final class Peran extends Model
{
    use HasUuids;

    public const CREATED_AT = 'dibuat_pada';

    public const UPDATED_AT = 'diubah_pada';

    protected $table = 'akses.peran';

    protected $fillable = ['kode', 'nama', 'uraian', 'wajib_mfa', 'status'];

    protected function casts(): array
    {
        return ['bawaan_sistem' => 'boolean', 'wajib_mfa' => 'boolean', 'revisi' => 'integer', 'masa_percaya_hari' => 'integer'];
    }

    protected static function booted(): void
    {
        self::saved(fn () => app(CacheHakAkses::class)->lupakanSemua());
    }

    /** @return BelongsToMany<Izin, $this> */
    public function izin(): BelongsToMany
    {
        return $this->belongsToMany(Izin::class, 'akses.peran_izin')->wherePivot('status', 'aktif');
    }

    /**
     * Peran yang membuat pemegangnya wajib MFA dan tidak bisa dibebaskan admin (PRD 10.3.2):
     * bertanda wajib_mfa (superadmin selalu, dijaga constraint) atau memuat izin sensitif.
     *
     * @param  Builder<self>  $query
     */
    public function scopeMewajibkanMfa(Builder $query): void
    {
        $query->where('status', 'aktif')
            ->where(fn (Builder $q) => $q->where('wajib_mfa', true)->orWhereHas('izin', fn (Builder $i) => $i->where('sensitif', true)));
    }

    /**
     * Tambah kolom jumlah_akun (akun dengan penugasan berlaku) dan memuat_izin_sensitif, sebagai
     * subquery agar daftar berhalaman tidak N+1.
     *
     * @param  Builder<self>  $query
     */
    public function scopeDenganRingkasan(Builder $query): void
    {
        $query->select('akses.peran.*')
            ->selectSub(PenggunaPeran::query()->berlaku()->whereColumn('peran_id', 'akses.peran.id')
                ->selectRaw('count(distinct pengguna_id)'), 'jumlah_akun')
            ->withExists(['izin as memuat_izin_sensitif' => fn (Builder $izin) => $izin->where('sensitif', true)]);
    }

    /** Akun dengan penugasan peran ini yang berlaku sekarang. */
    public function jumlahAkunBerlaku(): int
    {
        return PenggunaPeran::query()->berlaku()->where('peran_id', $this->id)->distinct()->count('pengguna_id');
    }

    public function memuatIzinSensitif(): bool
    {
        return $this->izin()->where('sensitif', true)->exists();
    }
}
