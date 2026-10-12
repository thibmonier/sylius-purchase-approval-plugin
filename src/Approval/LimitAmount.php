<?php

declare(strict_types=1);

namespace Tmonier\SyliusPurchaseApprovalPlugin\Approval;

/** Limits are typed in major units ("1500" or "1500.50") and stored in minor units. */
final class LimitAmount
{
    /** Ten million in major units: well above any sensible limit, below the integer column range. */
    public const MAX = 1_000_000_000;

    /**
     * @return int|false|null minor units, null for an empty value (no limit), false when the
     *                        value is not a positive amount with at most two decimals
     */
    public static function parse(?string $value): int|false|null
    {
        $value = str_replace([' ', "\u{a0}", "\u{202f}"], '', trim((string) $value));
        if ('' === $value) {
            return null;
        }
        $value = str_replace(',', '.', $value);
        if (1 !== preg_match('/^\d{1,9}(\.\d{1,2})?$/', $value)) {
            return false;
        }
        [$units, $cents] = explode('.', $value . '.');
        $amount = (int) $units * 100 + (int) str_pad($cents, 2, '0');

        return $amount > self::MAX ? false : $amount;
    }

    public static function format(?int $amount): string
    {
        return null === $amount ? '' : sprintf('%d.%02d', intdiv($amount, 100), $amount % 100);
    }
}
