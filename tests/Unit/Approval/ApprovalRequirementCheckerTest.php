<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusPurchaseApprovalPlugin\Unit\Approval;

use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Tmonier\SyliusCompanyAccountsPlugin\Entity\CompanyMemberInterface;
use Tmonier\SyliusCompanyAccountsPlugin\Member\CompanyOrderEligibilityCheckerInterface;
use Tmonier\SyliusCompanyAccountsPlugin\Repository\CompanyMemberRepositoryInterface;
use Tmonier\SyliusPurchaseApprovalPlugin\Approval\ApprovalAmountResolver;
use Tmonier\SyliusPurchaseApprovalPlugin\Approval\ApprovalRequirementChecker;
use Tmonier\SyliusPurchaseApprovalPlugin\Entity\MemberLimitInterface;
use Tmonier\SyliusPurchaseApprovalPlugin\Repository\MemberLimitRepositoryInterface;

/** The order limit is compared to the amount the resolver gives: the total tax included, or without its tax. */
final class ApprovalRequirementCheckerTest extends TestCase
{
    public function testOnTheGrossBasisTheTaxCountsTowardsTheLimit(): void
    {
        $checker = $this->checker(ApprovalAmountResolver::GROSS, 55000);

        self::assertNotNull($checker->getRequirement($this->order(60000, 10000)));
    }

    public function testOnTheNetBasisTheTaxDoesNotCountTowardsTheLimit(): void
    {
        $checker = $this->checker(ApprovalAmountResolver::NET, 55000);

        self::assertNull($checker->getRequirement($this->order(60000, 10000)));
    }

    public function testOnTheNetBasisAnOrderAboveTheLimitWithoutItsTaxStillWaits(): void
    {
        $checker = $this->checker(ApprovalAmountResolver::NET, 45000);

        $requirement = $checker->getRequirement($this->order(60000, 10000));

        self::assertNotNull($requirement);
        self::assertSame(45000, $requirement->orderLimit);
    }

    private function checker(string $basis, int $orderLimit): ApprovalRequirementChecker
    {
        $member = $this->createStub(CompanyMemberInterface::class);
        $member->method('isActive')->willReturn(true);
        $members = $this->createStub(CompanyMemberRepositoryInterface::class);
        $members->method('findOneByCustomer')->willReturn($member);
        $limit = $this->createStub(MemberLimitInterface::class);
        $limit->method('getOrderLimit')->willReturn($orderLimit);
        $limit->method('getCurrencyCode')->willReturn('EUR');
        $limits = $this->createStub(MemberLimitRepositoryInterface::class);
        $limits->method('findOneByMember')->willReturn($limit);
        $eligibility = $this->createStub(CompanyOrderEligibilityCheckerInterface::class);
        $eligibility->method('isEligible')->willReturn(true);

        return new ApprovalRequirementChecker($members, $limits, $eligibility, new ApprovalAmountResolver($basis));
    }

    private function order(int $total, int $tax): OrderInterface
    {
        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getId')->willReturn(7);
        $order = $this->createStub(OrderInterface::class);
        $order->method('getCustomer')->willReturn($customer);
        $order->method('getCurrencyCode')->willReturn('EUR');
        $order->method('getTotal')->willReturn($total);
        $order->method('getTaxTotal')->willReturn($tax);

        return $order;
    }
}
