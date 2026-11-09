<?php

use App\Support\TyreIndex;

it('reads the load and speed indices of a tyre', function () {
    expect(TyreIndex::describe('91', 'V'))->toBe('615 kg · 240 km/h')
        ->and(TyreIndex::loadKg('102/100'))->toBe(850)
        ->and(TyreIndex::speedKmh('h'))->toBe(210)
        ->and(TyreIndex::describe('999', 'X'))->toBe('');
});
