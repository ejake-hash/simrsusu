<?php

namespace App\Modules\Integrasi\Domain;

/**
 * Sistem luar fase 1 (tiket 1.9, kontrak v0.13.0). Nilai = `integrasi.kotak_keluar.tujuan` (PRD 11.2). Kolom
 * kredensial memakai istilah sistem luar agar mudah dicocokkan dengan dokumen BPJS/Kemenkes.
 */
enum SistemIntegrasi: string
{
    case BpjsVclaim = 'bpjs_vclaim';
    case Aplicares = 'aplicares';
    case Satusehat = 'satusehat';

    public const LINGKUNGAN = ['sandbox', 'produksi'];

    public function nama(): string
    {
        return match ($this) {
            self::BpjsVclaim => 'BPJS VClaim',
            self::Aplicares => 'BPJS Aplicares',
            self::Satusehat => 'SATUSEHAT',
        };
    }

    /**
     * Kolom kredensial: nama => rahasia. Semua wajib.
     *
     * @return array<string, bool>
     */
    public function kolom(): array
    {
        return match ($this) {
            self::BpjsVclaim, self::Aplicares => ['cons_id' => false, 'secret_key' => true, 'user_key' => true],
            self::Satusehat => ['client_id' => false, 'organization_id' => false, 'client_secret' => true],
        };
    }

    /** Sub-menu pemantauan (kotak keluar) sistem ini: integrasi.<sub>.lihat/perbaiki. */
    public function subMenuPemantauan(): string
    {
        return $this === self::Satusehat ? 'integrasi.satusehat' : 'integrasi.bpjs';
    }

    public function kodeBpjs(): bool
    {
        return $this !== self::Satusehat;
    }
}
