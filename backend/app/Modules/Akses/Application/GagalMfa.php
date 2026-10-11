<?php

namespace App\Modules\Akses\Application;

use Carbon\CarbonInterface;
use RuntimeException;

final class GagalMfa extends RuntimeException
{
    /** @param  CarbonInterface|null  $terkunciSampai  hanya diisi untuk AkunTerkunci */
    public function __construct(
        public readonly AlasanGagalMfa $alasan,
        public readonly ?CarbonInterface $terkunciSampai = null,
    ) {
        parent::__construct($alasan->value);
    }
}
