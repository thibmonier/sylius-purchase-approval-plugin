<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusPurchaseApprovalPlugin\Unit\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Tmonier\SyliusPurchaseApprovalPlugin\DependencyInjection\Configuration;
use Tmonier\SyliusPurchaseApprovalPlugin\DependencyInjection\TmonierSyliusPurchaseApprovalExtension;

final class ExtensionTest extends TestCase
{
    public function testDefaults(): void
    {
        /** @var array{approver_roles: list<string>, expire_after_days: int, header_badge: bool} $config */
        $config = (new Processor())->processConfiguration(new Configuration(), []);

        self::assertSame(['approver'], $config['approver_roles']);
        self::assertSame(14, $config['expire_after_days']);
        self::assertTrue($config['header_badge']);
    }

    public function testTheHeaderEntryCanBeDisabledAndAdminsCanBeApprovers(): void
    {
        $container = $this->container([], ['header_badge' => false, 'approver_roles' => ['approver', 'admin']]);

        self::assertFalse($container->getParameter('tmonier_purchase_approval.header_badge'));
        self::assertSame(['approver', 'admin'], $container->getParameter('tmonier_purchase_approval.approver_roles'));
    }

    public function testUnknownRolesAreRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        (new Processor())->processConfiguration(new Configuration(), [['approver_roles' => ['owner']]]);
    }

    public function testTheWorkflowIsDeclared(): void
    {
        $container = $this->container([]);

        $workflows = $container->getExtensionConfig('framework')[0]['workflows'] ?? null;
        self::assertIsArray($workflows);
        $workflow = $workflows['tmonier_order_approval'] ?? null;
        self::assertIsArray($workflow);
        self::assertSame(['pending', 'approved', 'rejected', 'expired', 'cancelled'], $workflow['places'] ?? null);
        self::assertIsArray($workflow['transitions'] ?? null);
        self::assertSame(['approve', 'reject', 'expire', 'cancel'], array_keys($workflow['transitions']));
    }

    public function testBridgesAreLoadedOnlyWithTheirBundles(): void
    {
        $without = $this->container([]);
        self::assertFalse($without->hasDefinition('tmonier_purchase_approval.invoicing.invoice_creator'));
        self::assertFalse($without->hasDefinition('tmonier_purchase_approval.webhook.change_detector'));

        $with = $this->container(['sylius_invoicing', 'tmonier_sylius_webhook_outbox']);
        self::assertSame('sylius_invoicing.creator.invoice', $with->getDefinition('tmonier_purchase_approval.invoicing.invoice_creator')->getDecoratedService()[0] ?? null);
        self::assertArrayHasKey('tmonier_webhook.change_detector', $with->getDefinition('tmonier_purchase_approval.webhook.change_detector')->getTags());
    }

    /**
     * @param list<string> $otherExtensions
     * @param array<string, mixed> $config
     */
    private function container(array $otherExtensions, array $config = []): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', false);
        foreach ($otherExtensions as $alias) {
            $container->registerExtension(new class($alias) extends Extension {
                public function __construct(private readonly string $alias)
                {
                }

                public function load(array $configs, ContainerBuilder $container): void
                {
                }

                public function getAlias(): string
                {
                    return $this->alias;
                }
            });
        }
        $extension = new TmonierSyliusPurchaseApprovalExtension();
        $container->registerExtension($extension);
        $extension->prepend($container);
        $extension->load([$config], $container);

        return $container;
    }
}
