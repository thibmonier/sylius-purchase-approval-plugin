<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusPurchaseApprovalPlugin\Integration;

use Sylius\Component\Core\Model\OrderInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Tmonier\SyliusCompanyAccountsPlugin\Entity\CompanyInterface;
use Tmonier\SyliusCompanyAccountsPlugin\Entity\CompanyMemberInterface;
use Tmonier\SyliusPurchaseApprovalPlugin\Approval\ApprovalDecisionMaker;
use Tmonier\SyliusPurchaseApprovalPlugin\Approval\ApprovalPolicy;
use Tmonier\SyliusPurchaseApprovalPlugin\Approval\Decider;
use Tmonier\SyliusPurchaseApprovalPlugin\Entity\OrderApprovalInterface;
use Tmonier\SyliusPurchaseApprovalPlugin\Event\OrderApprovalEvent;
use Tmonier\SyliusPurchaseApprovalPlugin\Repository\OrderApprovalRepositoryInterface;

final class ApprovalDecisionTest extends KernelTestCase
{
    use IntegrationTestTrait;

    public function testAnApproverApprovesAndTheOrderStaysPlaced(): void
    {
        [$company, $approval] = $this->pendingApproval();
        $approver = $this->catalog()->member($company, null, CompanyMemberInterface::ROLE_APPROVER);
        $events = $this->recordEvents();

        self::assertNull($this->decisionMaker()->approve($approval, Decider::member($approver), '  Within budget  '));

        $approval = $this->reload($approval);
        self::assertSame(OrderApprovalInterface::STATE_APPROVED, $approval->getState());
        self::assertSame(OrderApprovalInterface::DECIDED_BY_MEMBER, $approval->getDeciderType());
        self::assertSame($approver->getCustomer()?->getId(), $approval->getDecidedBy()?->getId());
        self::assertSame('Within budget', $approval->getComment());
        self::assertNotNull($approval->getDecidedAt());
        self::assertSame(OrderInterface::STATE_NEW, $approval->getOrder()?->getState());
        self::assertSame([OrderApprovalEvent::APPROVED], $events->getArrayCopy());
    }

    public function testARejectedOrExpiredOrderIsCancelled(): void
    {
        [$company, $rejected] = $this->pendingApproval();
        [, $expired] = $this->pendingApproval($company);
        $approver = $this->catalog()->member($company, null, CompanyMemberInterface::ROLE_APPROVER);
        $events = $this->recordEvents();

        self::assertNull($this->decisionMaker()->reject($rejected, Decider::member($approver), 'Over budget'));
        self::assertNull($this->decisionMaker()->expire($expired));

        $rejected = $this->reload($rejected);
        self::assertSame([OrderApprovalInterface::STATE_REJECTED, 'Over budget'], [$rejected->getState(), $rejected->getComment()]);
        self::assertSame(OrderInterface::STATE_CANCELLED, $rejected->getOrder()?->getState());
        $expired = $this->reload($expired);
        self::assertSame([OrderApprovalInterface::STATE_EXPIRED, OrderApprovalInterface::DECIDED_BY_SYSTEM], [$expired->getState(), $expired->getDeciderType()]);
        self::assertSame(OrderInterface::STATE_CANCELLED, $expired->getOrder()?->getState());
        self::assertSame([OrderApprovalEvent::REJECTED, OrderApprovalEvent::EXPIRED], $events->getArrayCopy());
    }

    public function testNobodyApprovesTheirOwnOrder(): void
    {
        $company = $this->catalog()->company();
        $approverBuyer = $this->catalog()->member($company, null, CompanyMemberInterface::ROLE_APPROVER);
        $approval = $this->approvalOf($this->catalog()->orderAgainstLimit($approverBuyer, -1));

        self::assertSame(ApprovalPolicy::ERROR_OWN_ORDER, $this->decisionMaker()->approve($approval, Decider::member($approverBuyer)));
        self::assertSame(ApprovalPolicy::ERROR_OWN_ORDER, $this->decisionMaker()->reject($approval, Decider::member($approverBuyer)));
        self::assertTrue($this->reload($approval)->isPending());
    }

    public function testOnlyActiveApproversOfTheCompanyDecide(): void
    {
        [$company, $approval] = $this->pendingApproval();
        $deciders = [
            'buyer' => $this->catalog()->member($company),
            'company admin (not an approver role by default)' => $this->catalog()->member($company, null, CompanyMemberInterface::ROLE_ADMIN),
            'disabled approver' => $this->catalog()->member($company, null, CompanyMemberInterface::ROLE_APPROVER, false),
            'approver of another company' => $this->catalog()->member($this->catalog()->company('Other'), null, CompanyMemberInterface::ROLE_APPROVER),
        ];

        foreach ($deciders as $label => $member) {
            self::assertSame(ApprovalPolicy::ERROR_NOT_ALLOWED, $this->decisionMaker()->approve($approval, Decider::member($member)), $label);
            self::assertSame(ApprovalPolicy::ERROR_NOT_ALLOWED, $this->decisionMaker()->reject($approval, Decider::member($member)), $label);
        }
        self::assertTrue($this->reload($approval)->isPending());
    }

