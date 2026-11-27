<?php

declare(strict_types=1);

namespace Tmonier\SyliusPurchaseApprovalPlugin\Health;

use Symfony\Component\Translation\TranslatableMessage;
use Tmonier\SyliusPlatformHealthPlugin\Health\HealthCategory;
use Tmonier\SyliusPlatformHealthPlugin\Health\HealthCheckInterface;
use Tmonier\SyliusPlatformHealthPlugin\Health\HealthResult;
use Tmonier\SyliusPurchaseApprovalPlugin\Repository\OrderApprovalRepositoryInterface;

/**
 * Approvals still pending on an order that is no longer open (cancelled by a path that bypassed the order workflow).
 * Loaded only when the health plugin is installed: this class implements its interface.
 */
final class ClosedOrdersCheck implements HealthCheckInterface
{
    public function __construct(
        private readonly OrderApprovalRepositoryInterface $approvals,
    ) {
    }

    public function identifier(): string
    {
        return 'purchase_approval.closed_orders';
    }

    public function category(): HealthCategory
    {
        return HealthCategory::Plugins;
    }

    public function run(): HealthResult
    {
        $this->approvals->findPendingIdsOfClosedOrders(1);

        return HealthResult::unknown(new TranslatableMessage('tmonier_purchase_approval.health.closed_orders.ok'));
    }
}
