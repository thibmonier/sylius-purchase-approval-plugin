<?php

declare(strict_types=1);

namespace Tmonier\SyliusPurchaseApprovalPlugin\EventListener;

use Doctrine\ORM\Event\OnClearEventArgs;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\OrderCheckoutStates;
use Sylius\Resource\Factory\FactoryInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Service\ResetInterface;
use Tmonier\SyliusPurchaseApprovalPlugin\Approval\ApprovalRequirementChecker;
use Tmonier\SyliusPurchaseApprovalPlugin\Entity\OrderApprovalInterface;
use Tmonier\SyliusPurchaseApprovalPlugin\Event\OrderApprovalEvent;
use Tmonier\SyliusPurchaseApprovalPlugin\Repository\OrderApprovalRepositoryInterface;

/**
 * Creates the pending approval in the flush that completes the checkout (shop, API, staff
 * orders, fixtures alike): the order is never visible as placed without its approval, so no
 * payment, invoice or confirmation can slip in between. `requested` events are dispatched after
 * the flush; those of a flush that fails are dropped.
 */
final class OrderApprovalRequestListener implements ResetInterface
{
    /** @var list<OrderApprovalInterface> */
    private array $flushing = [];

    /** @param FactoryInterface<OrderApprovalInterface> $approvalFactory */
    public function __construct(
        private readonly ApprovalRequirementChecker $requirementChecker,
        private readonly OrderApprovalRepositoryInterface $approvalRepository,
        private readonly FactoryInterface $approvalFactory,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $this->flushing = [];
        $entityManager = $args->getObjectManager();
        $unitOfWork = $entityManager->getUnitOfWork();

        $completing = [];
        foreach ($unitOfWork->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof OrderInterface && OrderCheckoutStates::STATE_COMPLETED === $entity->getCheckoutState()) {
                $completing[] = $entity;
            }
        }
        foreach ($unitOfWork->getScheduledEntityUpdates() as $entity) {
            if (!$entity instanceof OrderInterface) {
                continue;
            }
            $change = $unitOfWork->getEntityChangeSet($entity)['checkoutState'] ?? null;
            if (null !== $change && OrderCheckoutStates::STATE_COMPLETED === $change[1] && OrderCheckoutStates::STATE_COMPLETED !== $change[0]) {
                $completing[] = $entity;
            }
        }

        foreach ($completing as $order) {
            if (null !== $this->approvalRepository->findOneByOrder($order)) {
                continue;
            }
            $requirement = $this->requirementChecker->getRequirement($order);
            if (null === $requirement) {
                continue;
            }

            $approval = $this->approvalFactory->createNew();
            $approval->setOrder($order);
            $approval->setCompany($requirement->member->getCompany());
            $approval->setTotal($order->getTotal());
            $approval->setCurrencyCode($order->getCurrencyCode());
            $approval->setOrderLimit($requirement->orderLimit);
            $entityManager->persist($approval);
            $unitOfWork->computeChangeSet($entityManager->getClassMetadata($approval::class), $approval);
            $this->flushing[] = $approval;
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        $approvals = $this->flushing;
        $this->flushing = [];
        foreach ($approvals as $approval) {
            $this->eventDispatcher->dispatch(new OrderApprovalEvent($approval), OrderApprovalEvent::REQUESTED);
        }
    }

    public function onClear(OnClearEventArgs $args): void
    {
        $this->flushing = [];
    }

    public function reset(): void
    {
        $this->flushing = [];
    }
}
