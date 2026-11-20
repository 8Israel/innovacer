<?php

use App\Services\AmountInWords;

test('it writes peso amounts in words', function (string $amount, string $words) {
    expect((new AmountInWords)->pesos($amount))->toBe($words);
})->with([
    'design example' => ['8577.00', 'Ocho mil quinientos setenta y siete pesos 00/100 M.N.'],
    'one peso' => ['1', 'Un peso 00/100 M.N.'],
    'apocope before pesos' => ['21.50', 'Veintiún pesos 50/100 M.N.'],
    'exact hundred' => ['100', 'Cien pesos 00/100 M.N.'],
    'hundred and something' => ['166.50', 'Ciento sesenta y seis pesos 50/100 M.N.'],
    'apocope before thousand' => ['31000', 'Treinta y un mil pesos 00/100 M.N.'],
    'exact million' => ['1000000', 'Un millón de pesos 00/100 M.N.'],
    'millions and more' => ['2101115.10', 'Dos millones ciento un mil ciento quince pesos 10/100 M.N.'],
    'zero' => ['0', 'Cero pesos 00/100 M.N.'],
]);
