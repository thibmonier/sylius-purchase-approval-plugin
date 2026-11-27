<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusPurchaseApprovalPlugin\Unit\Health;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Translation\TranslatableMessage;
use Tmonier\SyliusPlatformHealthPlugin\Health\HealthCategory;
use Tmonier\SyliusPlatformHealthPlugin\Health\HealthCheckRunner;
use Tmonier\SyliusPlatformHealthPlugin\Health\HealthStatus;
use Tmonier\SyliusPurchaseApprovalPlugin\Health\ClosedOrdersCheck;
use Tmonier\SyliusPurchaseApprovalPlugin\Repository\OrderApprovalRepositoryInterface;

final class ClosedOrdersCheckTest extends TestCase
{
    /**
     * One row per row of the table of cases of US-304.
     *
     * @return iterable<string, array{int, HealthStatus}>
     */
    public static function counts(): iterable
    {
        yield 'no pending approval on a closed order' => [0, HealthStatus::Ok];
        yield 'one pending approval on a closed order' => [1, HealthStatus::Warning];
        yield 'several' => [4, HealthStatus::Warning];
    }

    #[DataProvider('counts')]
    public function testTheStatusFollowsTheNumberOfApprovalsOfClosedOrders(int $count, HealthStatus $expected): void
    {
        self::assertSame($expected, $this->check($count)->run()->status);
    }

    public function testTheSummaryGivesTheCount(): void
    {
        $summary = $this->check(4)->run()->summary;

        self::assertInstanceOf(TranslatableMessage::class, $summary);
        self::assertSame('tmonier_purchase_approval.health.closed_orders.found', $summary->getMessage());
        self::assertSame(['%count%' => '4'], $summary->getParameters());
    }

    public function testAHealthySummaryCarriesNoParameter(): void
    {
        $summary = $this->check(0)->run()->summary;

        self::assertInstanceOf(TranslatableMessage::class, $summary);
        self::assertSame('tmonier_purchase_approval.health.closed_orders.ok', $summary->getMessage());
    }

    public function testFiveHundredApprovalsAreCountedExactly(): void
    {
        $summary = $this->check(500)->run()->summary;

        self::assertInstanceOf(TranslatableMessage::class, $summary);
        self::assertSame('500', $summary->getParameters()['%count%']);
    }

    public function testBeyondFiveHundredTheSummarySaysMoreThanFiveHundred(): void
    {
        $summary = $this->check(501)->run()->summary;

        self::assertInstanceOf(TranslatableMessage::class, $summary);
        self::assertSame('500+', $summary->getParameters()['%count%']);
    }

    public function testTheRepositoryIsAskedForNoMoreThanFiveHundredAndOneApprovals(): void
    {
        $approvals = $this->createMock(OrderApprovalRepositoryInterface::class);
        $approvals->expects(self::once())->method('findPendingIdsOfClosedOrders')->with(501)->willReturn([]);

        (new ClosedOrdersCheck($approvals))->run();
    }

    public function testAnApprovalOfAClosedOrderLinksToTheOrdersGridFilteredOnPendingApprovals(): void
    {
        $action = $this->check(1)->run()->action;

        self::assertNotNull($action);
        self::assertSame('sylius_admin_order_index', $action->route);
        self::assertSame(['criteria[tmonier_approval_state]' => 'pending'], $action->parameters);
    }

    public function testTheProbeBelongsToThePluginsCategoryWithAFixedIdentifier(): void
    {
        $check = $this->check(0);

        self::assertSame('purchase_approval.closed_orders', $check->identifier());
        self::assertSame(HealthCategory::Plugins, $check->category());
    }

    public function testAFailingQueryMakesTheProbeUnknownWithoutTheCause(): void
    {
        $approvals = $this->createStub(OrderApprovalRepositoryInterface::class);
        $approvals->method('findPendingIdsOfClosedOrders')->willThrowException(new \RuntimeException('Connection refused to db.internal:3306'));

        $reports = (new HealthCheckRunner([new ClosedOrdersCheck($approvals)], new NullLogger()))->run();

        self::assertSame(HealthStatus::Unknown, $reports[0]->result->status);
        self::assertInstanceOf(TranslatableMessage::class, $reports[0]->result->summary);
        self::assertSame('tmonier_platform_health.result.check_failed', $reports[0]->result->summary->getMessage());
    }

    private function check(int $count): ClosedOrdersCheck
    {
        $approvals = $this->createStub(OrderApprovalRepositoryInterface::class);
        $approvals->method('findPendingIdsOfClosedOrders')->willReturn($count > 0 ? range(1, $count) : []);

        return new ClosedOrdersCheck($approvals);
    }
}
