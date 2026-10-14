<?php

declare(strict_types=1);

namespace Tmonier\SyliusPurchaseApprovalPlugin\Bridge\AdminOrderCreation;

use Sylius\Component\Core\Model\OrderInterface;
use Tmonier\SyliusAdminOrderCreationPlugin\Mailer\PaymentLinkEmailManagerInterface;
use Tmonier\SyliusPurchaseApprovalPlugin\Repository\OrderApprovalRepositoryInterface;

/**
 * tmonier/sylius-admin-order-creation-plugin: no "pay your order" e-mail for a staff order
 * that waits for its approval (the pay page would refuse it). The link can be sent once the
 * order is approved.
 */
final readonly class ApprovalAwarePaymentLinkEmailManager implements PaymentLinkEmailManagerInterface
{
    public function __construct(
        private PaymentLinkEmailManagerInterface $decorated,
        private OrderApprovalRepositoryInterface $approvalRepository,
    ) {
    }

    public function canSend(OrderInterface $order): bool
    {
        $approval = $this->approvalRepository->findOneByOrder($order);

        return (!$approval instanceof \Tmonier\SyliusPurchaseApprovalPlugin\Entity\OrderApprovalInterface || $approval->isApproved()) && $this->decorated->canSend($order);
    }

    public function send(OrderInterface $order): void
    {
        if (!$this->canSend($order)) {
            throw new \LogicException(sprintf('Order %s waits for an approval: no payment link.', (string) $order->getNumber()));
        }

        $this->decorated->send($order);
    }
}
