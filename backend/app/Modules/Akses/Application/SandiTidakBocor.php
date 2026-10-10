<?php

namespace App\Modules\Akses\Application;

use App\Modules\Akses\Infrastructure\DaftarSandiBocor;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Menolak sandi yang ada di daftar sandi bocor lokal (PRD 14.2, ADR 0001). */
final class SandiTidakBocor implements ValidationRule
{
    public function __construct(private readonly DaftarSandiBocor $daftar) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && $this->daftar->memuat($value)) {
            $fail('Sandi ini termasuk sandi yang pernah bocor. Pilih sandi lain.');
        }
    }
}
