<?php

namespace App\Modules\Integrasi\Domain;

/**
 * Penyimpan rahasia kredensial integrasi (K9). Implementasi fase 1: enkripsi tabel dengan KREDENSIAL_KUNCI
 * (PenyandiKredensial); vault/KMS bisa dipasang kemudian tanpa mengubah pemakainya.
 */
interface PenyimpanRahasia
{
    /**
     * @param  array<string, string>  $rahasia
     * @return array{teks: string, id_kunci: string}
     */
    public function sandikan(array $rahasia): array;

    /** @return array<string, string> */
    public function buka(string $teks, string $idKunci): array;

    public function siap(): bool;

    public function idAktif(): ?string;
}
