<?php

namespace App\Modules\Akses\Application;

use RuntimeException;

final class GagalMasuk extends RuntimeException
{
    public function __construct(public readonly AlasanGagalMasuk $alasan)
    {
        parent::__construct($alasan->value);
    }
}
