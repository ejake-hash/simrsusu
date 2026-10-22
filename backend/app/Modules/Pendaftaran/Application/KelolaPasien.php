<?php

namespace App\Modules\Pendaftaran\Application;

use App\Core\Audit\CatatAktivitas;
use App\Core\Http\ApiError;
use App\Core\Http\IfMatch;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\Pendaftaran\Models\IdentitasPasien;
use App\Modules\Pendaftaran\Models\KontakDaruratPasien;
use App\Modules\Pendaftaran\Models\Pasien;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Daftarkan dan perbaiki pasien (tiket 2.5; FR-01-04, FR-01-05, Permenkes 24/2022 Ps. 30). No. RM dari penghitung di
 * transaksi yang sama: pendaftaran gagal ikut membatalkan nomornya, jadi tanpa celah dan tidak dipakai ulang. Setiap
 * perubahan menyimpan isi sebelumnya (terenkripsi) di pasien_versi. Audit hanya memuat nama field, bukan nilainya.
 */
final class KelolaPasien
{
    public function __construct(private readonly CatatAktivitas $audit) {}

    /** @param  array<string, mixed>  $isi  isian POST (kontrak IsianPasien) */
    public function buat(Pengguna $oleh, array $isi): Pasien
    {
        return $this->tangkapGanda($isi, null, fn () => DB::transaction(function () use ($oleh, $isi) {
            $pasien = new Pasien;
            $pasien->tanpa_identitas = (bool) ($isi['tanpa_identitas'] ?? false);
            $this->terapkan($pasien, $isi);
            $this->periksaAkhir($pasien, $isi, false);
            $this->periksaGanda($isi, null);
            $pasien->no_rm = $this->noRmBaru();
            $pasien->forceFill(['dibuat_oleh' => $oleh->id, 'diubah_oleh' => $oleh->id])->save();
            foreach (array_keys(IdentitasPasien::JENIS) as $field) {
                $this->aturIdentitas($pasien, $field, $isi[$field] ?? null, $oleh->id);
            }
            $this->gantiKontak($pasien, $isi['kontak_darurat'] ?? [], $oleh->id);

            $terisi = array_keys(array_filter($this->isi($pasien->refresh()), fn ($nilai) => $nilai !== null && $nilai !== [] && $nilai !== false));
            $this->audit->catat('pasien.dibuat', $oleh->id, 'pasien.pasien', $pasien->id, ['no_rm' => $pasien->no_rm, 'field' => $terisi]);

            return $pasien;
        }));
    }

