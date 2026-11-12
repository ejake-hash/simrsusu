<?php

namespace App\Modules\RawatInap\Http\Controllers;

use App\Core\Http\IfMatch;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\RawatInap\Application\BacaRawatInap;
use App\Modules\RawatInap\Application\KelolaRencanaPulang;
use App\Modules\RawatInap\Application\KelolaResumeMedis;
use App\Modules\RawatInap\Application\PulangkanPasien;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Pemulangan rawat inap (kontrak v0.39.0, tiket 2.40; FR-05-01..05): rencana pulang (GET/PUT), pulang lewat matriks
 * (POST), resume medis (GET/POST susun, POST sahkan). Izin dan cakupan diperiksa layanan sebelum isian dibaca, kecuali
 * `tujuan` pulang yang menentukan sel matriks. Respons memuat dua penanda identitas pasien (PRD 5.1 butir 9); `no-store`.
 */
final class PemulanganController
{
    private const TEKS_ISIAN = ['sometimes', 'nullable', 'string', 'max:4000'];

    public function rencana(Request $request, string $kunjungan, KelolaRencanaPulang $kelola): JsonResponse
    {
        /** @var Pengguna $pengguna */
        $pengguna = $request->user();
        $kelola->izinkanLihat($pengguna, $kunjungan);

        return $this->rencanaRespons($kelola->baca($kunjungan));
    }

    public function simpanRencana(Request $request, string $kunjungan, KelolaRencanaPulang $kelola): JsonResponse
    {
        /** @var Pengguna $pengguna */
        $pengguna = $request->user();
        $kelola->izinkanSimpan($pengguna, $kunjungan);
        $isi = $request->validate([
            'rencana_pulang_pada' => ['required', 'date_format:Y-m-d'],
            'rencana_lama_rawat' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:365'],
            'daftar_periksa' => ['sometimes', 'array', 'max:100'],
            'daftar_periksa.*.kode' => ['required', 'string', 'max:30', 'distinct'],
            'daftar_periksa.*.selesai' => ['required', 'boolean'],
        ]);
        $versi = trim((string) $request->header('If-Match')) === '' ? null : IfMatch::versi($request);
        $baru = $kelola->simpan($pengguna, $kunjungan, $versi, $isi);

        return $this->rencanaRespons($kelola->baca($kunjungan), $baru ? 201 : 200);
    }

    public function pulang(Request $request, string $kunjungan, PulangkanPasien $layanan, BacaRawatInap $baca): JsonResponse
    {
        /** @var Pengguna $pengguna */
        $pengguna = $request->user();
        ['tujuan' => $tujuan] = $request->validate(['tujuan' => ['required', 'string', Rule::in(PulangkanPasien::TUJUAN)]]);
        $layanan->izinkan($pengguna, $tujuan, $kunjungan);
        $isi = $request->validate([
            'tujuan' => ['required', 'string'],
            'alasan' => ['required', 'string', 'min:5', 'max:1000'],
            'tanggapan' => ['sometimes', 'array', 'max:100'],
            'tanggapan.*.kode' => ['required', 'string', 'max:30', 'distinct'],
            'tanggapan.*.alasan' => ['required', 'string', 'min:5', 'max:500'],
        ]);
        $layanan->pulangkan($pengguna, $kunjungan, $isi);

        return (new JsonResponse(['data' => $baca->satu($kunjungan)]))->header('Cache-Control', 'no-store');
    }

    public function resume(Request $request, string $kunjungan, KelolaRencanaPulang $rencana, KelolaResumeMedis $kelola): JsonResponse
    {
        /** @var Pengguna $pengguna */
        $pengguna = $request->user();
        $rencana->izinkanLihat($pengguna, $kunjungan, KelolaResumeMedis::IZIN_LIHAT);

        return $this->resumeRespons($kelola->baca($kunjungan));
    }

    public function susunResume(Request $request, string $kunjungan, KelolaResumeMedis $kelola): JsonResponse
    {
        /** @var Pengguna $pengguna */
        $pengguna = $request->user();
        $kelola->izinkanSusun($pengguna, $kunjungan);
        $isi = $request->validate(array_fill_keys(['ringkasan_perjalanan', 'kondisi_pulang', 'instruksi_pulang', 'obat_pulang', 'kontrol'], self::TEKS_ISIAN));
        [, $baru] = $kelola->susun($pengguna, $kunjungan, $isi);

        return $this->resumeRespons($kelola->baca($kunjungan), $baru ? 201 : 200);
    }

    public function sahkanResume(Request $request, string $resume, KelolaResumeMedis $kelola): JsonResponse
    {
        /** @var Pengguna $pengguna */
        $pengguna = $request->user();
        $kunjungan = $kelola->izinkanSahkan($pengguna, $resume);
        $isi = $request->validate(['pin' => ['nullable', 'string', 'max:20'], 'pernyataan_disetujui' => ['required', 'accepted']]);
        $kelola->sahkan($request, $pengguna, $resume, IfMatch::versi($request), $isi['pin'] ?? null);

        return $this->resumeRespons($kelola->baca($kunjungan));
    }

    /** @param  array<string, mixed>  $data */
    private function rencanaRespons(array $data, int $status = 200): JsonResponse
    {
        $respons = (new JsonResponse(['data' => $data], $status))->header('Cache-Control', 'no-store');

        return $data['versi'] === null ? $respons : $respons->setEtag((string) $data['versi']);
    }

    /** @param  array<string, mixed>|null  $data */
    private function resumeRespons(?array $data, int $status = 200): JsonResponse
    {
        $respons = (new JsonResponse(['data' => $data], $status))->header('Cache-Control', 'no-store');

        return $data === null ? $respons : $respons->setEtag((string) $data['versi']);
    }
}
