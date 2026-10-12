<?php

namespace App\Modules\Akses\Models;

use App\Modules\Akses\Application\CacheHakAkses;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Izin label sensitivitas per akun (lapis 4; tiket 1.5, PRD 11.2 pengguna_label). Dibuat saat diajukan (menunggu),
 * aktif saat disetujui pejabat PDP, dicabut lewat status batal. Status `ditolak` tidak disimpan: dihitung dari
 * persetujuannya (ditolak, kedaluwarsa, atau ditarik pengaju), sehingga tidak perlu tugas penyelaras.
 *
 * @property string $id
 * @property string $pengguna_id
 * @property string $label_sensitivitas_id
 * @property string $persetujuan_akses_id
 * @property string $status menunggu | aktif | batal
 * @property Carbon|null $berlaku_dari
 * @property Carbon $berlaku_sampai
 * @property string $dasar_pemberian
 * @property string $alasan
 * @property string $diberikan_oleh
 * @property string|null $disetujui_oleh
 * @property string|null $alasan_batal
 * @property string|null $dibatalkan_oleh
 * @property Carbon|null $dibatalkan_pada
 * @property Carbon $dibuat_pada
 * @property-read LabelSensitivitas $label
 * @property-read PersetujuanAkses $persetujuan
 */
final class PenggunaLabel extends Model
{
    use HasUuids;

    public const CREATED_AT = 'dibuat_pada';

    public const UPDATED_AT = 'diubah_pada';

    protected $table = 'akses.pengguna_label';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['berlaku_dari' => 'datetime', 'berlaku_sampai' => 'datetime', 'dibatalkan_pada' => 'datetime'];
    }

    protected static function booted(): void
    {
        self::saved(fn (self $baris) => app(CacheHakAkses::class)->lupakan($baris->pengguna_id));
    }

    /** @return BelongsTo<LabelSensitivitas, $this> */
    public function label(): BelongsTo
    {
        return $this->belongsTo(LabelSensitivitas::class, 'label_sensitivitas_id');
    }

    /** @return BelongsTo<PersetujuanAkses, $this> */
    public function persetujuan(): BelongsTo
    {
        return $this->belongsTo(PersetujuanAkses::class, 'persetujuan_akses_id');
    }

    /**
     * Pengajuan yang masih menunggu: baris menunggu dan persetujuannya belum diputuskan atau kedaluwarsa.
     *
     * @param  Builder<self>  $query
     */
    public function scopeMenunggu(Builder $query): void
    {
        $query->where('status', 'menunggu')
            ->whereHas('persetujuan', fn (Builder $p) => $p->where('status', 'menunggu')->where('kedaluwarsa_pada', '>', now()->format('Y-m-d H:i:s.uP')));
    }

    /**
     * Aktif dan belum lewat masanya.
     *
     * @param  Builder<self>  $query
     */
    public function scopeBerlaku(Builder $query): void
    {
        $query->where('status', 'aktif')->where('berlaku_sampai', '>', now()->format('Y-m-d H:i:s.uP'));
    }

    /** menunggu | berlaku | berakhir | batal | ditolak (kontrak IzinLabelAkun). */
    public function statusTampil(): string
    {
        return match ($this->status) {
            'batal' => 'batal',
            'aktif' => $this->berlaku_sampai->isFuture() ? 'berlaku' : 'berakhir',
            default => $this->persetujuan->status === 'menunggu' && $this->persetujuan->kedaluwarsa_pada->isFuture() ? 'menunggu' : 'ditolak',
        };
    }
}
