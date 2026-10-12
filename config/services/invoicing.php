<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Tmonier\SyliusPurchaseApprovalPlugin\Bridge\Invoicing\ApprovalAwareInvoiceCreator;
use Tmonier\SyliusPurchaseApprovalPlugin\Bridge\Invoicing\InvoiceOnApprovalListener;
use Tmonier\SyliusPurchaseApprovalPlugin\Event\OrderApprovalEvent;

/*
 * Loaded only when sylius/invoicing-plugin is enabled: the invoice of an order that needs an
 * approval is generated when it is approved, never for a rejected or expired one.
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services
        ->set('tmonier_purchase_approval.invoicing.invoice_creator', ApprovalAwareInvoiceCreator::class)
        ->decorate('sylius_invoicing.creator.invoice')
        ->args([service('.inner'), service('tmonier_purchase_approval.repository.order_approval')])
    ;
    $services
        ->set('tmonier_purchase_approval.invoicing.invoice_on_approval', InvoiceOnApprovalListener::class)
        ->args([service('sylius.event_bus')])
        ->tag('kernel.event_listener', ['event' => OrderApprovalEvent::APPROVED])
    ;
};
