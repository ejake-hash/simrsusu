<?php

namespace App\Modules\RawatInap\Application;

use App\Core\Audit\CatatAktivitas;
use App\Core\Http\ApiError;
use App\Core\Http\IfMatch;
use App\Core\Http\SuntinganBerversi;
use App\Modules\Akses\Domain\Cakupan;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\MasterData\Models\DaftarPeriksaPulang;
use App\Modules\Pendaftaran\Application\KunciKunjungan;
use App\Modules\Pendaftaran\Models\Kunjungan;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Rencana pulang rawat inap (tiket 2.40, kontrak v0.39.0; FR-05-01, FR-05-03, keputusan pemilik 13 Nov 2026).
 * - Sub-menu sendiri rawat_inap.rencana_pulang (non-klinis, tanpa syarat SIP/SPK; keputusan pemilik 13 Nov 2026 "pisah
 *   izin"): dibuat pemegang `.input`, diubah pemegang `.perbaiki` (If-Match), dibaca pemegang `.lihat`, semuanya di cakupan
 *   yang memuat unit bangsal kunjungan. Izin pemulangan (pulang, resume) tidak dipakai di sini.
 * - Butir daftar periksa disalin (kode, nama, sumber, wajib) dari data induk aktif saat rencana dibuat; butir sumber
 *   `kejadian` (tagihan/jaminan) tidak bisa dicentang di sini (menunggu kasir, Tahap D).
 * - Status TT `rencana_pulang`/`melewati_rencana_lama_rawat` dihitung peta saat dibaca (BacaPetaTempatTidur).
 */
final class KelolaRencanaPulang
{
    public const IZIN_LIHAT = 'rawat_inap.rencana_pulang.lihat';

    public const IZIN_INPUT = 'rawat_inap.rencana_pulang.input';

    public const IZIN_PERBAIKI = 'rawat_inap.rencana_pulang.perbaiki';

    public function __construct(
        private readonly CatatAktivitas $audit,
        private readonly NamaPelaku $pelaku,
    ) {}

    /**
     * GET: izin di cakupan mana pun (403) → kunjungan ada (404) → cakupan unit bangsal kunjungan (403). Dipakai juga GET
     * resume dengan izin rawat_inap.pemulangan.lihat.
     */
    public function izinkanLihat(Pengguna $pengguna, string $kunjunganId, string $izin = self::IZIN_LIHAT): void
    {
        Gate::forUser($pengguna)->authorize($izin);
        $k = self::kunjungan($kunjunganId);
        Gate::forUser($pengguna)->authorize($izin, self::cakupan($k->unit_id));
    }

    /** PUT, sebelum isian dibaca: izin input atau perbaiki di cakupan mana pun (403) → kunjungan ada (404). */
    public function izinkanSimpan(Pengguna $pengguna, string $kunjunganId): void
    {
        $gate = Gate::forUser($pengguna);
        if (! $gate->allows(self::IZIN_INPUT) && ! $gate->allows(self::IZIN_PERBAIKI)) {
            // Kode penolakan lapis 5 (mis. mfa_ulang_diperlukan) diteruskan dari izin input.
            $gate->authorize(self::IZIN_INPUT);
        }
        self::kunjungan($kunjunganId);
    }

    /**
     * @param  array{rencana_pulang_pada: string, rencana_lama_rawat?: int|null, daftar_periksa?: list<array{kode: string, selesai: bool}>}  $isi
     * @return bool true = rencana baru dibuat
     */
    public function simpan(Pengguna $pengguna, string $kunjunganId, ?string $versi, array $isi): bool
    {
        return DB::transaction(function () use ($pengguna, $kunjunganId, $versi, $isi) {
            $k = KunciKunjungan::pasienLaluKunjungan($kunjunganId) ?? throw new ApiError(404, 'tidak_ditemukan', 'Kunjungan tidak ditemukan.');
            $rencana = DB::table('kunjungan.rencana_pulang')->where('kunjungan_id', $k->id)->lockForUpdate()->first();
            $cakupan = self::cakupan($k->unit_id);
            Gate::forUser($pengguna)->authorize($rencana === null ? self::IZIN_INPUT : self::IZIN_PERBAIKI, $cakupan);
            self::pastikanRawatInapAktif($k);
            if ($rencana !== null) {
                if ($versi === null) {
                    throw new ApiError(428, 'if_match_wajib', 'Versi data tidak dikirim. Muat ulang halaman lalu coba lagi.');
                }
                if (IfMatch::revisi($versi) !== (int) $rencana->revisi) {
                    throw new ApiError(412, 'versi_berubah', 'Rencana pulang ini diubah orang lain sejak Anda membukanya. Muat ulang untuk melihat perubahan terbaru.');
                }
            }
            $pertama = DB::table('kunjungan.penempatan_tempat_tidur')->where('kunjungan_id', $k->id)->min('mulai_pada');
            if ($pertama !== null) {
                $tanggalPertama = Carbon::parse((string) $pertama)->setTimezone((string) config('instalasi.zona_waktu'))->toDateString();
                if ($isi['rencana_pulang_pada'] < $tanggalPertama) {
                    throw ValidationException::withMessages(['rencana_pulang_pada' => "Tanggal rencana pulang tidak boleh sebelum tanggal masuk TT ($tanggalPertama)."]);
                }
            }

            $butir = $rencana === null ? self::salinDataInduk() : self::butirTersimpan($rencana->daftar_periksa);
            $butir = self::terapkanCentang($butir, $isi['daftar_periksa'] ?? [], $pengguna->id);
            $jam = now()->utc()->format('Y-m-d H:i:s.uP');
            $nilai = [
                'rencana_pulang_pada' => $isi['rencana_pulang_pada'],
                'rencana_lama_rawat' => array_key_exists('rencana_lama_rawat', $isi) ? $isi['rencana_lama_rawat'] : ($rencana->rencana_lama_rawat ?? null),
                'daftar_periksa' => json_encode($butir), 'diubah_oleh' => $pengguna->id, 'diubah_pada' => $jam,
            ];
            if ($rencana === null) {
                $id = (string) Str::uuid7();
                DB::table('kunjungan.rencana_pulang')->insert([...$nilai, 'id' => $id, 'kunjungan_id' => $k->id, 'status' => 'direncanakan',
                    'dibuat_oleh' => $pengguna->id, 'dibuat_pada' => $jam]);
            } else {
                $id = (string) $rencana->id;
                DB::table('kunjungan.rencana_pulang')->where('id', $id)->update([...$nilai, 'revisi' => DB::raw('revisi + 1')]);
            }
            $this->audit->catat($rencana === null ? 'rawat_inap.rencana_pulang_dibuat' : 'rawat_inap.rencana_pulang_diubah', $pengguna->id,
                'kunjungan.rencana_pulang', $id, [
                    'kunjungan_id' => $k->id, 'rencana_pulang_pada' => $nilai['rencana_pulang_pada'], 'rencana_lama_rawat' => $nilai['rencana_lama_rawat'],
                    'butir_selesai' => array_values(array_map(fn (array $b) => $b['kode'], array_filter($butir, fn (array $b) => $b['selesai_pada'] !== null))),
                ], $rencana === null ? null : [
                    'rencana_pulang_pada' => substr((string) $rencana->rencana_pulang_pada, 0, 10), 'rencana_lama_rawat' => $rencana->rencana_lama_rawat,
                ]);

            return $rencana === null;
        });
    }

    /**
     * Bentuk kontrak `RencanaPulang`: identitas pasien (dua penanda) + rencana; tanpa rencana, `id` null dan daftar periksa =
     * pratinjau butir data induk aktif.
     *
     * @return array<string, mixed>
     */
    public function baca(string $kunjunganId): array
    {
        $b = DB::table('kunjungan.kunjungan as k')->join('pasien.pasien as p', 'p.id', '=', 'k.pasien_id')->where('k.id', $kunjunganId)
            ->first(['k.id', 'k.status', 'k.jenis_kunjungan', 'p.id as pasien_id', 'p.no_rm', 'p.nama', 'p.jenis_kelamin', 'p.tanggal_lahir'])
            ?? throw new ApiError(404, 'tidak_ditemukan', 'Kunjungan tidak ditemukan.');
        // Bukan rawat inap: 409 tanpa identitas pasien (tinjauan #204).
        if ($b->jenis_kunjungan !== Kunjungan::RAWAT_INAP) {
            throw new ApiError(409, 'status_tidak_sesuai', 'Rencana pulang hanya untuk kunjungan rawat inap.',
                [['jenis_kunjungan' => (string) $b->jenis_kunjungan, 'status' => (string) $b->status]]);
        }
        $rencana = DB::table('kunjungan.rencana_pulang')->where('kunjungan_id', $kunjunganId)->first();
        $butir = $rencana === null ? self::salinDataInduk() : self::butirTersimpan($rencana->daftar_periksa);
        $pelaku = $this->pelaku->untuk(array_map(fn (array $x) => $x['selesai_oleh'], $butir));
        $pertama = DB::table('kunjungan.penempatan_tempat_tidur')->where('kunjungan_id', $kunjunganId)->min('mulai_pada');
        $aktif = DB::table('kunjungan.penempatan_tempat_tidur')->where('kunjungan_id', $kunjunganId)->whereNull('selesai_pada')->exists();

        return [
            'kunjungan_id' => (string) $b->id,
            'kunjungan_status' => (string) $b->status,
            'pasien' => self::pasien($b),
            'hari_rawat' => $pertama === null || ! $aktif ? null : BacaRawatInap::hariRawat((string) $pertama),
            'id' => $rencana === null ? null : (string) $rencana->id,
            'status' => $rencana === null ? null : (string) $rencana->status,
            'rencana_pulang_pada' => $rencana === null ? null : substr((string) $rencana->rencana_pulang_pada, 0, 10),
            'rencana_lama_rawat' => $rencana === null || $rencana->rencana_lama_rawat === null ? null : (int) $rencana->rencana_lama_rawat,
            'daftar_periksa' => array_map(fn (array $x) => [
                'kode' => $x['kode'], 'nama' => $x['nama'], 'sumber' => $x['sumber'], 'wajib' => $x['wajib'],
                'status' => $x['sumber'] === DaftarPeriksaPulang::SUMBER_KEJADIAN ? 'menunggu_kasir' : ($x['selesai_pada'] === null ? 'belum' : 'selesai'),
                'selesai_pada' => $x['selesai_pada'] === null ? null : Carbon::parse($x['selesai_pada'])->toIso8601String(),
                'selesai_oleh' => $x['selesai_oleh'] === null ? null : ($pelaku[$x['selesai_oleh']] ?? null),
                'alasan_tidak_selesai' => $x['alasan_tidak_selesai'],
            ], $butir),
            'versi' => $rencana === null ? null : (string) $rencana->revisi,
        ];
    }

    /**
     * Butir data induk aktif (urutan, kode) dalam bentuk tersimpan.
     *
     * @return list<array{kode: string, nama: string, sumber: string, wajib: bool, selesai_pada: string|null, selesai_oleh: string|null, alasan_tidak_selesai: string|null}>
     */
    public static function salinDataInduk(): array
    {
        return DaftarPeriksaPulang::query()->where('status', 'aktif')->orderBy('urutan')->orderBy('kode')->limit(100)->get()
            ->map(fn (DaftarPeriksaPulang $d) => ['kode' => $d->kode, 'nama' => $d->nama, 'sumber' => $d->sumber, 'wajib' => $d->wajib,
                'selesai_pada' => null, 'selesai_oleh' => null, 'alasan_tidak_selesai' => null])->values()->all();
    }

    /**
     * @return list<array{kode: string, nama: string, sumber: string, wajib: bool, selesai_pada: string|null, selesai_oleh: string|null, alasan_tidak_selesai: string|null}>
     */
    public static function butirTersimpan(mixed $json): array
    {
        $daftar = is_string($json) ? json_decode($json, true) : $json;

        return array_values(array_map(fn (array $x) => [
            'kode' => (string) $x['kode'], 'nama' => (string) $x['nama'], 'sumber' => (string) ($x['sumber'] ?? DaftarPeriksaPulang::SUMBER_MANUAL),
            'wajib' => (bool) ($x['wajib'] ?? true), 'selesai_pada' => $x['selesai_pada'] ?? null, 'selesai_oleh' => $x['selesai_oleh'] ?? null,
            'alasan_tidak_selesai' => $x['alasan_tidak_selesai'] ?? null,
        ], is_array($daftar) ? $daftar : []));
    }

    /** Kunjungan rawat inap yang masih berjalan; selain itu 409 status_tidak_sesuai. */
    public static function pastikanRawatInapAktif(Kunjungan $k): void
    {
        if ($k->jenis_kunjungan !== Kunjungan::RAWAT_INAP || ! in_array($k->status, Kunjungan::STATUS_AKTIF, true)) {
            throw new ApiError(409, 'status_tidak_sesuai', 'Pemulangan hanya untuk kunjungan rawat inap yang masih berjalan.',
                [['jenis_kunjungan' => $k->jenis_kunjungan, 'status' => $k->status]]);
        }
    }

    public static function kunjungan(string $kunjunganId): Kunjungan
    {
        return (Str::isUuid($kunjunganId) ? Kunjungan::query()->find($kunjunganId, ['id', 'unit_id', 'pasien_id', 'jenis_kunjungan', 'status', 'pegawai_id']) : null)
            ?? throw new ApiError(404, 'tidak_ditemukan', 'Kunjungan tidak ditemukan.');
    }

    public static function cakupan(?string $unitId): Cakupan
    {
        return $unitId === null ? Cakupan::rs() : new Cakupan('unit', $unitId);
    }

    /** @return array{id: string, no_rm: string, nama: string, jenis_kelamin: mixed, tanggal_lahir: string|null} */
    public static function pasien(stdClass $b): array
    {
        return ['id' => (string) $b->pasien_id, 'no_rm' => (string) $b->no_rm, 'nama' => (string) $b->nama, 'jenis_kelamin' => $b->jenis_kelamin,
            'tanggal_lahir' => $b->tanggal_lahir === null ? null : substr((string) $b->tanggal_lahir, 0, 10)];
    }

    /**
     * Centang butir manual: selesai true mencatat jam dan pelaku (sekali; centang ulang tidak mengubahnya), false
     * mengosongkan. Kode tak dikenal atau butir sumber kejadian → 422.
     *
     * @param  list<array{kode: string, nama: string, sumber: string, wajib: bool, selesai_pada: string|null, selesai_oleh: string|null, alasan_tidak_selesai: string|null}>  $butir
     * @param  list<array{kode: string, selesai: bool}>  $centang
     * @return list<array{kode: string, nama: string, sumber: string, wajib: bool, selesai_pada: string|null, selesai_oleh: string|null, alasan_tidak_selesai: string|null}>
     */
    private static function terapkanCentang(array $butir, array $centang, string $olehId): array
    {
        $indeks = array_flip(array_column($butir, 'kode'));
        $salah = [];
        foreach ($centang as $n => $c) {
            $i = $indeks[$c['kode']] ?? null;
            if ($i === null) {
                $salah["daftar_periksa.$n.kode"] = 'Butir tidak ada di daftar periksa rencana ini.';

                continue;
            }
            if ($butir[$i]['sumber'] === DaftarPeriksaPulang::SUMBER_KEJADIAN) {
                $salah["daftar_periksa.$n.kode"] = 'Butir ini diselesaikan kasir (Tahap D), bukan dicentang.';

                continue;
            }
            if ($c['selesai'] && $butir[$i]['selesai_pada'] === null) {
                $butir[$i]['selesai_pada'] = now()->utc()->format('Y-m-d H:i:s.uP');
                $butir[$i]['selesai_oleh'] = $olehId;
            } elseif (! $c['selesai']) {
                $butir[$i]['selesai_pada'] = null;
                $butir[$i]['selesai_oleh'] = null;
            }
        }
        if ($salah !== []) {
            throw ValidationException::withMessages($salah);
        }

        return $butir;
    }
}
