<?php

namespace App\Modules\Akses\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Perangkat tepercaya akun (tiket 1.13; PRD 10.3.2): MFA dilewati dari jaringan RS selama token cookie sah. Token hanya
 * tersimpan sebagai hash; pencabutan mengisi dicabut_pada, baris tidak dihapus.
 *
 * @property string $id
 * @property string $pengguna_id
 * @property string $token_hash
 * @property string $nama_perangkat
 * @property Carbon $berlaku_sampai
 * @property Carbon|null $terakhir_dipakai_pada
 * @property Carbon|null $dicabut_pada
 * @property string|null $dicabut_oleh
 * @property string|null $alasan_cabut dicabut_pemilik | dicabut_admin | sandi_diganti | sandi_direset | mfa_direset | akun_nonaktif
 * @property string|null $keterangan_cabut
 * @property Carbon $dibuat_pada
 */
final class PerangkatTepercaya extends Model
{
    use HasUuids;

    public const CREATED_AT = 'dibuat_pada';

    public const UPDATED_AT = 'diubah_pada';

    protected $table = 'akses.perangkat_tepercaya';

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'berlaku_sampai' => 'datetime', 'terakhir_dipakai_pada' => 'datetime', 'dicabut_pada' => 'datetime', 'dibuat_pada' => 'datetime',
        ];
    }
}
