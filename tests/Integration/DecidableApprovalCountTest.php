<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusPurchaseApprovalPlugin\Integration;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Tmonier\SyliusCompanyAccountsPlugin\Entity\CompanyInterface;
use Tmonier\SyliusCompanyAccountsPlugin\Entity\CompanyMemberInterface;
use Tmonier\SyliusPurchaseApprovalPlugin\Approval\ApprovalPolicy;
use Tmonier\SyliusPurchaseApprovalPlugin\Approval\DecidableApprovalCounter;
use Tmonier\SyliusPurchaseApprovalPlugin\Approval\Decider;
use Tmonier\SyliusPurchaseApprovalPlugin\Entity\OrderApprovalInterface;
use Tmonier\SyliusPurchaseApprovalPlugin\Repository\MemberLimitRepositoryInterface;
use Tmonier\SyliusPurchaseApprovalPlugin\Repository\OrderApprovalRepositoryInterface;

final class DecidableApprovalCountTest extends KernelTestCase
{
    use IntegrationTestTrait;

    public function testTheCountIsWhatTheMemberCanDecide(): void
    {
        [$company, $approver, $buyer] = $this->company();
        $this->catalog()->orderAgainstLimit($buyer, -1);
        $this->catalog()->orderAgainstLimit($buyer, -1);

        self::assertSame(2, $this->counter()->count($approver));
    }

    public function testTheirOwnOrderIsNotCounted(): void
    {
        [, $approver, $buyer] = $this->company();
        $this->catalog()->orderAgainstLimit($buyer, -1);
        $this->catalog()->orderAgainstLimit($approver, -1);

        self::assertSame(1, $this->counter()->count($approver));
    }

    public function testAnOrderAboveTheApprovalLimitIsNotCounted(): void
    {
        [, $approver, $buyer] = $this->company();
        $this->catalog()->orderAgainstLimit($buyer, -1);
        $this->catalog()->limit($approver, null, 1);

        self::assertSame(0, $this->counter()->count($approver));
    }

    public function testALimitInAnotherCurrencyCountsNothingJustLikeTheApprovalButtons(): void
    {
        [, $approver, $buyer] = $this->company();
        $this->catalog()->orderAgainstLimit($buyer, -1);
        $this->catalog()->limit($approver, null, 1_000_000_000, 'XTS');

        self::assertSame(0, $this->counter()->count($approver));
    }

    public function testAnApprovalLimitLeftEmptyMeansNoMaximum(): void
    {
        [, $approver, $buyer] = $this->company();
        $this->catalog()->orderAgainstLimit($buyer, -1);
        $this->catalog()->limit($approver, 100);

        self::assertSame(1, $this->counter()->count($approver));
    }

    public function testAnotherCompanyAndDecidedApprovalsAreNotCounted(): void
    {
        [, $approver, $buyer] = $this->company();
        [, , $otherBuyer] = $this->company();
        $decided = $this->catalog()->orderAgainstLimit($buyer, -1);
        $this->catalog()->orderAgainstLimit($buyer, -1);
        $this->catalog()->orderAgainstLimit($otherBuyer, -1);

        $approval = $this->repository()->findOneByOrder($decided);
        self::assertInstanceOf(OrderApprovalInterface::class, $approval);
        $other = $this->catalog()->member($approver->getCompany() ?? throw new \LogicException(), null, CompanyMemberInterface::ROLE_APPROVER);
        self::assertNull($this->service('tmonier_purchase_approval.decision_maker', \Tmonier\SyliusPurchaseApprovalPlugin\Approval\ApprovalDecisionMaker::class)->approve($approval, Decider::member($other), ''));

        self::assertSame(1, $this->counter()->count($approver));
    }

    public function testACompanyAdminCountsWhenTheirRoleIsConfiguredAsApprover(): void
    {
        [$company, , $buyer] = $this->company();
        $admin = $this->catalog()->member($company, null, CompanyMemberInterface::ROLE_ADMIN);
        $this->catalog()->orderAgainstLimit($buyer, -1);
        $this->catalog()->orderAgainstLimit($admin, -1);

        self::assertNull($this->counter()->count($admin), 'Default configuration: administrators do not approve.');
        self::assertSame(1, $this->counter(['approver', 'admin'])->count($admin), 'Their own order is excluded.');
    }

    public function testTheCountEqualsTheFilteredListAndThePolicy(): void
    {
        [$company, $approver, $buyer] = $this->company();
        $this->catalog()->orderAgainstLimit($buyer, -1);
        $this->catalog()->orderAgainstLimit($buyer, -1);
        $this->catalog()->orderAgainstLimit($approver, -1);
        $this->catalog()->limit($approver, null, 2_000_000);
        $counter = $this->counter();

        $listed = $counter->listQueryBuilder($approver)->getQuery()->getResult();
        /** @var list<OrderApprovalInterface> $pending */
        $pending = $this->repository()->createListQueryBuilderByCompany($company, true)->getQuery()->getResult();
        $byPolicy = array_filter(
            $pending,
            fn (OrderApprovalInterface $approval): bool => null === $this->policy()->checkApprove($approver, $approval),
        );

        self::assertIsArray($listed);
        self::assertCount($counter->count($approver) ?? -1, $listed);
        self::assertCount(count($byPolicy), $listed);
    }

    /** @param list<string> $roles */
    private function counter(array $roles = ['approver']): DecidableApprovalCounter
    {
        $limits = $this->service('tmonier_purchase_approval.repository.member_limit', MemberLimitRepositoryInterface::class);

        return new DecidableApprovalCounter($this->repository(), $limits, new ApprovalPolicy($limits, $roles));
    }

    private function policy(): ApprovalPolicy
    {
        return $this->service('tmonier_purchase_approval.policy', ApprovalPolicy::class);
    }

    private function repository(): OrderApprovalRepositoryInterface
    {
        return $this->service('tmonier_purchase_approval.repository.order_approval', OrderApprovalRepositoryInterface::class);
    }

    /** @return array{CompanyInterface, CompanyMemberInterface, CompanyMemberInterface} company, approver, buyer */
    private function company(): array
    {
        $company = $this->catalog()->company();

        return [
            $company,
            $this->catalog()->member($company, null, CompanyMemberInterface::ROLE_APPROVER),
            $this->catalog()->member($company),
        ];
    }
}
