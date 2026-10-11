<?php

namespace App\Core\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Pemeriksaan kesiapan (PRD 14.1.1 butir 8): simpul hanya menerima lalu lintas bila basis data
 * dan kedua instans Valkey dapat dijangkau. Respons hanya nama komponen dan status, tanpa pesan
 * galat, alamat, atau versi.
 */
final class SiapController
{
    public function __invoke(): JsonResponse
    {
        $komponen = [
            'basis_data' => self::periksa(fn () => DB::select('SELECT 1')),
            'valkey_sesi' => self::periksa(fn () => Redis::connection('default')->ping()),
            'valkey_cache' => self::periksa(fn () => Redis::connection('cache')->ping()),
        ];

        $gagal = array_keys(array_filter($komponen, fn (string $status) => $status !== 'ok'));
        if ($gagal !== []) {
            return GalatApi::respons(503, 'layanan_tidak_tersedia', 'Layanan sedang tidak tersedia.',
                array_map(fn (string $nama) => ['komponen' => $nama, 'status' => 'gagal'], $gagal));
        }

        return new JsonResponse(['status' => 'siap', 'komponen' => $komponen]);
    }

    private static function periksa(callable $cek): string
    {
        try {
            $cek();

            return 'ok';
        } catch (Throwable $e) {
            report($e);

            return 'gagal';
        }
    }
}
