<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Sylius\Bundle\GridBundle\Form\Type\Filter\SelectFilterType;
use Tmonier\SyliusPurchaseApprovalPlugin\Api\Admin\AdminApprovalDecisionProcessor;
use Tmonier\SyliusPurchaseApprovalPlugin\Api\Admin\AdminApprovalProvider;
use Tmonier\SyliusPurchaseApprovalPlugin\Api\Shop\ShopApprovalDecisionProcessor;
use Tmonier\SyliusPurchaseApprovalPlugin\Api\Shop\ShopApprovalProvider;
use Tmonier\SyliusPurchaseApprovalPlugin\Approval\ApprovalDecisionMaker;
use Tmonier\SyliusPurchaseApprovalPlugin\Approval\ApprovalPolicy;
use Tmonier\SyliusPurchaseApprovalPlugin\Approval\ApprovalRequirementChecker;
use Tmonier\SyliusPurchaseApprovalPlugin\Approval\DecidableApprovalCounter;
use Tmonier\SyliusPurchaseApprovalPlugin\Approval\MemberLimitUpdater;
use Tmonier\SyliusPurchaseApprovalPlugin\Command\ExpireApprovalsCommand;
use Tmonier\SyliusPurchaseApprovalPlugin\Controller\Admin\ApprovalDecisionController;
use Tmonier\SyliusPurchaseApprovalPlugin\Controller\Shop\ApprovalController;
use Tmonier\SyliusPurchaseApprovalPlugin\Event\OrderApprovalEvent;
use Tmonier\SyliusPurchaseApprovalPlugin\EventListener\OrderApprovalRequestListener;
use Tmonier\SyliusPurchaseApprovalPlugin\EventListener\OrderCancellationListener;
use Tmonier\SyliusPurchaseApprovalPlugin\EventListener\PaymentCompletionAlertListener;
use Tmonier\SyliusPurchaseApprovalPlugin\EventListener\ShipmentApprovalGuardListener;
use Tmonier\SyliusPurchaseApprovalPlugin\Fixture\PurchaseApprovalFixture;
use Tmonier\SyliusPurchaseApprovalPlugin\Form\Extension\CompanyMemberLimitsTypeExtension;
use Tmonier\SyliusPurchaseApprovalPlugin\Grid\Filter\ApprovalStateFilter;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Tmonier\SyliusPurchaseApprovalPlugin\Mailer\AcknowledgementEmailSender;
use Tmonier\SyliusPurchaseApprovalPlugin\Mailer\ApprovalAwareOrderConfirmationHandler;
use Tmonier\SyliusPurchaseApprovalPlugin\Mailer\ApprovalEmailListener;
use Tmonier\SyliusPurchaseApprovalPlugin\Menu\AccountMenuListener;
use Tmonier\SyliusPurchaseApprovalPlugin\OrderHold\ApprovalHold;
use Tmonier\SyliusPurchaseApprovalPlugin\Security\ApprovalVoter;
use Tmonier\SyliusPurchaseApprovalPlugin\Twig\HeaderApprovalsExtension;
use Tmonier\SyliusPurchaseApprovalPlugin\Twig\PurchaseApprovalExtension;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    // Rules
    $services
        ->set('tmonier_purchase_approval.requirement_checker', ApprovalRequirementChecker::class)
        ->args([
            service('tmonier_company_accounts.repository.company_member'),
            service('tmonier_purchase_approval.repository.member_limit'),
            service('tmonier_company_accounts.company_order_eligibility_checker'),
        ])
    ;
    $services
        ->set('tmonier_purchase_approval.policy', ApprovalPolicy::class)
        ->args([
            service('tmonier_purchase_approval.repository.member_limit'),
            param('tmonier_purchase_approval.approver_roles'),
        ])
    ;
    $services->alias(ApprovalPolicy::class, 'tmonier_purchase_approval.policy');
    $services
        ->set('tmonier_purchase_approval.decision_maker', ApprovalDecisionMaker::class)
        ->args([
            service('doctrine.orm.entity_manager'),
            service('sylius_abstraction.state_machine'),
            service('tmonier_purchase_approval.policy'),
            service('event_dispatcher'),
        ])
    ;
    $services->alias(ApprovalDecisionMaker::class, 'tmonier_purchase_approval.decision_maker');
    $services
        ->set('tmonier_purchase_approval.member_limit_updater', MemberLimitUpdater::class)
        ->args([
            service('doctrine.orm.entity_manager'),
            service('tmonier_purchase_approval.repository.member_limit'),
            service('tmonier_purchase_approval.factory.member_limit'),
        ])
    ;
    $services->alias(MemberLimitUpdater::class, 'tmonier_purchase_approval.member_limit_updater');

    // Approval request in the checkout flush, workflow guards
    $services
        ->set('tmonier_purchase_approval.listener.approval_request', OrderApprovalRequestListener::class)
        ->args([
            service('tmonier_purchase_approval.requirement_checker'),
            service('tmonier_purchase_approval.repository.order_approval'),
            service('tmonier_purchase_approval.factory.order_approval'),
            service('event_dispatcher'),
        ])
        // Before the listeners that read the approvals of the flush (webhook outbox capture).
        ->tag('doctrine.event_listener', ['event' => 'onFlush', 'priority' => 100])
        ->tag('doctrine.event_listener', ['event' => 'postFlush'])
        ->tag('doctrine.event_listener', ['event' => 'onClear'])
        ->tag('kernel.reset', ['method' => 'reset'])
    ;
    $services
        ->set('tmonier_purchase_approval.listener.shipment_guard', ShipmentApprovalGuardListener::class)
        ->args([service('tmonier_purchase_approval.repository.order_approval')])
        ->tag('kernel.event_listener', ['event' => 'workflow.sylius_shipment.guard.ship'])
    ;
    $services
        ->set('tmonier_purchase_approval.listener.order_cancellation', OrderCancellationListener::class)
        ->args([service('tmonier_purchase_approval.repository.order_approval'), service('sylius_abstraction.state_machine')])
        ->tag('kernel.event_listener', ['event' => 'workflow.sylius_order.completed.cancel'])
    ;
    $services
        ->set('tmonier_purchase_approval.listener.payment_completion_alert', PaymentCompletionAlertListener::class)
        ->args([service('tmonier_purchase_approval.repository.order_approval'), service('logger')])
        ->tag('kernel.event_listener', ['event' => 'workflow.sylius_payment.completed.complete'])
        ->tag('monolog.logger', ['channel' => 'tmonier_purchase_approval'])
    ;

    // Order-hold kernel: payment entry blocker and unpaid orders cancellation exclusion (tags
    // set explicitly, the plugin services are not autoconfigured).
    $services
        ->set('tmonier_purchase_approval.order_hold.approval_hold', ApprovalHold::class)
        ->args([service('tmonier_purchase_approval.repository.order_approval')])
        ->tag('tmonier_order_hold.payment_entry_blocker')
        ->tag('tmonier_order_hold.unpaid_order_cancellation_exclusion')
    ;

    // E-mails
    $services
        ->set('tmonier_purchase_approval.mailer.acknowledgement_sender', AcknowledgementEmailSender::class)
        ->decorate('sylius.email_sender')
        ->args([service('.inner'), service('tmonier_purchase_approval.repository.order_approval')])
    ;
    $services
        ->set('tmonier_purchase_approval.mailer.order_confirmation_handler', ApprovalAwareOrderConfirmationHandler::class)
        ->decorate('sylius_api.command_handler.checkout.send_order_confirmation', null, 0, ContainerInterface::IGNORE_ON_INVALID_REFERENCE)
        ->args([service('.inner'), service('sylius.repository.order'), service('tmonier_purchase_approval.repository.order_approval')])
    ;
    $services
        ->set('tmonier_purchase_approval.mailer.approval_emails', ApprovalEmailListener::class)
        ->args([
            service('sylius.email_sender'),
            service('sylius.mailer.order_email_manager'),
            service('tmonier_company_accounts.repository.company_member'),
            service('tmonier_purchase_approval.policy'),
        ])
        ->tag('kernel.event_listener', ['event' => OrderApprovalEvent::REQUESTED, 'method' => 'onRequested'])
        ->tag('kernel.event_listener', ['event' => OrderApprovalEvent::APPROVED, 'method' => 'onApproved'])
        ->tag('kernel.event_listener', ['event' => OrderApprovalEvent::REJECTED, 'method' => 'onRejected'])
        ->tag('kernel.event_listener', ['event' => OrderApprovalEvent::EXPIRED, 'method' => 'onExpired'])
    ;

    // Security, Twig, menus, grid filter, admin form
    $services
        ->set('tmonier_purchase_approval.voter.approval', ApprovalVoter::class)
        ->args([service('tmonier_company_accounts.current_member_resolver'), service('tmonier_purchase_approval.policy')])
        ->tag('security.voter')
    ;
    $services
        ->set('tmonier_purchase_approval.twig.extension', PurchaseApprovalExtension::class)
        ->args([
            service('tmonier_purchase_approval.repository.order_approval'),
            service('tmonier_purchase_approval.repository.member_limit'),
            service('tmonier_company_accounts.current_member_resolver'),
            service('tmonier_purchase_approval.policy'),
            service('doctrine.orm.entity_manager'),
        ])
        ->tag('twig.extension')
        ->tag('kernel.reset', ['method' => 'reset'])
    ;
    $services
        ->set('tmonier_purchase_approval.decidable_counter', DecidableApprovalCounter::class)
        ->args([
            service('tmonier_purchase_approval.repository.order_approval'),
            service('tmonier_purchase_approval.repository.member_limit'),
            service('tmonier_purchase_approval.policy'),
        ])
        ->tag('kernel.reset', ['method' => 'reset'])
    ;
    $services->alias(DecidableApprovalCounter::class, 'tmonier_purchase_approval.decidable_counter');
    $services
        ->set('tmonier_purchase_approval.twig.header_extension', HeaderApprovalsExtension::class)
        ->args([
            service('tmonier_company_accounts.current_member_resolver'),
            service('tmonier_purchase_approval.decidable_counter'),
            param('tmonier_purchase_approval.header_badge'),
        ])
        ->tag('twig.extension')
        ->tag('kernel.reset', ['method' => 'reset'])
    ;
    $services
        ->set('tmonier_purchase_approval.listener.account_menu', AccountMenuListener::class)
        ->args([service('tmonier_company_accounts.current_member_resolver'), service('security.authorization_checker')])
        ->tag('kernel.event_listener', ['event' => 'sylius.menu.shop.account'])
    ;
    $services
        ->set('tmonier_purchase_approval.grid_filter.approval_state', ApprovalStateFilter::class)
        ->args([param('tmonier_purchase_approval.model.order_approval.class')])
        ->tag('sylius.grid_filter', ['type' => 'tmonier_purchase_approval_state', 'form_type' => SelectFilterType::class])
    ;
    $services
        ->set('tmonier_purchase_approval.form.extension.company_member_limits', CompanyMemberLimitsTypeExtension::class)
        ->args([
            service('tmonier_purchase_approval.repository.member_limit'),
            service('tmonier_purchase_approval.factory.member_limit'),
            service('sylius.repository.currency'),
            service('doctrine.orm.entity_manager'),
            service('translator'),
        ])
        ->tag('form.type_extension')
    ;

    // Controllers and command
    $services
        ->set('tmonier_purchase_approval.controller.shop.approval', ApprovalController::class)
        ->args([
            service('tmonier_company_accounts.current_member_resolver'),
            service('tmonier_purchase_approval.repository.order_approval'),
            service('tmonier_company_accounts.repository.company_member'),
            service('tmonier_purchase_approval.repository.member_limit'),
            service('tmonier_purchase_approval.decision_maker'),
            service('tmonier_purchase_approval.member_limit_updater'),
            service('sylius.context.channel'),
            service('translator'),
            service('tmonier_purchase_approval.decidable_counter'),
        ])
        ->call('setContainer', [service(\Psr\Container\ContainerInterface::class)])
        ->tag('controller.service_arguments')
        ->tag('container.service_subscriber')
        ->public()
    ;
    $services
        ->set('tmonier_purchase_approval.controller.admin.approval_decision', ApprovalDecisionController::class)
        ->args([
            service('sylius.repository.order'),
            service('tmonier_purchase_approval.repository.order_approval'),
            service('tmonier_purchase_approval.decision_maker'),
            service('translator'),
        ])
        ->call('setContainer', [service(\Psr\Container\ContainerInterface::class)])
        ->tag('controller.service_arguments')
        ->tag('container.service_subscriber')
        ->public()
    ;
    $services
        ->set('tmonier_purchase_approval.command.expire', ExpireApprovalsCommand::class)
        ->args([
            service('tmonier_purchase_approval.repository.order_approval'),
            service('tmonier_purchase_approval.decision_maker'),
            service('doctrine.orm.entity_manager'),
            service('doctrine'),
            service('logger'),
            param('tmonier_purchase_approval.expire_after_days'),
        ])
        ->tag('console.command')
        ->tag('monolog.logger', ['channel' => 'tmonier_purchase_approval'])
    ;

    // API
    $services
        ->set(ShopApprovalProvider::class)
        ->args([
            service('tmonier_company_accounts.current_member_resolver'),
            service('tmonier_purchase_approval.repository.order_approval'),
            service('security.authorization_checker'),
            service('tmonier_purchase_approval.policy'),
        ])
        ->tag('api_platform.state_provider')
    ;
    $services
        ->set(ShopApprovalDecisionProcessor::class)
        ->args([
            service(ShopApprovalProvider::class),
            service('tmonier_company_accounts.current_member_resolver'),
            service('tmonier_purchase_approval.decision_maker'),
            service('translator'),
            service('sylius.context.locale'),
        ])
        ->tag('api_platform.state_processor')
    ;
    $services
        ->set(AdminApprovalProvider::class)
        ->args([service('tmonier_purchase_approval.repository.order_approval')])
        ->tag('api_platform.state_provider')
    ;
    $services
        ->set(AdminApprovalDecisionProcessor::class)
        ->args([
            service(AdminApprovalProvider::class),
            service('tmonier_purchase_approval.decision_maker'),
            service('security.token_storage'),
            service('translator'),
        ])
        ->tag('api_platform.state_processor')
    ;

    if (class_exists(\Sylius\Bundle\FixturesBundle\Fixture\AbstractFixture::class)) {
        $services
            ->set('tmonier_purchase_approval.fixture.purchase_approval', PurchaseApprovalFixture::class)
            ->args([
                service('doctrine.orm.entity_manager'),
                service('tmonier_company_accounts.repository.company'),
                service('tmonier_purchase_approval.factory.member_limit'),
                service('tmonier_purchase_approval.repository.member_limit'),
                service('sylius.fixture.example_factory.order'),
            ])
            ->tag('sylius_fixtures.fixture')
        ;
    }
};
