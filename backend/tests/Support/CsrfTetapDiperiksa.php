<?php

namespace Tests\Support;

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;

/** Laravel melewati CSRF saat tes; versi ini tetap memeriksa agar alur SPA teruji apa adanya. */
final class CsrfTetapDiperiksa extends ValidateCsrfToken
{
    protected function runningUnitTests(): bool
    {
        return false;
    }
}
