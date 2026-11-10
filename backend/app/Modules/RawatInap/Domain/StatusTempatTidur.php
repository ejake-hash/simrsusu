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

    /** FR-08-01 (tinjauan #198 butir 2): disetel rencana pulang (2.40); sampai itu hanya dikenal CHECK dan peta. */
    public const MELEWATI_RENCANA_LAMA_RAWAT = 'melewati_rencana_lama_rawat';

    public const SEMUA = [self::TERISI, self::KOSONG_SIAP, self::DIBERSIHKAN, self::DIPESAN, self::TIDAK_DIPAKAI, self::RENCANA_PULANG,
        self::MELEWATI_RENCANA_LAMA_RAWAT];

    /** Status TT yang ditempati pasien (penempatan aktif). */
    public const DITEMPATI = [self::TERISI, self::RENCANA_PULANG, self::MELEWATI_RENCANA_LAMA_RAWAT];

    /** Petugas ruangan (tiket 2.27). */
    public const SUMBER_PETUGAS = 'petugas';

    /** Alokasi permintaan kamar (2.28): kosong siap → dipesan. */
    public const SUMBER_ALOKASI = 'alokasi';

    /** Permintaan kamar yang sudah dialokasikan dibatalkan (2.28): dipesan → kosong siap. */
    public const SUMBER_ALOKASI_BATAL = 'alokasi_batal';

    /** Pasien ditempatkan (2.29): dipesan → terisi. */
    public const SUMBER_PENEMPATAN = 'penempatan';

    /** Pindah ruang (2.29): TT lama terisi → dibersihkan, TT baru kosong siap → terisi. */
    public const SUMBER_PINDAH_RUANG = 'pindah_ruang';

    /** `alokasi`, `alokasi_batal` (2.28), `penempatan`, `pindah_ruang` (2.29), `pulang` (2.40). */
    public const SUMBER = [self::SUMBER_PETUGAS, self::SUMBER_ALOKASI, self::SUMBER_ALOKASI_BATAL, self::SUMBER_PENEMPATAN, self::SUMBER_PINDAH_RUANG, 'pulang'];

    /**
     * Transisi yang boleh diminta petugas lewat POST /tempat-tidur/{id}/status: status tujuan => status asal yang sah.
     * Tiket 2.27 hanya "selesai dibersihkan" (FR-08-04); status lain berubah dari alur kerja unit (2.28/2.29).
     */
    public const TRANSISI_PETUGAS = [self::KOSONG_SIAP => [self::DIBERSIHKAN]];
}
