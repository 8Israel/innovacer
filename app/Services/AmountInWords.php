<?php

namespace App\Services;

/**
 * Writes an amount in Mexican pesos in words, as sale notes show it:
 * 8577.00 → "Ocho mil quinientos setenta y siete pesos 00/100 M.N."
 */
class AmountInWords
{
    private const UNITS = [
        'cero', 'uno', 'dos', 'tres', 'cuatro', 'cinco', 'seis', 'siete', 'ocho', 'nueve',
        'diez', 'once', 'doce', 'trece', 'catorce', 'quince', 'dieciséis', 'diecisiete', 'dieciocho', 'diecinueve',
        'veinte', 'veintiuno', 'veintidós', 'veintitrés', 'veinticuatro', 'veinticinco', 'veintiséis', 'veintisiete', 'veintiocho', 'veintinueve',
    ];

    private const TENS = [3 => 'treinta', 4 => 'cuarenta', 5 => 'cincuenta', 6 => 'sesenta', 7 => 'setenta', 8 => 'ochenta', 9 => 'noventa'];

    private const HUNDREDS = [
        1 => 'ciento', 2 => 'doscientos', 3 => 'trescientos', 4 => 'cuatrocientos', 5 => 'quinientos',
        6 => 'seiscientos', 7 => 'setecientos', 8 => 'ochocientos', 9 => 'novecientos',
    ];

    public function pesos(string $amount): string
    {
        [$whole, $cents] = explode('.', bcadd($amount, '0', 2));
        $pesos = (int) $whole;

        $words = match (true) {
            $pesos === 1 => 'un peso',
            $pesos >= 1_000_000 && $pesos % 1_000_000 === 0 => $this->words($pesos, apocope: true).' de pesos',
            default => $this->words($pesos, apocope: true).' pesos',
        };

        return mb_strtoupper(mb_substr($words, 0, 1)).mb_substr($words, 1)." {$cents}/100 M.N.";
    }

    /**
     * A whole number in words. With apocope, a trailing "uno" becomes "un"
     * (veintiún pesos, treinta y un mil).
     */
    private function words(int $number, bool $apocope = false): string
    {
        if ($number >= 1_000_000) {
            $millions = intdiv($number, 1_000_000);
            $rest = $number % 1_000_000;
            $head = $millions === 1 ? 'un millón' : $this->words($millions, apocope: true).' millones';

            return $rest === 0 ? $head : $head.' '.$this->words($rest, $apocope);
        }

        if ($number >= 1000) {
            $thousands = intdiv($number, 1000);
            $rest = $number % 1000;
            $head = $thousands === 1 ? 'mil' : $this->words($thousands, apocope: true).' mil';

            return $rest === 0 ? $head : $head.' '.$this->words($rest, $apocope);
        }

        if ($number >= 100) {
            $rest = $number % 100;

            if ($number === 100) {
                return 'cien';
            }

            $head = self::HUNDREDS[intdiv($number, 100)];

            return $rest === 0 ? $head : $head.' '.$this->words($rest, $apocope);
        }

        $words = $number < 30
            ? self::UNITS[$number]
            : self::TENS[intdiv($number, 10)].($number % 10 === 0 ? '' : ' y '.self::UNITS[$number % 10]);

        if (! $apocope) {
            return $words;
        }

        return match (true) {
            $words === 'veintiuno' => 'veintiún',
            str_ends_with($words, 'uno') => substr($words, 0, -1),
            default => $words,
        };
    }
}
