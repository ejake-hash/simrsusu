<?php

namespace App\Modules\Akses\Application;

use App\Core\Audit\CatatAktivitas;
use App\Core\Http\ApiError;
use App\Modules\Akses\Database\MenuBawaan;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\Akses\Models\PenggunaIzinKhusus;
use App\Modules\Akses\Models\PenggunaPeran;
use App\Modules\Akses\Models\Peran;
use App\Modules\Akses\Models\PerangkatTepercaya;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Perangkat tepercaya (tiket 1.13; PRD 10.3.2 "keringanan lewat perangkat terdaftar", 14.2; ADR 0002).
 *
 * Masa percaya efektif akun = nilai terkecil dari kebijakan instalasi, `masa_percaya_hari` tiap peran berlaku, dan batas
 * konfigurasi (umum, superadmin, peran/izin khusus sensitif). 0 = fitur mati. Token hanya berlaku dari jaringan RS dan
 * untuk akun pemiliknya; masa berlakunya dihitung ulang saat dipakai, jadi kebijakan yang turun ikut memendekkannya.
 * MFA ulang (K11) tidak pernah dilewati: sesi yang masuk lewat perangkat tepercaya tidak dicatat sebagai sudah MFA.
 */
final class KelolaPerangkat
{
    /** Atribut permintaan: perangkat yang dipercaya atau dipakai pada langkah masuk ini. */
    public const ATRIBUT = 'akses.perangkat_tepercaya';

    /** Kunci sesi: id perangkat yang terikat ke sesi ini (`perangkat_ini`). */
    public const SESI = 'autentikasi.perangkat_tepercaya_id';

    public const ALASAN_CABUT = ['dicabut_pemilik', 'dicabut_admin', 'sandi_diganti', 'sandi_direset', 'mfa_direset', 'akun_nonaktif'];

    private const JALUR_COOKIE = '/api/v1/autentikasi';

    public function __construct(
        private readonly CatatAktivitas $audit,
        private readonly KebijakanMfa $kebijakanMfa,
    ) {}

    /** @return array{maks_hari: int, maks_hari_superadmin: int, maks_hari_peran_sensitif: int} */
    public static function batas(): array
    {
        $maks = max(0, (int) config('autentikasi.perangkat_tepercaya.maks_hari'));

        return [
            'maks_hari' => $maks,
            'maks_hari_superadmin' => min($maks, max(0, (int) config('autentikasi.perangkat_tepercaya.maks_hari_superadmin'))),
            'maks_hari_peran_sensitif' => min($maks, max(0, (int) config('autentikasi.perangkat_tepercaya.maks_hari_peran_sensitif'))),
        ];
    }

    /** Kebijakan instalasi (Pengaturan → Keamanan), tidak melebihi batas umum konfigurasi. */
    public function hariInstalasi(): int
    {
        return min(self::batas()['maks_hari'], (int) DB::table('akses.kebijakan_keamanan')->value('perangkat_tepercaya_hari'));
    }

    /** Batas atas masa percaya untuk peran ini (konfigurasi; tidak bisa dinaikkan dari layar). */
    public static function batasPeran(Peran $peran, bool $memuatIzinSensitif): int
    {
        $batas = self::batas();

        return match (true) {
            $peran->kode === MenuBawaan::PERAN_SUPERADMIN => $batas['maks_hari_superadmin'],
            $memuatIzinSensitif => $batas['maks_hari_peran_sensitif'],
            default => $batas['maks_hari'],
        };
    }

    public function hariEfektif(Pengguna $pengguna): int
    {
        $hari = $this->hariInstalasi();
        $daftarPeran = Peran::query()
            ->whereIn('id', PenggunaPeran::query()->berlaku()->where('pengguna_id', $pengguna->id)->select('peran_id'))
            ->withExists(['izin as memuat_izin_sensitif' => fn ($izin) => $izin->where('sensitif', true)])
            ->get();
        foreach ($daftarPeran as $peran) {
            $hari = min($hari, $peran->masa_percaya_hari ?? $hari, self::batasPeran($peran, (bool) $peran->getAttribute('memuat_izin_sensitif')));
        }
        $izinKhususSensitif = PenggunaIzinKhusus::query()->berlaku()->where('pengguna_id', $pengguna->id)
            ->where('efek', 'tambah')->whereHas('izin', fn ($izin) => $izin->where('sensitif', true))->exists();
        if ($izinKhususSensitif) {
            $hari = min($hari, self::batas()['maks_hari_peran_sensitif']);
        }

        return max(0, $hari);
    }

