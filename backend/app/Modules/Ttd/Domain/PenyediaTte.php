<?php

namespace App\Modules\Ttd\Domain;

/**
 * Adapter penyedia TTE tersertifikasi (T2, PSrE; PRD 7.57.3, FR-57-04). Alur asinkron: permintaan dikirim, penanda
 * tangan menyetujui di aplikasi/OTP penyedia, hasil datang lewat webhook. Fase 1 hanya SimulatorTte (K10); API Privy
 * dan penyedia lain belum diverifikasi, jadi tidak ada implementasi nyata.
 */
interface PenyediaTte
{
    /** Kode penyedia (ttd.permintaan.penyedia). */
    public function kode(): string;

    /** Kirim permintaan tanda tangan; kembalikan id transaksi penyedia. */
    public function kirim(string $permintaanId, string $penandaTanganId, string $hashIsi): string;

    /** Status di penyedia: menunggu | ditandatangani | ditolak | kedaluwarsa | gagal. */
    public function status(string $idTransaksi): string;
}
