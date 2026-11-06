<?php

namespace App\Modules\Pendaftaran\Application;

use App\Core\Audit\CatatAktivitas;
use App\Core\Http\ApiError;
use App\Modules\Integrasi\Application\KelolaKredensial;
use App\Modules\Integrasi\Application\KotakKeluar;
use App\Modules\Integrasi\Application\LayananVclaim;
use App\Modules\Integrasi\Application\PengirimKiriman;
use App\Modules\Integrasi\Application\SirkuitIntegrasi;
use App\Modules\Integrasi\Domain\SistemIntegrasi;
use App\Modules\MasterData\Models\Poliklinik;
use App\Modules\Pendaftaran\Models\Kunjungan;
use App\Modules\Pendaftaran\Models\Sep;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Penerbitan SEP (tiket 2.7; FR-01-08, FR-01-10, kontrak v0.21.0 POST /kunjungan/{id}/sep). Pemeriksaan berurutan sesuai
 * kontrak; pengiriman lewat outbox 1.9 (baris SEP + kiriman satu transaksi), lalu dikirim saat itu juga oleh
 * PengirimKiriman (circuit breaker, retry). Yang belum terbit menjadi tugas SEP_TERTUNDA (UC-01 6a).
 */
final class KelolaSep
{
    public const JENIS_KIRIMAN = 'sep_insert';

    /** Asal rujukan kontrak => asalRujukan VClaim (catatan §4.1). */
    public const ASAL = ['fktp' => '1', 'rs' => '2'];

    public function __construct(
        private readonly LayananVclaim $vclaim,
        private readonly KotakKeluar $kotakKeluar,
        private readonly PengirimKiriman $pengirim,
        private readonly KelolaKredensial $kredensial,
        private readonly SirkuitIntegrasi $sirkuit,
        private readonly PenanganSep $penangan,
        private readonly CatatAktivitas $audit,
    ) {}

