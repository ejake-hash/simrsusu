<?php

namespace App\Modules\Ttd\Application;

use App\Core\Audit\CatatAktivitas;
use App\Core\Http\ApiError;
use App\Modules\Akses\Application\AlurPersetujuan;
use App\Modules\Akses\Application\StatusTtdSaya;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\Akses\Models\PersetujuanAkses;
use App\Modules\Ttd\Domain\AturanPin;
use App\Modules\Ttd\Domain\KunciTtd;
use App\Modules\Ttd\Infrastructure\KunciServer;
use App\Modules\Ttd\Models\Kredensial;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Siklus hidup TTE T1 (tiket 1.6, kontrak v0.8.0): aktivasi oleh pemilik (dengan sandi akun), ganti PIN, reset lewat
 * persetujuan akses (SDM mengajukan, atasan langsung menyetujui; pencabutannya di PencabutKredensial). PIN tidak pernah
 * masuk audit, log, atau respons.
 */
final class KelolaTtd implements StatusTtdSaya
{
    /** Batas sandi salah saat aktivasi, sama dengan ganti sandi (PRD 14.2). */
    private const BATAS_SANDI_SALAH = 5;

    private const JENDELA_DETIK = 900;

    public function __construct(
        private readonly CatatAktivitas $audit,
        private readonly PenjagaPin $penjaga,
        private readonly SesiTtd $sesi,
        private readonly KunciServer $kunciServer,
        private readonly AtasanLangsung $atasan,
        private readonly AlurPersetujuan $alur,
    ) {}

    /** @return array{aktif: bool, diaktifkan_pada: string|null, dicabut_pada: string|null, terkunci_sampai: string|null, sesi_berlaku_sampai: string|null, reset_menunggu: bool} */
    public function untuk(Request $request, Pengguna $pengguna): array
    {
        $aktif = Kredensial::query()->where('pengguna_id', $pengguna->id)->where('tingkat', 'internal')->where('status', 'aktif')->first();
        $dicabut = $aktif !== null ? null : Kredensial::query()->where('pengguna_id', $pengguna->id)->where('tingkat', 'internal')
            ->where('status', 'dicabut')->orderByDesc('dicabut_pada')->value('dicabut_pada');

        return [
            'aktif' => $aktif !== null,
            'diaktifkan_pada' => $aktif?->diaktifkan_pada?->toIso8601String(),
            'dicabut_pada' => $dicabut === null ? null : Carbon::parse($dicabut)->toIso8601String(),
            'terkunci_sampai' => $aktif?->terkunci() ? $aktif->terkunci_sampai?->toIso8601String() : null,
            'sesi_berlaku_sampai' => $this->sesi->berlakuSampai($request, $aktif)?->toIso8601String(),
            'reset_menunggu' => PersetujuanAkses::query()->menunggu()->where('objek_jenis', 'reset_ttd')->where('sasaran_pengguna_id', $pengguna->id)->exists(),
        ];
    }

    public function aktivasi(Request $request, Pengguna $pengguna, string $sandi, string $pin, string $pinUlang): void
    {
        $kunci = 'ttd-aktivasi:'.$pengguna->id;
        if (RateLimiter::tooManyAttempts($kunci, self::BATAS_SANDI_SALAH)) {
            throw new ApiError(429, 'terlalu_banyak_permintaan', 'Terlalu banyak percobaan. Coba lagi sebentar lagi.', [],
                ['Retry-After' => (string) RateLimiter::availableIn($kunci)]);
        }
        if (! Hash::check($sandi, $pengguna->sandi_hash)) {
            RateLimiter::hit($kunci, self::JENDELA_DETIK);
            throw new ApiError(422, 'sandi_salah', 'Sandi akun tidak cocok.', [['kolom' => 'sandi', 'kode' => 'sandi_salah', 'pesan' => 'Sandi akun tidak cocok.']]);
        }
        RateLimiter::clear($kunci);
        $this->pastikanPinBaru($pin, $pinUlang, 'pin');
        $server = $this->kunciServer->aktif();

        try {
            DB::transaction(function () use ($pengguna, $pin, $server) {
                Pengguna::query()->whereKey($pengguna->id)->lockForUpdate()->first();
                if (Kredensial::query()->where('pengguna_id', $pengguna->id)->where('tingkat', 'internal')->where('status', 'aktif')->exists()) {
                    throw new ApiError(409, 'ttd_sudah_aktif', 'Tanda tangan elektronik sudah aktif.');
                }
                $kunci = KunciTtd::buat($pin, $server['kunci'], $pengguna->id);
                $k = new Kredensial([
                    'pengguna_id' => $pengguna->id, 'kunci_publik' => $kunci['publik'], 'kunci_privat_terbungkus' => $kunci['terbungkus'],
                    'garam' => $kunci['garam'], 'parameter_kdf' => [...$kunci['parameter'], 'kunci' => $server['id']],
                    'pin_hash' => Hash::make(KunciTtd::berlada($pin, $server['kunci'])), 'diaktifkan_pada' => now(),
                    'dibuat_oleh' => $pengguna->id, 'diubah_oleh' => $pengguna->id,
                ]);
                $k->save();
                $this->audit->catat('ttd.diaktifkan', $pengguna->id, 'ttd.kredensial', $k->id, ['sidik_kunci' => KunciTtd::sidik($kunci['publik'])]);
            });
        } catch (UniqueConstraintViolationException) {
            throw new ApiError(409, 'ttd_sudah_aktif', 'Tanda tangan elektronik sudah aktif.');
        }
    }

