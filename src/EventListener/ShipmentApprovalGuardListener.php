<?php

declare(strict_types=1);

namespace Tmonier\SyliusPurchaseApprovalPlugin\EventListener;

use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\ShipmentInterface;
use Symfony\Component\Workflow\Event\GuardEvent;
use Symfony\Component\Workflow\TransitionBlocker;
use Tmonier\SyliusPurchaseApprovalPlugin\Repository\OrderApprovalRepositoryInterface;

/**
 * Guard of `sylius_shipment.ship`: an order is not shipped before it is approved (admin "Ship"
 * button, API, any code using the state machine).
 */
final class ShipmentApprovalGuardListener
{
    public const BLOCKER_CODE = 'tmonier_purchase_approval.not_approved';

    public function __construct(private readonly OrderApprovalRepositoryInterface $approvalRepository)
    {
    }

    public function __invoke(GuardEvent $event): void
    {
        $shipment = $event->getSubject();
        if (!$shipment instanceof ShipmentInterface) {
            return;
        }
        $order = $shipment->getOrder();
        if (!$order instanceof OrderInterface) {
            return;
        }
        $approval = $this->approvalRepository->findOneByOrder($order);
        if (null !== $approval && !$approval->isApproved()) {
            $event->addTransitionBlocker(new TransitionBlocker('The order is not approved.', self::BLOCKER_CODE));
        }
    }
}
