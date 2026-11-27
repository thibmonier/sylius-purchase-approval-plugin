<?php

declare(strict_types=1);

namespace Tmonier\SyliusPurchaseApprovalPlugin\Health;

use Symfony\Component\Translation\TranslatableMessage;
use Tmonier\SyliusPlatformHealthPlugin\Health\HealthActionLink;
use Tmonier\SyliusPlatformHealthPlugin\Health\HealthCategory;
use Tmonier\SyliusPlatformHealthPlugin\Health\HealthCheckInterface;
use Tmonier\SyliusPlatformHealthPlugin\Health\HealthResult;
use Tmonier\SyliusPurchaseApprovalPlugin\Repository\OrderApprovalRepositoryInterface;

/**
 * Approvals still pending on an order that is no longer open (cancelled by a path that bypassed the order workflow).
 * The ids only, never more than 501 of them: no entity is loaded.
 * Loaded only when the health plugin is installed: this class implements its interface.
 */
final class ClosedOrdersCheck implements HealthCheckInterface
{
    private const COUNT_LIMIT = 500;

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
        $count = \count($this->approvals->findPendingIdsOfClosedOrders(self::COUNT_LIMIT + 1));
        if (0 === $count) {
            return HealthResult::ok(new TranslatableMessage('tmonier_purchase_approval.health.closed_orders.ok'));
        }

        return HealthResult::warning(
            new TranslatableMessage('tmonier_purchase_approval.health.closed_orders.found', [
                '%count%' => $count > self::COUNT_LIMIT ? self::COUNT_LIMIT . '+' : (string) $count,
            ]),
            action: new HealthActionLink('sylius_admin_order_index', ['criteria[tmonier_approval_state]' => 'pending']),
        );
    }
}
