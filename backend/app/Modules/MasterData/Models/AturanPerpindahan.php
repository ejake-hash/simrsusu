<?php

namespace App\Modules\MasterData\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Satu sel matriks perpindahan antar-pos (tiket 2.26; FR-00-02, PRD 7.00.3): pos asal → pos tujuan yang boleh
 * diperintahkan dan kode izin yang wajib dipegang pemberi perintah. Data induk RS; diubah lewat usulan data induk.
 *
 * @property string $id
 * @property string $pos_asal
 * @property string $pos_tujuan
 * @property string $izin_perintah
 * @property string|null $keterangan
 * @property string $status aktif | nonaktif
 * @property int $revisi
 * @property Carbon $diubah_pada
 */
final class AturanPerpindahan extends Model
{
    use HasUuids;

    public const CREATED_AT = 'dibuat_pada';

    public const UPDATED_AT = 'diubah_pada';

    /** Baris tabel 7.00.3 (sama dengan CHECK aturan_perpindahan_cek). */
    public const POS_ASAL = ['pendaftaran', 'poliklinik', 'igd', 'penunjang', 'kamar_operasi', 'icu_hcu', 'rawat_inap', 'farmasi', 'kasir'];

    /** Kolom tabel 7.00.3, ditambah APS dan DOA dari FR-03-09 (sama dengan CHECK aturan_perpindahan_cek). */
    public const POS_TUJUAN = ['poli_lain', 'penunjang', 'igd', 'kamar_operasi', 'icu_hcu', 'rawat_inap', 'rujuk_keluar', 'pulang', 'meninggal', 'aps', 'doa'];

    protected $table = 'kunjungan.aturan_perpindahan';

    protected $fillable = ['pos_asal', 'pos_tujuan', 'izin_perintah', 'keterangan', 'status'];

    protected $attributes = ['revisi' => 0, 'status' => 'aktif'];

    protected function casts(): array
    {
        return ['revisi' => 'integer'];
    }

    /** Aturan aktif untuk pasangan ini; null = perpindahan di luar matriks. */
    public static function berlaku(string $posAsal, string $posTujuan): ?self
    {
        return self::query()->where('pos_asal', $posAsal)->where('pos_tujuan', $posTujuan)->where('status', 'aktif')->first();
    }
}
