<?php

declare(strict_types=1);

namespace Tmonier\SyliusPurchaseApprovalPlugin\Bridge\WebhookOutbox;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\UnitOfWork;
use Sylius\Component\Core\Model\OrderInterface;
use Tmonier\SyliusPurchaseApprovalPlugin\Entity\OrderApprovalInterface;
use Tmonier\SyliusWebhookOutboxPlugin\Capture\ChangeDetectorInterface;
use Tmonier\SyliusWebhookOutboxPlugin\Outbox\LazyValue;
use Tmonier\SyliusWebhookOutboxPlugin\Outbox\WebhookEvent;
use Tmonier\SyliusWebhookOutboxPlugin\Payload\OrderPayloadNormalizer;

/**
 * Approval events in the outbox, in the same transaction as the decision: requested (with the
 * order, inserted pending), approved, rejected, expired (state change). The payload is the core
 * order payload plus the approval state and dates; no comment (free text, may hold personal
 * data).
 */
final class ApprovalChangeDetector implements ChangeDetectorInterface
{
    private const TYPES = [
        OrderApprovalInterface::STATE_APPROVED => ApprovalEventTypeProvider::ORDER_APPROVED,
        OrderApprovalInterface::STATE_REJECTED => ApprovalEventTypeProvider::ORDER_APPROVAL_REJECTED,
        OrderApprovalInterface::STATE_EXPIRED => ApprovalEventTypeProvider::ORDER_APPROVAL_EXPIRED,
    ];

    public function __construct(private readonly OrderPayloadNormalizer $normalizer)
    {
    }

    public function detect(EntityManagerInterface $entityManager, UnitOfWork $unitOfWork): iterable
    {
        foreach ($unitOfWork->getScheduledEntityInsertions() as $approval) {
            if ($approval instanceof OrderApprovalInterface && $approval->isPending()) {
                yield from $this->event(ApprovalEventTypeProvider::ORDER_APPROVAL_REQUESTED, $approval);
            }
        }
        foreach ($unitOfWork->getScheduledEntityUpdates() as $approval) {
            if (!$approval instanceof OrderApprovalInterface) {
                continue;
            }
            $change = $unitOfWork->getEntityChangeSet($approval)['state'] ?? null;
            $type = null !== $change && is_string($change[1]) ? (self::TYPES[$change[1]] ?? null) : null;
            if (null !== $type && $change[0] !== $change[1]) {
                yield from $this->event($type, $approval);
            }
        }
    }

    /** @return iterable<WebhookEvent> */
    private function event(string $type, OrderApprovalInterface $approval): iterable
    {
        $order = $approval->getOrder();
        if (!$order instanceof OrderInterface) {
            return;
        }
        $customer = $order->getCustomer();

        yield new WebhookEvent(
            $type,
            $this->normalizer->normalize($order) + ['approval' => [
                'state' => $approval->getState(),
                'company' => $approval->getCompany()?->getCode(),
                'requestedAt' => $approval->getCreatedAt()->format(\DATE_ATOM),
                'decidedAt' => $approval->getDecidedAt()?->format(\DATE_ATOM),
                'decidedBy' => $approval->getDeciderType(),
            ]],
            $order->getChannel()?->getCode(),
            'order',
            LazyValue::idOf($order),
            null === $customer ? null : LazyValue::idOf($customer),
        );
    }
}
