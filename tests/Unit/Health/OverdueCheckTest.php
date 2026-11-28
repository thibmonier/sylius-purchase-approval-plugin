<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusPurchaseApprovalPlugin\Unit\Health;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Translation\TranslatableMessage;
use Tmonier\SyliusPlatformHealthPlugin\Health\HealthCategory;
use Tmonier\SyliusPlatformHealthPlugin\Health\HealthCheckRunner;
use Tmonier\SyliusPlatformHealthPlugin\Health\HealthStatus;
use Tmonier\SyliusPurchaseApprovalPlugin\Health\OverdueCheck;
use Tmonier\SyliusPurchaseApprovalPlugin\Repository\OrderApprovalRepositoryInterface;

final class OverdueCheckTest extends TestCase
{
    private const NOW = '2026-11-27 12:00:00';

    /**
     * One row per row of the table of cases of US-304 that the probe decides: the border itself is the date given to the
     * repository ("requested before"), proven against the database by Integration\Health\OverdueQueryTest.
     *
     * @return iterable<string, array{int, HealthStatus}>
     */
    public static function overdueCounts(): iterable
    {
        yield 'no approval is overdue' => [0, HealthStatus::Ok];
        yield 'one approval is overdue' => [1, HealthStatus::Warning];
        yield 'several approvals are overdue' => [7, HealthStatus::Warning];
    }

    #[DataProvider('overdueCounts')]
    public function testTheStatusFollowsTheNumberOfOverdueApprovals(int $overdue, HealthStatus $expected): void
    {
        self::assertSame($expected, $this->check($overdue)->run()->status);
    }

    public function testTheRepositoryIsAskedForTheApprovalsRequestedBeforeTheExpiryDate(): void
    {
        $clock = new MockClock(self::NOW);
        $approvals = $this->createMock(OrderApprovalRepositoryInterface::class);
        $approvals->expects(self::once())
            ->method('findPendingIdsRequestedBefore')
            ->with(self::equalTo($clock->now()->modify('-14 days')), 501)
            ->willReturn([])
        ;

        (new OverdueCheck($approvals, $clock, 14))->run();
    }

    public function testTheExpiryDelayIsTheConfiguredOne(): void
    {
        $clock = new MockClock(self::NOW);
        $approvals = $this->createMock(OrderApprovalRepositoryInterface::class);
        $approvals->expects(self::once())
            ->method('findPendingIdsRequestedBefore')
            ->with(self::equalTo($clock->now()->modify('-3 days')), 501)
            ->willReturn([])
        ;

        (new OverdueCheck($approvals, $clock, 3))->run();
    }

    public function testTheSummaryGivesTheCountAndTheDelay(): void
    {
        $summary = $this->check(3)->run()->summary;

        self::assertInstanceOf(TranslatableMessage::class, $summary);
        self::assertSame('tmonier_purchase_approval.health.overdue.found', $summary->getMessage());
        self::assertSame(['%count%' => '3', '%days%' => '14'], $summary->getParameters());
    }

    public function testAHealthySummaryCarriesNoParameter(): void
    {
        $summary = $this->check(0)->run()->summary;

        self::assertInstanceOf(TranslatableMessage::class, $summary);
        self::assertSame('tmonier_purchase_approval.health.overdue.ok', $summary->getMessage());
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

    public function testAnOverdueApprovalLinksToTheOrdersGridFilteredOnPendingApprovals(): void
    {
        $action = $this->check(1)->run()->action;

        self::assertNotNull($action);
        self::assertSame('sylius_admin_order_index', $action->route);
        self::assertSame(['criteria[tmonier_approval_state]' => 'pending'], $action->parameters);
    }

    public function testTheProbeBelongsToThePluginsCategoryWithAFixedIdentifier(): void
    {
        $check = $this->check(0);

        self::assertSame('purchase_approval.overdue', $check->identifier());
        self::assertSame(HealthCategory::Plugins, $check->category());
    }

    public function testAFailingQueryMakesTheProbeUnknownWithoutTheCause(): void
    {
        $approvals = $this->createStub(OrderApprovalRepositoryInterface::class);
        $approvals->method('findPendingIdsRequestedBefore')->willThrowException(new \RuntimeException('SQLSTATE[HY000] mysql://shop:secret@db/sylius'));

        $reports = (new HealthCheckRunner([new OverdueCheck($approvals, new MockClock(self::NOW), 14)], new NullLogger()))->run();

        self::assertSame(HealthStatus::Unknown, $reports[0]->result->status);
        self::assertInstanceOf(TranslatableMessage::class, $reports[0]->result->summary);
        self::assertSame('tmonier_platform_health.result.check_failed', $reports[0]->result->summary->getMessage());
    }

    private function check(int $overdue): OverdueCheck
    {
        $approvals = $this->createStub(OrderApprovalRepositoryInterface::class);
        $approvals->method('findPendingIdsRequestedBefore')->willReturn($overdue > 0 ? range(1, $overdue) : []);

        return new OverdueCheck($approvals, new MockClock(self::NOW), 14);
    }
}
