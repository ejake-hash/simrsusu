<?php

namespace Tests\Support;

use Illuminate\Testing\TestResponse;

/**
 * Meniru peramban SPA: cookie dibawa antar-permintaan, CSRF tetap diperiksa, dan guard serta isi
 * sesi di memori dibuang sebelum tiap permintaan (seperti permintaan HTTP baru).
 */
trait KlienSpa
{
    protected const ORIGIN = 'http://localhost:5173';

    /** @var array<string, string> cookie mentah (terenkripsi) seperti di peramban */
    private array $kue = [];

    /** @var array<string, string> header tambahan untuk setiap permintaan berikutnya */
    private array $headerTambahan = [];

    /** Peramban baru: cookie kosong, ambil cookie CSRF lagi. */
    protected function mulaiSesiSpa(): void
    {
        $this->kue = [];
        config(['sanctum.middleware.validate_csrf_token' => CsrfTetapDiperiksa::class]);
        $this->minta('GET', '/sanctum/csrf-cookie')->assertNoContent();
    }

    /** @param  array<string, mixed>  $data */
    protected function minta(string $metode, string $uri, array $data = []): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $this->app['session']->driver()->flush();

        $this->unencryptedCookies = $this->kue;
        $this->defaultHeaders = array_filter([
            'Origin' => self::ORIGIN,
            'Referer' => self::ORIGIN.'/',
            'X-XSRF-TOKEN' => $this->kue['XSRF-TOKEN'] ?? null,
            ...$this->headerTambahan,
        ]);

        $respons = $this->withCredentials()->json($metode, $uri, $data);

        foreach ($respons->headers->getCookies() as $kue) {
            if ($kue->isCleared()) {
                unset($this->kue[$kue->getName()]);
            } else {
                $this->kue[$kue->getName()] = $kue->getValue();
            }
        }

        return $respons;
    }
}
