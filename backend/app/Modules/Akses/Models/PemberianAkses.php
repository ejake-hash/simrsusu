<?php

namespace App\Modules\Akses\Models;

use App\Modules\Akses\Application\CacheHakAkses;
use App\Modules\Akses\Domain\Cakupan;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Kolom bersama penugasan peran dan izin khusus: cakupan (lapis 3), masa berlaku, dasar pemberian,
 * dan pembatalan (status = 'batal', tidak dihapus).
 *
 * @property string $id
 * @property string $pengguna_id
 * @property string $cakupan_jenis
 * @property string|null $cakupan_id
 * @property Carbon $berlaku_dari
 * @property Carbon|null $berlaku_sampai
 * @property string $dasar_pemberian
 * @property string|null $alasan
 * @property string $status aktif | batal
 */
abstract class PemberianAkses extends Model
{
    use HasUuids;

    public const CREATED_AT = 'dibuat_pada';

    public const UPDATED_AT = 'diubah_pada';

    protected function casts(): array
    {
        return ['berlaku_dari' => 'datetime', 'berlaku_sampai' => 'datetime', 'dibatalkan_pada' => 'datetime'];
    }

    protected static function booted(): void
    {
        // Diisi jam aplikasi, bukan bawaan DB: masa berlaku dibandingkan dengan now() aplikasi, dan jam
        // server basis data bisa berselisih beberapa milidetik.
        static::creating(fn (self $baris) => $baris->berlaku_dari ??= now());
        static::saved(fn (self $baris) => app(CacheHakAkses::class)->lupakan($baris->pengguna_id));
    }

    /**
     * Status aktif dan masa berlaku memuat $waktu.
     *
     * @param  Builder<static>  $query
     */
    public function scopeBerlaku(Builder $query, ?CarbonInterface $waktu = null): void
    {
        // Dengan mikrodetik: binding tanggal bawaan Laravel memotong ke detik.
        $waktu = ($waktu ?? now())->format('Y-m-d H:i:s.uP');
        $query->where('status', 'aktif')
            ->where('berlaku_dari', '<=', $waktu)
            ->where(fn (Builder $q) => $q->whereNull('berlaku_sampai')->orWhere('berlaku_sampai', '>', $waktu));
    }

    public function cakupan(): Cakupan
    {
        return new Cakupan($this->cakupan_jenis, $this->cakupan_id);
    }
}
