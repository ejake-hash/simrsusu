<?php

namespace App\Modules\RekamMedis\Application;

use App\Core\Audit\CatatAktivitas;
use App\Core\Http\ApiError;
use App\Core\Http\IfMatch;
use App\Core\Instalasi\StatusInstalasi;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\Pendaftaran\Application\KelolaPasien;
use App\Modules\RekamMedis\Events\AsesmenPerawatSelesai;
use App\Modules\RekamMedis\Models\CatatanKlinis;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Asesmen awal keperawatan rawat jalan (tiket 2.13; FR-07-02, FR-07-21; kontrak v0.23.0). Draf bebas diubah penulisnya;
 * sesudah selesai, perbaikan ≤ 2 × 24 jam dengan alasan dan versi lama tersimpan (FR-26-06). Tidak memberi relasi
 * perawatan (lapis 4). Audit hanya id: isi klinis tidak pernah ke log/audit.
 */
final class KelolaAsesmenAwal
{
    /** Tanda vital → LOINC + UCUM (kode baku, bukan nilai klinis). */
    public const LOINC = [
        'sistolik' => ['8480-6', 'mm[Hg]'], 'diastolik' => ['8462-4', 'mm[Hg]'], 'nadi' => ['8867-4', '/min'], 'napas' => ['9279-1', '/min'],
        'suhu' => ['8310-5', 'Cel'], 'spo2' => ['59408-5', '%'], 'berat_badan' => ['29463-7', 'kg'], 'tinggi_badan' => ['8302-2', 'cm'],
        'imt' => ['39156-5', 'kg/m2'],
    ];

    public const TANDA_VITAL = ['sistolik', 'diastolik', 'nadi', 'napas', 'suhu', 'spo2', 'berat_badan', 'tinggi_badan'];

    public const SKRINING = ['skala_nyeri', 'lokasi_nyeri', 'risiko_jatuh', 'gizi_berisiko', 'catatan'];

    public const TEKS = ['keluhan_utama', 'riwayat_penyakit_sekarang', 'riwayat_penyakit_dahulu', 'catatan'];

    private const JAM_PERBAIKAN = 48;

    public function __construct(private readonly CatatAktivitas $audit, private readonly StatusInstalasi $instalasi) {}

    /**
     * @param  object{id: string, episode_id: string, pasien_id: string, status: string}  $kunjungan
     * @param  array<string, mixed>  $isian
     */
    public function buat(Pengguna $penulis, object $kunjungan, string $unitId, array $isian): CatatanKlinis
    {
        KelolaPasien::pastikanSiapMelayani($this->instalasi);
        if (empty($penulis->pegawai_id)) {
            throw new ApiError(403, 'akses_ditolak', 'Asesmen hanya bisa ditulis akun pegawai.');
        }

        return DB::transaction(function () use ($penulis, $kunjungan, $unitId, $isian) {
            $status = DB::table('kunjungan.kunjungan')->where('id', $kunjungan->id)->lockForUpdate()->value('status');
            if (in_array($status, ['batal', 'selesai'], true)) {
                throw new ApiError(409, 'status_tidak_sesuai', 'Kunjungan sudah batal atau selesai.', [['status' => $status]]);
            }
            $ada = CatatanKlinis::query()->where('kunjungan_id', $kunjungan->id)->where('jenis', 'asesmen')->where('profesi', 'perawat')->value('id');
            if ($ada !== null) {
                throw new ApiError(409, 'asesmen_sudah_ada', 'Kunjungan ini sudah punya asesmen awal.', [['id' => $ada]]);
            }
            $catatan = new CatatanKlinis([
                'episode_id' => $kunjungan->episode_id, 'kunjungan_id' => $kunjungan->id, 'unit_id' => $unitId,
                'pasien_id' => $kunjungan->pasien_id, 'penulis_id' => $penulis->id, 'profesi' => 'perawat', 'tatanan' => 'rj',
                'jenis' => 'asesmen', 'isi' => self::gabung([], $isian), 'dibuat_oleh' => $penulis->id, 'diubah_oleh' => $penulis->id,
            ]);
            try {
                $catatan->save();
            } catch (QueryException $e) {
                // Dua perawat memulai bersamaan: indeks unik catatan_klinis_asesmen_awal_unik.
                throw $e->getCode() === '23505' ? new ApiError(409, 'asesmen_sudah_ada', 'Kunjungan ini sudah punya asesmen awal.') : $e;
            }
            $this->audit->catat('klinis.asesmen_dibuat', $penulis->id, $catatan->getTable(), $catatan->id, ['kunjungan_id' => $kunjungan->id]);

            return $catatan->refresh();
        });
    }

