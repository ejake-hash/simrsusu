<?php

namespace App\Core\Antrean;

/**
 * Antrean dipisah per jenis dengan pekerja Horizon masing-masing (PRD 14.1.1 butir 4), agar
 * sistem luar yang macet tidak menghabiskan pekerja jenis lain. Pekerjaan masuk lewat:
 * `dispatch($job)->onConnection($jenis->koneksi())->onQueue($jenis->value)`.
 */
enum JenisAntrean: string
{
    /** BPJS, SATUSEHAT, PSrE. */
    case Integrasi = 'integrasi';
    case Notifikasi = 'notifikasi';
    /** Pembuatan PDF. */
    case Dokumen = 'dokumen';
    /** Ekspor dan laporan besar (PRD 14.1.1 butir 3). */
    case Laporan = 'laporan';
    case Bawaan = 'bawaan';

    /** Laporan punya koneksi sendiri karena retry_after harus lebih lama dari batas waktunya. */
    public function koneksi(): string
    {
        return $this === self::Laporan ? 'redis-laporan' : 'redis';
    }

    /** Batas waktu satu pekerjaan di pekerja (detik); harus di bawah retry_after koneksinya. */
    public function batasWaktu(): int
    {
        return match ($this) {
            self::Integrasi => 60,
            self::Notifikasi => 30,
            self::Dokumen => 120,
            self::Laporan => 900,
            self::Bawaan => 60,
        };
    }
}
