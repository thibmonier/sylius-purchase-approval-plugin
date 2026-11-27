<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Tmonier\SyliusPurchaseApprovalPlugin\Health\ClosedOrdersCheck;
use Tmonier\SyliusPurchaseApprovalPlugin\Health\OverdueCheck;

/*
 * The probes of the plugin, apart from its other services: the extension loads this file only when the health plugin
 * is installed. The autoconfiguration of the health plugin gives them their tag, never tag them by hand.
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services
        ->set('tmonier_purchase_approval.health.overdue', OverdueCheck::class)
        ->args([
            service('tmonier_purchase_approval.repository.order_approval'),
            service('clock'),
            param('tmonier_purchase_approval.expire_after_days'),
        ])
        ->autoconfigure()
    ;
    $services
        ->set('tmonier_purchase_approval.health.closed_orders', ClosedOrdersCheck::class)
        ->args([service('tmonier_purchase_approval.repository.order_approval')])
        ->autoconfigure()
    ;
};
