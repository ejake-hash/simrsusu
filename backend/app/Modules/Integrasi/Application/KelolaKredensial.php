<?php

namespace App\Modules\Integrasi\Application;

use App\Core\Audit\CatatAktivitas;
use App\Core\Http\ApiError;
use App\Modules\Integrasi\Domain\PenyimpanRahasia;
use App\Modules\Integrasi\Domain\SistemIntegrasi;
use App\Modules\Integrasi\Infrastructure\PenyandiKredensial;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Kredensial per sistem + lingkungan (K9, kontrak v0.13.0). Tulis saja: kolom rahasia hanya keluar sebagai `akhir`
 * (4 karakter terakhir bila panjangnya ≥ 16), tidak pernah di log, audit, atau pesan error. Satu lingkungan aktif per
 * sistem; kiriman tidak pernah berpindah lingkungan (yang belum terkirim ditahan saat lingkungan berganti).
 */
final class KelolaKredensial
{
    /** Panjang minimum rahasia agar 4 karakter terakhirnya boleh ditampilkan. */
    private const PANJANG_TAMPIL_AKHIR = 16;

    public function __construct(
        private readonly PenyimpanRahasia $penyandi,
        private readonly CatatAktivitas $audit,
    ) {}

    /** @param  array<string, string>  $kolom */
    public function simpan(SistemIntegrasi $sistem, string $lingkungan, array $kolom, bool $aktifkan, bool $nonaktifkan, string $olehId): void
    {
        $dikenal = $sistem->kolom();
        $salah = [];
        foreach ($kolom as $nama => $nilai) {
            if (! array_key_exists($nama, $dikenal)) {
                $salah[] = ['kolom' => "kolom.$nama", 'pesan' => 'Kolom ini bukan milik '.$sistem->nama().'.'];
            } elseif ($nilai === '' || mb_strlen($nilai) > 500 || trim($nilai) !== $nilai) {
                $salah[] = ['kolom' => "kolom.$nama", 'pesan' => 'Isi 1–500 karakter tanpa spasi di awal atau akhir.'];
            }
        }
        if ($aktifkan && $nonaktifkan) {
            $salah[] = ['kolom' => 'aktifkan', 'pesan' => 'Pilih aktifkan atau nonaktifkan, tidak keduanya.'];
        }
        if ($salah !== []) {
            throw new ApiError(422, 'validasi_gagal', 'Isian belum benar.', $salah);
        }
        $rahasiaBaru = array_filter($kolom, fn ($n) => $dikenal[$n], ARRAY_FILTER_USE_KEY);
        if ($rahasiaBaru !== [] && ! $this->penyandi->siap()) {
            throw PenyandiKredensial::belumDikonfigurasi();
        }

        DB::transaction(function () use ($sistem, $lingkungan, $kolom, $dikenal, $rahasiaBaru, $aktifkan, $nonaktifkan, $olehId) {
            DB::statement("SELECT pg_advisory_xact_lock(hashtext('integrasi:kredensial:' || ?))", [$sistem->value]);
            $baris = DB::table('integrasi.kredensial_api')->where('tujuan', $sistem->value)->where('lingkungan', $lingkungan)->first();
            $isian = $baris === null ? [] : (array) json_decode($baris->isian, true);
            $rahasia = $baris?->rahasia === null ? [] : $this->penyandi->buka($baris->rahasia, (string) $baris->id_kunci);
            foreach ($kolom as $nama => $nilai) {
                if ($dikenal[$nama]) {
                    $rahasia[$nama] = $nilai;
                } else {
                    $isian[$nama] = $nilai;
                }
            }
            $sandi = $rahasia === [] ? null : ($rahasiaBaru === [] && $baris !== null ? ['teks' => $baris->rahasia, 'id_kunci' => $baris->id_kunci] : $this->penyandi->sandikan($rahasia));
            $ringkas = array_map(fn (string $v) => mb_strlen($v) >= self::PANJANG_TAMPIL_AKHIR ? mb_substr($v, -4) : null, $rahasia);
            $nilai = ['isian' => json_encode((object) $isian), 'rahasia' => $sandi['teks'] ?? null, 'id_kunci' => $sandi['id_kunci'] ?? null,
                'rahasia_ringkas' => json_encode((object) $ringkas), 'diubah_oleh' => $olehId, 'diubah_pada' => now()];
            if ($baris === null) {
                DB::table('integrasi.kredensial_api')->insert([...$nilai, 'id' => (string) Str::uuid7(), 'tujuan' => $sistem->value, 'lingkungan' => $lingkungan, 'dibuat_oleh' => $olehId]);
            } else {
                DB::table('integrasi.kredensial_api')->where('id', $baris->id)->update([...$nilai, 'revisi' => DB::raw('revisi + 1')]);
            }

            $aktifLama = $this->lingkunganAktif($sistem);
            if ($aktifkan) {
                $kurang = array_values(array_diff(array_keys($dikenal), array_keys([...$isian, ...$rahasia])));
                if ($kurang !== []) {
                    throw new ApiError(422, 'kredensial_belum_lengkap', 'Lengkapi kredensial sebelum mengaktifkan lingkungan ini.',
                        array_map(fn ($n) => ['kolom' => "kolom.$n", 'pesan' => 'Wajib diisi.'], $kurang));
                }
                DB::table('integrasi.kredensial_api')->where('tujuan', $sistem->value)->where('lingkungan', '<>', $lingkungan)->update(['aktif' => false]);
                DB::table('integrasi.kredensial_api')->where('tujuan', $sistem->value)->where('lingkungan', $lingkungan)->update(['aktif' => true]);
                if ($aktifLama !== $lingkungan) {
                    $this->tahanBelumTerkirim($sistem, 'lingkungan_berubah', 'Lingkungan integrasi berganti; kiriman ini tidak dipindah ke lingkungan baru.', $lingkungan);
                    $this->tutupSirkuit($sistem);
                }
            } elseif ($nonaktifkan) {
                DB::table('integrasi.kredensial_api')->where('tujuan', $sistem->value)->update(['aktif' => false]);
                $this->tahanBelumTerkirim($sistem, 'integrasi_nonaktif', 'Integrasi sistem ini dinonaktifkan.', null);
            }

            $this->audit->catat('integrasi.kredensial_diubah', $olehId, 'integrasi.kredensial_api', null, [
                'sistem' => $sistem->value, 'lingkungan' => $lingkungan, 'kolom' => array_keys($kolom), 'aktifkan' => $aktifkan, 'nonaktifkan' => $nonaktifkan,
            ]);
        });
    }

