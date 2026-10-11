<?php

namespace App\Modules\Sdm\Models;

use App\Modules\Sdm\Database\Factories\PegawaiFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Pegawai RS (PRD 11.2 sdm.pegawai; tiket 1.8d). NIK hanya lewat aturNik()/nik(): tersimpan terenkripsi dengan sidik
 * HMAC untuk keunikan, tidak pernah ikut toArray()/log.
 *
 * @property string $id
 * @property string|null $nip
 * @property string $nama
 * @property string $status aktif | nonaktif (keluar sudah diproses)
 * @property string|null $nik_enkripsi sudah didekripsi oleh cast `encrypted`
 * @property string|null $nik_sidik
 * @property string|null $jenis_kepegawaian
 * @property string|null $profesi
 * @property string|null $unit_organisasi_id
 * @property string|null $jabatan
 * @property Carbon|null $tmt
 * @property Carbon|null $tanggal_keluar
 * @property string|null $alasan_keluar
 * @property string|null $ihs_practitioner_id
 * @property int $revisi
 * @property Carbon $diubah_pada
 */
final class Pegawai extends Model
{
    /** @use HasFactory<PegawaiFactory> */
    use HasFactory, HasUuids;

    public const CREATED_AT = 'dibuat_pada';

    public const UPDATED_AT = 'diubah_pada';

    public const PROFESI = ['dokter', 'dokter_gigi', 'perawat', 'bidan', 'apoteker', 'tenaga_kesehatan_lain', 'non_kesehatan'];

    protected $table = 'sdm.pegawai';

    protected $fillable = ['nip', 'nama', 'jenis_kepegawaian', 'profesi', 'unit_organisasi_id', 'jabatan', 'tmt', 'tanggal_keluar', 'alasan_keluar'];

    protected $hidden = ['nik_enkripsi', 'nik_sidik'];

    protected $attributes = ['status' => 'aktif', 'revisi' => 0];

    protected function casts(): array
    {
        return ['nik_enkripsi' => 'encrypted', 'tmt' => 'date', 'tanggal_keluar' => 'date', 'revisi' => 'integer'];
    }

    public function aturNik(?string $nik): void
    {
        $this->nik_enkripsi = $nik;
        $this->nik_sidik = $nik === null ? null : self::sidik($nik);
    }

    public function nik(): ?string
    {
        return $this->nik_enkripsi;
    }

    /** "1271 •••• •••• 0042" (PRD 11.1); null bila NIK belum diisi. Tidak butuh dekripsi di daftar bila sidik kosong. */
    public function nikSamaran(): ?string
    {
        $nik = ($this->getAttributes()['nik_enkripsi'] ?? null) === null ? null : $this->nik_enkripsi;

        return $nik === null ? null : substr($nik, 0, 4).' •••• •••• '.substr($nik, -4);
    }

    /** Sidik NIK untuk keunikan: HMAC dengan kunci turunan APP_KEY (rotasi APP_KEY = hitung ulang sidik). */
    public static function sidik(string $nik): string
    {
        return hash_hmac('sha256', $nik, hash_hmac('sha256', 'sdm.pegawai.nik', (string) config('app.key')));
    }

    /** aktif | akan_keluar | keluar, dari tanggal_keluar (zona RS). */
    public function statusKepegawaian(): string
    {
        $hariIni = now(config('instalasi.zona_waktu'))->toDateString();

        return match (true) {
            $this->tanggal_keluar === null => 'aktif',
            $this->tanggal_keluar->toDateString() <= $hariIni => 'keluar',
            default => 'akan_keluar',
        };
    }

    protected static function newFactory(): PegawaiFactory
    {
        return PegawaiFactory::new();
    }
}
