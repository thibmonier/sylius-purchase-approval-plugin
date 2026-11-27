<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusPurchaseApprovalPlugin\Integration\Health;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Tests\Tmonier\SyliusPurchaseApprovalPlugin\Integration\IntegrationTestTrait;
use Tmonier\SyliusPlatformHealthPlugin\Health\HealthStatus;
use Tmonier\SyliusPurchaseApprovalPlugin\Entity\OrderApprovalInterface;
use Tmonier\SyliusPurchaseApprovalPlugin\Health\OverdueCheck;
use Tmonier\SyliusPurchaseApprovalPlugin\Repository\OrderApprovalRepositoryInterface;

/**
 * The borders of "overdue", against the real schema: an approval requested exactly "expire_after_days" ago is not overdue,
 * one second earlier it is.
 */
final class OverdueQueryTest extends KernelTestCase
{
    use IntegrationTestTrait;

    private const NOW = '2026-11-27 12:00:00';

    private const DAYS = 14;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection()->executeStatement('DELETE FROM tmonier_purchase_approval_order_approval');
    }

    public function testNothingIsOverdueWithoutPendingApproval(): void
    {
        self::assertSame(HealthStatus::Ok, $this->check()->run()->status);
    }

    public function testAnApprovalRequestedExactlyTheExpiryDelayAgoIsNotOverdue(): void
    {
        $this->pendingApprovalRequestedAt('2026-11-13 12:00:00');

        self::assertSame(HealthStatus::Ok, $this->check()->run()->status);
    }

    public function testAnApprovalRequestedOneSecondBeforeTheExpiryDelayIsOverdue(): void
    {
        $this->pendingApprovalRequestedAt('2026-11-13 11:59:59');

        self::assertSame(HealthStatus::Warning, $this->check()->run()->status);
    }

    public function testAnApprovalRequestedOneSecondAfterTheExpiryDelayIsNotOverdue(): void
    {
        $this->pendingApprovalRequestedAt('2026-11-13 12:00:01');

        self::assertSame(HealthStatus::Ok, $this->check()->run()->status);
    }

    public function testADecidedApprovalIsNeverOverdue(): void
    {
        $this->pendingApprovalRequestedAt('2026-10-01 12:00:00', OrderApprovalInterface::STATE_APPROVED);

        self::assertSame(HealthStatus::Ok, $this->check()->run()->status);
    }

    private function pendingApprovalRequestedAt(string $date, string $state = OrderApprovalInterface::STATE_PENDING): void
    {
        $order = $this->catalog()->orderAgainstLimit($this->catalog()->member($this->catalog()->company()), -1);
        $this->connection()->executeStatement(
            'UPDATE tmonier_purchase_approval_order_approval SET created_at = ?, state = ? WHERE order_id = ?',
            [$date, $state, $order->getId()],
        );
    }

    private function check(): OverdueCheck
    {
        $this->entityManager()->clear();

        return new OverdueCheck(
            $this->service('tmonier_purchase_approval.repository.order_approval', OrderApprovalRepositoryInterface::class),
            new MockClock(self::NOW),
            self::DAYS,
        );
    }
}