    public function lingkunganAktif(SistemIntegrasi $sistem): ?string
    {
        return DB::table('integrasi.kredensial_api')->where('tujuan', $sistem->value)->where('aktif', true)->value('lingkungan');
    }

    /**
     * Nilai lengkap (rahasia terbuka) untuk klien; hanya dipakai di dalam proses, tidak pernah dikembalikan ke layar.
     *
     * @return array<string, string>|null null = belum ada baris
     */
    public function nilai(SistemIntegrasi $sistem, string $lingkungan): ?array
    {
        $baris = DB::table('integrasi.kredensial_api')->where('tujuan', $sistem->value)->where('lingkungan', $lingkungan)->first();
        if ($baris === null) {
            return null;
        }
        $rahasia = $baris->rahasia === null ? [] : $this->penyandi->buka($baris->rahasia, (string) $baris->id_kunci);

        return [...(array) json_decode($baris->isian, true), ...$rahasia];
    }

    /** @return list<string> kolom wajib yang belum terisi */
    public function kurang(SistemIntegrasi $sistem, string $lingkungan): array
    {
        $baris = DB::table('integrasi.kredensial_api')->where('tujuan', $sistem->value)->where('lingkungan', $lingkungan)->first();
        $terisi = $baris === null ? [] : [...array_keys((array) json_decode($baris->isian, true)), ...array_keys((array) json_decode($baris->rahasia_ringkas, true))];

        return array_values(array_diff(array_keys($sistem->kolom()), $terisi));
    }

    /**
     * KredensialLingkungan[] (kontrak v0.13.0) tanpa nilai rahasia.
     *
     * @param  callable(list<string>): array<string, array{id: string, nama: string}>  $pelaku
     * @return list<array<string, mixed>>
     */
    public function ringkas(SistemIntegrasi $sistem, callable $pelaku): array
    {
        $baris = DB::table('integrasi.kredensial_api')->where('tujuan', $sistem->value)->get()->keyBy('lingkungan');
        $nama = $pelaku($baris->pluck('diubah_oleh')->filter()->unique()->values()->all());
        $hasil = [];
        foreach (SistemIntegrasi::LINGKUNGAN as $lingkungan) {
            $b = $baris[$lingkungan] ?? null;
            $isian = $b === null ? [] : (array) json_decode($b->isian, true);
            $ringkas = $b === null ? [] : (array) json_decode($b->rahasia_ringkas, true);
            $kolom = [];
            foreach ($sistem->kolom() as $n => $rahasiakah) {
                $terisi = $rahasiakah ? array_key_exists($n, $ringkas) : isset($isian[$n]);
                $kolom[] = [
                    'nama' => $n, 'rahasia' => $rahasiakah, 'wajib' => true, 'terisi' => $terisi,
                    'nilai' => $rahasiakah ? null : ($isian[$n] ?? null),
                    'akhir' => $rahasiakah ? ($ringkas[$n] ?? null) : null,
                ];
            }
            $hasil[] = [
                'lingkungan' => $lingkungan, 'aktif' => (bool) ($b->aktif ?? false),
                'lengkap' => ! in_array(false, array_column($kolom, 'terisi'), true), 'kolom' => $kolom,
                'diubah_pada' => $b === null ? null : Carbon::parse($b->diubah_pada)->toIso8601String(),
                'diubah_oleh' => $b?->diubah_oleh === null ? null : ($nama[$b->diubah_oleh] ?? null),
            ];
        }

        return $hasil;
    }

    /** Kiriman menunggu/gagal yang tidak terikat ke lingkungan aktif baru ditahan (tidak pernah dipindah lingkungan). */
    private function tahanBelumTerkirim(SistemIntegrasi $sistem, string $kode, string $pesan, ?string $lingkunganBaru): void
    {
        DB::table('integrasi.kotak_keluar')->where('tujuan', $sistem->value)->whereIn('status', ['menunggu', 'gagal'])
            ->when($lingkunganBaru !== null, fn ($q) => $q->where(fn ($q) => $q->where('lingkungan', '<>', $lingkunganBaru)->orWhereNull('lingkungan')))
            ->update(['status' => 'tertahan', 'penyebab_tertahan_kode' => $kode, 'penyebab_tertahan_pesan' => $pesan, 'coba_lagi_pada' => null, 'diubah_pada' => now()]);
    }

    private function tutupSirkuit(SistemIntegrasi $sistem): void
    {
        DB::table('integrasi.status_sistem')->where('tujuan', $sistem->value)
            ->update(['cb_status' => 'tertutup', 'gagal_beruntun' => 0, 'cb_sejak' => null, 'coba_lagi_pada' => null, 'buka_detik' => 0, 'diubah_pada' => now()]);
    }
}
