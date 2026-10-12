<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusPurchaseApprovalPlugin\Unit\Approval;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tmonier\SyliusPurchaseApprovalPlugin\Approval\LimitAmount;

final class LimitAmountTest extends TestCase
{
    /** @return iterable<string, array{?string, int|false|null}> */
    public static function amounts(): iterable
    {
        yield 'empty' => ['', null];
        yield 'null' => [null, null];
        yield 'blank' => ['  ', null];
        yield 'units' => ['1500', 150000];
        yield 'cents' => ['1500.5', 150050];
        yield 'two decimals' => ['0.07', 7];
        yield 'decimal comma and spaces' => ['1 500,25', 150025];
        yield 'zero' => ['0', 0];
        yield 'negative' => ['-5', false];
        yield 'three decimals' => ['1.234', false];
        yield 'text' => ['abc', false];
        yield 'too large' => ['10000001', false];
    }

    #[DataProvider('amounts')]
    public function testParse(?string $input, int|false|null $expected): void
    {
        self::assertSame($expected, LimitAmount::parse($input));
    }

    public function testFormat(): void
    {
        self::assertSame('', LimitAmount::format(null));
        self::assertSame('1500.05', LimitAmount::format(150005));
        self::assertSame('0.00', LimitAmount::format(0));
    }
}