    public function gantiPin(Request $request, Pengguna $pengguna, string $pinLama, string $pinBaru, string $pinUlang): void
    {
        $this->penjaga->kredensialAktif($pengguna);
        $this->pastikanPinBaru($pinBaru, $pinUlang, 'pin_baru');
        [$k, $rahasia] = $this->penjaga->buka($pengguna, $pinLama, 'pin_lama');
        $server = $this->kunciServer->aktif();
        try {
            DB::transaction(function () use ($k, $rahasia, $pinBaru, $pengguna, $server) {
                $k = Kredensial::query()->whereKey($k->id)->where('status', 'aktif')->lockForUpdate()->first()
                    ?? throw new ApiError(409, 'ttd_belum_aktif', 'Tanda tangan elektronik belum diaktifkan.');
                $bungkus = KunciTtd::bungkus($rahasia, $pinBaru, $server['kunci'], $pengguna->id, (string) $k->kunci_publik);
                $k->forceFill([
                    'kunci_privat_terbungkus' => $bungkus['terbungkus'], 'garam' => $bungkus['garam'],
                    'parameter_kdf' => [...$bungkus['parameter'], 'kunci' => $server['id']],
                    'pin_hash' => Hash::make(KunciTtd::berlada($pinBaru, $server['kunci'])), 'diubah_oleh' => $pengguna->id,
                    'revisi' => $k->revisi + 1,
                ])->save();
                $this->audit->catat('ttd.pin_diganti', $pengguna->id, 'ttd.kredensial', $k->id, []);
            });
        } finally {
            sodium_memzero($rahasia);
        }
        $this->sesi->akhiri($request);
    }

    /** Pengajuan reset oleh petugas SDM; atasan langsung menyetujui lewat persetujuan akses. */
    public function ajukanReset(Pengguna $pengaju, Pengguna $pemilik, string $alasan, string $dasar): PersetujuanAkses
    {
        if ($pengaju->id === $pemilik->id) {
            throw new ApiError(403, 'diri_sendiri', 'Reset TTE akun sendiri diajukan oleh petugas SDM lain.');
        }

        return DB::transaction(function () use ($pengaju, $pemilik, $alasan, $dasar) {
            Pengguna::query()->whereKey($pemilik->id)->lockForUpdate()->first();
            $k = Kredensial::query()->where('pengguna_id', $pemilik->id)->where('tingkat', 'internal')->where('status', 'aktif')->first()
                ?? throw new ApiError(409, 'ttd_belum_aktif', 'Akun ini belum mengaktifkan tanda tangan elektronik.');
            if (PersetujuanAkses::query()->menunggu()->where('objek_jenis', 'reset_ttd')->where('sasaran_pengguna_id', $pemilik->id)->exists()) {
                throw new ApiError(409, 'persetujuan_ganda', 'Reset TTE untuk akun ini masih menunggu persetujuan.');
            }
            $atasan = $this->atasan->untuk($pemilik, [$pengaju->id])
                ?? throw new ApiError(422, 'atasan_belum_ditetapkan', 'Atasan langsung akun ini belum ditetapkan atau belum punya akun aktif. Lengkapi kepala unit di data organisasi.');

            return $this->alur->ajukan($pengaju, 'reset_ttd', 'reset_ttd', ['pengguna_id' => $pemilik->id, 'kredensial_id' => $k->id], [],
                ['sasaran_pengguna_id' => $pemilik->id], $dasar, $alasan, $atasan->id)[0];
        });
    }

    private function pastikanPinBaru(string $pin, string $ulang, string $kolom): void
    {
        if (AturanPin::lemah($pin)) {
            throw new ApiError(422, 'pin_lemah', 'PIN harus 6 angka, tidak berurutan, dan tidak berulang.',
                [['kolom' => $kolom, 'kode' => 'pin_lemah', 'pesan' => 'PIN harus 6 angka, tidak berurutan (mis. 123456), dan tidak berulang (mis. 111111).']]);
        }
        if (! hash_equals($pin, $ulang)) {
            throw new ApiError(422, 'pin_tidak_sama', 'Ulangan PIN tidak sama.', [['kolom' => 'pin_ulang', 'kode' => 'pin_tidak_sama', 'pesan' => 'Ulangan PIN tidak sama.']]);
        }
    }
}
