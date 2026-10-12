<?php

declare(strict_types=1);

namespace Tmonier\SyliusPurchaseApprovalPlugin\Mailer;

use Sylius\Bundle\CoreBundle\Mailer\Emails as CoreEmails;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Mailer\Sender\SenderInterface;
use Tmonier\SyliusPurchaseApprovalPlugin\Repository\OrderApprovalRepositoryInterface;

/**
 * Decorates the Sylius e-mail sender: while an order waits for its approval, the order
 * confirmation becomes an acknowledgement of receipt ("awaiting approval"). Both confirmation
 * paths go through it: the shop listener of `sylius.order.post_complete` and the API
 * `SendOrderConfirmation` message handler (possibly asynchronous). The confirmation is sent
 * once the order is approved (ApprovalEmailListener). Late asynchronous confirmations are
 * filtered on the Messenger path only (ApprovalAwareOrderConfirmationHandler): a confirmation
 * sent on purpose (code, admin tools) always goes out.
 */
final class AcknowledgementEmailSender implements SenderInterface
{
    public function __construct(
        private readonly SenderInterface $decorated,
        private readonly OrderApprovalRepositoryInterface $approvalRepository,
    ) {
    }

    public function send(string $code, array $recipients, array $data = [], array $attachments = [], array $replyTo = []): void
    {
        // Carbon copy arguments of SyliusMailerBundle 1.8+ (not in the interface yet).
        $extra = array_slice(func_get_args(), 5);
        $order = $data['order'] ?? null;
        $approval = CoreEmails::ORDER_CONFIRMATION === $code && $order instanceof OrderInterface ? $this->approvalRepository->findOneByOrder($order) : null;
        if (null !== $approval && $approval->isPending()) {
            $code = Emails::ACKNOWLEDGEMENT;
            $data['approval'] = $approval;
        }

        $this->decorated->send($code, $recipients, $data, $attachments, $replyTo, ...$extra);

        // Recorded once sent: a failed send leaves a late asynchronous confirmation free to go.
        if (null !== $approval && $approval->isApproved() && null === $approval->getConfirmationSentAt()) {
            $this->approvalRepository->markConfirmationSent($approval, new \DateTimeImmutable());
        }
    }
}
