<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusPurchaseApprovalPlugin\Unit\Approval;

use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\OrderInterface;
use Tmonier\SyliusPurchaseApprovalPlugin\Approval\ApprovalAmountResolver;
use Tmonier\SyliusPurchaseApprovalPlugin\Approval\ApprovalAmountResolverInterface;

final class ApprovalAmountResolverTest extends TestCase
{
    public function testTheGrossBasisIsTheTotalOfTheOrderTaxIncluded(): void
    {
        $resolver = new ApprovalAmountResolver(ApprovalAmountResolver::GROSS);

        self::assertSame(60000, $resolver->resolve($this->order(60000, 10000)));
    }

    public function testTheNetBasisIsTheTotalOfTheOrderWithoutItsTax(): void
    {
        $resolver = new ApprovalAmountResolver(ApprovalAmountResolver::NET);

        self::assertSame(50000, $resolver->resolve($this->order(60000, 10000)));
    }

    public function testBothBasesAgreeOnAnOrderWithoutTax(): void
    {
        $order = $this->order(60000, 0);

        self::assertSame(60000, (new ApprovalAmountResolver(ApprovalAmountResolver::GROSS))->resolve($order));
        self::assertSame(60000, (new ApprovalAmountResolver(ApprovalAmountResolver::NET))->resolve($order));
    }

    public function testAnUnknownBasisIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ApprovalAmountResolver('half');
    }

    public function testTheResolverIsReplaceableThroughItsInterface(): void
    {
        self::assertInstanceOf(ApprovalAmountResolverInterface::class, new ApprovalAmountResolver(ApprovalAmountResolver::NET));
    }

    private function order(int $total, int $tax): OrderInterface
    {
        $order = $this->createStub(OrderInterface::class);
        $order->method('getTotal')->willReturn($total);
        $order->method('getTaxTotal')->willReturn($tax);

        return $order;
    }
}
