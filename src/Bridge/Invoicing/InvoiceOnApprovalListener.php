<?php

declare(strict_types=1);

namespace Tmonier\SyliusPurchaseApprovalPlugin\Bridge\Invoicing;

use Sylius\InvoicingPlugin\Event\OrderPlaced;
use Symfony\Component\Messenger\MessageBusInterface;
use Tmonier\SyliusPurchaseApprovalPlugin\Event\OrderApprovalEvent;

/** The approval is the moment the order is really placed for invoicing: same event as the plugin. */
final class InvoiceOnApprovalListener
{
    public function __construct(private readonly MessageBusInterface $eventBus)
    {
    }

    public function __invoke(OrderApprovalEvent $event): void
    {
        $number = $event->getApproval()->getOrder()?->getNumber();
        if (null !== $number) {
            $this->eventBus->dispatch(new OrderPlaced($number, $event->getApproval()->getDecidedAt() ?? new \DateTimeImmutable()));
        }
    }
}
