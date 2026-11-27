<?php

declare(strict_types=1);

namespace Tmonier\SyliusPurchaseApprovalPlugin\Health;

use Psr\Clock\ClockInterface;
use Symfony\Component\Translation\TranslatableMessage;
use Tmonier\SyliusPlatformHealthPlugin\Health\HealthCategory;
use Tmonier\SyliusPlatformHealthPlugin\Health\HealthCheckInterface;
use Tmonier\SyliusPlatformHealthPlugin\Health\HealthResult;
use Tmonier\SyliusPurchaseApprovalPlugin\Repository\OrderApprovalRepositoryInterface;

/**
 * Approvals still pending after "expire_after_days": the cron of tmonier:approval:expire no longer runs.
 * Loaded only when the health plugin is installed: this class implements its interface.
 */
final class OverdueCheck implements HealthCheckInterface
{
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
        $this->approvals->findPendingIdsRequestedBefore($this->clock->now()->modify(sprintf('-%d days', $this->expireAfterDays)), 1);

        return HealthResult::unknown(new TranslatableMessage('tmonier_purchase_approval.health.overdue.ok'));
    }
}
