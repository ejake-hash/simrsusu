<?php

namespace App\Modules\Akses\Application;

use Carbon\CarbonInterface;
use RuntimeException;

final class GagalMasuk extends RuntimeException
{
    /** @param  CarbonInterface|null  $terkunciSampai  hanya diisi untuk AkunTerkunci */
    public function __construct(
        public readonly AlasanGagalMasuk $alasan,
        public readonly ?CarbonInterface $terkunciSampai = null,
    ) {
        parent::__construct($alasan->value);
    }
}
