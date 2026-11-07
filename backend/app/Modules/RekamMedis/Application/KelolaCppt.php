<?php

namespace App\Modules\RekamMedis\Application;

use App\Core\Audit\CatatAktivitas;
use App\Core\Http\ApiError;
use App\Core\Http\IfMatch;
use App\Core\Instalasi\StatusInstalasi;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\Pendaftaran\Application\KelolaPasien;
use App\Modules\RekamMedis\Models\CatatanKlinis;
use App\Modules\Ttd\Application\Penandatangan;
use App\Modules\Ttd\Models\Kredensial;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * CPPT episode (tiket 2.14, kontrak v0.24.0; FR-07-19, FR-26-02, FR-26-06, FR-26-18). Pengesahan, perbaikan sah, dan
 * adendum memakai T1 milik penulis: PIN dibuka di luar transaksi (hitungan PIN salah tetap tersimpan), lalu status dan
 * tanda tangan disimpan dalam satu transaksi. Isi klinis tidak pernah ke log/audit.
 */
final class KelolaCppt
{
    public const SOAP = ['subjektif', 'objektif', 'asesmen', 'plan'];

    private const JAM_PERBAIKAN = 48;

    /** sdm.pegawai.profesi → klinis.catatan_klinis.profesi (gizi/fisioterapis menunggu rincian profesi pegawai). */
    private const PROFESI = ['dokter' => 'dokter', 'dokter_gigi' => 'dokter', 'perawat' => 'perawat', 'bidan' => 'bidan', 'apoteker' => 'apoteker'];

    public function __construct(
        private readonly CatatAktivitas $audit,
        private readonly StatusInstalasi $instalasi,
        private readonly Penandatangan $ttd,
    ) {}

    /**
     * @param  object{id: string, episode_id: string, pasien_id: string}  $kunjungan
     * @param  array<string, mixed>  $isian  bagian SOAP + label
     */
    public function buat(Pengguna $penulis, object $kunjungan, string $unitId, array $isian): CatatanKlinis
    {
        KelolaPasien::pastikanSiapMelayani($this->instalasi);
        if (empty($penulis->pegawai_id)) {
            throw new ApiError(403, 'akses_ditolak', 'CPPT hanya bisa ditulis akun pegawai.');
        }
        $profesi = self::PROFESI[(string) DB::table('sdm.pegawai')->where('id', $penulis->pegawai_id)->value('profesi')]
            ?? throw new ApiError(403, 'profesi_tidak_dikenali', 'Profesi pegawai Anda belum dikenali untuk menulis CPPT.');
        $isi = self::isi([], $isian);

        return DB::transaction(function () use ($penulis, $kunjungan, $unitId, $isian, $isi, $profesi) {
            $status = DB::table('kunjungan.kunjungan')->where('id', $kunjungan->id)->lockForUpdate()->value('status');
            if ($status === 'batal') {
                throw new ApiError(409, 'status_tidak_sesuai', 'Kunjungan sudah batal.', [['status' => $status]]);
            }
            $c = new CatatanKlinis([
                'episode_id' => $kunjungan->episode_id, 'kunjungan_id' => $kunjungan->id, 'unit_id' => $unitId, 'pasien_id' => $kunjungan->pasien_id,
                'penulis_id' => $penulis->id, 'profesi' => $profesi, 'tatanan' => 'rj', 'jenis' => 'soap', 'isi' => $isi,
                'label' => $isian['label'] ?? null, 'dibuat_oleh' => $penulis->id, 'diubah_oleh' => $penulis->id,
            ]);
            $c->save();
            $this->audit->catat('klinis.cppt_dibuat', $penulis->id, $c->getTable(), $c->id, ['kunjungan_id' => $kunjungan->id]);

            return $c->refresh();
        });
    }