    /**
     * @param  array{no_rujukan?: string|null, asal_rujukan?: string|null, diagnosis_awal?: string|null, catatan?: string|null, tunda?: bool}  $isi
     * @return array{0: Sep, 1: int} SEP dan status HTTP (201 terbit, 202 menunggu)
     */
    public function ajukan(string $olehId, string $kunjunganId, array $isi, ?string $kunci): array
    {
        $kunjungan = Kunjungan::query()->with('pasien.identitas')->findOrFail($kunjunganId);
        if ($kunci !== null && ($ada = Sep::query()->where('kunjungan_id', $kunjungan->id)->where('kunci_idempotensi', $kunci)->first()) !== null) {
            return self::hasil($ada);
        }
        // 1. Kunjungan dan pemetaan kode BPJS.
        if ($kunjungan->status === 'batal') {
            throw new ApiError(409, 'sudah_batal', 'Kunjungan ini sudah dibatalkan.');
        }
        if ($kunjungan->cara_bayar !== 'jkn') {
            throw new ApiError(422, 'bukan_jkn', 'SEP hanya untuk kunjungan dengan cara bayar JKN.');
        }
        $noKartu = $kunjungan->pasien->nilaiIdentitas('no_kartu_bpjs');
        if ($noKartu === null) {
            throw new ApiError(422, 'no_kartu_wajib', 'Pasien belum punya No. kartu BPJS. Lengkapi data pasien dulu.');
        }
        $kosong = array_keys(array_filter(['kode_poli_bpjs' => PenanganSep::kodePoli($kunjungan->poli_id), 'kode_ppk_bpjs' => PenanganSep::kodePpk()],
            fn (?string $v) => $v === null));
        if ($kosong !== []) {
            throw new ApiError(422, 'pemetaan_bpjs_belum_lengkap', 'Kode BPJS di data induk belum lengkap.', array_map(fn (string $k) => ['kode' => $k], $kosong));
        }
        // 2. SEP yang ada.
        $aktif = Sep::query()->where('kunjungan_id', $kunjungan->id)->whereIn('status', ['menunggu', 'terbit'])->first();
        if ($aktif?->status === 'terbit') {
            throw new ApiError(409, 'sep_sudah_terbit', 'Kunjungan ini sudah punya SEP.', [['sep_id' => $aktif->id]]);
        }
        if ($aktif !== null) {
            throw self::sedangDiproses();
        }
        // 3. Rujukan (aturan 7.01). SEP yang ditunda tidak bisa membaca rujukan: diperiksa pekerja sebelum mengirim.
        $igd = $kunjungan->jenis_kunjungan === 'igd';
        $noRujukan = $isi['no_rujukan'] ?? null;
        if ($noRujukan === null && ! $igd) {
            throw new ApiError(422, 'rujukan_wajib', 'Nomor rujukan wajib diisi untuk kunjungan selain IGD.', [['kolom' => 'no_rujukan', 'pesan' => 'Isi nomor rujukan.']]);
        }
        $tunda = (bool) ($isi['tunda'] ?? false);
        $rujukan = null;
        $muatan = [];
        if (! $tunda) {
            $this->pastikanSiap();
            if ($noRujukan !== null) {
                $hasil = $this->vclaim->rujukan($noRujukan, self::ASAL[$isi['asal_rujukan'] ?? ''] ?? null);
                if (! $hasil['berhasil']) {
                    throw $this->galatBaca($hasil);
                }
                $rujukan = self::bentukRujukan((array) $hasil['data'], (string) $hasil['asal'], $kunjungan->tanggal->toDateString());
                if (($galat = self::periksaRujukan($rujukan, $noKartu, $igd)) !== null) {
                    throw new ApiError(422, ...$galat);
                }
                $muatan = array_filter(['kls_rawat_hak' => $hasil['data']['peserta']['hakKelas']['kode'] ?? null]);
            }
        }
        // 4. Diagnosis awal: bawaan diagnosis pada rujukan.
        $diagnosis = $rujukan['diagnosis'] ?? null;
        if (($isi['diagnosis_awal'] ?? null) !== null) {
            $nama = DB::table('inti.terminologi')->where('sistem', 'icd10')->where('kode', $isi['diagnosis_awal'])->where('aktif', true)->value('tampilan');
            if ($nama === null) {
                throw new ApiError(422, 'referensi_tidak_valid', 'Kode diagnosis awal tidak ada di paket referensi ICD-10 aktif.',
                    [['kolom' => 'diagnosis_awal', 'pesan' => 'Pilih kode ICD-10 dari daftar.']]);
            }
            $diagnosis = ['kode' => $isi['diagnosis_awal'], 'nama' => $nama];
        }

        // 5. Simpan baris SEP + kiriman outbox, lalu kirim sekarang (kecuali ditunda).
        try {
            $sep = DB::transaction(function () use ($olehId, $kunjungan, $isi, $noRujukan, $rujukan, $diagnosis, $muatan, $kunci, $tunda) {
                $sep = Sep::query()->create([
                    'kunjungan_id' => $kunjungan->id, 'episode_id' => $kunjungan->episode_id, 'pasien_id' => $kunjungan->pasien_id,
                    'tanggal_sep' => $kunjungan->tanggal->toDateString(), 'status' => 'menunggu',
                    ...($rujukan === null ? ['no_rujukan' => $noRujukan, 'asal_rujukan' => $isi['asal_rujukan'] ?? null] : self::kolomRujukan($rujukan)),
                    'diagnosis_kode' => $diagnosis['kode'] ?? null, 'diagnosis_nama' => $diagnosis['nama'] ?? null, 'catatan' => $isi['catatan'] ?? null,
                    'lingkungan' => $this->kredensial->lingkunganAktif(SistemIntegrasi::BpjsVclaim), 'kunci_idempotensi' => $kunci, 'dibuat_oleh' => $olehId,
                ]);
                $sep->forceFill(['kiriman_id' => $this->kotakKeluar->antrekan(SistemIntegrasi::BpjsVclaim, self::JENIS_KIRIMAN, 'sep', $sep->id, $muatan)])->save();
                if ($tunda) {
                    $this->tunda($sep, $kunjungan);
                }

                return $sep;
            });
        } catch (UniqueConstraintViolationException) {
            throw self::sedangDiproses();
        }
        if (! $tunda) {
            $this->pengirim->proses((string) $sep->kiriman_id);
            $sep->refresh();
            if ($sep->status === 'menunggu') {
                DB::transaction(fn () => $this->tunda($sep, $kunjungan));
            }
        }

        return self::hasil($sep->refresh());
    }