    /**
     * Untuk respons `perlu_mfa`/`perlu_daftar_mfa`: kotak "Percayai perangkat ini" hanya bila bisa dipakai, yaitu dari
     * jaringan RS (tempat token berlaku) dan masa percaya efektif lebih dari 0.
     *
     * @return array{tersedia: bool, hari: int}
     */
    public function tawaran(Request $request, Pengguna $pengguna): array
    {
        $hari = $this->kebijakanMfa->dariJaringanRs($request->ip()) ? $this->hariEfektif($pengguna) : 0;

        return ['tersedia' => $hari > 0, 'hari' => $hari];
    }

    /**
     * Sesudah MFA berhasil dengan centang "Percayai perangkat ini": token baru (hanya hash yang disimpan), dicatat di
     * atribut permintaan untuk respons dan batas diam sesi. Null bila tidak tersedia (tanpa error: centang diabaikan).
     *
     * @return array{0: PerangkatTepercaya, 1: Cookie}|null
     */
    public function percayai(Request $request, Pengguna $pengguna): ?array
    {
        $tawaran = $this->tawaran($request, $pengguna);
        if (! $tawaran['tersedia']) {
            return null;
        }

        $token = bin2hex(random_bytes(32));
        $perangkat = DB::transaction(function () use ($request, $pengguna, $tawaran, $token) {
            $perangkat = new PerangkatTepercaya;
            $perangkat->forceFill([
                'pengguna_id' => $pengguna->id, 'token_hash' => hash('sha256', $token),
                'nama_perangkat' => self::namaPerangkat((string) $request->userAgent()),
                'berlaku_sampai' => now()->addDays($tawaran['hari']), 'dibuat_oleh' => $pengguna->id, 'diubah_oleh' => $pengguna->id,
            ])->save();
            // Tanpa token (PRD 14.2).
            $this->audit->catat('akses.perangkat_dipercaya', $pengguna->id, 'akses.pengguna', $pengguna->id, [
                'perangkat_id' => $perangkat->id, 'nama_perangkat' => $perangkat->nama_perangkat,
                'berlaku_sampai' => $perangkat->berlaku_sampai->toIso8601String(),
            ]);

            return $perangkat;
        });
        $request->attributes->set(self::ATRIBUT, $perangkat);

        return [$perangkat, $this->cookie($token, $perangkat->berlaku_sampai)];
    }

    /** Token cookie sah untuk akun ini, dari jaringan RS, belum dicabut, dan masih dalam masa percaya efektif. */
    public function cocokkan(Request $request, Pengguna $pengguna): ?PerangkatTepercaya
    {
        $token = $request->cookie((string) config('autentikasi.perangkat_tepercaya.cookie'));
        if (! is_string($token) || $token === '' || strlen($token) > 128 || ! $this->kebijakanMfa->dariJaringanRs($request->ip())) {
            return null;
        }
        $perangkat = PerangkatTepercaya::query()->where('token_hash', hash('sha256', $token))
            ->where('pengguna_id', $pengguna->id)->whereNull('dicabut_pada')->first();
        if ($perangkat === null || ! $this->berlakuSampai($perangkat, $pengguna)->isFuture()) {
            return null;
        }

        return $perangkat;
    }

    /** MFA dilewati karena perangkat ini (langkah 1). */
    public function pakai(Request $request, PerangkatTepercaya $perangkat): void
    {
        $perangkat->forceFill(['terakhir_dipakai_pada' => now()])->save();
        $request->attributes->set(self::ATRIBUT, $perangkat);
    }

    /** Masa berlaku efektif: tersimpan, atau lebih pendek bila kebijakan yang berlaku sekarang lebih ketat. */
    public function berlakuSampai(PerangkatTepercaya $perangkat, Pengguna $pengguna): CarbonInterface
    {
        $menurutKebijakan = $perangkat->dibuat_pada->copy()->addDays($this->hariEfektif($pengguna));

        return $perangkat->berlaku_sampai->lessThan($menurutKebijakan) ? $perangkat->berlaku_sampai : $menurutKebijakan;
    }

