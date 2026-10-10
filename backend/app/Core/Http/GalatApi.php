<?php

namespace App\Core\Http;

use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Galat API dengan kode yang bisa dibaca klien (PRD 10.3.1).
 * `pesan` untuk pengguna, bahasa Indonesia, dan tidak boleh memuat data pasien.
 */
final class GalatApi extends HttpException
{
    /** @param  list<array<string, mixed>>  $rincian */
    public function __construct(
        int $status,
        public readonly string $kode,
        string $pesan,
        public readonly array $rincian = [],
        array $headers = [],
    ) {
        parent::__construct($status, $pesan, null, $headers);
    }

    public function render(): JsonResponse
    {
        return self::respons($this->getStatusCode(), $this->kode, $this->getMessage(), $this->rincian, $this->getHeaders());
    }

    /** @param  list<array<string, mixed>>  $rincian */
    public static function respons(int $status, string $kode, string $pesan, array $rincian = [], array $headers = []): JsonResponse
    {
        return new JsonResponse(['galat' => compact('kode', 'pesan', 'rincian')], $status, $headers);
    }
}
