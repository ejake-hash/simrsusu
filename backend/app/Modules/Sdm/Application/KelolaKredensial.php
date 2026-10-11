<?php

namespace App\Modules\Sdm\Application;

use App\Core\Audit\CatatAktivitas;
use App\Core\Http\ApiError;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\Sdm\Models\IzinPraktik;
use App\Modules\Sdm\Models\KewenanganKlinis;
use App\Modules\Sdm\Models\Pegawai;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Izin praktik (STR/SIP/SIK/SPK) dan RKK minimal (tiket 1.8d; PRD 11.2, FR-40-01). Tidak diubah setelah dicatat:
 * perbaikan = batalkan lalu catat ulang. Dokumen disimpan di disk privat, jenis dari isi berkas.
 */
final class KelolaKredensial
{
    private const MIME = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];

    public function __construct(private readonly CatatAktivitas $audit) {}

    /** @param  array{jenis: string, nomor: string, terbit: string, berlaku_sampai?: string|null}  $isi */
    public function catatIzinPraktik(Pengguna $penyunting, Pegawai $pegawai, array $isi, ?UploadedFile $berkas): IzinPraktik
    {
        $sampai = $isi['berlaku_sampai'] ?? null;
        if ($sampai === null && $isi['jenis'] !== 'str') {
            throw $this->tanggal('berlaku_sampai', 'Isi tanggal berakhir; hanya STR yang boleh seumur hidup.');
        }
        if ($sampai !== null && $sampai < $isi['terbit']) {
            throw $this->tanggal('berlaku_sampai', 'Tanggal berakhir tidak boleh sebelum tanggal terbit.');
        }
        [$mime, $isiBerkas] = $berkas === null ? [null, null] : $this->periksaBerkas($berkas);
        $jalur = $mime === null ? null : 'izin-praktik/'.$pegawai->id.'/'.Str::uuid7().'.'.self::MIME[$mime];

        try {
            return DB::transaction(function () use ($penyunting, $pegawai, $isi, $sampai, $mime, $isiBerkas, $jalur) {
                $izin = new IzinPraktik([
                    'pegawai_id' => $pegawai->id, 'jenis' => $isi['jenis'], 'nomor' => $isi['nomor'], 'terbit' => $isi['terbit'],
                    'berlaku_sampai' => $sampai, 'dokumen_jalur' => $jalur, 'dokumen_mime' => $mime,
                    'dokumen_ukuran' => $isiBerkas === null ? null : strlen($isiBerkas), 'status' => 'aktif',
                    'dibuat_oleh' => $penyunting->id, 'diubah_oleh' => $penyunting->id,
                ]);
                $izin->save();
                if ($jalur !== null && ! Storage::disk((string) config('sdm.dokumen_disk'))->put($jalur, (string) $isiBerkas)) {
                    throw new \RuntimeException('Dokumen izin praktik gagal disimpan.');
                }
                $this->audit->catat('sdm.izin_praktik_dicatat', $penyunting->id, 'sdm.izin_praktik', $izin->id, [
                    'pegawai_id' => $pegawai->id, 'jenis' => $izin->jenis, 'nomor' => $izin->nomor,
                    'berlaku_sampai' => $sampai, 'dokumen' => $jalur !== null,
                ]);

                return $izin;
            });
        } catch (QueryException $e) {
            throw $e->getCode() === '23505'
                ? new ApiError(409, 'izin_ganda', 'Izin praktik dengan jenis dan nomor ini sudah tercatat.', [['kolom' => 'nomor', 'pesan' => 'Batalkan catatan lama bila ingin mencatat ulang.']])
                : $e;
        }
    }

    /** @param  array{kategori: string, kode_tindakan: list<string>, berlaku_dari: string, berlaku_sampai: string, komite: string, dasar?: string|null}  $isi */
    public function catatKewenangan(Pengguna $penyunting, Pegawai $pegawai, array $isi): KewenanganKlinis
    {
        if ($isi['berlaku_sampai'] < $isi['berlaku_dari']) {
            throw $this->tanggal('berlaku_sampai', 'Tanggal berakhir tidak boleh sebelum tanggal mulai.');
        }

        return DB::transaction(function () use ($penyunting, $pegawai, $isi) {
            $rkk = new KewenanganKlinis([
                'pegawai_id' => $pegawai->id, 'kategori' => $isi['kategori'], 'kode_tindakan' => $isi['kode_tindakan'],
                'berlaku_dari' => $isi['berlaku_dari'], 'berlaku_sampai' => $isi['berlaku_sampai'], 'komite' => $isi['komite'],
                'dasar' => $isi['dasar'] ?? null, 'status' => 'aktif', 'dibuat_oleh' => $penyunting->id, 'diubah_oleh' => $penyunting->id,
            ]);
            $rkk->save();
            $this->audit->catat('sdm.kewenangan_klinis_dicatat', $penyunting->id, 'sdm.kewenangan_klinis', $rkk->id, [
                'pegawai_id' => $pegawai->id, 'kategori' => $rkk->kategori, 'jumlah_kode' => count($rkk->kode_tindakan),
                'berlaku_dari' => $isi['berlaku_dari'], 'berlaku_sampai' => $isi['berlaku_sampai'],
            ]);

            return $rkk;
        });
    }

    /**
     * @template T of IzinPraktik|KewenanganKlinis
     *
     * @param  T  $catatan
     * @return T
     */
    public function batalkan(Pengguna $penyunting, IzinPraktik|KewenanganKlinis $catatan, string $alasan): IzinPraktik|KewenanganKlinis
    {
        return DB::transaction(function () use ($penyunting, $catatan, $alasan) {
            $baris = $catatan::query()->whereKey($catatan->id)->lockForUpdate()->firstOrFail();
            if ($baris->status === 'batal') {
                throw new ApiError(409, 'sudah_batal', 'Catatan ini sudah dibatalkan.');
            }
            $baris->forceFill(['status' => 'batal', 'alasan_batal' => $alasan, 'dibatalkan_oleh' => $penyunting->id,
                'dibatalkan_pada' => now(), 'diubah_oleh' => $penyunting->id])->save();
            $this->audit->catat($baris instanceof IzinPraktik ? 'sdm.izin_praktik_dibatalkan' : 'sdm.kewenangan_klinis_dibatalkan',
                $penyunting->id, $baris->getTable(), $baris->id, ['pegawai_id' => $baris->pegawai_id, 'alasan' => $alasan]);

            return $baris;
        });
    }

    /** belum_berlaku | berlaku | akan_habis | habis | batal (zona RS; ambang dari konfigurasi). */
    public static function statusIzin(IzinPraktik $izin): string
    {
        $hariIni = now(config('instalasi.zona_waktu'))->startOfDay();

        return match (true) {
            $izin->status === 'batal' => 'batal',
            $izin->terbit->gt($hariIni) => 'belum_berlaku',
            $izin->berlaku_sampai === null => 'berlaku',
            $izin->berlaku_sampai->lt($hariIni) => 'habis',
            $izin->berlaku_sampai->lte($hariIni->copy()->addDays((int) config('sdm.izin_praktik_hari_peringatan'))) => 'akan_habis',
            default => 'berlaku',
        };
    }

    /** belum_berlaku | berlaku | habis | batal. */
    public static function statusKewenangan(KewenanganKlinis $rkk): string
    {
        $hariIni = now(config('instalasi.zona_waktu'))->toDateString();

        return match (true) {
            $rkk->status === 'batal' => 'batal',
            $rkk->berlaku_dari->toDateString() > $hariIni => 'belum_berlaku',
            $rkk->berlaku_sampai->toDateString() < $hariIni => 'habis',
            default => 'berlaku',
        };
    }

    /** @return array{0: string, 1: string} mime dari isi berkas, isi berkas */
    private function periksaBerkas(UploadedFile $berkas): array
    {
        if ($berkas->getSize() > (int) config('sdm.dokumen_batas_kb') * 1024) {
            throw new ApiError(422, 'berkas_terlalu_besar', 'Dokumen paling besar '.((int) config('sdm.dokumen_batas_kb') / 1024).' MB.', [['kolom' => 'berkas', 'pesan' => 'Kecilkan ukuran dokumen.']]);
        }
        $isi = (string) file_get_contents($berkas->getRealPath());
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($isi);
        if (! is_string($mime) || ! isset(self::MIME[$mime])) {
            throw new ApiError(422, 'tipe_berkas_tidak_diizinkan', 'Dokumen harus PDF, JPEG, atau PNG.', [['kolom' => 'berkas', 'pesan' => 'Pilih berkas PDF, JPEG, atau PNG.']]);
        }

        return [$mime, $isi];
    }

    private function tanggal(string $kolom, string $pesan): ApiError
    {
        return new ApiError(422, 'tanggal_tidak_valid', $pesan, [['kolom' => $kolom, 'pesan' => $pesan]]);
    }
}
