<?php

namespace App\Concerns;

trait RoundsDecimals
{
    /**
     * Round a bcmath decimal string half up to the given scale (bcmath alone truncates).
     */
    protected function roundHalfUp(string $value, int $scale): string
    {
        $half = '0.'.str_repeat('0', $scale).'5';

        return bccomp($value, '0', $scale + 4) >= 0
            ? bcadd($value, $half, $scale)
            : bcsub($value, $half, $scale);
    }

    /**
     * Turn a database aggregate (string, int, float or null) into a decimal string.
     */
    protected function decimal(mixed $value, int $scale): string
    {
        if ($value === null) {
            return bcadd('0', '0', $scale);
        }

        return is_float($value) ? sprintf("%.{$scale}f", $value) : bcadd((string) $value, '0', $scale);
    }
}
