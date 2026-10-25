<?php

declare(strict_types=1);

namespace Tmonier\SyliusPurchaseApprovalPlugin\Approval;

use Sylius\Component\Core\Model\OrderInterface;

/**
 * The amount of an order that is compared to the order limit of its buyer, and frozen on the approval request
 * (which the approvers then compare to their approval limit). Minor units of the currency of the order.
 */
interface ApprovalAmountResolverInterface
{
    public function resolve(OrderInterface $order): int;
}
