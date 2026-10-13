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
 * @property array{lencana: string, judul: string, judul_sorot: string|null, uraian: string, fitur: list<array{judul: string, uraian: string}>, foto_keterangan: string|null, tampilkan_motif: bool}|null $halaman_masuk
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

    /**
     * Warna bawaan produk selama instalasi belum mengisi warnanya sendiri: palet pedoman identitas RS mitra (keputusan
     * pemilik 13 Okt 2026, tiket 1.15), sama dengan --brand-600 dan --accent di frontend/src/design-system/tokens.css.
     */
    public const WARNA_BAWAAN = ['brand' => '#006937', 'accent' => '#F3C300'];

    /** Teks panel halaman masuk selama belum diatur (tiket 1.15); sama dengan HALAMAN_MASUK_BAWAAN di frontend. */
    public const HALAMAN_MASUK_BAWAAN = [
        'lencana' => 'Satu data untuk seluruh rumah sakit',
        'judul' => 'Pelayanan, pendapatan, dan mutu dalam',
        'judul_sorot' => 'satu layar kerja.',
        'uraian' => 'Dari pendaftaran sampai klaim, setiap pencatatan langsung menjadi rekam medis, tagihan, dan laporan, tanpa entri ulang.',
        'fitur' => [
            ['judul' => 'Paperless', 'uraian' => 'Pengesahan elektronik dengan PIN, catatan terkunci, perubahan jadi adendum.'],
            ['judul' => 'Terhubung', 'uraian' => 'BPJS dan SATUSEHAT berjalan dari layar yang sama.'],
            ['judul' => 'Pendidikan', 'uraian' => 'Akun peserta didik mengikuti masa rotasi dan supervisi.'],
        ],
        'foto_keterangan' => null,
        'tampilkan_motif' => true,
    ];

    public const JENIS_BERKAS = ['logo', 'motif', 'foto'];

    protected $table = 'inti.profil_rs';

    protected $fillable = ['nama', 'kode_kemenkes', 'kelas', 'status_blu', 'kode_ppk_bpjs', 'alamat', 'kontak_admin_unit', 'kontak_admin'];

    protected $attributes = ['paket_identitas' => '{}', 'kontak_admin_unit' => 'admin SIMRS', 'status_penyiapan' => 'sebagian', 'revisi' => 0];

    protected function casts(): array
    {
        return ['status_blu' => 'boolean', 'paket_identitas' => 'array', 'halaman_masuk' => 'array', 'revisi' => 'integer'];
    }

    public static function satu(): ?self
    {
        return self::query()->first();
    }
}
