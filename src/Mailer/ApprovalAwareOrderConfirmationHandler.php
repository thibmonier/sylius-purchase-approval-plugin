<?php

declare(strict_types=1);

namespace Tmonier\SyliusPurchaseApprovalPlugin\Mailer;

use Sylius\Bundle\ApiBundle\Command\Checkout\SendOrderConfirmation;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Repository\OrderRepositoryInterface;
use Tmonier\SyliusPurchaseApprovalPlugin\Repository\OrderApprovalRepositoryInterface;

/**
 * Decorates the API `SendOrderConfirmation` handler (possibly asynchronous): a message
 * consumed after the decision is a late duplicate. The buyer already has the confirmation of an
 * approved order (sent at the approval, `confirmationSentAt`), or the rejection / expiry /
 * cancellation instead. A pending order gets the acknowledgement (AcknowledgementEmailSender).
 */
final readonly class ApprovalAwareOrderConfirmationHandler
{
    /**
     * @param callable(SendOrderConfirmation): void        $decorated
     * @param OrderRepositoryInterface<OrderInterface> $orderRepository
     */
    public function __construct(
        private mixed $decorated,
        private OrderRepositoryInterface $orderRepository,
        private OrderApprovalRepositoryInterface $approvalRepository,
    ) {
    }

    public function __invoke(SendOrderConfirmation $message): void
    {
        $order = $this->orderRepository->findOneByTokenValue($message->orderToken);
        $approval = $order instanceof OrderInterface ? $this->approvalRepository->findOneByOrder($order) : null;
        if ($approval instanceof \Tmonier\SyliusPurchaseApprovalPlugin\Entity\OrderApprovalInterface && !$approval->isPending() && (!$approval->isApproved() || $approval->getConfirmationSentAt() instanceof \DateTimeImmutable)) {
            return;
        }

        ($this->decorated)($message);
    }
}