    /** @param  array<string, mixed>  $isi  isian PATCH (kontrak IsianPasienUbah + alasan_perubahan) */
    public function ubah(Pengguna $oleh, Pasien $pasien, string $versi, array $isi, bool $bolehIdentitas): Pasien
    {
        return $this->tangkapGanda($isi, $pasien->id, fn () => DB::transaction(function () use ($oleh, $pasien, $versi, $isi, $bolehIdentitas) {
            $pasien = Pasien::query()->whereKey($pasien->id)->lockForUpdate()->firstOrFail();
            if (IfMatch::revisi($versi) !== $pasien->revisi) {
                throw new ApiError(412, 'versi_berubah', 'Data pasien ini diubah orang lain sejak Anda membukanya. Muat ulang untuk melihat perubahan terbaru.');
            }
            $sebelum = $this->isi($pasien);

            foreach (['nik', 'no_paspor'] as $field) {
                // Ditolak walau nilainya sama: jawaban berbeda untuk nilai sama/beda membuka tebakan NIK.
                if (array_key_exists($field, $isi) && $sebelum[$field] !== null && ! $bolehIdentitas) {
                    throw new ApiError(403, 'akses_ditolak', 'Mengubah NIK atau paspor yang sudah terisi butuh izin melihat identitas pasien.');
                }
            }
            if (($isi['tanpa_identitas'] ?? null) === true && ! $pasien->tanpa_identitas) {
                throw self::validasi('tanpa_identitas', 'Pasien yang identitasnya sudah lengkap tidak bisa dijadikan pasien tanpa identitas.');
            }
            $melengkapi = $pasien->tanpa_identitas && ($isi['tanpa_identitas'] ?? null) === false;
            if ($melengkapi && ($isi['nama'] ?? null) === null) {
                throw self::validasi('nama', 'Isi nama pasien sesuai identitasnya.');
            }
            if (array_key_exists('tanpa_identitas', $isi)) {
                $pasien->tanpa_identitas = (bool) $isi['tanpa_identitas'];
            }

            $this->terapkan($pasien, $isi);
            $this->periksaAkhir($pasien, $isi, true);
            $this->periksaGanda($isi, $pasien->id);
            $pasien->forceFill(['revisi' => $pasien->revisi + 1, 'diubah_oleh' => $oleh->id])->save();
            foreach (array_keys(IdentitasPasien::JENIS) as $field) {
                if (array_key_exists($field, $isi)) {
                    $this->aturIdentitas($pasien, $field, $isi[$field], $oleh->id);
                }
            }
            if (array_key_exists('kontak_darurat', $isi)) {
                $this->gantiKontak($pasien, $isi['kontak_darurat'] ?? [], $oleh->id);
            }

            DB::table('pasien.pasien_versi')->insert([
                'id' => (string) Str::uuid7(), 'pasien_id' => $pasien->id, 'revisi' => $pasien->revisi - 1,
                'isi_enkripsi' => Crypt::encryptString((string) json_encode($sebelum)), 'alasan_perubahan' => $isi['alasan_perubahan'],
                'dibuat_pada' => now(), 'dibuat_oleh' => $oleh->id,
            ]);
            $sesudah = $this->isi($pasien->refresh());
            $berubah = array_keys(array_filter($sesudah, fn ($nilai, $field) => $nilai !== $sebelum[$field], ARRAY_FILTER_USE_BOTH));
            $this->audit->catat('pasien.diubah', $oleh->id, 'pasien.pasien', $pasien->id, ['field' => $berubah]);

            return $pasien;
        }));
    }

    /** Nomor berikutnya dari penghitung satu baris; kunci baris menahan pendaftaran lain sampai commit. */
    private function noRmBaru(): string
    {
        $terakhir = DB::selectOne('UPDATE pasien.nomor_rm SET terakhir = terakhir + 1 WHERE id = 1 RETURNING terakhir')->terakhir;

        return str_pad((string) $terakhir, (int) config('instalasi.panjang_no_rm'), '0', STR_PAD_LEFT);
    }

    /** @param  array<string, mixed>  $isi */
    private function terapkan(Pasien $pasien, array $isi): void
    {
        $this->periksaReferensi($isi);
        $pasien->fill(array_intersect_key($isi, array_flip($pasien->getFillable())));
        if (array_key_exists('kewarganegaraan', $isi) && $isi['kewarganegaraan'] === null) {
            $pasien->kewarganegaraan = 'wni';
        }
        if (array_key_exists('telepon', $isi)) {
            $pasien->telepon_enkripsi = $isi['telepon'];
        }
        if (array_key_exists('alamat', $isi)) {
            $alamat = $isi['alamat'];
            $pasien->wilayah_id = $alamat === null ? null : $this->wilayahId($alamat['wilayah_kode']);
            $pasien->alamat_enkripsi = $alamat === null ? null
                : ['jalan' => $alamat['jalan'], 'rt' => $alamat['rt'] ?? null, 'rw' => $alamat['rw'] ?? null, 'kode_pos' => $alamat['kode_pos'] ?? null];
        }
        // Pasien tanpa identitas tanpa nama: nama sementara menurut jenis kelamin (FR-01-05).
        if ($pasien->tanpa_identitas && ($pasien->nama ?? '') === '') {
            $pasien->nama = match ($pasien->jenis_kelamin) {
                'laki_laki' => 'Mr. X',
                'perempuan' => 'Ny. X',
                default => 'X',
            };
        }
    }

