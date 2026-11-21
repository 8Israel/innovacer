<?php

namespace App\Services;

/**
 * EAN-13 barcodes drawn as SVG, for product labels (RF-07). The internal codes the
 * system generates (prefix 200) and most manufacturer codes are EAN-13; a 12-digit
 * UPC-A is the same code with a leading zero.
 */
class Ean13
{
    private const L = ['0001101', '0011001', '0010011', '0111101', '0100011', '0110001', '0101111', '0111011', '0110111', '0001011'];

    private const G = ['0100111', '0110011', '0011011', '0100001', '0011101', '0111001', '0000101', '0010001', '0001001', '0010111'];

    private const R = ['1110010', '1100110', '1101100', '1000010', '1011100', '1001110', '1010000', '1000100', '1001000', '1110100'];

    /** Which of L or G encodes each digit of the left half, by the first digit. */
    private const PARITY = ['LLLLLL', 'LLGLGG', 'LLGGLG', 'LLGGGL', 'LGLLGG', 'LGGLLG', 'LGGGLL', 'LGLGLG', 'LGLGGL', 'LGGLGL'];

    /**
     * The code as 13 digits when it is a valid EAN-13 or UPC-A, otherwise null.
     */
    public function normalize(?string $code): ?string
    {
        $code = preg_replace('/\s+/', '', (string) $code);

        if (preg_match('/^\d{12}$/', $code) === 1) {
            $code = '0'.$code;
        }

        if (preg_match('/^\d{13}$/', $code) !== 1) {
            return null;
        }

        return $this->checkDigit(substr($code, 0, 12)) === (int) $code[12] ? $code : null;
    }

    public function checkDigit(string $twelveDigits): int
    {
        $sum = 0;

        foreach (str_split($twelveDigits) as $position => $digit) {
            $sum += (int) $digit * ($position % 2 === 0 ? 1 : 3);
        }

        return (10 - $sum % 10) % 10;
    }

    /**
     * The 95 modules of the code: 1 is a bar, 0 a space.
     */
    public function modules(string $code): string
    {
        $digits = array_map('intval', str_split($code));
        $parity = self::PARITY[$digits[0]];
        $modules = '101';

        for ($index = 1; $index <= 6; $index++) {
            $modules .= ($parity[$index - 1] === 'L' ? self::L : self::G)[$digits[$index]];
        }

        $modules .= '01010';

        for ($index = 7; $index <= 12; $index++) {
            $modules .= self::R[$digits[$index]];
        }

        return $modules.'101';
    }

    /**
     * An SVG of the bars only (with the quiet zones), stretched to fill its container,
     * so the code is as wide as the label allows; the digits go underneath as text.
     */
    public function svg(string $code): string
    {
        $modules = $this->modules($code);
        $quiet = 9;
        $width = strlen($modules) + $quiet * 2;
        $bars = '';

        foreach (str_split($modules) as $position => $module) {
            if ($module === '1') {
                $bars .= '<rect x="'.($quiet + $position).'" y="0" width="1" height="50"/>';
            }
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '.$width.' 50" preserveAspectRatio="none" role="img" aria-label="'.$code.'">'
            .'<g fill="#000" shape-rendering="crispEdges">'.$bars.'</g></svg>';
    }

    /**
     * The digits as printed under the bars: "7 501234 567890".
     */
    public function digits(string $code): string
    {
        return $code[0].' '.substr($code, 1, 6).' '.substr($code, 7, 6);
    }
}
