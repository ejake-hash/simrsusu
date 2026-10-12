<?php

namespace App\Modules\Ttd\Domain;

/** Aturan PIN T1 (PRD 7.57.2): 6 digit, bukan berurutan naik/turun, bukan berulang (111111, 121212, 123123). */
final class AturanPin
{
    public static function lemah(string $pin): bool
    {
        if (preg_match('/^[0-9]{6}$/', $pin) !== 1) {
            return true;
        }
        $langkah = [];
        for ($i = 1; $i < 6; $i++) {
            $langkah[] = (int) $pin[$i] - (int) $pin[$i - 1];
        }
        $berurutan = count(array_unique($langkah)) === 1 && in_array($langkah[0], [1, -1], true);
        $berulang = str_repeat(substr($pin, 0, 1), 6) === $pin || str_repeat(substr($pin, 0, 2), 3) === $pin || str_repeat(substr($pin, 0, 3), 2) === $pin;

        return $berurutan || $berulang;
    }
}