    /**
     * Aturan sesudah perubahan: selain pasien tanpa identitas, nama, tanggal lahir, dan jenis kelamin wajib; WNA tanpa NIK
     * wajib paspor.
     *
     * @param  array<string, mixed>  $isi
     */
    private function periksaAkhir(Pasien $pasien, array $isi, bool $ubah): void
    {
        if (! $pasien->tanpa_identitas) {
            if (($pasien->nama ?? '') === '') {
                throw self::validasi('nama', 'Nama pasien wajib diisi.');
            }
            if ($pasien->tanggal_lahir === null) {
                throw self::validasi('tanggal_lahir', 'Tanggal lahir wajib diisi.');
            }
            if ($pasien->jenis_kelamin === 'tidak_diketahui') {
                throw self::validasi('jenis_kelamin', 'Jenis kelamin "tidak diketahui" hanya untuk pasien tanpa identitas.');
            }
        }
        $nilai = fn (string $field) => array_key_exists($field, $isi) ? $isi[$field] : ($ubah ? $pasien->nilaiIdentitas($field) : null);
        if ($pasien->kewarganegaraan === 'wna' && $nilai('nik') === null && $nilai('no_paspor') === null) {
            throw self::validasi('no_paspor', 'Pasien WNA tanpa NIK wajib diisi nomor paspor.');
        }
    }

    /**
     * NIK dan No. kartu BPJS milik satu pasien: 409 dengan rujukan pasien yang ada (di rincian, tidak di pesan).
     *
     * @param  array<string, mixed>  $isi
     */
    private function periksaGanda(array $isi, ?string $kecuali): void
    {
        foreach (['nik' => 'nik_sudah_terdaftar', 'no_kartu_bpjs' => 'no_kartu_sudah_terdaftar'] as $field => $kode) {
            if (($isi[$field] ?? null) === null) {
                continue;
            }
            $jenis = IdentitasPasien::JENIS[$field];
            $ada = DB::table('pasien.identitas AS i')->join('pasien.pasien AS p', 'p.id', '=', 'i.pasien_id')
                ->where('i.jenis', $jenis)->where('i.sidik', IdentitasPasien::sidik($jenis, $isi[$field]))
                ->when($kecuali, fn ($q, $id) => $q->where('i.pasien_id', '<>', $id))->first(['p.id', 'p.no_rm']);
            if ($ada !== null) {
                throw new ApiError(409, $kode, $field === 'nik'
                    ? 'NIK ini sudah terdaftar untuk pasien lain. Buka data pasien tersebut, jangan membuat No. RM baru.'
                    : 'No. kartu BPJS ini sudah tercatat untuk pasien lain. Buka data pasien tersebut, jangan membuat No. RM baru.',
                    [['pasien_id' => $ada->id, 'no_rm' => $ada->no_rm]]);
            }
        }
    }

    /**
     * Pendaftaran bersamaan dengan NIK/No. kartu sama lolos periksaGanda: indeks unik menolak salah satunya, lalu
     * dijawab 409 yang sama.
     *
     * @template T
     *
     * @param  array<string, mixed>  $isi
     * @param  callable(): T  $kerja
     * @return T
     */
    private function tangkapGanda(array $isi, ?string $kecuali, callable $kerja): mixed
    {
        try {
            return $kerja();
        } catch (QueryException $e) {
            if ($e->getCode() !== '23505') {
                throw $e;
            }
            $this->periksaGanda($isi, $kecuali);
            throw $e;
        }
    }

