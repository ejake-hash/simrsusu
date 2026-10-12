<?php

namespace App\Modules\Integrasi\Domain;

/**
 * Penangan satu jenis kiriman (tujuan + jenis_resource). Dipasang modul pembuat kiriman lewat DaftarPenangan:
 * SATUSEHAT Organization/Location, Aplicares bed/update, dst. Penangan harus aman dikirim ulang (idempoten di sisi
 * sistem luar, mis. memperlakukan "sudah terdaftar" sebagai berhasil).
 */
interface PenanganKiriman
{
    public function tujuan(): SistemIntegrasi;

    public function jenisResource(): string;

    /**
     * Syarat data induk sebelum dikirim (FR-49-04). null = siap.
     *
     * @return array{kode: string, pesan: string, pemilik_unit_id: string|null}|null
     */
    public function periksa(Kiriman $kiriman): ?array;

    /** @param  array<string, string>  $kredensial */
    public function kirim(Kiriman $kiriman, string $lingkungan, array $kredensial): HasilKirim;
}
