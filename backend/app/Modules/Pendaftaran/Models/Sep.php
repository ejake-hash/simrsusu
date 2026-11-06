<?php

namespace App\Modules\Pendaftaran\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * SEP VClaim per kunjungan (tiket 2.7, kontrak v0.21.0 `Sep`). Tidak dihapus: `ditolak` disimpan sebagai jejak, `batal`
 * beserta alasan dan pelaku.
 *
 * @property string $id
 * @property string $kunjungan_id
 * @property string $episode_id
 * @property string $pasien_id
 * @property string|null $no_sep
 * @property Carbon $tanggal_sep
 * @property string $jenis_pelayanan
 * @property string $status menunggu | terbit | ditolak | batal
 * @property string|null $no_rujukan
 * @property string|null $asal_rujukan fktp | rs
 * @property Carbon|null $tanggal_rujukan
 * @property Carbon|null $berlaku_sampai
 * @property string|null $ppk_rujukan
 * @property string|null $diagnosis_kode
 * @property string|null $diagnosis_nama
 * @property string|null $catatan
 * @property list<array{kode: string, pesan: string}> $peringatan
 * @property string|null $galat_sumber
 * @property string|null $galat_kode
 * @property string|null $galat_pesan
 * @property string|null $kiriman_id
 * @property string|null $lingkungan
 * @property string|null $kunci_idempotensi
 * @property Carbon|null $terbit_pada
 * @property string|null $dibuat_oleh
 * @property Carbon $dibuat_pada
 * @property Carbon $diubah_pada
 */
final class Sep extends Model
{
    use HasUuids;

    public const CREATED_AT = 'dibuat_pada';

    public const UPDATED_AT = 'diubah_pada';

    protected $table = 'integrasi.sep';

    protected $guarded = ['id'];

    protected $attributes = ['jenis_pelayanan' => 'rawat_jalan', 'peringatan' => '[]', 'revisi' => 0];

    protected function casts(): array
    {
        return ['tanggal_sep' => 'date', 'tanggal_rujukan' => 'date', 'berlaku_sampai' => 'date', 'peringatan' => 'array',
            'terbit_pada' => 'datetime', 'dibatalkan_pada' => 'datetime'];
    }
}
