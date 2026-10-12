<?php

declare(strict_types=1);

namespace Tmonier\SyliusPurchaseApprovalPlugin\Event;

use Symfony\Contracts\EventDispatcher\Event;
use Tmonier\SyliusPurchaseApprovalPlugin\Entity\OrderApprovalInterface;

/** Dispatched after the flush that requested or decided an approval. */
final class OrderApprovalEvent extends Event
{
    /** A pending approval was created with the order (checkout completed above the buyer's limit). */
    public const REQUESTED = 'tmonier_purchase_approval.order_approval.requested';

    public const APPROVED = 'tmonier_purchase_approval.order_approval.approved';

    /** Rejected by an approver or an administrator: the order is cancelled. */
    public const REJECTED = 'tmonier_purchase_approval.order_approval.rejected';

    /** Expired by tmonier:approval:expire: the order is cancelled. */
    public const EXPIRED = 'tmonier_purchase_approval.order_approval.expired';

    public const ALL = [self::REQUESTED, self::APPROVED, self::REJECTED, self::EXPIRED];

    public function __construct(private readonly OrderApprovalInterface $approval)
    {
    }

    public function getApproval(): OrderApprovalInterface
    {
        return $this->approval;
    }
}
