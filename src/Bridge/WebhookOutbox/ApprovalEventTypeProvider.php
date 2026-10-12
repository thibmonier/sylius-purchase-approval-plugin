<?php

declare(strict_types=1);

namespace Tmonier\SyliusPurchaseApprovalPlugin\Bridge\WebhookOutbox;

use Tmonier\SyliusWebhookOutboxPlugin\EventType\EventType;
use Tmonier\SyliusWebhookOutboxPlugin\EventType\EventTypeProviderInterface;

final class ApprovalEventTypeProvider implements EventTypeProviderInterface
{
    public const ORDER_APPROVAL_REQUESTED = 'order.approval_requested';

    /** The business "order confirmed" event of an order that needed an approval. */
    public const ORDER_APPROVED = 'order.approved';

    public const ORDER_APPROVAL_REJECTED = 'order.approval_rejected';

    public const ORDER_APPROVAL_EXPIRED = 'order.approval_expired';

    public function getEventTypes(): iterable
    {
        yield new EventType(self::ORDER_APPROVAL_REQUESTED, label: 'tmonier_purchase_approval.webhook.order_approval_requested');
        yield new EventType(self::ORDER_APPROVED, label: 'tmonier_purchase_approval.webhook.order_approved');
        yield new EventType(self::ORDER_APPROVAL_REJECTED, label: 'tmonier_purchase_approval.webhook.order_approval_rejected');
        yield new EventType(self::ORDER_APPROVAL_EXPIRED, label: 'tmonier_purchase_approval.webhook.order_approval_expired');
    }
}
