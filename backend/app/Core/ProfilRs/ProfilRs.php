<?php

namespace App\Core\ProfilRs;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Profil RS dan paket identitas (PRD 3.4, 11.2 inti.profil_rs). Paling banyak satu baris.
 *
 * @property string $id
 * @property string|null $nama
 * @property string|null $kode_kemenkes
 * @property string|null $kelas
 * @property bool|null $status_blu
 * @property string|null $kode_ppk_bpjs
 * @property string|null $alamat
 * @property array{warna?: array{brand: string, accent: string}, logo?: array<string, mixed>, motif?: array<string, mixed>, foto?: array<string, mixed>} $paket_identitas
 * @property string $kontak_admin_unit
 * @property string|null $kontak_admin
 * @property string $status_penyiapan
 * @property int $revisi
 * @property Carbon $diubah_pada
 */
final class ProfilRs extends Model
{
    use HasUuids;

    public const CREATED_AT = 'dibuat_pada';

    public const UPDATED_AT = 'diubah_pada';

    /** Paket identitas bawaan produk (Karsitara) selama instalasi belum mengisi warnanya sendiri. */
    public const WARNA_BAWAAN = ['brand' => '#006937', 'accent' => '#F3C300'];

    public const JENIS_BERKAS = ['logo', 'motif', 'foto'];

    protected $table = 'inti.profil_rs';

    protected $fillable = ['nama', 'kode_kemenkes', 'kelas', 'status_blu', 'kode_ppk_bpjs', 'alamat', 'kontak_admin_unit', 'kontak_admin'];

    protected $attributes = ['paket_identitas' => '{}', 'kontak_admin_unit' => 'admin SIMRS', 'status_penyiapan' => 'sebagian', 'revisi' => 0];

    protected function casts(): array
    {
        return ['status_blu' => 'boolean', 'paket_identitas' => 'array', 'revisi' => 'integer'];
    }

    public static function satu(): ?self
    {
        return self::query()->first();
    }
}
