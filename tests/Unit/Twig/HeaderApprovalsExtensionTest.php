<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusPurchaseApprovalPlugin\Unit\Twig;

use PHPUnit\Framework\TestCase;
use Tmonier\SyliusCompanyAccountsPlugin\Entity\CompanyMemberInterface;
use Tmonier\SyliusCompanyAccountsPlugin\Member\CurrentMemberResolverInterface;
use Tmonier\SyliusPurchaseApprovalPlugin\Approval\ApprovalPolicy;
use Tmonier\SyliusPurchaseApprovalPlugin\Approval\DecidableApprovalCounter;
use Tmonier\SyliusPurchaseApprovalPlugin\Repository\MemberLimitRepositoryInterface;
use Tmonier\SyliusPurchaseApprovalPlugin\Repository\OrderApprovalRepositoryInterface;
use Tmonier\SyliusPurchaseApprovalPlugin\Twig\HeaderApprovalsExtension;

final class HeaderApprovalsExtensionTest extends TestCase
{
    public function testTheEntryIsOffWhenTheOptionIsDisabled(): void
    {
        $resolver = $this->createMock(CurrentMemberResolverInterface::class);
        $resolver->expects(self::never())->method('getMember');

        self::assertNull($this->extension($resolver, $this->neverCounting(), false)->headerApprovals());
    }

    public function testAVisitorOrACustomerInNoCompanyCostsNoCountQuery(): void
    {
        $resolver = $this->createStub(CurrentMemberResolverInterface::class);
        $resolver->method('getMember')->willReturn(null);

        self::assertNull($this->extension($resolver, $this->neverCounting(), true)->headerApprovals());
    }

    public function testABuyerCostsNoCountQuery(): void
    {
        $member = $this->createStub(CompanyMemberInterface::class);
        $member->method('getRole')->willReturn(CompanyMemberInterface::ROLE_BUYER);
        $member->method('isActive')->willReturn(true);
        $resolver = $this->createStub(CurrentMemberResolverInterface::class);
        $resolver->method('getMember')->willReturn($member);

        self::assertNull($this->extension($resolver, $this->neverCounting(), true)->headerApprovals());
    }

    public function testAnApproverGetsTheirCount(): void
    {
        $member = $this->createStub(CompanyMemberInterface::class);
        $member->method('getId')->willReturn(4);
        $member->method('getRole')->willReturn(CompanyMemberInterface::ROLE_APPROVER);
        $member->method('isActive')->willReturn(true);
        $resolver = $this->createStub(CurrentMemberResolverInterface::class);
        $resolver->method('getMember')->willReturn($member);
        $repository = $this->createStub(OrderApprovalRepositoryInterface::class);
        $repository->method('countPendingDecidableBy')->willReturn(5);

        self::assertSame(5, $this->extension($resolver, $this->counter($repository), true)->headerApprovals());
    }

    private function extension(CurrentMemberResolverInterface $resolver, DecidableApprovalCounter $counter, bool $enabled): HeaderApprovalsExtension
    {
        return new HeaderApprovalsExtension($resolver, $counter, $enabled);
    }

    private function neverCounting(): DecidableApprovalCounter
    {
        $repository = $this->createMock(OrderApprovalRepositoryInterface::class);
        $repository->expects(self::never())->method('countPendingDecidableBy');

        return $this->counter($repository);
    }

    private function counter(OrderApprovalRepositoryInterface $repository): DecidableApprovalCounter
    {
        $limits = $this->createStub(MemberLimitRepositoryInterface::class);

        return new DecidableApprovalCounter($repository, $limits, new ApprovalPolicy($limits, ['approver']));
    }
}
