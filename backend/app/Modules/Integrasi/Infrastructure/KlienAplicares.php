<?php

namespace App\Modules\Integrasi\Infrastructure;

use App\Modules\Integrasi\Domain\SistemIntegrasi;

/**
 * Panggilan Aplicares (catatan lapangan §24; belum dicocokkan dengan dokumen resmi BPJS, perlu verifikasi): header BPJS
 * yang sama dengan VClaim (§1.1), isi permintaan JSON, respons JSON polos tanpa enkripsi. Penentu sukses di
 * KlienBpjs::suksesAplicares().
 */
final class KlienAplicares
{
    public function __construct(private readonly PanggilanHttp $http) {}

    /**
     * @param  array<string, string>  $kredensial  cons_id, secret_key, user_key
     * @param  array<string, mixed>|null  $isi
     * @return array{jawaban: JawabanHttp, berhasil: bool, pesan: string|null}
     */
    public function kirim(string $lingkungan, array $kredensial, string $metode, string $path, ?array $isi, ?string $kotakKeluarId = null): array
    {
        $jawaban = $this->http->kirim(SistemIntegrasi::Aplicares, $lingkungan, $metode, $path,
            ['headers' => KlienBpjs::header($kredensial), ...($isi === null ? [] : ['json' => $isi])]);
        $json = $jawaban->json();
        $meta = KlienBpjs::meta($json);
        $berhasil = $jawaban->kodeHttp === 200 && KlienBpjs::suksesAplicares($json);
        $pesan = match (true) {
            $berhasil => null,
            $jawaban->galat !== null => $jawaban->galat,
            $meta['message'] !== null => 'Aplicares: '.$meta['message'].($meta['code'] === null ? '' : ' (kode '.$meta['code'].')'),
            default => 'Jawaban Aplicares tidak dikenali (HTTP '.$jawaban->kodeHttp.').',
        };
        $this->http->catat(SistemIntegrasi::Aplicares, $lingkungan, $metode, $path, $jawaban, $berhasil, $pesan, $isi, $json, $kotakKeluarId);

        return ['jawaban' => $jawaban, 'berhasil' => $berhasil, 'pesan' => $pesan];
    }
}
