<?php

declare(strict_types=1);

namespace Tmonier\SyliusPurchaseApprovalPlugin\EventListener;

use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Symfony\Component\Workflow\Event\CompletedEvent;
use Tmonier\SyliusPurchaseApprovalPlugin\Approval\ApprovalDecisionMaker;
use Tmonier\SyliusPurchaseApprovalPlugin\Entity\OrderApprovalInterface;
use Tmonier\SyliusPurchaseApprovalPlugin\Repository\OrderApprovalRepositoryInterface;

/**
 * `sylius_order.cancel` applied outside the plugin (admin Cancel, admin API, another plugin)
 * while the order waits: the approval is closed (`cancelled`, system decision) in the same
 * flush, so it is no longer listed, approved, expired or e-mailed about. No guard on the
 * cancellation itself (order-hold kernel rule).
 */
final class OrderCancellationListener
{
    public function __construct(
        private readonly OrderApprovalRepositoryInterface $approvalRepository,
        private readonly StateMachineInterface $stateMachine,
    ) {
    }

    public function __invoke(CompletedEvent $event): void
    {
        $order = $event->getSubject();
        if (!$order instanceof OrderInterface) {
            return;
        }
        $approval = $this->approvalRepository->findOneByOrder($order);
        if (null === $approval || !$approval->isPending() || !$this->stateMachine->can($approval, ApprovalDecisionMaker::GRAPH, ApprovalDecisionMaker::TRANSITION_CANCEL)) {
            return;
        }

        $this->stateMachine->apply($approval, ApprovalDecisionMaker::GRAPH, ApprovalDecisionMaker::TRANSITION_CANCEL);
        $approval->setDecidedAt(new \DateTimeImmutable());
        $approval->setDeciderType(OrderApprovalInterface::DECIDED_BY_SYSTEM);
        $approval->setDecidedBy(null);
        $approval->setDeciderName(null);
    }
}
