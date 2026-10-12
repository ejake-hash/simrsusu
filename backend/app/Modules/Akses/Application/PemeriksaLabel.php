<?php

namespace App\Modules\Akses\Application;

use App\Modules\Akses\Models\Pengguna;

/**
 * Pintu lapis 4 untuk pembentuk respons data pasien (tiket 1.5; PRD 9.2.2, 9.2.5): objek berlabel yang tidak lolos
 * dikirim sebagai `Terbatas` ({terbatas: true}, kontrak v0.14.0), tanpa isi dan tanpa nama label. Dipakai modul
 * pelayanan mulai fase 2; keputusan tetap di AccessEvaluator::bolehLabel().
 */
final class PemeriksaLabel
{
    public const TERBATAS = ['terbatas' => true];

    public function __construct(private readonly AccessEvaluator $evaluator) {}

    /** $label null = objek tidak berlabel (selalu lolos lapis 4). */
    public function boleh(Pengguna $pengguna, ?string $label, ?string $pasienId): bool
    {
        return $label === null || $this->evaluator->bolehLabel($pengguna, $label, $pasienId);
    }

    /**
     * @template T of array
     *
     * @param  callable(): T  $isi  hanya dipanggil bila lolos, agar isi berlabel tidak pernah dibentuk untuk akun lain
     * @return T|array{terbatas: true}
     */
    public function bentuk(Pengguna $pengguna, ?string $label, ?string $pasienId, callable $isi): array
    {
        return $this->boleh($pengguna, $label, $pasienId) ? $isi() : self::TERBATAS;
    }
}
