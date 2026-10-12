<?php

declare(strict_types=1);

namespace Tmonier\SyliusPurchaseApprovalPlugin\Fixture;

use Doctrine\ORM\EntityManagerInterface;
use Sylius\Bundle\CoreBundle\Fixture\Factory\ExampleFactoryInterface;
use Sylius\Bundle\FixturesBundle\Fixture\AbstractFixture;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Tmonier\SyliusCompanyAccountsPlugin\Entity\CompanyInterface;
use Tmonier\SyliusCompanyAccountsPlugin\Entity\CompanyMemberInterface;
use Tmonier\SyliusCompanyAccountsPlugin\Repository\CompanyRepositoryInterface;
use Tmonier\SyliusPurchaseApprovalPlugin\Approval\LimitAmount;
use Tmonier\SyliusPurchaseApprovalPlugin\Entity\MemberLimitInterface;
use Tmonier\SyliusPurchaseApprovalPlugin\Repository\MemberLimitRepositoryInterface;

/**
 * Demo limits on the company-accounts demo company (run it after that fixture): the buyer
 * needs an approval above `buyer_order_limit`, the approver may approve up to
 * `approver_approval_limit`; then orders of the buyer, which wait for the approver.
 */
final class PurchaseApprovalFixture extends AbstractFixture
{
    /**
     * @param FactoryInterface<MemberLimitInterface> $limitFactory
     * @param ExampleFactoryInterface<OrderInterface> $orderExampleFactory
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CompanyRepositoryInterface $companyRepository,
        private readonly FactoryInterface $limitFactory,
        private readonly MemberLimitRepositoryInterface $limitRepository,
        private readonly ExampleFactoryInterface $orderExampleFactory,
    ) {
    }

    public function getName(): string
    {
        return 'tmonier_purchase_approval';
    }

    /** @param array{company: string, buyer_order_limit: string, approver_approval_limit: string|null, channel: string|null, pending_orders: int} $options */
    public function load(array $options): void
    {
        $company = $this->companyRepository->findOneBy(['code' => $options['company']]);
        if (!$company instanceof CompanyInterface) {
            return;
        }
        $channel = null === $options['channel'] ? null : $this->entityManager->getRepository(ChannelInterface::class)->findOneBy(['code' => $options['channel']]);
        $channel ??= $this->entityManager->getRepository(ChannelInterface::class)->findOneBy(['enabled' => true], ['id' => 'ASC']);
        $currency = $channel instanceof ChannelInterface ? $channel->getBaseCurrency()?->getCode() : null;
        if (!$channel instanceof ChannelInterface || null === $currency) {
            return;
        }

        $buyer = null;
        foreach ($company->getMembers() as $member) {
            $amounts = match ($member->getRole()) {
                CompanyMemberInterface::ROLE_BUYER => [LimitAmount::parse($options['buyer_order_limit']), null],
                CompanyMemberInterface::ROLE_APPROVER => [null, LimitAmount::parse($options['approver_approval_limit'])],
                default => null,
            };
            if (null === $amounts || false === $amounts[0] || false === $amounts[1]) {
                continue;
            }
            $limit = $this->limitRepository->findOneByMember($member) ?? $this->limitFactory->createNew();
            $limit->setMember($member);
            $limit->setOrderLimit($amounts[0]);
            $limit->setApprovalLimit($amounts[1]);
            $limit->setCurrencyCode($currency);
            $limit->setUpdatedAt(new \DateTimeImmutable());
            $this->entityManager->persist($limit);
            if (CompanyMemberInterface::ROLE_BUYER === $member->getRole()) {
                $buyer = $member->getCustomer();
            }
        }
        $this->entityManager->flush();

        // Orders above the limit get their pending approval in the checkout flush.
        for ($i = 0; null !== $buyer && $i < $options['pending_orders']; ++$i) {
            $order = $this->orderExampleFactory->create(['channel' => $channel, 'customer' => $buyer, 'complete_date' => new \DateTime(sprintf('-%d hours', $i + 1))]);
            $this->entityManager->persist($order);
            $this->entityManager->flush();
        }
    }

    protected function configureOptionsNode(ArrayNodeDefinition $optionsNode): void
    {
        $optionsNode
            ->children()
                ->scalarNode('company')->defaultValue('ACME_INDUSTRIES')->cannotBeEmpty()->end()
                ->scalarNode('buyer_order_limit')->defaultValue('50.00')->cannotBeEmpty()->end()
                ->scalarNode('approver_approval_limit')->defaultValue('100000.00')->end()
                ->scalarNode('channel')->defaultNull()->end()
                ->integerNode('pending_orders')->defaultValue(2)->min(0)->end()
            ->end()
        ;
    }
}
