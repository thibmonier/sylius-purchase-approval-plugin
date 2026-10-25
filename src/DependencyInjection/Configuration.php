<?php

declare(strict_types=1);

namespace Tmonier\SyliusPurchaseApprovalPlugin\DependencyInjection;

use Sylius\Bundle\ResourceBundle\Controller\ResourceController;
use Sylius\Bundle\ResourceBundle\SyliusResourceBundle;
use Sylius\Resource\Factory\Factory;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Tmonier\SyliusCompanyAccountsPlugin\Entity\CompanyMemberInterface;
use Tmonier\SyliusPurchaseApprovalPlugin\Approval\ApprovalAmountResolver;
use Tmonier\SyliusPurchaseApprovalPlugin\Entity\MemberLimit;
use Tmonier\SyliusPurchaseApprovalPlugin\Entity\MemberLimitInterface;
use Tmonier\SyliusPurchaseApprovalPlugin\Entity\OrderApproval;
use Tmonier\SyliusPurchaseApprovalPlugin\Entity\OrderApprovalInterface;
use Tmonier\SyliusPurchaseApprovalPlugin\Repository\MemberLimitRepository;
use Tmonier\SyliusPurchaseApprovalPlugin\Repository\OrderApprovalRepository;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('tmonier_sylius_purchase_approval');
        /** @var ArrayNodeDefinition $rootNode */
        $rootNode = $treeBuilder->getRootNode();

        $rootNode
            ->addDefaultsIfNotSet()
            ->children()
                ->arrayNode('approver_roles')
                    ->info('Member roles that may approve the orders of their colleagues (within their approval limit).')
                    ->defaultValue([CompanyMemberInterface::ROLE_APPROVER])
                    ->requiresAtLeastOneElement()
                    ->enumPrototype()->values(CompanyMemberInterface::ROLES)->end()
                ->end()
                ->enumNode('amount_basis')
                    ->info('Amount compared to the order limit and frozen on the approval: "gross" (total, tax included) or "net" (total without its tax).')
                    ->values(ApprovalAmountResolver::BASES)
                    ->defaultValue(ApprovalAmountResolver::GROSS)
                ->end()
                ->booleanNode('header_badge')
                    ->info('Show the "Approvals" entry with the number of orders waiting for the member in the shop header (members who can approve only).')
                    ->defaultTrue()
                ->end()
                ->integerNode('expire_after_days')
                    ->info('Pending approvals older than this are expired (and their order cancelled) by tmonier:approval:expire.')
                    ->defaultValue(14)
                    ->min(1)
                ->end()
                ->scalarNode('driver')->defaultValue(SyliusResourceBundle::DRIVER_DOCTRINE_ORM)->end()
                ->arrayNode('resources')
                    ->addDefaultsIfNotSet()
                    ->append($this->resource('order_approval', OrderApproval::class, OrderApprovalInterface::class, OrderApprovalRepository::class))
                    ->append($this->resource('member_limit', MemberLimit::class, MemberLimitInterface::class, MemberLimitRepository::class))
                ->end()
            ->end()
        ;

        return $treeBuilder;
    }

    private function resource(string $name, string $model, string $interface, string $repository): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition($name);
        $node
            ->addDefaultsIfNotSet()
            ->children()
                ->arrayNode('classes')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('model')->defaultValue($model)->cannotBeEmpty()->end()
                        ->scalarNode('interface')->defaultValue($interface)->cannotBeEmpty()->end()
                        ->scalarNode('controller')->defaultValue(ResourceController::class)->cannotBeEmpty()->end()
                        ->scalarNode('repository')->defaultValue($repository)->cannotBeEmpty()->end()
                        ->scalarNode('factory')->defaultValue(Factory::class)->end()
                    ->end()
                ->end()
            ->end()
        ;

        return $node;
    }
}