    /** @return 'aktif'|'kedaluwarsa'|'dicabut' */
    public function status(PerangkatTepercaya $perangkat, Pengguna $pengguna): string
    {
        return match (true) {
            $perangkat->dicabut_pada !== null => 'dicabut',
            $this->berlakuSampai($perangkat, $pengguna)->isFuture() => 'aktif',
            default => 'kedaluwarsa',
        };
    }

    /** Satu perangkat; 409 bila sudah dicabut atau kedaluwarsa. Sesi berjalan tidak diputus. */
    public function cabut(PerangkatTepercaya $perangkat, Pengguna $pemilik, string $alasan, ?string $olehId, ?string $keterangan = null): PerangkatTepercaya
    {
        return DB::transaction(function () use ($perangkat, $pemilik, $alasan, $olehId, $keterangan) {
            $perangkat = PerangkatTepercaya::query()->whereKey($perangkat->id)->lockForUpdate()->firstOrFail();
            if ($this->status($perangkat, $pemilik) !== 'aktif') {
                throw new ApiError(409, 'perangkat_tidak_aktif', 'Perangkat ini sudah dicabut atau kedaluwarsa.');
            }
            $perangkat->forceFill([
                'dicabut_pada' => now(), 'dicabut_oleh' => $olehId, 'alasan_cabut' => $alasan, 'keterangan_cabut' => $keterangan,
                'diubah_oleh' => $olehId,
            ])->save();
            $this->audit->catat('akses.perangkat_dicabut', $olehId, 'akses.pengguna', $pemilik->id, array_filter([
                'perangkat_id' => [$perangkat->id], 'alasan' => $alasan, 'keterangan' => $keterangan,
            ], fn ($v) => $v !== null));

            return $perangkat;
        });
    }

    /**
     * Semua perangkat aktif akun dicabut (ganti/reset sandi, reset MFA, nonaktif). Dipanggil di dalam transaksi pemanggil,
     * sebelum catat() milik pemanggil.
     */
    public function cabutSemua(string $penggunaId, string $alasan, ?string $olehId): int
    {
        $id = PerangkatTepercaya::query()->where('pengguna_id', $penggunaId)->whereNull('dicabut_pada')
            ->where('berlaku_sampai', '>', now()->format('Y-m-d H:i:s.uP'))->lockForUpdate()->pluck('id')->all();
        if ($id === []) {
            return 0;
        }
        PerangkatTepercaya::query()->whereKey($id)->update([
            'dicabut_pada' => now(), 'dicabut_oleh' => $olehId, 'alasan_cabut' => $alasan, 'diubah_oleh' => $olehId, 'diubah_pada' => now(),
        ]);
        $this->audit->catat('akses.perangkat_dicabut', $olehId, 'akses.pengguna', $penggunaId, ['perangkat_id' => $id, 'alasan' => $alasan]);

        return count($id);
    }

    /** Cookie kedaluwarsa, dikirim saat perangkat sesi ini dicabut pemiliknya. */
    public function cookieHapus(): Cookie
    {
        return $this->cookie('', now()->subYear());
    }

    private function cookie(string $nilai, CarbonInterface $sampai): Cookie
    {
        return Cookie::create((string) config('autentikasi.perangkat_tepercaya.cookie'), $nilai, $sampai, self::JALUR_COOKIE, null,
            (bool) config('session.secure'), true, false, Cookie::SAMESITE_STRICT);
    }

    /** "Chrome di Windows" dari user-agent; user-agent mentah tidak disimpan. */
    public static function namaPerangkat(string $userAgent): string
    {
        $peramban = match (true) {
            str_contains($userAgent, 'Edg/') => 'Edge',
            str_contains($userAgent, 'OPR/') => 'Opera',
            str_contains($userAgent, 'Firefox/') => 'Firefox',
            str_contains($userAgent, 'Chrome/') => 'Chrome',
            str_contains($userAgent, 'Safari/') => 'Safari',
            default => 'Peramban',
        };
        $sistem = match (true) {
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'iPhone') => 'iPhone',
            str_contains($userAgent, 'iPad') => 'iPad',
            str_contains($userAgent, 'Mac OS X') => 'macOS',
            str_contains($userAgent, 'CrOS') => 'ChromeOS',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => 'perangkat tidak dikenal',
        };

        return "{$peramban} di {$sistem}";
    }
}
