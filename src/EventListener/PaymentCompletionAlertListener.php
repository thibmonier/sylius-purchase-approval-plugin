<?php

declare(strict_types=1);

namespace Tmonier\SyliusPurchaseApprovalPlugin\EventListener;

use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Symfony\Component\Workflow\Event\CompletedEvent;
use Tmonier\SyliusPurchaseApprovalPlugin\Repository\OrderApprovalRepositoryInterface;

/**
 * Alert only: a payment completed while its order is not approved (a gateway that captures
 * during the checkout, an administrator marking it paid). A guard on the payment graph would
 * not stop the payment service provider from capturing the money (Payum skips the transition
 * silently), so the entry points are blocked instead (order-hold kernel) and this is logged
 * for the merchant to refund or approve.
 */
final readonly class PaymentCompletionAlertListener
{
    public function __construct(
        private OrderApprovalRepositoryInterface $approvalRepository,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(CompletedEvent $event): void
    {
        $payment = $event->getSubject();
        if (!$payment instanceof PaymentInterface) {
            return;
        }
        $order = $payment->getOrder();
        if (!$order instanceof OrderInterface) {
            return;
        }
        $approval = $this->approvalRepository->findOneByOrder($order);
        if ($approval instanceof \Tmonier\SyliusPurchaseApprovalPlugin\Entity\OrderApprovalInterface && !$approval->isApproved()) {
            $this->logger->warning('Payment {payment} of order {order} completed while the order is {state}: refund it or approve the order.', [
                'payment' => $payment->getId(),
                'order' => $order->getNumber(),
                'state' => $approval->getState(),
            ]);
        }
    }
}
