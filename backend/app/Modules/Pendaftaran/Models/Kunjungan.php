<?php

namespace App\Modules\Pendaftaran\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Kunjungan poli, IGD, dan rawat inap (PRD 7.00.2; tiket 2.6, 2.23, 2.29). Tidak dihapus: batal = status beserta alasan
 * dan pelaku. Jadwal dan poli hanya kosong untuk IGD dan rawat inap (CHECK kunjungan_jenis_cek); rawat inap wajib unit
 * bangsal.
 *
 * @property string $id
 * @property string $nomor
 * @property string $pasien_id
 * @property string $episode_id
 * @property string $jenis_kunjungan poliklinik | igd | rawat_inap
 * @property string|null $jadwal_praktik_id
 * @property string|null $poli_id
 * @property string|null $pegawai_id dokter jadwal (poli) atau DPJP awal (rawat inap)
 * @property string|null $unit_id unit organisasi bangsal (rawat inap)
 * @property Carbon $tanggal
 * @property Carbon|null $tiba_pada jam tiba IGD (jam server)
 * @property string $cara_bayar jkn | non_jkn
 * @property string|null $penjamin_id
 * @property string $status terdaftar | dilayani | selesai | batal
 * @property string|null $keluhan
 * @property string|null $alasan_batal
 * @property string|null $dibatalkan_oleh
 * @property Carbon|null $dibatalkan_pada
 * @property string|null $dibuat_oleh
 * @property int $revisi
 * @property Carbon $dibuat_pada
 * @property Carbon $diubah_pada
 * @property-read Pasien $pasien
 * @property-read Episode $episode
 */
final class Kunjungan extends Model
{
    use HasUuids;

    public const CREATED_AT = 'dibuat_pada';

    public const UPDATED_AT = 'diubah_pada';

    public const STATUS = ['terdaftar', 'dilayani', 'selesai', 'batal'];

    public const POLIKLINIK = 'poliklinik';

    public const IGD = 'igd';

    public const RAWAT_INAP = 'rawat_inap';

    /** Masih di IGD: belum selesai (disposisi) dan tidak batal. */
    public const STATUS_AKTIF = ['terdaftar', 'dilayani'];

    /** 7.00.2: IGD < 6 jam = rawat jalan harian; lewat dari itu ditandai (FR-00-06). */
    public const BATAS_JAM_IGD = 6;

    protected $table = 'kunjungan.kunjungan';

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'terdaftar', 'jenis_kunjungan' => 'poliklinik', 'revisi' => 0];

    protected function casts(): array
    {
        return ['tanggal' => 'date', 'tiba_pada' => 'datetime', 'dibatalkan_pada' => 'datetime', 'revisi' => 'integer'];
    }

    /** Kunjungan IGD aktif yang sudah melewati 6 jam sejak tiba; episodenya dipindah BentukEpisodeIgd (tugas terjadwal). */
    public function lebihEnamJam(): bool
    {
        return $this->jenis_kunjungan === self::IGD && $this->tiba_pada !== null
            && in_array($this->status, self::STATUS_AKTIF, true)
            && now()->greaterThan($this->tiba_pada->copy()->addHours(self::BATAS_JAM_IGD));
    }

    /** @return BelongsTo<Pasien, $this> */
    public function pasien(): BelongsTo
    {
        return $this->belongsTo(Pasien::class, 'pasien_id');
    }

    /** @return BelongsTo<Episode, $this> */
    public function episode(): BelongsTo
    {
        return $this->belongsTo(Episode::class, 'episode_id');
    }
}
