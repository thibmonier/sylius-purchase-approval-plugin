<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Tmonier\SyliusPurchaseApprovalPlugin\Bridge\AdminOrderCreation\ApprovalAwarePaymentLinkEmailManager;

/*
 * Loaded only when tmonier/sylius-admin-order-creation-plugin is enabled: no payment link for
 * a staff order that waits for its approval.
 */
return static function (ContainerConfigurator $container): void {
    $container->services()
        ->set('tmonier_purchase_approval.admin_order_creation.payment_link', ApprovalAwarePaymentLinkEmailManager::class)
        ->decorate('tmonier_admin_order.mailer.payment_link')
        ->args([service('.inner'), service('tmonier_purchase_approval.repository.order_approval')])
    ;
};