    /**
     * Masa berlaku rujukan terhadap tanggal pelayanan (FR-01-08): `berlaku_sampai` = tanggal rujukan + masa berlaku di
     * konfigurasi; hampir habis bila sisa ≤ 7 hari.
     *
     * @return array{berlaku_sampai: string, sisa_hari: int, status_masa_berlaku: string}
     */
    public static function masaBerlaku(string $tanggalRujukan, string $tanggal): array
    {
        $sampai = Carbon::parse($tanggalRujukan)->addDays((int) config('integrasi.vclaim_masa_berlaku_rujukan_hari'));
        $sisa = (int) Carbon::parse($tanggal)->diffInDays($sampai, false);

        return ['berlaku_sampai' => $sampai->toDateString(), 'sisa_hari' => $sisa, 'status_masa_berlaku' => self::statusMasa($sisa)];
    }

    public static function statusMasa(int $sisaHari): string
    {
        return $sisaHari < 0 ? 'kedaluwarsa' : ($sisaHari <= 7 ? 'hampir_habis' : 'berlaku');
    }

    /**
     * Bentuk RujukanBpjs (tanpa lingkungan/dibaca_pada) dari rujukan VClaim (catatan §4.1).
     *
     * @param  array<string, mixed>  $r
     * @return array<string, mixed>
     */
    public static function bentukRujukan(array $r, string $asalVclaim, string $tanggal): array
    {
        $kodePoli = (string) ($r['poliRujukan']['kode'] ?? '');
        $poli = $kodePoli === '' ? null : Poliklinik::query()->where('kode_poli_bpjs', $kodePoli)->where('status', 'aktif')->orderBy('kode')->first(['id', 'kode', 'nama']);

        return [
            'no_rujukan' => (string) ($r['noKunjungan'] ?? ''),
            'asal' => $asalVclaim === '2' ? 'rs' : 'fktp',
            'tanggal_rujukan' => (string) ($r['tglKunjungan'] ?? ''),
            ...self::masaBerlaku((string) ($r['tglKunjungan'] ?? ''), $tanggal),
            'faskes_asal' => self::kodeNama($r['provPerujuk'] ?? null),
            'poli_tujuan' => ['kode_poli_bpjs' => $kodePoli, 'nama' => (string) ($r['poliRujukan']['nama'] ?? ''),
                'poliklinik' => $poli === null ? null : ['id' => $poli->id, 'kode' => $poli->kode, 'nama' => $poli->nama]],
            'diagnosis' => self::kodeNama($r['diagnosa'] ?? null),
            'peserta' => ['no_kartu' => (string) ($r['peserta']['noKartu'] ?? ''), 'nama' => (string) ($r['peserta']['nama'] ?? '')],
        ];
    }

