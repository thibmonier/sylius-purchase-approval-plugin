<?php

declare(strict_types=1);

namespace Tmonier\SyliusPurchaseApprovalPlugin\DependencyInjection;

use Sylius\Bundle\CoreBundle\DependencyInjection\PrependDoctrineMigrationsTrait;
use Sylius\Bundle\ResourceBundle\DependencyInjection\Extension\AbstractResourceExtension;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Tmonier\SyliusPurchaseApprovalPlugin\Approval\ApprovalDecisionMaker;
use Tmonier\SyliusPurchaseApprovalPlugin\Entity\OrderApprovalInterface;
use Tmonier\SyliusPurchaseApprovalPlugin\Mailer\Emails;

final class TmonierSyliusPurchaseApprovalExtension extends AbstractResourceExtension implements PrependExtensionInterface
{
    use PrependDoctrineMigrationsTrait;

    private const INVOICING_PARAMETER = 'tmonier_purchase_approval.invoicing_bridge';

    private const OUTBOX_PARAMETER = 'tmonier_purchase_approval.webhook_outbox_bridge';

    private const ADMIN_ORDER_CREATION_PARAMETER = 'tmonier_purchase_approval.admin_order_creation_bridge';

    public function load(array $configs, ContainerBuilder $container): void
    {
        /** @var array{approver_roles: list<string>, expire_after_days: int, driver: string, resources: array<string, mixed>} $config */
        $config = $this->processConfiguration(new Configuration(), $configs);

        $this->registerResources('tmonier_purchase_approval', $config['driver'], $config['resources'], $container);
        $container->setParameter('tmonier_purchase_approval.approver_roles', array_values(array_unique($config['approver_roles'])));
        $container->setParameter('tmonier_purchase_approval.expire_after_days', $config['expire_after_days']);

        $loader = new PhpFileLoader($container, new FileLocator(__DIR__ . '/../../config'));
        $loader->load('services.php');

        // Optional bridges, decided at prepend time (when the other extensions are known).
        if ($this->flag($container, self::INVOICING_PARAMETER)) {
            $loader->load('services/invoicing.php');
        }
        if ($this->flag($container, self::OUTBOX_PARAMETER)) {
            $loader->load('services/webhook_outbox.php');
        }
        if ($this->flag($container, self::ADMIN_ORDER_CREATION_PARAMETER)) {
            $loader->load('services/admin_order_creation.php');
        }
    }

    public function prepend(ContainerBuilder $container): void
    {
        // Grids are processed before this extension is loaded: the model class parameters have
        // to exist at prepend time.
        $configs = array_map(
            static fn (array $config): array => isset($config['resources']) ? ['resources' => $config['resources']] : [],
            $container->getExtensionConfig($this->getAlias()),
        );
        /** @var array{resources: array<string, array{classes: array{model: string}}>} $config */
        $config = $this->processConfiguration(new Configuration(), $configs);
        foreach ($config['resources'] as $name => $resource) {
            $container->setParameter(sprintf('tmonier_purchase_approval.model.%s.class', $name), $resource['classes']['model']);
        }

        $container->setParameter(
            self::INVOICING_PARAMETER,
            $container->hasExtension('sylius_invoicing') && interface_exists(\Sylius\InvoicingPlugin\Creator\InvoiceCreatorInterface::class),
        );
        $container->setParameter(
            self::OUTBOX_PARAMETER,
            $container->hasExtension('tmonier_sylius_webhook_outbox') && interface_exists(\Tmonier\SyliusWebhookOutboxPlugin\Capture\ChangeDetectorInterface::class),
        );

        $container->setParameter(
            self::ADMIN_ORDER_CREATION_PARAMETER,
            $container->hasExtension('tmonier_sylius_admin_order_creation') && interface_exists(\Tmonier\SyliusAdminOrderCreationPlugin\Mailer\PaymentLinkEmailManagerInterface::class),
        );

        $container->prependExtensionConfig('doctrine', [
            'orm' => [
                'mappings' => [
                    'TmonierSyliusPurchaseApprovalPlugin' => [
                        'type' => 'xml',
                        'dir' => 'config/doctrine',
                        'prefix' => 'Tmonier\SyliusPurchaseApprovalPlugin\Entity',
                        'is_bundle' => true,
                    ],
                ],
            ],
        ]);

        $container->prependExtensionConfig('framework', [
            'workflows' => [
                ApprovalDecisionMaker::GRAPH => [
                    'type' => 'state_machine',
                    'marking_store' => ['type' => 'method', 'property' => 'state'],
                    'supports' => [OrderApprovalInterface::class],
                    'initial_marking' => OrderApprovalInterface::STATE_PENDING,
                    'places' => OrderApprovalInterface::STATES,
                    'transitions' => [
                        ApprovalDecisionMaker::TRANSITION_APPROVE => ['from' => OrderApprovalInterface::STATE_PENDING, 'to' => OrderApprovalInterface::STATE_APPROVED],
                        ApprovalDecisionMaker::TRANSITION_REJECT => ['from' => OrderApprovalInterface::STATE_PENDING, 'to' => OrderApprovalInterface::STATE_REJECTED],
                        ApprovalDecisionMaker::TRANSITION_EXPIRE => ['from' => OrderApprovalInterface::STATE_PENDING, 'to' => OrderApprovalInterface::STATE_EXPIRED],
                        ApprovalDecisionMaker::TRANSITION_CANCEL => ['from' => OrderApprovalInterface::STATE_PENDING, 'to' => OrderApprovalInterface::STATE_CANCELLED],
                    ],
                ],
            ],
        ]);

        $container->prependExtensionConfig('sylius_mailer', [
            'emails' => [
                Emails::ACKNOWLEDGEMENT => [
                    'subject' => 'tmonier_purchase_approval.email.acknowledgement.subject',
                    'template' => '@TmonierSyliusPurchaseApprovalPlugin/email/acknowledgement.html.twig',
                ],
                Emails::APPROVAL_REQUESTED => [
                    'subject' => 'tmonier_purchase_approval.email.requested.subject',
                    'template' => '@TmonierSyliusPurchaseApprovalPlugin/email/requested.html.twig',
                ],
                Emails::REJECTED => [
                    'subject' => 'tmonier_purchase_approval.email.rejected.subject',
                    'template' => '@TmonierSyliusPurchaseApprovalPlugin/email/rejected.html.twig',
                ],
                Emails::EXPIRED => [
                    'subject' => 'tmonier_purchase_approval.email.expired.subject',
                    'template' => '@TmonierSyliusPurchaseApprovalPlugin/email/expired.html.twig',
                ],
            ],
        ]);

        if ($container->hasExtension('api_platform')) {
            $container->prependExtensionConfig('api_platform', [
                'mapping' => ['paths' => [\dirname(__DIR__, 2) . '/config/api_platform', __DIR__ . '/../Api']],
            ]);
        }

        $this->prependDoctrineMigrations($container);
    }

    protected function getMigrationsNamespace(): string
    {
        return 'Tmonier\SyliusPurchaseApprovalPlugin\Migrations';
    }

    protected function getMigrationsDirectory(): string
    {
        return '@TmonierSyliusPurchaseApprovalPlugin/src/Migrations';
    }

    protected function getNamespacesOfMigrationsExecutedBefore(): array
    {
        return [
            'Sylius\Bundle\CoreBundle\Migrations',
            'Tmonier\SyliusCompanyAccountsPlugin\Migrations',
        ];
    }

    private function flag(ContainerBuilder $container, string $parameter): bool
    {
        return $container->hasParameter($parameter) && true === $container->getParameter($parameter);
    }
}
