<?php

declare(strict_types=1);

namespace Tmonier\SyliusPurchaseApprovalPlugin\Approval;

use Psr\Clock\ClockInterface;
use Tmonier\SyliusCompanyAccountsPlugin\Entity\CompanyMemberInterface;
use Tmonier\SyliusPurchaseApprovalPlugin\Entity\OrderApprovalInterface;

/**
 * The header panel: the oldest pending approvals the member may decide (they expire first), with
 * how long they have waited and, when the expiry is close, in how many days it comes.
 */
final readonly class PendingApprovalPreviewer
{
    public const LIMIT = 5;

    /** The "expires in N days" mention starts this many days before the expiry. */
    public const EXPIRY_ALERT_DAYS = 3;

    public function __construct(
        private DecidableApprovalCounter $counter,
        private ClockInterface $clock,
        private int $expireAfterDays,
    ) {
    }

    public function preview(CompanyMemberInterface $member): PendingApprovalPreview
    {
        $total = $this->counter->count($member);
        if (null === $total || 0 === $total) {
            return new PendingApprovalPreview([], 0);
        }
        $approvals = $this->counter->oldest($member, self::LIMIT);
        $now = $this->clock->now();

        return new PendingApprovalPreview(
            array_map(fn (OrderApprovalInterface $approval): PendingApprovalPreviewItem => $this->item($approval, $now), $approvals),
            $total,
        );
    }

    private function item(OrderApprovalInterface $approval, \DateTimeImmutable $now): PendingApprovalPreviewItem
    {
        $order = $approval->getOrder();
        $requestedAt = $approval->getCreatedAt();
        $age = max(0, (int) $requestedAt->diff($now)->days);
        $daysLeft = max(0, $this->expireAfterDays - $age);

        return new PendingApprovalPreviewItem(
            (string) $order?->getNumber(),
            trim((string) $order?->getCustomer()?->getFullName()),
            $approval->getTotal(),
            $approval->getCurrencyCode(),
            $age,
            $daysLeft <= self::EXPIRY_ALERT_DAYS ? $daysLeft : null,
        );
    }
}
