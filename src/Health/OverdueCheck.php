<?php

declare(strict_types=1);

namespace Tmonier\SyliusPurchaseApprovalPlugin\Health;

use Psr\Clock\ClockInterface;
use Symfony\Component\Translation\TranslatableMessage;
use Tmonier\SyliusPlatformHealthPlugin\Health\HealthActionLink;
use Tmonier\SyliusPlatformHealthPlugin\Health\HealthCategory;
use Tmonier\SyliusPlatformHealthPlugin\Health\HealthCheckInterface;
use Tmonier\SyliusPlatformHealthPlugin\Health\HealthResult;
use Tmonier\SyliusPurchaseApprovalPlugin\Repository\OrderApprovalRepositoryInterface;

/**
 * Approvals still pending after "expire_after_days": the cron of tmonier:approval:expire no longer runs. One query on the
 * "state, created_at" index that returns the ids only, never more than 501 of them: no entity is loaded.
 * Loaded only when the health plugin is installed: this class implements its interface.
 */
final class OverdueCheck implements HealthCheckInterface
{
    private const COUNT_LIMIT = 500;

    public function __construct(
        private readonly OrderApprovalRepositoryInterface $approvals,
        private readonly ClockInterface $clock,
        private readonly int $expireAfterDays,
    ) {
    }

    public function identifier(): string
    {
        return 'purchase_approval.overdue';
    }

    public function category(): HealthCategory
    {
        return HealthCategory::Plugins;
    }

    public function run(): HealthResult
    {
        $before = $this->clock->now()->modify(sprintf('-%d days', $this->expireAfterDays));
        $overdue = \count($this->approvals->findPendingIdsRequestedBefore($before, self::COUNT_LIMIT + 1));
        if (0 === $overdue) {
            return HealthResult::ok(new TranslatableMessage('tmonier_purchase_approval.health.overdue.ok'));
        }

        return HealthResult::warning(
            new TranslatableMessage('tmonier_purchase_approval.health.overdue.found', [
                '%count%' => $overdue > self::COUNT_LIMIT ? self::COUNT_LIMIT . '+' : (string) $overdue,
                '%days%' => (string) $this->expireAfterDays,
            ]),
            action: new HealthActionLink('sylius_admin_order_index', ['criteria[tmonier_approval_state]' => 'pending']),
        );
    }
}
