<?php

namespace App\Modules\RekamMedis\Application;

use App\Core\Audit\CatatAktivitas;
use App\Core\Http\ApiError;
use App\Core\Http\IfMatch;
use App\Core\Instalasi\StatusInstalasi;
use App\Modules\Akses\Application\KelolaRelasiPerawatan;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\Pendaftaran\Application\KelolaPasien;
use App\Modules\Pendaftaran\Models\Kunjungan;
use App\Modules\RekamMedis\Events\PemeriksaanDimulai;
use App\Modules\RekamMedis\Events\PemeriksaanSelesai;
use App\Modules\RekamMedis\Models\CatatanKlinis;
use App\Modules\RekamMedis\Models\Diagnosis;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pemeriksaan dokter rawat jalan (tiket 2.15, kontrak v0.25.0; FR-07-05, FR-02-09, 7.02.1): mulai periksa, diagnosis ICD-10
 * dari paket yang dimuat RS (K7), selesai kunjungan. Tiap aksi mengunci baris kunjungan dulu, jadi aksi serentak pada
 * kunjungan yang sama berurutan. Kode diagnosis tidak pernah ke log/audit.
 */
final class KelolaPemeriksaan
{
    public function __construct(
        private readonly CatatAktivitas $audit,
        private readonly StatusInstalasi $instalasi,
        private readonly KelolaRelasiPerawatan $relasi,
    ) {}

    public function mulai(Pengguna $oleh, string $kunjunganId): Kunjungan
    {
        KelolaPasien::pastikanSiapMelayani($this->instalasi);

        return DB::transaction(function () use ($oleh, $kunjunganId) {
            $k = self::kunci($kunjunganId);
            if ($k->status === 'dilayani') {
                return $k; // Dokter membuka RM lagi: kejadian task 4 dan audit tidak diulang.
            }
            self::pastikanStatus($k, 'terdaftar');
            $k->forceFill(['status' => 'dilayani', 'diubah_oleh' => $oleh->id, 'revisi' => $k->revisi + 1])->save();
            PemeriksaanDimulai::dispatch($k->id, $oleh->id, now()->toIso8601String());
            $this->audit->catat('kunjungan.pemeriksaan_dimulai', $oleh->id, $k->getTable(), $k->id, ['nomor' => $k->nomor]);

            return $k;
        });
    }

    public function selesai(Pengguna $oleh, string $kunjunganId, string $versi): Kunjungan
    {
        return DB::transaction(function () use ($oleh, $kunjunganId, $versi) {
            $k = self::kunci($kunjunganId);
            self::pastikanStatus($k, 'dilayani');
            if (IfMatch::revisi($versi) !== $k->revisi) {
                throw new ApiError(412, 'versi_berubah', 'Kunjungan ini diubah sejak Anda membukanya. Muat ulang untuk melihat perubahan terbaru.');
            }
            $kurang = [];
            if (! Diagnosis::query()->where('kunjungan_id', $k->id)->where('jenis', 'utama')->where('status', 'aktif')->exists()) {
                $kurang[] = ['kolom' => 'diagnosis_utama', 'pesan' => 'Diagnosis utama belum dicatat.'];
            }
            $draf = CatatanKlinis::query()->where('kunjungan_id', $k->id)->where('jenis', 'soap')->where('profesi', 'dokter')
                ->where('status', 'draf')->limit(200)->get(['id', 'penulis_id']);
            if ($draf->isNotEmpty()) {
                // Draf orang lain tidak terlihat (aturan CPPT): hanya jumlahnya, id hanya milik akun ini.
                $kurang[] = ['kolom' => 'cppt_dokter_draf', 'pesan' => 'Masih ada CPPT dokter berstatus draf. Sahkan dulu.',
                    'jumlah' => $draf->count(), 'id' => $draf->where('penulis_id', $oleh->id)->pluck('id')->values()->all()];
            }
            if ($kurang !== []) {
                throw new ApiError(409, 'syarat_selesai_belum_lengkap', 'Kunjungan belum bisa diselesaikan.', $kurang);
            }
            $k->forceFill(['status' => 'selesai', 'diubah_oleh' => $oleh->id, 'revisi' => $k->revisi + 1])->save();
            $this->relasi->akhiri('kunjungan', $k->id, 'Kunjungan selesai', $oleh->id);
            PemeriksaanSelesai::dispatch($k->id, $oleh->id, now()->toIso8601String());
            $this->audit->catat('kunjungan.pemeriksaan_selesai', $oleh->id, $k->getTable(), $k->id, ['nomor' => $k->nomor]);

            return $k;
        });
    }

