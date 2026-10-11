<?php

namespace App\Modules\Akses\Domain;

/**
 * Cakupan data sebuah penugasan atau data yang diakses (lapis 3, PRD 9.2.2). `rs` memuat semua.
 * `pasien_dirawat` butuh relasi perawatan yang belum ada, jadi untuk sementara tidak memuat apa pun
 * selain dirinya sendiri.
 */
final readonly class Cakupan
{
    public function __construct(public string $jenis, public ?string $id = null) {}

    public static function rs(): self
    {
        return new self('rs');
    }

    public function memuat(self $data): bool
    {
        return $this->jenis === 'rs' || ($this->jenis === $data->jenis && $this->id === $data->id);
    }
}
