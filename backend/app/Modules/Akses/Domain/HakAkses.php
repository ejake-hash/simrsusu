<?php

namespace App\Modules\Akses\Domain;

/**
 * Hasil lapis 1–3 untuk satu akun: kode menu/sub-menu/bagian yang boleh dibuka dan kode izin
 * aksi beserta cakupan datanya. Bentuk array agar bisa disimpan di cache.
 */
final readonly class HakAkses
{
    /**
     * @param  array<string, true>  $menu
     * @param  array<string, list<array{0: string, 1: string|null}>>  $izin
     */
    public function __construct(public array $menu = [], public array $izin = []) {}

    /** @param  array{menu: array<string, true>, izin: array<string, list<array{0: string, 1: string|null}>>}  $data */
    public static function dariArray(array $data): self
    {
        return new self($data['menu'], $data['izin']);
    }

    /** @return array{menu: array<string, true>, izin: array<string, list<array{0: string, 1: string|null}>>} */
    public function keArray(): array
    {
        return ['menu' => $this->menu, 'izin' => $this->izin];
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
}