    /** @param  array<string, mixed>  $isian  boleh berisi alasan_perubahan */
    public function ubah(Pengguna $penulis, CatatanKlinis $catatan, string $versi, array $isian): CatatanKlinis
    {
        $alasan = $isian['alasan_perubahan'] ?? null;
        unset($isian['alasan_perubahan']);
        if ($isian === []) {
            throw ValidationException::withMessages(['keluhan_utama' => 'Isi minimal satu bagian asesmen.']);
        }

        return DB::transaction(function () use ($penulis, $catatan, $versi, $isian, $alasan) {
            $catatan = $this->kunciMilik($penulis, $catatan->id, $versi);
            // Kunjungan batal tidak menerima perbaikan; kunjungan selesai tetap boleh dalam 2 × 24 jam (FR-26-06).
            if (DB::table('kunjungan.kunjungan')->where('id', $catatan->kunjungan_id)->value('status') === 'batal') {
                throw new ApiError(409, 'status_tidak_sesuai', 'Kunjungan sudah batal.', [['status' => 'batal']]);
            }
            $selesai = $catatan->status !== 'draf';
            if ($selesai) {
                if ($alasan === null) {
                    throw ValidationException::withMessages(['alasan_perubahan' => 'Alasan perubahan wajib untuk asesmen yang sudah selesai.']);
                }
                if (now()->greaterThan($catatan->batas_perbaikan_pada)) {
                    throw new ApiError(409, 'batas_perbaikan_lewat', 'Batas perbaikan 2 × 24 jam sudah lewat. Koreksi klinis lewat adendum.');
                }
                DB::table('klinis.catatan_klinis_versi')->insert([
                    'id' => (string) Str::uuid7(), 'catatan_klinis_id' => $catatan->id, 'versi' => $catatan->jumlah_versi + 1,
                    'isi' => json_encode($catatan->isi), 'alasan' => $alasan, 'diubah_oleh' => $penulis->id, 'diubah_pada' => now(),
                ]);
            }
            $catatan->forceFill([
                'isi' => self::gabung($catatan->isi, $isian), 'diubah_oleh' => $penulis->id,
                'jumlah_versi' => $catatan->jumlah_versi + ($selesai ? 1 : 0),
            ])->save();
            if ($selesai) {
                $this->catatObservasi($catatan, $penulis, true);
            }
            $this->audit->catat($selesai ? 'klinis.asesmen_diperbaiki' : 'klinis.asesmen_diubah', $penulis->id, $catatan->getTable(), $catatan->id, []);

            return $catatan->refresh();
        });
    }

    public function selesai(Pengguna $penulis, CatatanKlinis $catatan, string $versi): CatatanKlinis
    {
        return DB::transaction(function () use ($penulis, $catatan, $versi) {
            $catatan = $this->kunciMilik($penulis, $catatan->id, $versi);
            if ($catatan->status !== 'draf') {
                throw new ApiError(409, 'status_tidak_sesuai', 'Asesmen sudah selesai.', [['status' => $catatan->status]]);
            }
            $kurang = [];
            if (trim((string) ($catatan->isi['keluhan_utama'] ?? '')) === '') {
                $kurang['keluhan_utama'] = 'Keluhan utama wajib diisi.';
            }
            foreach (['sistolik', 'diastolik', 'nadi', 'napas', 'suhu'] as $tv) {
                if (($catatan->isi['tanda_vital'][$tv] ?? null) === null) {
                    $kurang["tanda_vital.$tv"] = 'Tanda vital ini wajib diisi.';
                }
            }
            if ($kurang !== []) {
                throw ValidationException::withMessages($kurang);
            }
            $sekarang = now();
            $catatan->forceFill(['status' => 'selesai', 'selesai_pada' => $sekarang, 'batas_perbaikan_pada' => $sekarang->copy()->addHours(self::JAM_PERBAIKAN),
                'diubah_oleh' => $penulis->id])->save();
            $this->catatObservasi($catatan, $penulis, false);
            AsesmenPerawatSelesai::dispatch($catatan->kunjungan_id, $penulis->id, $sekarang->toIso8601String());
            $this->audit->catat('klinis.asesmen_selesai', $penulis->id, $catatan->getTable(), $catatan->id, ['kunjungan_id' => $catatan->kunjungan_id]);

            return $catatan->refresh();
        });
    }

