<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusPurchaseApprovalPlugin\Integration;

use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\ShipmentInterface;
use Sylius\Component\Payment\PaymentTransitions;
use Sylius\Component\Shipping\ShipmentTransitions;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Tmonier\SyliusOrderHoldPlugin\PaymentEntry\PaymentEntryBlockerInterface;
use Tmonier\SyliusOrderHoldPlugin\UnpaidOrder\UnpaidOrderCancellationExclusionInterface;
use Tmonier\SyliusPurchaseApprovalPlugin\Approval\ApprovalDecisionMaker;
use Tmonier\SyliusPurchaseApprovalPlugin\Approval\Decider;
use Tmonier\SyliusPurchaseApprovalPlugin\Entity\OrderApprovalInterface;
use Tmonier\SyliusPurchaseApprovalPlugin\Repository\OrderApprovalRepositoryInterface;

/** No payment and no shipment before the approval; the unpaid-orders delay runs from the approval. */
final class OrderHoldTest extends KernelTestCase
{
    use IntegrationTestTrait;

    public function testPaymentIsBlockedUntilTheOrderIsApproved(): void
    {
        $company = $this->catalog()->company();
        $pending = $this->catalog()->orderAgainstLimit($this->catalog()->member($company), -1);
        $rejected = $this->catalog()->orderAgainstLimit($this->catalog()->member($company), -1);
        $within = $this->catalog()->orderAgainstLimit($this->catalog()->member($company), 0);
        $this->decisionMaker()->reject($this->approvalOf($rejected), Decider::administrator(null));

        self::assertSame('tmonier_purchase_approval.payment_entry.awaiting_approval', $this->hold()->getBlock($pending)?->getMessage());
        self::assertSame('tmonier_purchase_approval.payment_entry.not_approved', $this->hold()->getBlock($rejected)?->getMessage());
        self::assertNull($this->hold()->getBlock($within));

        $this->decisionMaker()->approve($this->approvalOf($pending), Decider::administrator(null));
        self::assertNull($this->hold()->getBlock($pending));
    }

    public function testShipmentWaitsForTheApproval(): void
    {
        $order = $this->catalog()->orderAgainstLimit($this->catalog()->member($this->catalog()->company()), -1);
        $shipment = $order->getShipments()->first();
        self::assertInstanceOf(ShipmentInterface::class, $shipment);

        self::assertFalse($this->stateMachine()->can($shipment, ShipmentTransitions::GRAPH, ShipmentTransitions::TRANSITION_SHIP));

        $this->decisionMaker()->approve($this->approvalOf($order), Decider::administrator(null));
        self::assertTrue($this->stateMachine()->can($shipment, ShipmentTransitions::GRAPH, ShipmentTransitions::TRANSITION_SHIP));
    }

    public function testThePendingOrderIsNeverCancelledAsUnpaidAndTheDelayRunsFromTheApproval(): void
    {
        $company = $this->catalog()->company();
        $pending = $this->catalog()->orderAgainstLimit($this->catalog()->member($company), -1);
        $approvedNow = $this->catalog()->orderAgainstLimit($this->catalog()->member($company), -1);
        $approvedLongAgo = $this->catalog()->orderAgainstLimit($this->catalog()->member($company), -1);
        $this->decisionMaker()->approve($this->approvalOf($approvedNow), Decider::administrator(null));
        $this->decisionMaker()->approve($this->approvalOf($approvedLongAgo), Decider::administrator(null));
        foreach ([$pending, $approvedNow, $approvedLongAgo] as $order) {
            $this->connection()->executeStatement('UPDATE sylius_order SET checkout_completed_at = ? WHERE id = ?', [(new \DateTime('-20 days'))->format('Y-m-d H:i:s'), $order->getId()]);
        }
        $this->connection()->executeStatement('UPDATE tmonier_purchase_approval_order_approval SET decided_at = ? WHERE order_id = ?', [(new \DateTime('-10 days'))->format('Y-m-d H:i:s'), $approvedLongAgo->getId()]);

        self::assertTrue($this->exclusion()->getHold($this->reload($pending))?->isIndefinite());
        $this->entityManager()->clear();
        $tester = new CommandTester((new Application(self::$kernel ?? throw new \LogicException()))->find('sylius:cancel-unpaid-orders'));
        self::assertSame(0, $tester->execute([]));

        self::assertSame(OrderInterface::STATE_NEW, $this->stateOf($pending));
        self::assertSame(OrderInterface::STATE_NEW, $this->stateOf($approvedNow), 'Approved today: 5 days to pay from now.');
        self::assertSame(OrderInterface::STATE_CANCELLED, $this->stateOf($approvedLongAgo), 'Approved 10 days ago and still unpaid.');
    }

    public function testAPaymentCompletedBeforeTheApprovalLeavesItPending(): void
    {
        // A gateway that captured during the checkout: the order still waits for its approval.
        $order = $this->catalog()->orderAgainstLimit($this->catalog()->member($this->catalog()->company()), -1);
        $payment = $order->getLastPayment(PaymentInterface::STATE_NEW);
        self::assertInstanceOf(PaymentInterface::class, $payment);
        $this->stateMachine()->apply($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_COMPLETE);
        $this->entityManager()->flush();

        $approval = $this->reload($this->approvalOf($order));
        self::assertTrue($approval->isPending());
        $shipment = $approval->getOrder()?->getShipments()->first();
        self::assertInstanceOf(ShipmentInterface::class, $shipment);
        self::assertFalse($this->stateMachine()->can($shipment, ShipmentTransitions::GRAPH, ShipmentTransitions::TRANSITION_SHIP), 'Paid but not approved: not shipped.');
    }

    private function stateOf(OrderInterface $order): mixed
    {
        return $this->connection()->fetchOne('SELECT state FROM sylius_order WHERE id = ?', [$order->getId()]);
    }

    private function approvalOf(OrderInterface $order): OrderApprovalInterface
    {
        $approval = $this->service('tmonier_purchase_approval.repository.order_approval', OrderApprovalRepositoryInterface::class)->findOneByOrder($order);
        self::assertInstanceOf(OrderApprovalInterface::class, $approval);

        return $approval;
    }

    private function hold(): PaymentEntryBlockerInterface
    {
        return $this->service('tmonier_purchase_approval.test.approval_hold', PaymentEntryBlockerInterface::class);
    }

    private function exclusion(): UnpaidOrderCancellationExclusionInterface
    {
        return $this->service('tmonier_purchase_approval.test.approval_hold', UnpaidOrderCancellationExclusionInterface::class);
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