    public function testTheApprovalLimitCapsApprovalsNotRejections(): void
    {
        [$company, $approval] = $this->pendingApproval();
        $limited = $this->catalog()->member($company, null, CompanyMemberInterface::ROLE_APPROVER);
        $this->catalog()->limit($limited, null, $approval->getTotal() - 1);
        $otherCurrency = $this->catalog()->member($company, null, CompanyMemberInterface::ROLE_APPROVER);
        $this->catalog()->limit($otherCurrency, null, 100_000_00, 'XTS');
        $enough = $this->catalog()->member($company, null, CompanyMemberInterface::ROLE_APPROVER);
        $this->catalog()->limit($enough, null, $approval->getTotal());

        self::assertSame(ApprovalPolicy::ERROR_ABOVE_LIMIT, $this->decisionMaker()->approve($approval, Decider::member($limited)));
        self::assertSame(ApprovalPolicy::ERROR_ABOVE_LIMIT, $this->decisionMaker()->approve($approval, Decider::member($otherCurrency)));
        self::assertNull($this->decisionMaker()->approve($approval, Decider::member($enough)));

        [, $toReject] = $this->pendingApproval($company);
        self::assertNull($this->decisionMaker()->reject($toReject, Decider::member($limited)));
    }

    public function testADecisionIsTakenOnce(): void
    {
        [$company, $approval] = $this->pendingApproval();
        $first = $this->catalog()->member($company, null, CompanyMemberInterface::ROLE_APPROVER);
        $second = $this->catalog()->member($company, null, CompanyMemberInterface::ROLE_APPROVER);

        self::assertNull($this->decisionMaker()->approve($approval, Decider::member($first)));
        self::assertSame(ApprovalPolicy::ERROR_NOT_PENDING, $this->decisionMaker()->reject($approval, Decider::member($second)));
        self::assertSame(ApprovalPolicy::ERROR_NOT_PENDING, $this->decisionMaker()->expire($approval));
        self::assertSame(OrderInterface::STATE_NEW, $this->reload($approval)->getOrder()?->getState());
    }

    public function testADecisionTakenMeanwhileInAnotherRequestWins(): void
    {
        [$company, $approval] = $this->pendingApproval();
        $approver = $this->catalog()->member($company, null, CompanyMemberInterface::ROLE_APPROVER);

        // Another approver rejected it in another request: this one still holds it as pending.
        $this->connection()->update('tmonier_purchase_approval_order_approval', ['state' => OrderApprovalInterface::STATE_REJECTED], ['id' => $approval->getId()]);
        self::assertTrue($approval->isPending());

        self::assertSame(ApprovalPolicy::ERROR_NOT_PENDING, $this->decisionMaker()->approve($approval, Decider::member($approver)));
        self::assertSame(OrderApprovalInterface::STATE_REJECTED, $this->reload($approval)->getState());
    }

    public function testTheStoreIsNotBoundByTheCompanyRules(): void
    {
        [, $approval] = $this->pendingApproval();

        self::assertNull($this->decisionMaker()->approve($approval, Decider::administrator(null), 'Phone confirmation'));

        $approval = $this->reload($approval);
        self::assertSame([OrderApprovalInterface::STATE_APPROVED, OrderApprovalInterface::DECIDED_BY_ADMINISTRATOR], [$approval->getState(), $approval->getDeciderType()]);
        self::assertNull($approval->getDecidedBy());
    }

    /** @return array{CompanyInterface, OrderApprovalInterface} */
    private function pendingApproval(?CompanyInterface $company = null): array
    {
        $company ??= $this->catalog()->company();

        return [$company, $this->approvalOf($this->catalog()->orderAgainstLimit($this->catalog()->member($company), -1))];
    }

    private function approvalOf(OrderInterface $order): OrderApprovalInterface
    {
        $approval = $this->service('tmonier_purchase_approval.repository.order_approval', OrderApprovalRepositoryInterface::class)->findOneByOrder($order);
        self::assertInstanceOf(OrderApprovalInterface::class, $approval);

        return $approval;
    }

    /** @return \ArrayObject<int, string> */
    private function recordEvents(): \ArrayObject
    {
        /** @var \ArrayObject<int, string> $recorder */
        $recorder = new \ArrayObject();
        $dispatcher = $this->service('event_dispatcher', EventDispatcherInterface::class);
        foreach ([OrderApprovalEvent::APPROVED, OrderApprovalEvent::REJECTED, OrderApprovalEvent::EXPIRED] as $name) {
            $dispatcher->addListener($name, static function (OrderApprovalEvent $event, string $eventName) use ($recorder): void {
                $recorder->append($eventName);
            });
        }

        return $recorder;
    }

    private function decisionMaker(): ApprovalDecisionMaker
    {
        return $this->service('tmonier_purchase_approval.decision_maker', ApprovalDecisionMaker::class);
    }
}
