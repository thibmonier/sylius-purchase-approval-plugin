<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Tmonier\SyliusPurchaseApprovalPlugin\Bridge\WebhookOutbox\ApprovalChangeDetector;
use Tmonier\SyliusPurchaseApprovalPlugin\Bridge\WebhookOutbox\ApprovalEventTypeProvider;

/*
 * Loaded only when tmonier/sylius-webhook-outbox-plugin is enabled: approval events in the
 * outbox, recorded in the transaction of the decision.
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services
        ->set('tmonier_purchase_approval.webhook.event_type_provider', ApprovalEventTypeProvider::class)
        ->tag('tmonier_webhook.event_type_provider')
    ;
    $services
        ->set('tmonier_purchase_approval.webhook.change_detector', ApprovalChangeDetector::class)
        ->args([service('tmonier_webhook.payload.order')])
        ->tag('tmonier_webhook.change_detector', ['priority' => 100])
    ;
};
