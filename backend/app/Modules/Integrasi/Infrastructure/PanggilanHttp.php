<?php

namespace App\Modules\Integrasi\Infrastructure;

use App\Modules\Integrasi\Domain\SistemIntegrasi;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Satu panggilan HTTP ke sistem luar + satu baris `integrasi.log_api` yang sudah disamarkan. Alamat dasar hanya dari
 * konfigurasi server (kontrak v0.13.0: tidak bisa diubah lewat API). Klien per sistem menentukan arti "berhasil".
 */
final class PanggilanHttp
{
    /** Alamat pengganti saat simulator aktif dan alamat sistem itu belum dikonfigurasi. */
    public const ALAMAT_SIMULATOR = 'https://%s.simulator.invalid';

    public static function alamat(SistemIntegrasi $sistem, string $lingkungan): ?string
    {
        $alamat = config("integrasi.alamat.{$sistem->value}.$lingkungan");
        if (($alamat === null || $alamat === '') && SimulatorIntegrasi::aktif()) {
            return sprintf(self::ALAMAT_SIMULATOR, str_replace('_', '-', $sistem->value).'-'.$lingkungan);
        }

        return $alamat === null || $alamat === '' ? null : rtrim((string) $alamat, '/');
    }

    /**
     * @param  array{headers?: array<string, string>, json?: array<string, mixed>, form?: array<string, string>, batas_waktu?: int}  $opsi
     */
    public function kirim(SistemIntegrasi $sistem, string $lingkungan, string $metode, string $path, array $opsi = []): JawabanHttp
    {
        $dasar = self::alamat($sistem, $lingkungan);
        if ($dasar === null) {
            return new JawabanHttp(null, null, null, 'Alamat '.$sistem->nama().' lingkungan '.$lingkungan.' belum dikonfigurasi di server.');
        }
        if (SimulatorIntegrasi::aktif()) {
            return app(SimulatorIntegrasi::class)->jawab($sistem, $lingkungan, $metode, $path, $opsi['headers'] ?? [], $opsi['form'] ?? $opsi['json'] ?? []);
        }
        $permintaan = Http::withHeaders($opsi['headers'] ?? [])->timeout($opsi['batas_waktu'] ?? (int) config('integrasi.batas_waktu'))->acceptJson();
        if (isset($opsi['form'])) {
            $permintaan = $permintaan->asForm();
        }
        $mulai = hrtime(true);
        try {
            $respons = $permintaan->send($metode, $dasar.'/'.ltrim($path, '/'), isset($opsi['form']) ? ['form_params' => $opsi['form']] : (isset($opsi['json']) ? ['json' => $opsi['json']] : []));

            return new JawabanHttp($respons->status(), $respons->body(), self::ms($mulai), null);
        } catch (ConnectionException $e) {
            // Pesan exception bisa memuat URL lengkap; cukup jenisnya.
            return new JawabanHttp(null, null, self::ms($mulai), str_contains(strtolower($e->getMessage()), 'timed out') ? 'Batas waktu habis.' : 'Tidak tersambung.');
        }
    }

    /**
     * @param  array<string, mixed>|string|null  $permintaan  isi permintaan apa adanya (disamarkan di sini)
     * @param  array<string, mixed>|string|null  $respons  isi respons terbaca (mis. sesudah dekripsi)
     */
    public function catat(SistemIntegrasi $sistem, string $lingkungan, string $metode, string $path, JawabanHttp $jawaban, bool $berhasil,
        ?string $pesan, array|string|null $permintaan, array|string|null $respons, ?string $kotakKeluarId = null): void
    {
        $maks = (int) config('integrasi.log_maks_bita');
        $terpotong = false;
        $potong = function (?string $isi) use ($maks, &$terpotong): ?string {
            if ($isi !== null && strlen($isi) > $maks) {
                $terpotong = true;

                return mb_strcut($isi, 0, $maks);
            }

            return $isi;
        };
        DB::table('integrasi.log_api')->insert([
            'id' => (string) Str::uuid7(), 'tujuan' => $sistem->value, 'lingkungan' => $lingkungan, 'metode' => $metode,
            'endpoint' => Penyamar::path('/'.ltrim($path, '/')), 'kode_http' => $jawaban->kodeHttp, 'berhasil' => $berhasil,
            'latensi_ms' => $jawaban->latensiMs, 'pesan' => Penyamar::teks($pesan), 'kotak_keluar_id' => $kotakKeluarId,
            'permintaan' => $potong(Penyamar::isi($permintaan, $sistem)), 'respons' => $potong(Penyamar::isi($respons ?? $jawaban->badan, $sistem)),
            'terpotong' => $terpotong,
        ]);
    }

    private static function ms(int|float $mulai): int
    {
        return (int) round((hrtime(true) - $mulai) / 1_000_000);
    }
}
