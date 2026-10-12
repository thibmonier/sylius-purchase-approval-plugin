<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/*
 * Test application only: services the tests read directly (removed from the container
 * otherwise, as nothing else uses them).
 */
return static function (ContainerConfigurator $container): void {
    $container->services()
        ->alias('tmonier_purchase_approval.test.approval_hold', 'tmonier_purchase_approval.order_hold.approval_hold')
        ->public()
    ;
    $container->services()
        ->alias('tmonier_purchase_approval.test.payment_link', 'tmonier_admin_order.mailer.payment_link')
        ->public()
    ;
};
