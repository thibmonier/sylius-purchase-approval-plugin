<?php

declare(strict_types=1);

namespace Tmonier\SyliusPurchaseApprovalPlugin\OrderHold;

use Sylius\Component\Core\Model\OrderInterface;
use Tmonier\SyliusOrderHoldPlugin\PaymentEntry\PaymentEntryBlock;
use Tmonier\SyliusOrderHoldPlugin\PaymentEntry\PaymentEntryBlockerInterface;
use Tmonier\SyliusOrderHoldPlugin\UnpaidOrder\UnpaidOrderCancellationExclusionInterface;
use Tmonier\SyliusOrderHoldPlugin\UnpaidOrder\UnpaidOrderHold;
use Tmonier\SyliusPurchaseApprovalPlugin\Entity\OrderApprovalInterface;
use Tmonier\SyliusPurchaseApprovalPlugin\Repository\OrderApprovalRepositoryInterface;

/**
 * Order-hold kernel contracts:
 * - no payment before the approval (pay page, Payum capture routes, shop API payment
 *   requests), with the reason as a flash / API message;
 * - `sylius:cancel-unpaid-orders` leaves pending orders alone and, once approved, lets the
 *   unpaid-orders expiration period run from the approval date, not from the checkout.
 */
final class ApprovalHold implements PaymentEntryBlockerInterface, UnpaidOrderCancellationExclusionInterface
{
    public const SOURCE = 'tmonier_purchase_approval';

    public function __construct(private readonly OrderApprovalRepositoryInterface $approvalRepository)
    {
    }

    public function getBlock(OrderInterface $order): ?PaymentEntryBlock
    {
        $approval = $this->approvalRepository->findOneByOrder($order);
        if (null === $approval || $approval->isApproved()) {
            return null;
        }

        return new PaymentEntryBlock(
            $approval->isPending() ? 'tmonier_purchase_approval.payment_entry.awaiting_approval' : 'tmonier_purchase_approval.payment_entry.not_approved',
            ['%number%' => (string) $order->getNumber()],
        );
    }

    public function getHold(OrderInterface $order): ?UnpaidOrderHold
    {
        $approval = $this->approvalRepository->findOneByOrder($order);

        return match (true) {
            null === $approval => null,
            $approval->isPending() => UnpaidOrderHold::indefinitely(self::SOURCE),
            OrderApprovalInterface::STATE_APPROVED === $approval->getState() && null !== $approval->getDecidedAt() => UnpaidOrderHold::since($approval->getDecidedAt(), self::SOURCE),
            default => null,
        };
    }
}
