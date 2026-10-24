<?php

namespace App\Modules\Pendaftaran\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Pasien (PRD 11.2 pasien.pasien; tiket 2.5). No. RM dibuat KelolaPasien dan tidak bisa diubah (trigger). Alamat dan
 * telepon terenkripsi; NIK, paspor, dan No. kartu ada di pasien.identitas.
 *
 * @property string $id
 * @property string $no_rm
 * @property string $nama
 * @property Carbon|null $tanggal_lahir
 * @property string|null $tempat_lahir
 * @property string $jenis_kelamin
 * @property string|null $agama
 * @property string|null $pendidikan
 * @property string|null $pekerjaan
 * @property string|null $status_kawin
 * @property string $kewarganegaraan
 * @property string|null $bahasa
 * @property string|null $kebutuhan_khusus
 * @property array{jalan: string, rt: string|null, rw: string|null, kode_pos: string|null}|null $alamat_enkripsi sudah didekripsi
 * @property string|null $wilayah_id
 * @property string|null $telepon_enkripsi sudah didekripsi
 * @property bool $tanpa_identitas
 * @property string|null $ciri_pengenal
 * @property Carbon|null $meninggal_pada
 * @property string|null $digabung_ke_id
 * @property Carbon|null $digabung_pada
 * @property int $revisi
 * @property Carbon $dibuat_pada
 * @property Carbon $diubah_pada
 */
final class Pasien extends Model
{
    use HasUuids;

    public const CREATED_AT = 'dibuat_pada';

    public const UPDATED_AT = 'diubah_pada';

    public const JENIS_KELAMIN = ['laki_laki', 'perempuan', 'tidak_diketahui'];

    /** Kolom isian langsung (kontrak IsianPasienUbah); alamat, telepon, identitas, dan kontak darurat ditangani terpisah. */
    public const ISIAN = ['nama', 'tanggal_lahir', 'tempat_lahir', 'jenis_kelamin', 'agama', 'pendidikan', 'pekerjaan', 'status_kawin',
        'kewarganegaraan', 'bahasa', 'kebutuhan_khusus', 'tanpa_identitas', 'ciri_pengenal'];

    /** Field isian => kelompok inti.referensi. */
    public const REFERENSI = ['agama' => 'agama', 'pendidikan' => 'pendidikan', 'pekerjaan' => 'pekerjaan', 'status_kawin' => 'status_kawin'];

    protected $table = 'pasien.pasien';

    protected $fillable = ['nama', 'tanggal_lahir', 'tempat_lahir', 'jenis_kelamin', 'agama', 'pendidikan', 'pekerjaan', 'status_kawin',
        'kewarganegaraan', 'bahasa', 'kebutuhan_khusus', 'ciri_pengenal'];

    protected $hidden = ['alamat_enkripsi', 'telepon_enkripsi'];

    protected $attributes = ['kewarganegaraan' => 'wni', 'tanpa_identitas' => false, 'revisi' => 0];

    protected function casts(): array
    {
        return [
            'tanggal_lahir' => 'date', 'meninggal_pada' => 'datetime', 'digabung_pada' => 'datetime', 'tanpa_identitas' => 'boolean', 'revisi' => 'integer',
            'alamat_enkripsi' => 'encrypted:array', 'telepon_enkripsi' => 'encrypted',
        ];
    }

    /** @return HasMany<IdentitasPasien, $this> */
    public function identitas(): HasMany
    {
        return $this->hasMany(IdentitasPasien::class, 'pasien_id');
    }

    /** @return HasMany<KontakDaruratPasien, $this> */
    public function kontakDarurat(): HasMany
    {
        return $this->hasMany(KontakDaruratPasien::class, 'pasien_id')->where('aktif', true)->orderBy('urutan');
    }

    /** Nilai identitas menurut field kontrak (nik, no_paspor, no_kartu_bpjs); null bila belum diisi. */
    public function nilaiIdentitas(string $field): ?string
    {
        $jenis = IdentitasPasien::JENIS[$field];

        return $this->identitas->firstWhere('jenis', $jenis)?->nilai_enkripsi;
    }
}
