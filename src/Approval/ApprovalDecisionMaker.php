<?php

declare(strict_types=1);

namespace Tmonier\SyliusPurchaseApprovalPlugin\Approval;

use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Order\OrderTransitions;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Tmonier\SyliusCompanyAccountsPlugin\Entity\CompanyMemberInterface;
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

    public const TRANSITION_CANCEL = 'cancel';

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

    /** The member as it is in the database now (re-read; deleted meanwhile: null). */
    private function managedMember(CompanyMemberInterface $member): ?CompanyMemberInterface
    {
        if ($this->entityManager->contains($member)) {
            $this->entityManager->refresh($member);

            return $member;
        }
        $found = null === $member->getId() ? null : $this->entityManager->find($this->entityManager->getClassMetadata($member::class)->getName(), $member->getId());

        return $found instanceof CompanyMemberInterface ? $found : null;
    }

    /**
     * Closes the approval of an order that is no longer new (cancelled outside the order
     * workflow): system decision, no event, the order is left as it is.
     *
     * @return bool whether it was closed
     */
    public function closeForClosedOrder(OrderApprovalInterface $approval): bool
    {
        /** @var bool $closed */
        $closed = $this->entityManager->wrapInTransaction(function () use ($approval): bool {
            $this->entityManager->refresh($approval, LockMode::PESSIMISTIC_WRITE);
            $order = $approval->getOrder();
            if (!$approval->isPending() || null === $order || OrderInterface::STATE_NEW === $order->getState() || !$this->stateMachine->can($approval, self::GRAPH, self::TRANSITION_CANCEL)) {
                return false;
            }
            $this->stateMachine->apply($approval, self::GRAPH, self::TRANSITION_CANCEL);
            $approval->setDecidedAt(new \DateTimeImmutable());
            $approval->setDeciderType(OrderApprovalInterface::DECIDED_BY_SYSTEM);
            $approval->setDecidedBy(null);
            $approval->setDeciderName(null);

            return true;
        });

        return $closed;
    }

    private function decide(OrderApprovalInterface $approval, Decider $decider, string $transition, ?string $comment): ?string
    {
        $comment = null === $comment ? null : mb_substr(trim($comment), 0, self::COMMENT_MAX_LENGTH);

        /** @var string|null $error */
        $error = $this->entityManager->wrapInTransaction(function () use ($approval, $decider, $transition, $comment): ?string {
            $this->entityManager->refresh($approval, LockMode::PESSIMISTIC_WRITE);
            $deciderCustomer = null;
            if (null !== $decider->member) {
                $member = $this->managedMember($decider->member);
                if (null === $member) {
                    return ApprovalPolicy::ERROR_NOT_ALLOWED;
                }
                $error = self::TRANSITION_APPROVE === $transition
                    ? $this->policy->checkApprove($member, $approval)
                    : $this->policy->checkReject($member, $approval);
                if (null !== $error) {
                    return $error;
                }
                $customer = $member->getCustomer();
                $deciderCustomer = $customer instanceof CustomerInterface ? $customer : null;
            }
            // An order cancelled meanwhile (store, API, another plugin) is closed, never approved.
            $order = $approval->getOrder();
            if (!$approval->isPending() || OrderInterface::STATE_NEW !== $order?->getState() || !$this->stateMachine->can($approval, self::GRAPH, $transition)) {
                return ApprovalPolicy::ERROR_NOT_PENDING;
            }

            $this->stateMachine->apply($approval, self::GRAPH, $transition);
            $approval->setDecidedAt(new \DateTimeImmutable());
            $approval->setDeciderType($decider->type);
            $approval->setDecidedBy($deciderCustomer);
            $approval->setDeciderName($decider->name);
            $approval->setComment('' === $comment ? null : $comment);

            if (self::TRANSITION_APPROVE !== $transition && $this->stateMachine->can($order, OrderTransitions::GRAPH, OrderTransitions::TRANSITION_CANCEL)) {
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
