<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusPurchaseApprovalPlugin\Unit\Approval;

use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\TestCase;
use Tmonier\SyliusCompanyAccountsPlugin\Entity\CompanyMemberInterface;
use Tmonier\SyliusPurchaseApprovalPlugin\Approval\ApprovalPolicy;
use Tmonier\SyliusPurchaseApprovalPlugin\Approval\DecidableApprovalCounter;
use Tmonier\SyliusPurchaseApprovalPlugin\Repository\MemberLimitRepositoryInterface;
use Tmonier\SyliusPurchaseApprovalPlugin\Repository\OrderApprovalRepositoryInterface;

final class DecidableApprovalCounterTest extends TestCase
{
    public function testNoMemberNoEntryAndNoQuery(): void
    {
        $repository = $this->createMock(OrderApprovalRepositoryInterface::class);
        $repository->expects(self::never())->method('countPendingDecidableBy');

        self::assertNull($this->counter($repository, ['approver'])->count(null));
    }

    public function testAMemberWhoCannotApproveHasNoEntryAndNoQuery(): void
    {
        $repository = $this->createMock(OrderApprovalRepositoryInterface::class);
        $repository->expects(self::never())->method('countPendingDecidableBy');
        $counter = $this->counter($repository, ['approver']);

        self::assertNull($counter->count($this->member(CompanyMemberInterface::ROLE_BUYER)));
        self::assertNull($counter->count($this->member(CompanyMemberInterface::ROLE_ADMIN)));
        self::assertNull($counter->count($this->member(CompanyMemberInterface::ROLE_APPROVER, false)));
    }

    public function testAnApproverGetsTheCountOfWhatTheyCanDecide(): void
    {
        $repository = $this->createMock(OrderApprovalRepositoryInterface::class);
        $repository->expects(self::once())->method('countPendingDecidableBy')->willReturn(3);

        self::assertSame(3, $this->counter($repository, ['approver'])->count($this->member(CompanyMemberInterface::ROLE_APPROVER)));
    }

    public function testACompanyAdminIsCountedWhenTheirRoleIsConfiguredAsApprover(): void
    {
        $repository = $this->createMock(OrderApprovalRepositoryInterface::class);
        $repository->expects(self::once())->method('countPendingDecidableBy')->willReturn(0);

        self::assertSame(0, $this->counter($repository, ['approver', 'admin'])->count($this->member(CompanyMemberInterface::ROLE_ADMIN)));
    }

    public function testTheCountIsComputedOncePerMemberAndRequest(): void
    {
        $repository = $this->createMock(OrderApprovalRepositoryInterface::class);
        $repository->expects(self::exactly(2))->method('countPendingDecidableBy')->willReturn(2);
        $counter = $this->counter($repository, ['approver']);
        $member = $this->member(CompanyMemberInterface::ROLE_APPROVER);

        $counts = [$counter->count($member), $counter->count($member)];
        $counter->reset();
        $counts[] = $counter->count($member);

        self::assertSame([2, 2, 2], $counts);
    }

    public function testThePendingListQueryBuilderIsExposedForTheFilteredList(): void
    {
        $queryBuilder = $this->createMock(QueryBuilder::class);
        $repository = $this->createMock(OrderApprovalRepositoryInterface::class);
        $repository->method('createPendingDecidableByQueryBuilder')->willReturn($queryBuilder);

        self::assertSame($queryBuilder, $this->counter($repository, ['approver'])->listQueryBuilder($this->member(CompanyMemberInterface::ROLE_APPROVER)));
    }

    /** @param list<string> $roles */
    private function counter(OrderApprovalRepositoryInterface $repository, array $roles): DecidableApprovalCounter
    {
        $limits = $this->createStub(MemberLimitRepositoryInterface::class);

        return new DecidableApprovalCounter($repository, $limits, new ApprovalPolicy($limits, $roles));
    }

    private function member(string $role, bool $active = true): CompanyMemberInterface
    {
        $member = $this->createStub(CompanyMemberInterface::class);
        $member->method('getId')->willReturn(7);
        $member->method('getRole')->willReturn($role);
        $member->method('isActive')->willReturn($active);

        return $member;
    }
}