    /** IMT dari berat (kg) dan tinggi (cm), dua desimal; null bila salah satu kosong. */
    public static function imt(?float $berat, ?float $tinggi): ?float
    {
        return $berat === null || $tinggi === null || $tinggi <= 0 ? null : round($berat / (($tinggi / 100) ** 2), 2);
    }

    /**
     * @param  array<string, mixed>  $lama
     * @param  array<string, mixed>  $baru
     * @return array<string, mixed>
     */
    private static function gabung(array $lama, array $baru): array
    {
        $isi = [...$lama, ...array_intersect_key($baru, array_flip(self::TEKS))];
        $isi['tanda_vital'] = [...array_fill_keys(self::TANDA_VITAL, null), ...($lama['tanda_vital'] ?? []), ...array_intersect_key($baru['tanda_vital'] ?? [], array_flip(self::TANDA_VITAL))];
        $isi['skrining'] = [...array_fill_keys(self::SKRINING, null), ...($lama['skrining'] ?? []), ...array_intersect_key($baru['skrining'] ?? [], array_flip(self::SKRINING))];
        $tv = $isi['tanda_vital'];
        $isi['imt'] = self::imt($tv['berat_badan'] === null ? null : (float) $tv['berat_badan'], $tv['tinggi_badan'] === null ? null : (float) $tv['tinggi_badan']);

        return $isi;
    }

    private function kunciMilik(Pengguna $penulis, string $id, string $versi): CatatanKlinis
    {
        $catatan = CatatanKlinis::query()->whereKey($id)->lockForUpdate()->firstOrFail();
        if ($catatan->penulis_id !== $penulis->id) {
            throw new ApiError(403, 'akses_ditolak', 'Hanya penulis yang dapat mengubah asesmen ini.');
        }
        if (IfMatch::revisi($versi) !== $catatan->revisi) {
            throw new ApiError(412, 'versi_berubah', 'Asesmen ini diubah sejak Anda membukanya. Muat ulang untuk melihat perubahan terbaru.');
        }
        $catatan->revisi++;

        return $catatan;
    }

    /** Tanda vital + IMT → observasi berkode LOINC; perbaikan membatalkan baris lama catatan ini lalu menulis yang baru. */
    private function catatObservasi(CatatanKlinis $catatan, Pengguna $petugas, bool $ganti): void
    {
        $waktu = $catatan->selesai_pada ?? now();
        if ($ganti) {
            DB::table('klinis.observasi')->where('catatan_klinis_id', $catatan->id)->whereNull('dibatalkan_pada')->update(['dibatalkan_pada' => now()]);
        }
        $nilai = [...$catatan->isi['tanda_vital'], 'imt' => $catatan->isi['imt']];
        $baris = [];
        foreach (self::LOINC as $kunci => [$loinc, $satuan]) {
            if (($nilai[$kunci] ?? null) !== null) {
                $baris[] = ['id' => (string) Str::uuid7(), 'kunjungan_id' => $catatan->kunjungan_id, 'pasien_id' => $catatan->pasien_id,
                    'catatan_klinis_id' => $catatan->id, 'kode_loinc' => $loinc, 'nilai_angka' => $nilai[$kunci], 'satuan' => $satuan,
                    'waktu' => $waktu, 'sumber' => 'manual', 'petugas_id' => $petugas->id];
            }
        }
        DB::table('klinis.observasi')->insert($baris);
    }
}
