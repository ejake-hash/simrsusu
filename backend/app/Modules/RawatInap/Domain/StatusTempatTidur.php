<?php

namespace App\Modules\RawatInap\Domain;

/**
 * Status tempat tidur di peta TT (FR-08-01) dan sumber kejadian perubahannya (PRD 11.2 `riwayat_status_tempat_tidur`).
 * Nilai sama dengan CHECK di inti.tempat_tidur.status_saat_ini dan kunjungan.riwayat_status_tempat_tidur.
 */
final class StatusTempatTidur
{
    public const TERISI = 'terisi';

    public const KOSONG_SIAP = 'kosong_siap';

    public const DIBERSIHKAN = 'dibersihkan';

    public const DIPESAN = 'dipesan';

    public const TIDAK_DIPAKAI = 'tidak_dipakai';

    public const RENCANA_PULANG = 'rencana_pulang';

    public const SEMUA = [self::TERISI, self::KOSONG_SIAP, self::DIBERSIHKAN, self::DIPESAN, self::TIDAK_DIPAKAI, self::RENCANA_PULANG];

    /** Petugas ruangan (tiket 2.27). */
    public const SUMBER_PETUGAS = 'petugas';

    /** Alokasi permintaan kamar (2.28): kosong siap → dipesan. */
    public const SUMBER_ALOKASI = 'alokasi';

    /** Permintaan kamar yang sudah dialokasikan dibatalkan (2.28): dipesan → kosong siap. */
    public const SUMBER_ALOKASI_BATAL = 'alokasi_batal';

    /** `alokasi`, `alokasi_batal` (2.28), `penempatan`, `pindah_ruang`, `pulang` (2.29). */
    public const SUMBER = [self::SUMBER_PETUGAS, self::SUMBER_ALOKASI, self::SUMBER_ALOKASI_BATAL, 'penempatan', 'pindah_ruang', 'pulang'];

    /**
     * Transisi yang boleh diminta petugas lewat POST /tempat-tidur/{id}/status: status tujuan => status asal yang sah.
     * Tiket 2.27 hanya "selesai dibersihkan" (FR-08-04); status lain berubah dari alur kerja unit (2.28/2.29).
     */
    public const TRANSISI_PETUGAS = [self::KOSONG_SIAP => [self::DIBERSIHKAN]];
}