    private function aturIdentitas(Pasien $pasien, string $field, ?string $nilai, string $olehId): void
    {
        $baris = IdentitasPasien::query()->firstOrNew(['pasien_id' => $pasien->id, 'jenis' => IdentitasPasien::JENIS[$field]]);
        if ($baris->exists ? $baris->nilai_enkripsi === $nilai : $nilai === null) {
            return;
        }
        $baris->aturNilai($nilai);
        $baris->forceFill([
            'revisi' => $baris->exists ? $baris->revisi + 1 : 0, 'diubah_oleh' => $olehId,
            'dibuat_oleh' => $baris->exists ? $baris->dibuat_oleh : $olehId,
        ])->save();
    }

    /** @param  list<array{nama: string, hubungan: string, telepon: string}>  $kontak */
    private function gantiKontak(Pasien $pasien, array $kontak, string $olehId): void
    {
        KontakDaruratPasien::query()->where('pasien_id', $pasien->id)->where('aktif', true)
            ->update(['aktif' => false, 'diganti_pada' => now(), 'diubah_pada' => now(), 'diubah_oleh' => $olehId]);
        foreach ($kontak as $i => $k) {
            (new KontakDaruratPasien(['pasien_id' => $pasien->id, 'urutan' => $i + 1, 'nama' => $k['nama'], 'hubungan' => $k['hubungan'], 'telepon_enkripsi' => $k['telepon']]))
                ->forceFill(['dibuat_oleh' => $olehId, 'diubah_oleh' => $olehId])->save();
        }
    }

    /** @param  array<string, mixed>  $isi */
    private function periksaReferensi(array $isi): void
    {
        foreach (Pasien::REFERENSI as $field => $kelompok) {
            if (($isi[$field] ?? null) !== null
                && ! DB::table('inti.referensi')->where('kelompok', $kelompok)->where('kode', $isi[$field])->where('aktif', true)->exists()) {
                throw new ApiError(422, 'referensi_tidak_valid', 'Pilihan tidak ada di daftar referensi yang berlaku.', [['kolom' => $field, 'pesan' => 'Pilih dari daftar.']]);
            }
        }
    }

    private function wilayahId(string $kode): string
    {
        $id = DB::table('inti.wilayah')->where('kode_kemendagri', $kode)->where('tingkat', 'kelurahan')->where('aktif', true)->value('id');

        return $id ?? throw new ApiError(422, 'wilayah_tidak_valid', 'Wilayah harus kelurahan/desa yang berlaku.',
            [['kolom' => 'alamat.wilayah_kode', 'pesan' => 'Pilih kelurahan/desa dari daftar.']]);
    }

    /**
     * Isi pasien berbentuk field kontrak, untuk versi sebelumnya dan pembanding field yang berubah.
     *
     * @return array<string, mixed>
     */
    private function isi(Pasien $pasien): array
    {
        $pasien->load(['identitas', 'kontakDarurat']);

        return [
            ...collect(Pasien::ISIAN)->mapWithKeys(fn ($f) => [$f => $pasien->getAttribute($f)])->all(),
            'tanggal_lahir' => $pasien->tanggal_lahir?->toDateString(),
            'nik' => $pasien->nilaiIdentitas('nik'),
            'no_paspor' => $pasien->nilaiIdentitas('no_paspor'),
            'no_kartu_bpjs' => $pasien->nilaiIdentitas('no_kartu_bpjs'),
            'alamat' => $pasien->alamat_enkripsi === null ? null : [...$pasien->alamat_enkripsi, 'wilayah_id' => $pasien->wilayah_id],
            'telepon' => $pasien->telepon_enkripsi,
            'kontak_darurat' => $pasien->kontakDarurat->map(fn (KontakDaruratPasien $k) => ['nama' => $k->nama, 'hubungan' => $k->hubungan, 'telepon' => $k->telepon_enkripsi])->all(),
        ];
    }

    private static function validasi(string $kolom, string $pesan): ApiError
    {
        return new ApiError(422, 'validasi_gagal', 'Isian belum benar.', [['kolom' => $kolom, 'pesan' => $pesan]]);
    }
}
