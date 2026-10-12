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
 * once the order is approved, and only once: an asynchronous confirmation consumed after the
 * approval (worker late or down) is dropped, the buyer already has the confirmation.
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
        if (CoreEmails::ORDER_CONFIRMATION === $code && $order instanceof OrderInterface) {
            $approval = $this->approvalRepository->findOneByOrder($order);
            if (null !== $approval) {
                if ($approval->isPending()) {
                    $code = Emails::ACKNOWLEDGEMENT;
                    $data['approval'] = $approval;
                } elseif (!$approval->isApproved() || null !== $approval->getConfirmationSentAt()) {
                    // Rejected, expired or cancelled (the buyer gets that e-mail instead), or
                    // already confirmed at the approval.
                    return;
                } else {
                    $this->approvalRepository->markConfirmationSent($approval, new \DateTimeImmutable());
                }
            }
        }

        $this->decorated->send($code, $recipients, $data, $attachments, $replyTo, ...$extra);
    }
}
