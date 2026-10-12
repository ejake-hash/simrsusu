<?php

namespace App\Modules\Integrasi\Infrastructure;

/** Hasil mentah satu panggilan HTTP. `galat` terisi bila tidak tersambung atau alamat belum dikonfigurasi. */
final class JawabanHttp
{
    public function __construct(
        public readonly ?int $kodeHttp,
        public readonly ?string $badan,
        public readonly ?int $latensiMs,
        public readonly ?string $galat,
    ) {}

    /** Kegagalan di sisi sistem luar atau jaringan (dihitung circuit breaker), bukan penolakan isi. */
    public function gangguan(): bool
    {
        return $this->galat !== null || $this->kodeHttp === null || $this->kodeHttp >= 500 || $this->kodeHttp === 429;
    }

    /** @return array<string, mixed>|null */
    public function json(): ?array
    {
        $isi = $this->badan === null ? null : json_decode($this->badan, true);

        return is_array($isi) ? $isi : null;
    }
}