    /** @param  array<string, mixed>  $isian  SOAP/label + alasan_perubahan, pin (entri sah) */
    public function ubah(Request $request, Pengguna $penulis, CatatanKlinis $c, string $versi, array $isian): CatatanKlinis
    {
        $ubahan = array_intersect_key($isian, array_flip([...self::SOAP, 'label']));
        if ($ubahan === []) {
            throw ValidationException::withMessages(['subjektif' => 'Isi minimal satu bagian SOAP atau label.']);
        }
        $this->pastikanPenulis($penulis, $c);
        $sah = $c->status === 'sah';
        if ($sah) {
            if (($isian['alasan_perubahan'] ?? null) === null || ($isian['pernyataan_disetujui'] ?? null) !== true) {
                throw ValidationException::withMessages(array_filter([
                    'alasan_perubahan' => ($isian['alasan_perubahan'] ?? null) === null ? 'Alasan perubahan wajib untuk CPPT yang sudah sah.' : null,
                    'pernyataan_disetujui' => ($isian['pernyataan_disetujui'] ?? null) !== true ? 'Setujui pernyataan tanda tangan.' : null,
                ]));
            }
            $this->pastikanMasihBisaDiperbaiki($c);
        }
        $kunci = $sah ? [...$this->ttd->buka($request, $penulis, $isian['pin'] ?? null), ($isian['pin'] ?? null) === null] : null;

        return $this->denganKunci($kunci, fn () => DB::transaction(function () use ($penulis, $c, $versi, $ubahan, $isian, $kunci) {
            $c = $this->kunciMilik($penulis, $c->id, $versi);
            $sah = $c->status === 'sah';
            if ($sah) {
                $this->pastikanMasihBisaDiperbaiki($c);
                DB::table('klinis.catatan_klinis_versi')->insert([
                    'id' => (string) Str::uuid7(), 'catatan_klinis_id' => $c->id, 'versi' => $c->jumlah_versi + 1,
                    'isi' => json_encode(['isi' => $c->isi, 'label' => $c->label]), 'alasan' => $isian['alasan_perubahan'], 'diubah_oleh' => $penulis->id, 'diubah_pada' => now(),
                ]);
            }
            $isi = self::isi($c->isi, $ubahan);
            $c->forceFill([
                'isi' => $isi, 'label' => array_key_exists('label', $ubahan) ? $ubahan['label'] : $c->label,
                'jumlah_versi' => $c->jumlah_versi + ($sah ? 1 : 0), 'diubah_oleh' => $penulis->id,
            ])->save();
            if ($sah) {
                $this->tandatangani($penulis, 'catatan_klinis', $c->id, $kunci ?? throw new ApiError(422, 'pin_wajib', 'Masukkan PIN untuk menandatangani.'));
            }
            $this->audit->catat($sah ? 'klinis.cppt_diperbaiki' : 'klinis.cppt_diubah', $penulis->id, $c->getTable(), $c->id, []);

            return $c->refresh();
        }));
    }

    public function sahkan(Request $request, Pengguna $penulis, CatatanKlinis $c, string $versi, ?string $pin): CatatanKlinis
    {
        $this->pastikanPenulis($penulis, $c);
        $this->pastikanDraf($c);
        $kunci = [...$this->ttd->buka($request, $penulis, $pin), $pin === null];

        return $this->denganKunci($kunci, fn () => DB::transaction(function () use ($penulis, $c, $versi, $kunci) {
            $c = $this->kunciMilik($penulis, $c->id, $versi);
            $this->pastikanDraf($c);
            $sekarang = now();
            $c->forceFill(['status' => 'sah', 'selesai_pada' => $sekarang, 'batas_perbaikan_pada' => $sekarang->copy()->addHours(self::JAM_PERBAIKAN),
                'diubah_oleh' => $penulis->id])->save();
            $this->tandatangani($penulis, 'catatan_klinis', $c->id, $kunci);
            $this->audit->catat('klinis.cppt_disahkan', $penulis->id, $c->getTable(), $c->id, ['kunjungan_id' => $c->kunjungan_id]);

            return $c->refresh();
        }));
    }

    public function adendum(Request $request, Pengguna $penulis, CatatanKlinis $c, string $versi, string $isi, string $alasan, ?string $pin): CatatanKlinis
    {
        $this->pastikanPenulis($penulis, $c);
        $this->pastikanBisaAdendum($c);
        $kunci = [...$this->ttd->buka($request, $penulis, $pin), $pin === null];

        return $this->denganKunci($kunci, fn () => DB::transaction(function () use ($penulis, $c, $versi, $isi, $alasan, $kunci) {
            $c = $this->kunciMilik($penulis, $c->id, $versi);
            $this->pastikanBisaAdendum($c);
            $id = (string) Str::uuid7();
            DB::table('klinis.catatan_klinis_adendum')->insert([
                'id' => $id, 'catatan_klinis_id' => $c->id, 'penulis_id' => $penulis->id, 'alasan' => $alasan, 'isi' => $isi, 'disahkan_pada' => now(),
            ]);
            $tandaTangan = $this->tandatangani($penulis, 'catatan_klinis_adendum', $id, $kunci);
            DB::table('klinis.catatan_klinis_adendum')->where('id', $id)->update(['tanda_tangan_id' => $tandaTangan]);
            // Revisi naik: daftar adendum bagian dari entri (ETag berubah).
            $c->forceFill(['diubah_oleh' => $penulis->id])->save();
            $this->audit->catat('klinis.cppt_adendum', $penulis->id, $c->getTable(), $c->id, ['adendum_id' => $id]);

            return $c->refresh();
        }));
    }

