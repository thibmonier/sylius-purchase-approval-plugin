<?php

declare(strict_types=1);

namespace Tmonier\SyliusPurchaseApprovalPlugin;

use Sylius\Bundle\CoreBundle\Application\SyliusPluginTrait;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use Tmonier\SyliusPurchaseApprovalPlugin\DependencyInjection\TmonierSyliusPurchaseApprovalExtension;

final class TmonierSyliusPurchaseApprovalPlugin extends Bundle
{
    use SyliusPluginTrait;

    /**
     * @param (\Closure(string): bool)|null $interfaceExists answers "does this interface exist?", interface_exists() by default;
     *                                                       a test passes a closure that answers false to play a shop without the health plugin
     */
    public function __construct(
        private readonly ?\Closure $interfaceExists = null,
    ) {
    }

    protected function createContainerExtension(): ExtensionInterface
    {
        return new TmonierSyliusPurchaseApprovalExtension($this->interfaceExists);
    }

    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
