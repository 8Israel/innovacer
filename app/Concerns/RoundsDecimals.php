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
}
