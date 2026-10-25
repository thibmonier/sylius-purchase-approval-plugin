<?php

declare(strict_types=1);

namespace Tmonier\SyliusPurchaseApprovalPlugin\Approval;

use Sylius\Component\Core\Model\OrderInterface;

/**
 * `gross`: the total of the order, tax included (the behaviour up to 1.3). `net`: the total without its tax, shipping included
 * (the "Total excluding tax" of a B2B cart), chosen with `amount_basis`.
 */
final readonly class ApprovalAmountResolver implements ApprovalAmountResolverInterface
{
    public const GROSS = 'gross';

    public const NET = 'net';

    public const BASES = [self::GROSS, self::NET];

    public function __construct(private string $basis = self::GROSS)
    {
        if (!in_array($basis, self::BASES, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown approval amount basis "%s", expected one of: %s.', $basis, implode(', ', self::BASES)));
        }
    }

    public function resolve(OrderInterface $order): int
    {
        return self::NET === $this->basis ? $order->getTotal() - $order->getTaxTotal() : $order->getTotal();
    }
}
