<?php

namespace App\Modules\Akses\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Satu persetujuan dari satu peran penyetuju; satu pengajuan = satu grup (tiket 1.2d).
 *
 * @property string $id
 * @property string $nomor
 * @property string $grup_id
 * @property string $jenis izin_sensitif | peran_superadmin | pengecualian
 * @property string $objek_jenis izin_peran | penugasan | pengakhiran_penugasan | izin_khusus | penyetuju_izin
 * @property array<string, mixed> $objek
 * @property string|null $peran_id
 * @property string|null $izin_id
 * @property string|null $sasaran_pengguna_id
 * @property string $penyetuju_peran_id
 * @property string $status menunggu | disetujui | ditolak | kedaluwarsa | dibatalkan
 * @property string $diajukan_oleh
 * @property string|null $alasan
 * @property string $dasar
 * @property Carbon $kedaluwarsa_pada
 * @property string|null $diputuskan_oleh
 * @property Carbon|null $diputuskan_pada
 * @property string|null $catatan_keputusan
 * @property Carbon|null $diterapkan_pada
 * @property Carbon $dibuat_pada
 * @property-read Peran $penyetujuPeran
 * @property-read Izin|null $izin
 */
final class PersetujuanAkses extends Model
{
    use HasUuids;

    public const CREATED_AT = 'dibuat_pada';

    public const UPDATED_AT = 'diubah_pada';

    protected $table = 'akses.persetujuan_akses';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'objek' => 'array', 'kedaluwarsa_pada' => 'datetime', 'diputuskan_pada' => 'datetime', 'diterapkan_pada' => 'datetime',
        ];
    }

    /** @return BelongsTo<Peran, $this> */
    public function penyetujuPeran(): BelongsTo
    {
        return $this->belongsTo(Peran::class, 'penyetuju_peran_id');
    }

    /** @return BelongsTo<Izin, $this> */
    public function izin(): BelongsTo
    {
        return $this->belongsTo(Izin::class, 'izin_id');
    }

    /**
     * Masih menunggu dan belum lewat batas waktu.
     *
     * @param  Builder<self>  $query
     */
    public function scopeMenunggu(Builder $query): void
    {
        $query->where('status', 'menunggu')->where('kedaluwarsa_pada', '>', now()->format('Y-m-d H:i:s.uP'));
    }
}
