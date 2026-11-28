<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusPurchaseApprovalPlugin\Unit\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Tmonier\SyliusPlatformHealthPlugin\Health\HealthCheckInterface;
use Tmonier\SyliusPurchaseApprovalPlugin\DependencyInjection\TmonierSyliusPurchaseApprovalExtension;
use Tmonier\SyliusPurchaseApprovalPlugin\TmonierSyliusPurchaseApprovalPlugin;

/**
 * A shop that installed the plugin and not the health plugin: the extension loads, no probe is registered, and no class of
 * the health plugin is needed. The interface check is injected, the health plugin being installed here for the tests.
 */
final class HealthProbesExtensionTest extends TestCase
{
    private const PROBES = ['tmonier_purchase_approval.health.overdue', 'tmonier_purchase_approval.health.closed_orders'];

    public function testTheExtensionLoadsWithoutAnyProbeWhenTheHealthPluginIsAbsent(): void
    {
        $container = $this->loaded(static fn (string $interface): bool => false);

        self::assertTrue($container->hasDefinition('tmonier_purchase_approval.command.expire'), 'The services of the plugin are loaded.');
        foreach (self::PROBES as $probe) {
            self::assertFalse($container->hasDefinition($probe), $probe);
        }
    }

    public function testNoServiceOfTheHealthNamespaceIsRegisteredWithoutTheHealthPlugin(): void
    {
        $container = $this->loaded(static fn (string $interface): bool => false);

        $classes = array_map(static fn ($definition): ?string => $definition->getClass(), $container->getDefinitions());
        self::assertSame([], array_values(array_filter($classes, static fn (?string $class): bool => null !== $class && str_contains($class, '\\Health\\'))));
    }

    public function testTheProbesAreAutoconfiguredWhenTheHealthPluginIsInstalled(): void
    {
        $container = $this->loaded(static fn (string $interface): bool => HealthCheckInterface::class === $interface);

        foreach (self::PROBES as $probe) {
            self::assertTrue($container->hasDefinition($probe), $probe);
            self::assertTrue($container->getDefinition($probe)->isAutoconfigured(), $probe);
        }
    }

    public function testTheOverdueProbeReadsTheConfiguredExpiryDelay(): void
    {
        $container = $this->loaded(static fn (string $interface): bool => true, ['expire_after_days' => 5]);

        self::assertSame(5, $container->getParameter('tmonier_purchase_approval.expire_after_days'));
        self::assertSame('%tmonier_purchase_approval.expire_after_days%', $container->getDefinition('tmonier_purchase_approval.health.overdue')->getArgument(2));
    }

    public function testTheBundleHandsTheCheckToItsExtension(): void
    {
        $asked = [];
        $bundle = new TmonierSyliusPurchaseApprovalPlugin(static function (string $interface) use (&$asked): bool {
            $asked[] = $interface;

            return false;
        });
        $extension = $bundle->getContainerExtension();
        self::assertNotNull($extension);
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', false);
        $container->registerExtension($extension);
        $extension->load([[]], $container);

        self::assertSame([HealthCheckInterface::class], $asked);
    }

    /**
     * @param \Closure(string): bool $interfaceExists
     * @param array<string, mixed>   $config
     */
    private function loaded(\Closure $interfaceExists, array $config = []): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', false);
        $extension = new TmonierSyliusPurchaseApprovalExtension($interfaceExists);
        $container->registerExtension($extension);
        $extension->prepend($container);
        $extension->load([$config], $container);

        return $container;
    }
}