    /** Batas perbaikan masih berjalan (dipakai juga `dapat` di respons). */
    public static function masihBisaDiperbaiki(CatatanKlinis $c): bool
    {
        return $c->status === 'draf' || ($c->batas_perbaikan_pada !== null && now()->lessThanOrEqualTo($c->batas_perbaikan_pada));
    }

    /**
     * @param  array<string, mixed>  $lama
     * @param  array<string, mixed>  $baru
     * @return array<string, string|null>
     */
    private static function isi(array $lama, array $baru): array
    {
        $isi = [];
        foreach (self::SOAP as $bagian) {
            $nilai = array_key_exists($bagian, $baru) ? $baru[$bagian] : ($lama[$bagian] ?? null);
            $isi[$bagian] = is_string($nilai) && trim($nilai) !== '' ? $nilai : null;
        }
        if (array_filter($isi) === []) {
            throw ValidationException::withMessages(['subjektif' => 'Isi minimal satu bagian SOAP.']);
        }

        return $isi;
    }

    /**
     * Tanda tangan T1 di dalam transaksi pemanggil dengan kunci yang dibuka sebelum transaksi; mengembalikan id tanda tangan.
     *
     * @param  array{0: Kredensial, 1: string, 2: bool}  $kunci
     */
    private function tandatangani(Pengguna $penulis, string $jenis, string $id, array $kunci): string
    {
        return $this->ttd->tandatanganiTerbuka($penulis, [['jenis' => $jenis, 'id' => $id]], $kunci[0], $kunci[1], $kunci[2])[0]['tanda_tangan']->id;
    }

    /**
     * Rahasia kunci T1 dihapus dari memori apa pun hasilnya.
     *
     * @template T
     *
     * @param  array{0: Kredensial, 1: string, 2: bool}|null  $kunci
     * @param  callable(): T  $kerja
     * @return T
     */
    private function denganKunci(?array $kunci, callable $kerja): mixed
    {
        try {
            return $kerja();
        } finally {
            if ($kunci !== null) {
                sodium_memzero($kunci[1]);
            }
        }
    }

    private function pastikanPenulis(Pengguna $penulis, CatatanKlinis $c): void
    {
        if ($c->penulis_id !== $penulis->id) {
            throw new ApiError(403, 'akses_ditolak', 'Hanya penulis yang dapat mengubah atau mengesahkan CPPT ini.');
        }
    }

    private function pastikanDraf(CatatanKlinis $c): void
    {
        if ($c->status !== 'draf') {
            throw new ApiError(409, 'sudah_disahkan', 'CPPT ini sudah disahkan.');
        }
    }

    private function pastikanMasihBisaDiperbaiki(CatatanKlinis $c): void
    {
        if (! self::masihBisaDiperbaiki($c)) {
            throw new ApiError(409, 'batas_perbaikan_lewat', 'Batas perbaikan 2 × 24 jam sudah lewat. Koreksi klinis lewat adendum.');
        }
    }

    private function pastikanBisaAdendum(CatatanKlinis $c): void
    {
        if ($c->status === 'draf') {
            throw new ApiError(409, 'belum_disahkan', 'CPPT ini belum disahkan.');
        }
        if (self::masihBisaDiperbaiki($c)) {
            throw new ApiError(409, 'masih_bisa_diperbaiki', 'CPPT ini masih bisa diperbaiki langsung dalam 2 × 24 jam.');
        }
    }

    private function kunciMilik(Pengguna $penulis, string $id, string $versi): CatatanKlinis
    {
        $c = CatatanKlinis::query()->whereKey($id)->lockForUpdate()->firstOrFail();
        $this->pastikanPenulis($penulis, $c);
        if (IfMatch::revisi($versi) !== $c->revisi) {
            throw new ApiError(412, 'versi_berubah', 'CPPT ini diubah sejak Anda membukanya. Muat ulang untuk melihat perubahan terbaru.');
        }
        $c->revisi++;

        return $c;
    }
}
