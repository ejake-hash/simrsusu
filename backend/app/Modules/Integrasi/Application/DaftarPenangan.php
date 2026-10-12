<?php

namespace App\Modules\Integrasi\Application;

use App\Modules\Integrasi\Domain\PenanganKiriman;
use App\Modules\Integrasi\Domain\SistemIntegrasi;

/** Penangan kiriman per tujuan + jenis_resource; diisi ModuleServiceProvider (dan tes). */
final class DaftarPenangan
{
    /** @var array<string, PenanganKiriman> */
    private array $penangan = [];

    /** @param  iterable<PenanganKiriman>  $penangan */
    public function __construct(iterable $penangan = [])
    {
        foreach ($penangan as $p) {
            $this->pasang($p);
        }
    }

    public function pasang(PenanganKiriman $p): void
    {
        $this->penangan[$p->tujuan()->value.':'.$p->jenisResource()] = $p;
    }

    public function untuk(SistemIntegrasi $tujuan, string $jenisResource): ?PenanganKiriman
    {
        return $this->penangan[$tujuan->value.':'.$jenisResource] ?? null;
    }
}
