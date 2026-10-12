<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusPurchaseApprovalPlugin\Integration;

use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\OrderCheckoutTransitions;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Tmonier\SyliusCompanyAccountsPlugin\Entity\CompanyMemberInterface;
use Tmonier\SyliusPurchaseApprovalPlugin\Entity\OrderApprovalInterface;
use Tmonier\SyliusPurchaseApprovalPlugin\Event\OrderApprovalEvent;
use Tmonier\SyliusPurchaseApprovalPlugin\Repository\OrderApprovalRepositoryInterface;

/** The pending approval is created in the flush that completes the checkout, above the limit only. */
final class ApprovalRequestTest extends KernelTestCase
{
    use IntegrationTestTrait;

    public function testAnOrderAboveTheLimitWaitsForAnApproval(): void
    {
        $company = $this->catalog()->company();
        $buyer = $this->catalog()->member($company);
        $requested = [];
        $this->service('event_dispatcher', EventDispatcherInterface::class)->addListener(OrderApprovalEvent::REQUESTED, static function (OrderApprovalEvent $event) use (&$requested): void {
            $requested[] = $event->getApproval();
        });

        $order = $this->catalog()->orderAgainstLimit($buyer, -1);

        $approval = $this->repository()->findOneByOrder($order);
        self::assertInstanceOf(OrderApprovalInterface::class, $approval);
        self::assertSame(OrderApprovalInterface::STATE_PENDING, $approval->getState());
        self::assertSame($company->getId(), $approval->getCompany()?->getId());
        self::assertSame($order->getTotal(), $approval->getTotal());
        self::assertSame($order->getTotal() - 1, $approval->getOrderLimit());
        self::assertSame($order->getCurrencyCode(), $approval->getCurrencyCode());
        self::assertSame([$approval], $requested);
    }

    public function testTheApprovalIsWrittenWithTheCompletedOrder(): void
    {
        $buyer = $this->catalog()->member($this->catalog()->company());
        $order = $this->catalog()->placedOrder($this->customerOf($buyer), checkoutState: 'payment_selected');
        $this->catalog()->limit($buyer, 0);
        self::assertNull($this->repository()->findOneByOrder($order));

        $this->catalog()->apply($order, OrderCheckoutTransitions::TRANSITION_COMPLETE);
        // No other flush in between: a listener of the completing flush sees the approval.
        $this->entityManager()->flush();

        $count = $this->connection()->fetchOne('SELECT COUNT(*) FROM tmonier_purchase_approval_order_approval WHERE order_id = ?', [$order->getId()]);
        self::assertEquals(1, $count);
    }

    public function testOrdersUpToTheLimitOrWithoutLimitNeedNone(): void
    {
        $company = $this->catalog()->company();
        $atLimit = $this->catalog()->orderAgainstLimit($this->catalog()->member($company), 0);
        $noLimit = $this->catalog()->placedOrder($this->customerOf($this->catalog()->member($company)));
        $member = $this->catalog()->member($company);
        $this->catalog()->limit($member, null, 100);
        $unlimited = $this->catalog()->placedOrder($this->customerOf($member));

        foreach (['at the limit' => $atLimit, 'no limit row' => $noLimit, 'no order limit' => $unlimited] as $label => $order) {
            self::assertNull($this->repository()->findOneByOrder($order), $label);
        }
    }

    public function testALimitInAnotherCurrencyAlwaysNeedsAnApproval(): void
    {
        $buyer = $this->catalog()->member($this->catalog()->company());
        $this->catalog()->limit($buyer, 100_000_00, null, 'XTS' === $this->catalog()->currencyCode() ? 'EUR' : 'XTS');

        $approval = $this->repository()->findOneByOrder($this->catalog()->placedOrder($this->customerOf($buyer)));

        self::assertInstanceOf(OrderApprovalInterface::class, $approval);
        self::assertNull($approval->getOrderLimit());
    }

    public function testOrdersOutsideAnActiveMembershipOrPlacedAsGuestNeedNone(): void
    {
        $company = $this->catalog()->company();
        $disabled = $this->catalog()->member($company, null, CompanyMemberInterface::ROLE_BUYER, false);
        $ofClosed = $this->catalog()->member($this->catalog()->company('Closed', false));
        $active = $this->catalog()->member($company);

        $orders = [
            'disabled member' => $this->catalog()->orderAgainstLimit($disabled, -1),
            'disabled company' => $this->catalog()->orderAgainstLimit($ofClosed, -1),
            // Sylius attaches the existing customer to a guest checkout with their e-mail.
            'guest checkout with the e-mail of a member' => $this->catalog()->orderAgainstLimit($active, -1, asGuest: true),
        ];
        foreach ($orders as $label => $order) {
            self::assertNull($this->repository()->findOneByOrder($order), $label);
        }
    }

    public function testStaffOrdersForAMemberFollowTheSameRules(): void
    {
        $company = $this->catalog()->company();
        $above = $this->catalog()->orderAgainstLimit($this->catalog()->member($company), -1, byStaff: true);
        $within = $this->catalog()->orderAgainstLimit($this->catalog()->member($company), 0, byStaff: true);

        self::assertTrue($above->isCreatedByGuest(), 'Staff orders are not placed by the signed-in customer.');
        self::assertSame(OrderApprovalInterface::STATE_PENDING, $this->repository()->findOneByOrder($above)?->getState());
        self::assertNull($this->repository()->findOneByOrder($within));
    }

    private function customerOf(CompanyMemberInterface $member): CustomerInterface
    {
        $customer = $member->getCustomer();
        self::assertInstanceOf(CustomerInterface::class, $customer);

        return $customer;
    }

    private function repository(): OrderApprovalRepositoryInterface
    {
        return $this->service('tmonier_purchase_approval.repository.order_approval', OrderApprovalRepositoryInterface::class);
    }
}
