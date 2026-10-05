<?php

declare(strict_types=1);

use App\ValueObject\Figure;

it('formats with a decimal comma and a space between thousands', function (): void {
    expect(Figure::format(1204.5, 1))->toBe('1 204,5')
        ->and(Figure::format(21.04, 2))->toBe('21,04')
        ->and(Figure::format(7, 0))->toBe('7')
        ->and(Figure::format(1_234_567, 0))->toBe('1 234 567');
});

it('signs a figure with plus or the true minus', function (): void {
    expect(Figure::signed(0.4, 1))->toBe('+0,4')
        ->and(Figure::signed(-1.2, 1))->toBe('−1,2')
        ->and(Figure::signed(-1204, 0))->toBe('−1 204')
        ->and(Figure::signed(1204, 0))->toBe('+1 204');
});

it('prints zero with a plus unless told to leave it bare', function (): void {
    expect(Figure::signed(0, 0))->toBe('+0')
        ->and(Figure::signed(0.0, 1))->toBe('+0,0')
        ->and(Figure::signed(0, 0, plusOnZero: false))->toBe('0');
});

it('follows the sign of the value, not of its rounding', function (): void {
    expect(Figure::signed(-0.04, 1))->toBe('−0,0');
});

it('marks only a negative figure with the true minus', function (): void {
    expect(Figure::withMinus(-60))->toBe('−60')
        ->and(Figure::withMinus(5))->toBe('5')
        ->and(Figure::withMinus(0))->toBe('0')
        ->and(Figure::withMinus(-1.25, 1))->toBe('−1,3');
});

it('prints a negative figure with the true minus, like the signed ones beside it', function (): void {
    expect(Figure::format(-3.24, 1))->toBe('−3,2')
        ->and(Figure::format(-1204, 0))->toBe('−1 204');
});

it('joins a range with a hyphen, and with words once an end is below zero', function (): void {
    expect(Figure::range(13.5, 15.1, 1))->toBe('13,5-15,1')
        ->and(Figure::range(-3.2, -1.0, 1))->toBe('−3,2 to −1,0')
        ->and(Figure::range(-0.5, 1.2, 1))->toBe('−0,5 to 1,2');
});

it('prints a figure to the hundredth, signed on request', function (): void {
    expect(Figure::twoDecimals(1020.1))->toBe('1 020,10')
        ->and(Figure::twoDecimals(-3.256))->toBe('−3,26')
        ->and(Figure::twoDecimals(0.03, signed: true))->toBe('+0,03')
        ->and(Figure::twoDecimals(-41.62, signed: true))->toBe('−41,62');
});
