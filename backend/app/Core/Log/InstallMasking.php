<?php

namespace App\Core\Log;

use Illuminate\Log\Logger;
use Monolog\Logger as Monolog;

/** `tap` channel log: memasang MaskingProcessor (didaftarkan ke semua channel di AppServiceProvider). */
final class InstallMasking
{
    public function __invoke(Logger $logger): void
    {
        $monolog = $logger->getLogger();
        if ($monolog instanceof Monolog) {
            $monolog->pushProcessor(new MaskingProcessor);
        }
    }
}
