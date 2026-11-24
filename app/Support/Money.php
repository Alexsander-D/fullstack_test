<?php

namespace App\Support;

use InvalidArgumentException;

class Money
{
    public static function toCents(string $amount): int
    {
        if (! preg_match('/^\d+(?:\.\d{1,2})?$/', $amount)) {
            throw new InvalidArgumentException('O valor deve ter no máximo duas casas decimais.');
        }

        [$units, $cents] = array_pad(explode('.', $amount, 2), 2, '0');

        return ((int) $units * 100) + (int) str_pad($cents, 2, '0');
    }

    public static function fromCents(int $cents): string
    {
        // Somar centavos inteiros evita diferenças de arredondamento nos valores da carteira.
        $negative = $cents < 0;
        $absolute = abs($cents);

        return ($negative ? '-' : '').intdiv($absolute, 100).'.'.str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
    }
}