    public function catat(Pengguna $oleh, string $kunjunganId, string $kode, string $jenis): Diagnosis
    {
        self::pastikanPaket('icd10');
        if (! DB::table('inti.terminologi')->where('sistem', 'icd10')->where('kode', $kode)->where('aktif', true)->exists()) {
            throw ValidationException::withMessages(['kode' => 'Kode tidak ada di paket ICD-10 yang dimuat RS.']);
        }

        return DB::transaction(function () use ($oleh, $kunjunganId, $kode, $jenis) {
            $k = self::kunci($kunjunganId);
            self::pastikanStatus($k, 'dilayani');
            $aktif = Diagnosis::query()->where('kunjungan_id', $k->id)->where('status', 'aktif');
            if ($jenis === 'utama' && (clone $aktif)->where('jenis', 'utama')->exists()) {
                throw new ApiError(409, 'diagnosis_utama_sudah_ada', 'Kunjungan ini sudah punya diagnosis utama. Ganti dulu yang lama.');
            }
            if ((clone $aktif)->where('sistem_icd', 'icd10')->where('kode_icd', $kode)->exists()) {
                throw new ApiError(409, 'diagnosis_sudah_tercatat', 'Kode ini sudah tercatat di kunjungan ini.');
            }
            $d = new Diagnosis(['kunjungan_id' => $k->id, 'pasien_id' => $k->pasien_id, 'kode_icd' => $kode, 'jenis' => $jenis,
                'dibuat_oleh' => $oleh->id, 'diubah_oleh' => $oleh->id]);
            $d->save();
            $this->audit->catat('klinis.diagnosis_dicatat', $oleh->id, $d->getTable(), $d->id, ['kunjungan_id' => $k->id]);

            return $d->refresh();
        });
    }

    /** @param  array{jenis?: string, status?: string, alasan_batal?: string}  $isian  tepat satu: jenis, atau status batal + alasan */
    public function ubah(Pengguna $oleh, string $diagnosisId, string $versi, array $isian): Diagnosis
    {
        return DB::transaction(function () use ($oleh, $diagnosisId, $versi, $isian) {
            $kunjunganId = Diagnosis::query()->whereKey($diagnosisId)->value('kunjungan_id');
            $k = self::kunci((string) $kunjunganId);
            $d = Diagnosis::query()->whereKey($diagnosisId)->lockForUpdate()->firstOrFail();
            if ($d->dibuat_oleh !== $oleh->id) {
                throw new ApiError(403, 'akses_ditolak', 'Hanya pencatat diagnosis ini yang bisa mengubahnya.');
            }
            self::pastikanStatus($k, 'dilayani');
            if ($d->status !== 'aktif') {
                throw new ApiError(409, 'status_tidak_sesuai', 'Diagnosis sudah dibatalkan. Catat diagnosis baru.', [['status' => $d->status]]);
            }
            if (IfMatch::revisi($versi) !== $d->revisi) {
                throw new ApiError(412, 'versi_berubah', 'Diagnosis ini diubah sejak Anda membukanya. Muat ulang untuk melihat perubahan terbaru.');
            }
            if (isset($isian['jenis'])) {
                if ($isian['jenis'] === 'utama' && Diagnosis::query()->where('kunjungan_id', $k->id)->where('status', 'aktif')
                    ->where('jenis', 'utama')->whereKeyNot($d->id)->exists()) {
                    throw new ApiError(409, 'diagnosis_utama_sudah_ada', 'Kunjungan ini sudah punya diagnosis utama. Ganti dulu yang lama.');
                }
                $d->jenis = $isian['jenis'];
            } else {
                $d->forceFill(['status' => 'batal', 'alasan_batal' => $isian['alasan_batal'], 'dibatalkan_oleh' => $oleh->id, 'dibatalkan_pada' => now()]);
            }
            $d->forceFill(['diubah_oleh' => $oleh->id, 'revisi' => $d->revisi + 1])->save();
            $this->audit->catat('klinis.diagnosis_diubah', $oleh->id, $d->getTable(), $d->id, ['kunjungan_id' => $k->id, 'status' => $d->status]);

            return $d->refresh();
        });
    }

    public static function pastikanPaket(string $sistem): void
    {
        if (! DB::table('inti.paket_referensi')->where('sistem', $sistem)->exists()) {
            throw new ApiError(409, 'paket_referensi_belum_dimuat', 'Paket referensi belum dimuat. Muat lewat langkah 4 penyiapan instalasi.', [['sistem' => $sistem]]);
        }
    }

    private static function kunci(string $id): Kunjungan
    {
        return Kunjungan::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw new ApiError(404, 'tidak_ditemukan', 'Kunjungan tidak ditemukan.');
    }

    private static function pastikanStatus(Kunjungan $k, string $harus): void
    {
        if ($k->status !== $harus) {
            $pesan = $harus === 'terdaftar' ? 'Kunjungan sudah selesai atau batal.' : 'Mulai periksa dulu; kunjungan belum dilayani, sudah selesai, atau batal.';
            throw new ApiError(409, 'status_tidak_sesuai', $pesan, [['status' => $k->status]]);
        }
    }
}
