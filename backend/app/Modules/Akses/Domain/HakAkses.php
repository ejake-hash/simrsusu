<?php

namespace App\Modules\Akses\Domain;

/**
 * Hasil lapis 1–3 untuk satu akun: kode menu/sub-menu/bagian yang boleh dibuka dan kode izin
 * aksi beserta cakupan datanya. Ditambah bahan lapis 4–5 (tiket 1.5) yang tidak berubah selama cache hidup:
 * izin label yang disetujui (masa berlaku dibandingkan saat dibaca) dan syarat lapis 5 tiap izin.
 * Bentuk array agar bisa disimpan di cache.
 */
final readonly class HakAkses
{
    /**
     * @param  array<string, true>  $menu
     * @param  array<string, list<array{0: string, 1: string|null}>>  $izin
     * @param  array<string, array{0: int, 1: bool}>  $label  kode label => [batas berlaku (detik epoch, eksklusif), butuh relasi perawatan]
     * @param  array<string, array{mfa: bool, klinis: bool}>  $syarat  hanya izin yang punya syarat lapis 5
     */
    public function __construct(public array $menu = [], public array $izin = [], public array $label = [], public array $syarat = []) {}

    /** @param  array{menu: array<string, true>, izin: array<string, list<array{0: string, 1: string|null}>>, label?: array<string, array{0: int, 1: bool}>, syarat?: array<string, array{mfa: bool, klinis: bool}>}  $data */
    public static function dariArray(array $data): self
    {
        return new self($data['menu'], $data['izin'], $data['label'] ?? [], $data['syarat'] ?? []);
    }

    /** @return array{menu: array<string, true>, izin: array<string, list<array{0: string, 1: string|null}>>, label: array<string, array{0: int, 1: bool}>, syarat: array<string, array{mfa: bool, klinis: bool}>} */
    public function keArray(): array
    {
        return ['menu' => $this->menu, 'izin' => $this->izin, 'label' => $this->label, 'syarat' => $this->syarat];
    }

    public function bolehMenu(string $kode): bool
    {
        return isset($this->menu[$kode]);
    }

    /** @return list<Cakupan> */
    public function cakupan(string $izin): array
    {
        return array_map(fn (array $c) => new Cakupan($c[0], $c[1]), $this->izin[$izin] ?? []);
    }

    /** Izin label yang disetujui dan masih berlaku pada $sekarang (detik epoch). */
    public function punyaLabel(string $kode, int $sekarang): bool
    {
        return isset($this->label[$kode]) && $this->label[$kode][0] > $sekarang;
    }

    /** Label yang dimiliki butuh relasi perawatan (dari data label saat dihitung; label yang tidak dimiliki: true). */
    public function butuhRelasi(string $kode): bool
    {
        return $this->label[$kode][1] ?? true;
    }

    /** @return array{mfa: bool, klinis: bool} */
    public function syarat(string $izin): array
    {
        return $this->syarat[$izin] ?? ['mfa' => false, 'klinis' => false];
    }
}