    /**
     * Langkah 3 kontrak, dipakai juga pekerja untuk SEP yang ditunda. null = lolos.
     *
     * @param  array<string, mixed>  $rujukan  hasil bentukRujukan()
     * @return array{0: string, 1: string, 2: list<array<string, mixed>>}|null kode, pesan, rincian
     */
    public static function periksaRujukan(array $rujukan, string $noKartu, bool $igd): ?array
    {
        if ($rujukan['peserta']['no_kartu'] !== $noKartu) {
            return ['rujukan_tidak_sesuai', 'Rujukan ini milik peserta lain.', []];
        }
        if ($rujukan['status_masa_berlaku'] === 'kedaluwarsa' && ! $igd) {
            return ['rujukan_kedaluwarsa', 'Rujukan sudah kedaluwarsa; SEP tidak bisa terbit kecuali kunjungan IGD. Minta rujukan baru.',
                [['berlaku_sampai' => $rujukan['berlaku_sampai']]]];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $rujukan  hasil bentukRujukan()
     * @return array<string, mixed> kolom integrasi.sep
     */
    public static function kolomRujukan(array $rujukan): array
    {
        return ['no_rujukan' => $rujukan['no_rujukan'], 'asal_rujukan' => $rujukan['asal'], 'tanggal_rujukan' => $rujukan['tanggal_rujukan'],
            'berlaku_sampai' => $rujukan['berlaku_sampai'], 'ppk_rujukan' => $rujukan['faskes_asal']['kode'] ?? null,
            'peringatan' => $rujukan['status_masa_berlaku'] === 'hampir_habis' ? [['kode' => 'rujukan_hampir_habis',
                'pesan' => 'Rujukan berlaku sampai '.$rujukan['berlaku_sampai'].' (sisa '.$rujukan['sisa_hari'].' hari).']] : []];
    }

    /**
     * Hasil baca VClaim yang gagal: sistem tidak bisa dipakai → 503, BPJS menolak → 422 dengan kode dan pesan BPJS.
     *
     * @param  array{pesan: string|null, kode: string|null, penyebab: string|null}  $hasil
     */
    public function galatBaca(array $hasil): ApiError
    {
        if ($hasil['penyebab'] !== null) {
            return $this->tidakTersedia($hasil['penyebab']);
        }

        return new ApiError(422, 'bpjs_menolak', 'BPJS menolak permintaan ini. Periksa kode dan pesan BPJS.',
            [['kode_bpjs' => (string) $hasil['kode'], 'pesan_bpjs' => (string) $hasil['pesan']]]);
    }

    /** Integrasi tidak siap atau circuit breaker terbuka → 503 sebelum apa pun disimpan (petugas bisa memilih tunda). */
    private function pastikanSiap(): void
    {
        $sistem = SistemIntegrasi::BpjsVclaim;
        $lingkungan = $this->kredensial->lingkunganAktif($sistem);
        $penyebab = match (true) {
            $lingkungan === null => 'integrasi_nonaktif',
            $this->kredensial->kurang($sistem, $lingkungan) !== [] => 'kredensial_belum_lengkap',
            DB::table('integrasi.status_sistem')->where('tujuan', $sistem->value)->where('cb_status', 'terbuka')->where('coba_lagi_pada', '>', now())->exists() => 'circuit_breaker_terbuka',
            default => null,
        };
        if ($penyebab !== null) {
            throw $this->tidakTersedia($penyebab);
        }
    }

    private function tidakTersedia(string $penyebab): ApiError
    {
        $coba = $penyebab === 'circuit_breaker_terbuka' ? $this->sirkuit->cobaLagiPada(SistemIntegrasi::BpjsVclaim) : null;

        return new ApiError(503, 'integrasi_tidak_tersedia', 'BPJS VClaim sedang tidak bisa dipakai. Simpan kunjungan dan terbitkan SEP nanti.',
            [['penyebab' => $penyebab, 'coba_lagi_pada' => $coba === null ? null : Carbon::parse($coba)->toIso8601String()]]);
    }

    private function tunda(Sep $sep, Kunjungan $kunjungan): void
    {
        $this->penangan->tugasTertunda($sep, "SEP kunjungan {$kunjungan->nomor} belum terbit");
        $this->audit->catat('sep.ditunda', $sep->dibuat_oleh, 'integrasi.sep', $sep->id, ['kunjungan' => $kunjungan->nomor, 'status' => 'menunggu']);
    }

    /** @return array{0: Sep, 1: int} */
    private static function hasil(Sep $sep): array
    {
        if ($sep->status === 'ditolak') {
            throw $sep->galat_sumber === 'bpjs'
                ? new ApiError(422, 'bpjs_menolak', 'BPJS menolak SEP ini. Periksa kode dan pesan BPJS, lalu ajukan ulang.',
                    [['kode_bpjs' => (string) $sep->galat_kode, 'pesan_bpjs' => (string) $sep->galat_pesan]])
                : new ApiError(422, (string) $sep->galat_kode, (string) $sep->galat_pesan);
        }

        return [$sep, $sep->status === 'terbit' ? 201 : 202];
    }

    private static function sedangDiproses(): ApiError
    {
        return new ApiError(409, 'sep_sedang_diproses', 'SEP kunjungan ini sedang diproses. Tunggu hasilnya di kotak kerja.');
    }

    /** @return array{kode: string, nama: string}|null */
    public static function kodeNama(mixed $x): ?array
    {
        return is_array($x) && isset($x['kode']) ? ['kode' => (string) $x['kode'], 'nama' => (string) ($x['nama'] ?? $x['keterangan'] ?? '')] : null;
    }
}
