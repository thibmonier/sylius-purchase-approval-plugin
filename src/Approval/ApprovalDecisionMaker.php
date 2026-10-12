<?php

declare(strict_types=1);

namespace Tmonier\SyliusPurchaseApprovalPlugin\Approval;

use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Order\OrderTransitions;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Tmonier\SyliusPurchaseApprovalPlugin\Entity\OrderApprovalInterface;
use Tmonier\SyliusPurchaseApprovalPlugin\Event\OrderApprovalEvent;

/**
 * Approves, rejects or expires an approval through the `tmonier_order_approval` workflow. The
 * approval row is locked and re-read first, so two approvers deciding at the same time cannot
 * both win (the second one gets "not pending"). A rejected or expired order is cancelled with
 * the core `sylius_order.cancel` transition (stock and promotion usage are released).
 */
final class ApprovalDecisionMaker
{
    public const GRAPH = 'tmonier_order_approval';

    public const TRANSITION_APPROVE = 'approve';

    public const TRANSITION_REJECT = 'reject';

    public const TRANSITION_EXPIRE = 'expire';

    public const COMMENT_MAX_LENGTH = 1000;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly StateMachineInterface $stateMachine,
        private readonly ApprovalPolicy $policy,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
    }

    /** @return string|null the refusal (ApprovalPolicy::ERROR_*), null once approved */
    public function approve(OrderApprovalInterface $approval, Decider $decider, ?string $comment = null): ?string
    {
        return $this->decide($approval, $decider, self::TRANSITION_APPROVE, $comment);
    }

    /** @return string|null the refusal (ApprovalPolicy::ERROR_*), null once rejected and the order cancelled */
    public function reject(OrderApprovalInterface $approval, Decider $decider, ?string $comment = null): ?string
    {
        return $this->decide($approval, $decider, self::TRANSITION_REJECT, $comment);
    }

    /** @return string|null the refusal, null once expired and the order cancelled */
    public function expire(OrderApprovalInterface $approval): ?string
    {
        return $this->decide($approval, Decider::system(), self::TRANSITION_EXPIRE, null);
    }

    private function decide(OrderApprovalInterface $approval, Decider $decider, string $transition, ?string $comment): ?string
    {
        $comment = null === $comment ? null : mb_substr(trim($comment), 0, self::COMMENT_MAX_LENGTH);

        /** @var string|null $error */
        $error = $this->entityManager->wrapInTransaction(function () use ($approval, $decider, $transition, $comment): ?string {
            $this->entityManager->refresh($approval, LockMode::PESSIMISTIC_WRITE);
            if (null !== $decider->member) {
                $this->entityManager->refresh($decider->member);
                $error = self::TRANSITION_APPROVE === $transition
                    ? $this->policy->checkApprove($decider->member, $approval)
                    : $this->policy->checkReject($decider->member, $approval);
                if (null !== $error) {
                    return $error;
                }
            }
            if (!$approval->isPending() || !$this->stateMachine->can($approval, self::GRAPH, $transition)) {
                return ApprovalPolicy::ERROR_NOT_PENDING;
            }

            $this->stateMachine->apply($approval, self::GRAPH, $transition);
            $approval->setDecidedAt(new \DateTimeImmutable());
            $approval->setDeciderType($decider->type);
            $approval->setDecidedBy($decider->customer);
            $approval->setDeciderName($decider->name);
            $approval->setComment('' === $comment ? null : $comment);

            $order = $approval->getOrder();
            if (self::TRANSITION_APPROVE !== $transition && null !== $order && $this->stateMachine->can($order, OrderTransitions::GRAPH, OrderTransitions::TRANSITION_CANCEL)) {
                $this->stateMachine->apply($order, OrderTransitions::GRAPH, OrderTransitions::TRANSITION_CANCEL);
            }

            return null;
        });

        if (null === $error) {
            $this->eventDispatcher->dispatch(new OrderApprovalEvent($approval), match ($transition) {
                self::TRANSITION_APPROVE => OrderApprovalEvent::APPROVED,
                self::TRANSITION_REJECT => OrderApprovalEvent::REJECTED,
                default => OrderApprovalEvent::EXPIRED,
            });
        }

        return $error;
    }
}
