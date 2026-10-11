<?php

namespace App\Modules\MasterData\Application;

use App\Modules\Akses\Application\AccessEvaluator;
use App\Modules\Akses\Domain\Cakupan;
use App\Modules\Akses\Models\Pengguna;

/**
 * Lapis 3 untuk data unit (kontrak v0.3.0): izin ber-cakupan `unit` berlaku di subpohon unit itu; unit akar
 * dan data seluruh RS butuh cakupan `rs`.
 */
final class LingkupUnit
{
    public function __construct(
        private readonly AccessEvaluator $evaluator,
        private readonly PohonUnit $pohon,
    ) {}

    /**
     * Unit yang boleh dibaca dengan salah satu izin: null = semua, selain itu akar subpohon yang boleh.
     *
     * @param  list<string>  $izin
     * @return list<string>|null
     */
    public function akarTerjangkau(Pengguna $pengguna, array $izin): ?array
    {
        $akar = [];
        foreach ($izin as $kode) {
            $unit = $this->evaluator->unitTerjangkau($pengguna, $kode);
            if ($unit === null) {
                return null;
            }
            $akar = [...$akar, ...$unit];
        }

        return $this->pohon->akar(array_values(array_unique($akar)));
    }

    /** @param  list<string>|null  $akar  hasil akarTerjangkau() */
    public function memuat(?array $akar, string $unitId): bool
    {
        return $akar === null || array_intersect($akar, $this->pohon->leluhur($unitId)) !== [];
    }

    /** Boleh mengubah/menambah di bawah induk ini (null = unit akar, butuh cakupan rs). */
    public function bolehDi(Pengguna $pengguna, string $izin, ?string $indukId): bool
    {
        return $this->evaluator->boleh($pengguna, $izin, $indukId === null ? Cakupan::rs() : new Cakupan('unit', $indukId));
    }
}
