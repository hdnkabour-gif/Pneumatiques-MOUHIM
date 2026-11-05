<?php

use App\Support\Decimal;
use App\Support\Money;

it('converts user decimals to integer cents without floats', function (string $input, int $cents) {
    expect(Decimal::toCents($input))->toBe($cents);
})->with([
    ['0', 0], ['12', 1200], ['12,5', 1250], ['12.50', 1250], ['1 234,56', 123456], ['0.01', 1], ['-3,10', -310],
]);

it('converts percentages to basis points', function () {
    expect(Decimal::percentToBasisPoints('20'))->toBe(2000)
        ->and(Decimal::percentToBasisPoints('0,25'))->toBe(25);
});

it('formats integers back to decimals', function () {
    expect(Decimal::fromCents(123456))->toBe('1234.56')
        ->and(Decimal::fromCents(5))->toBe('0.05')
        ->and(Decimal::fromCents(-310))->toBe('-3.10')
        ->and(Decimal::basisPointsToPercent(2000))->toBe('20.00');
});

it('rejects invalid or over-precise values', function (string $input) {
    Decimal::toCents($input);
})->throws(InvalidArgumentException::class)->with(['abc', '1,234', '12.345', '.', '1.2.3']);

it('formats amounts in MAD with a thousands separator', function () {
    expect(Money::format(120000))->toBe("1\u{202F}200,00 MAD")
        ->and(Money::format(123456789))->toBe("1\u{202F}234\u{202F}567,89 MAD")
        ->and(Money::format(-5))->toBe('-0,05 MAD')
        ->and(Money::format(null))->toBe('—');
});
