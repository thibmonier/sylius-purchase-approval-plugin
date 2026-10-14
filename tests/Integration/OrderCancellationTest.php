<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusPurchaseApprovalPlugin\Integration;

use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Order\OrderTransitions;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mime\Email;
use Tmonier\SyliusAdminOrderCreationPlugin\Mailer\PaymentLinkEmailManagerInterface;
use Tmonier\SyliusCompanyAccountsPlugin\Entity\CompanyMemberInterface;
use Tmonier\SyliusPurchaseApprovalPlugin\Approval\ApprovalDecisionMaker;
use Tmonier\SyliusPurchaseApprovalPlugin\Approval\ApprovalPolicy;
use Tmonier\SyliusPurchaseApprovalPlugin\Approval\Decider;
use Tmonier\SyliusPurchaseApprovalPlugin\Entity\OrderApprovalInterface;
use Tmonier\SyliusPurchaseApprovalPlugin\Repository\OrderApprovalRepositoryInterface;

/** Orders cancelled outside the plugin while they wait, and staff payment links. */
final class OrderCancellationTest extends KernelTestCase
{
    use IntegrationTestTrait;

    public function testCancellingAWaitingOrderClosesItsApproval(): void
    {
        $company = $this->catalog()->company();
        $approver = $this->catalog()->member($company, null, CompanyMemberInterface::ROLE_APPROVER);
        $order = $this->catalog()->orderAgainstLimit($this->catalog()->member($company), -1);

        // The admin "Cancel" button, the admin API or another plugin.
        $this->stateMachine()->apply($order, OrderTransitions::GRAPH, OrderTransitions::TRANSITION_CANCEL);
        $this->entityManager()->flush();

        $approval = $this->reload($this->approvalOf($order));
        self::assertSame([OrderApprovalInterface::STATE_CANCELLED, OrderApprovalInterface::DECIDED_BY_SYSTEM], [$approval->getState(), $approval->getDeciderType()]);
        self::assertNotNull($approval->getDecidedAt());
        self::assertSame(ApprovalPolicy::ERROR_NOT_PENDING, $this->decisionMaker()->approve($approval, Decider::member($approver)));
        self::assertSame(ApprovalPolicy::ERROR_NOT_PENDING, $this->decisionMaker()->approve($approval, Decider::administrator(null)));
        self::assertSame([], array_filter(self::getMailerMessages(), static fn (\Symfony\Component\Mime\RawMessage $email): bool => $email instanceof Email && 'Order confirmation' === $email->getSubject()));
    }

    public function testAnOrderNoLongerNewIsNeverApproved(): void
    {
        $order = $this->catalog()->orderAgainstLimit($this->catalog()->member($this->catalog()->company()), -1);
        $approval = $this->approvalOf($order);
        // Cancelled by a path that bypassed the workflow listeners.
        $order->setState(OrderInterface::STATE_CANCELLED);
        $this->entityManager()->flush();

        self::assertSame(ApprovalPolicy::ERROR_NOT_PENDING, $this->decisionMaker()->approve($approval, Decider::administrator(null)));
        self::assertTrue($this->reload($approval)->isPending());
    }

    public function testNoPaymentLinkForAStaffOrderWaitingForApproval(): void
    {
        $company = $this->catalog()->company();
        $waiting = $this->catalog()->orderAgainstLimit($this->catalog()->member($company), -1, byStaff: true);
        $within = $this->catalog()->orderAgainstLimit($this->catalog()->member($company), 0, byStaff: true);
        $paymentLink = $this->service('tmonier_purchase_approval.test.payment_link', PaymentLinkEmailManagerInterface::class);

        self::assertTrue($paymentLink->canSend($within));
        self::assertFalse($paymentLink->canSend($waiting));

        $this->decisionMaker()->approve($this->approvalOf($waiting), Decider::administrator(null));
        self::assertTrue($paymentLink->canSend($waiting));
    }

    private function approvalOf(OrderInterface $order): OrderApprovalInterface
    {
        $approval = $this->service('tmonier_purchase_approval.repository.order_approval', OrderApprovalRepositoryInterface::class)->findOneByOrder($order);
        self::assertInstanceOf(OrderApprovalInterface::class, $approval);

        return $approval;
    }

    private function decisionMaker(): ApprovalDecisionMaker
    {
        return $this->service('tmonier_purchase_approval.decision_maker', ApprovalDecisionMaker::class);
    }

    private function stateMachine(): StateMachineInterface
    {
        return $this->service('sylius_abstraction.state_machine', StateMachineInterface::class);
    }
}
