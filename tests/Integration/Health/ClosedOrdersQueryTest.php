<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusPurchaseApprovalPlugin\Integration\Health;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Tests\Tmonier\SyliusPurchaseApprovalPlugin\Integration\IntegrationTestTrait;
use Tmonier\SyliusPlatformHealthPlugin\Health\HealthCheckReport;
use Tmonier\SyliusPlatformHealthPlugin\Health\HealthCheckRunner;
use Tmonier\SyliusPlatformHealthPlugin\Health\HealthStatus;
use Tmonier\SyliusPurchaseApprovalPlugin\Entity\OrderApprovalInterface;

/**
 * The probes as the health page runs them, through the runner of the health plugin, against the test application.
 */
final class ClosedOrdersQueryTest extends KernelTestCase
{
    use IntegrationTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection()->executeStatement('DELETE FROM tmonier_purchase_approval_order_approval');
    }

    public function testBothProbesAreRegisteredAndOkWhenNothingIsPending(): void
    {
        self::assertSame(
            ['purchase_approval.overdue' => HealthStatus::Ok, 'purchase_approval.closed_orders' => HealthStatus::Ok],
            $this->statuses(),
        );
    }

    public function testAPendingApprovalOfAnOpenOrderIsNotReported(): void
    {
        $this->catalog()->orderAgainstLimit($this->catalog()->member($this->catalog()->company()), -1);

        self::assertSame(HealthStatus::Ok, $this->statuses()['purchase_approval.closed_orders'] ?? null);
    }

    public function testAPendingApprovalOfACancelledOrderIsReported(): void
    {
        $order = $this->catalog()->orderAgainstLimit($this->catalog()->member($this->catalog()->company()), -1);
        $this->connection()->executeStatement("UPDATE sylius_order SET state = 'cancelled' WHERE id = ?", [$order->getId()]);

        self::assertSame(HealthStatus::Warning, $this->statuses()['purchase_approval.closed_orders'] ?? null);
    }

    public function testADecidedApprovalOfACancelledOrderIsNotReported(): void
    {
        $order = $this->catalog()->orderAgainstLimit($this->catalog()->member($this->catalog()->company()), -1);
        $this->connection()->executeStatement("UPDATE sylius_order SET state = 'cancelled' WHERE id = ?", [$order->getId()]);
        $this->connection()->executeStatement('UPDATE tmonier_purchase_approval_order_approval SET state = ? WHERE order_id = ?', [OrderApprovalInterface::STATE_CANCELLED, $order->getId()]);

        self::assertSame(HealthStatus::Ok, $this->statuses()['purchase_approval.closed_orders'] ?? null);
    }

    /**
     * @return array<string, HealthStatus>
     */
    private function statuses(): array
    {
        $this->entityManager()->clear();
        $runner = $this->service('tmonier_sylius_platform_health.runner', HealthCheckRunner::class);

        $statuses = [];
        foreach ($runner->run() as $report) {
            self::assertInstanceOf(HealthCheckReport::class, $report);
            if (str_starts_with($report->identifier, 'purchase_approval.')) {
                $statuses[$report->identifier] = $report->result->status;
            }
        }

        return $statuses;
    }
}
