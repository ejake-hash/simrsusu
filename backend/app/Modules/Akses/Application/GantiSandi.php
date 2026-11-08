<?php

namespace App\Modules\Akses\Application;

use App\Core\Audit\CatatAktivitas;
use App\Core\Http\ApiError;
use App\Modules\Akses\Http\Middleware\SesiPengguna;
use App\Modules\Akses\Models\Pengguna;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * Ganti sandi oleh pemilik akun (tiket 1.8d; PRD 14.2, ADR 0001). Sandi lama salah dibatasi laju per akun dan tidak
 * menambah hitungan penguncian masuk. Sesudah berhasil: ID sesi diganti, sesi lain diputus, sesi ini tetap.
 */
final class GantiSandi
{
    public const BATAS_SALAH = 5;

    public const JENDELA_DETIK = 900;

    public function __construct(
        private readonly CatatAktivitas $audit,
        private readonly KelolaPerangkat $perangkat,
    ) {}

    public function ganti(Request $request, Pengguna $pengguna, string $sandiLama, string $sandiBaru): Pengguna
    {
        BatasSandiSalah::periksa('ganti-sandi:'.$pengguna->id, self::BATAS_SALAH, self::JENDELA_DETIK, $sandiLama, $pengguna->sandi_hash,
            new ApiError(422, 'sandi_lama_salah', 'Sandi lama tidak cocok.', [['kolom' => 'sandi_lama', 'pesan' => 'Sandi lama tidak cocok.']]));
        // Aturan PRD 14.2 ditulis eksplisit agar semua pesan berbahasa Indonesia (pesan bawaan Laravel berbahasa Inggris).
        $minimal = max(12, (int) config('autentikasi.sandi.panjang_minimal'));
        $aturan = ['required', 'string', 'max:200', 'min:'.$minimal, app(SandiTidakBocor::class), new SandiKontekstual($pengguna->nama_pengguna),
            'different:sandi_lama_teks', ...(config('autentikasi.sandi.cek_hibp') ? [Password::min($minimal)->uncompromised()] : [])];
        // Sama dengan Password::defaults(): sandi demo pendek hanya bila config autentikasi.sandi.demo_longgar di local.
        if (app()->environment('local') && config('autentikasi.sandi.demo_longgar')) {
            $minimal = 6;
            $aturan = ['required', 'string', 'max:200', 'min:'.$minimal, 'different:sandi_lama_teks'];
        }
        $validasi = Validator::make(['sandi_baru' => $sandiBaru, 'sandi_lama_teks' => $sandiLama], ['sandi_baru' => $aturan], [
            'sandi_baru.min' => "Sandi baru minimal {$minimal} karakter.",
            'sandi_baru.max' => 'Sandi baru paling panjang 200 karakter.',
            'sandi_baru.different' => 'Sandi baru harus berbeda dari sandi lama.',
            'sandi_baru.required' => 'Isi sandi baru.',
        ]);
        if ($validasi->fails()) {
            throw new ApiError(422, 'validasi_gagal', 'Sandi baru belum memenuhi aturan.',
                array_map(fn (string $pesan) => ['kolom' => 'sandi_baru', 'pesan' => $pesan], $validasi->errors()->get('sandi_baru')));
        }

        $pengguna = DB::transaction(function () use ($pengguna, $sandiBaru) {
            $akun = Pengguna::query()->whereKey($pengguna->id)->lockForUpdate()->firstOrFail();
            $akun->forceFill([
                'sandi_hash' => Hash::make($sandiBaru), 'wajib_ganti_sandi' => false, 'sandi_diubah_pada' => now(), 'diubah_oleh' => $akun->id,
            ])->save();
            $akun->cabutSesi();
            $this->perangkat->cabutSemua($akun->id, 'sandi_diganti', $akun->id);
            $this->audit->catat('akses.sandi_diganti', $akun->id, 'akses.pengguna', $akun->id, ['sesi_lain_diputus' => true]);

            return $akun;
        });
        // Sesi ini dimulai ulang sesudah waktu cabut, dengan ID baru: hanya sesi lain yang terputus. Guard memakai
        // pengguna dengan hash sandi baru agar AuthenticateSession tidak menganggap sesi ini basi.
        Auth::guard('web')->setUser($pengguna);
        $request->setUserResolver(fn () => $pengguna);
        $request->session()->migrate(true);
        $request->session()->put('password_hash_web', $pengguna->getAuthPassword());
        SesiPengguna::mulaiUlang($request);

        return $pengguna;
    }
}
