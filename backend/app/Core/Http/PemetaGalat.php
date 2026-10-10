<?php

namespace App\Core\Http;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Mengubah semua galat di bawah /api dan /sanctum menjadi format tunggal
 * {"galat": {"kode", "pesan", "rincian"}} (PRD 10.3.1). Detail teknis hanya masuk log.
 */
final class PemetaGalat
{
    public static function render(Throwable $e): JsonResponse
    {
        if ($e instanceof GalatApi) {
            return $e->render();
        }

        if ($e instanceof ValidationException) {
            $rincian = [];
            foreach ($e->errors() as $kolom => $pesan) {
                $rincian[] = ['kolom' => $kolom, 'pesan' => $pesan[0]];
            }

            return GalatApi::respons(422, 'validasi_gagal', 'Isian belum benar.', $rincian);
        }

        if ($e instanceof AuthenticationException) {
            return GalatApi::respons(401, 'belum_masuk', 'Silakan masuk terlebih dahulu.');
        }

        if ($e instanceof HttpExceptionInterface) {
            $headers = $e->getHeaders();

            return match ($e->getStatusCode()) {
                // TokenMismatchException diubah Laravel menjadi 419; PRD 10.3.1 hanya memakai status standar.
                419 => GalatApi::respons(403, 'csrf_tidak_valid', 'Sesi formulir kedaluwarsa. Muat ulang halaman lalu coba lagi.'),
                403 => GalatApi::respons(403, 'akses_ditolak', 'Anda tidak punya izin untuk tindakan ini.', [], $headers),
                404 => GalatApi::respons(404, 'tidak_ditemukan', 'Data tidak ditemukan.', [], $headers),
                405 => GalatApi::respons(405, 'metode_tidak_diizinkan', 'Metode tidak diizinkan.', [], $headers),
                429 => GalatApi::respons(429, 'terlalu_banyak_permintaan', 'Terlalu banyak permintaan. Coba lagi sebentar lagi.', [], $headers),
                503 => GalatApi::respons(503, 'layanan_tidak_tersedia', 'Layanan sedang tidak tersedia.', [], $headers),
                default => GalatApi::respons($e->getStatusCode(), 'permintaan_tidak_valid', 'Permintaan tidak dapat diproses.', [], $headers),
            };
        }

        return GalatApi::respons(500, 'kesalahan_server', 'Terjadi kesalahan pada server.');
    }
}
