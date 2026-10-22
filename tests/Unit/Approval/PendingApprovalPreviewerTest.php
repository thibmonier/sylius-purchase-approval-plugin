<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusPurchaseApprovalPlugin\Unit\Approval;

use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Symfony\Component\Clock\MockClock;
use Tmonier\SyliusCompanyAccountsPlugin\Entity\CompanyMemberInterface;
use Tmonier\SyliusPurchaseApprovalPlugin\Approval\ApprovalPolicy;
use Tmonier\SyliusPurchaseApprovalPlugin\Approval\DecidableApprovalCounter;
use Tmonier\SyliusPurchaseApprovalPlugin\Approval\PendingApprovalPreviewer;
use Tmonier\SyliusPurchaseApprovalPlugin\Entity\OrderApprovalInterface;
use Tmonier\SyliusPurchaseApprovalPlugin\Repository\MemberLimitRepositoryInterface;
use Tmonier\SyliusPurchaseApprovalPlugin\Repository\OrderApprovalRepositoryInterface;

final class PendingApprovalPreviewerTest extends TestCase
{
    private const NOW = '2026-10-22 12:00:00';

    public function testAMemberWhoCannotApproveGetsAnEmptyPreviewAndNoQuery(): void
    {
        $repository = $this->createMock(OrderApprovalRepositoryInterface::class);
        $repository->expects(self::never())->method('findOldestPendingDecidableBy');
        $repository->expects(self::never())->method('countPendingDecidableBy');

        $preview = $this->previewer($repository)->preview($this->member(CompanyMemberInterface::ROLE_BUYER));

        self::assertSame([], $preview->items);
        self::assertSame(0, $preview->total);
    }

    public function testTheFiveOldestAreRequestedAndTheTotalIsTheDecidableCount(): void
    {
        $repository = $this->createMock(OrderApprovalRepositoryInterface::class);
        $repository->expects(self::once())->method('findOldestPendingDecidableBy')->with(self::anything(), null, 5)->willReturn([$this->approval('100', '-2 days')]);
        $repository->method('countPendingDecidableBy')->willReturn(7);

        $preview = $this->previewer($repository)->preview($this->member(CompanyMemberInterface::ROLE_APPROVER));

        self::assertCount(1, $preview->items);
        self::assertSame(7, $preview->total);
    }

    public function testAnItemCarriesNumberBuyerAmountAndAge(): void
    {
        $item = $this->previewOf($this->approval('000123', '-3 days -2 hours', 'Bob Buyer', 12500))->items[0];

        self::assertSame('000123', $item->number);
        self::assertSame('Bob Buyer', $item->buyerName);
        self::assertSame(12500, $item->total);
        self::assertSame('EUR', $item->currencyCode);
        self::assertSame(3, $item->ageInDays);
    }

    public function testAnApprovalFarFromItsExpiryHasNoAlert(): void
    {
        self::assertNull($this->previewOf($this->approval('1', '-5 days'))->items[0]->expiresInDays);
    }

    public function testAnApprovalCloseToItsExpiryTellsInHowManyDays(): void
    {
        // expire_after_days = 14: requested 12 days ago, it expires in 2 days.
        self::assertSame(2, $this->previewOf($this->approval('1', '-12 days'))->items[0]->expiresInDays);
    }

    public function testTheAlertThresholdIsIncluded(): void
    {
        self::assertSame(3, $this->previewOf($this->approval('1', '-11 days'))->items[0]->expiresInDays);
        self::assertNull($this->previewOf($this->approval('1', '-10 days'))->items[0]->expiresInDays);
    }

    public function testAnOverdueApprovalExpiresToday(): void
    {
        self::assertSame(0, $this->previewOf($this->approval('1', '-20 days'))->items[0]->expiresInDays);
    }

    public function testAnOrderWithoutCustomerHasNoBuyerName(): void
    {
        self::assertSame('', $this->previewOf($this->approval('1', '-1 day', null))->items[0]->buyerName);
    }

    private function previewOf(OrderApprovalInterface $approval): \Tmonier\SyliusPurchaseApprovalPlugin\Approval\PendingApprovalPreview
    {
        $repository = $this->createStub(OrderApprovalRepositoryInterface::class);
        $repository->method('findOldestPendingDecidableBy')->willReturn([$approval]);
        $repository->method('countPendingDecidableBy')->willReturn(1);

        return $this->previewer($repository)->preview($this->member(CompanyMemberInterface::ROLE_APPROVER));
    }

    private function previewer(OrderApprovalRepositoryInterface $repository): PendingApprovalPreviewer
    {
        $limits = $this->createStub(MemberLimitRepositoryInterface::class);
        $counter = new DecidableApprovalCounter($repository, $limits, new ApprovalPolicy($limits, ['approver']));

        return new PendingApprovalPreviewer($repository, $limits, $counter, new MockClock(self::NOW), 14);
    }

    private function approval(string $number, string $requested, ?string $buyer = 'Bob Buyer', int $total = 1000): OrderApprovalInterface
    {
        $customer = null;
        if (null !== $buyer) {
            $customer = $this->createStub(CustomerInterface::class);
            $customer->method('getFullName')->willReturn($buyer);
        }
        $order = $this->createStub(OrderInterface::class);
        $order->method('getNumber')->willReturn($number);
        $order->method('getCustomer')->willReturn($customer);
        $approval = $this->createStub(OrderApprovalInterface::class);
        $approval->method('getOrder')->willReturn($order);
        $approval->method('getTotal')->willReturn($total);
        $approval->method('getCurrencyCode')->willReturn('EUR');
        $approval->method('getCreatedAt')->willReturn((new \DateTimeImmutable(self::NOW))->modify($requested));

        return $approval;
    }

    private function member(string $role): CompanyMemberInterface
    {
        $member = $this->createStub(CompanyMemberInterface::class);
        $member->method('getId')->willReturn(7);
        $member->method('getRole')->willReturn($role);
        $member->method('isActive')->willReturn(true);

        return $member;
    }
}
