<?php

declare(strict_types=1);

namespace Tmonier\SyliusPurchaseApprovalPlugin\Approval;

use Doctrine\ORM\EntityManagerInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Tmonier\SyliusCompanyAccountsPlugin\Entity\CompanyMemberInterface;
use Tmonier\SyliusPurchaseApprovalPlugin\Entity\MemberLimitInterface;
use Tmonier\SyliusPurchaseApprovalPlugin\Repository\MemberLimitRepositoryInterface;

/**
 * Limits set by a company admin in the shop. They never change their own limits (no
 * self-escalation): another admin or the store does it.
 */
final class MemberLimitUpdater
{
    public const ERROR_NOT_ALLOWED = 'tmonier_purchase_approval.limit.not_allowed';

    public const ERROR_OWN_LIMITS = 'tmonier_purchase_approval.limit.own_limits';

    public const ERROR_INVALID_AMOUNT = 'tmonier_purchase_approval.limit.invalid_amount';

    public const ERROR_INVALID_CURRENCY = 'tmonier_purchase_approval.limit.invalid_currency';

    /** @param FactoryInterface<MemberLimitInterface> $limitFactory */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MemberLimitRepositoryInterface $limitRepository,
        private readonly FactoryInterface $limitFactory,
    ) {
    }

    /**
     * @param list<string> $allowedCurrencies currencies of the channel
     *
     * @return string|null the refusal (translation key, domain `validators`), null once saved
     */
    public function update(CompanyMemberInterface $actor, CompanyMemberInterface $target, ?string $orderLimit, ?string $approvalLimit, ?string $currencyCode, array $allowedCurrencies): ?string
    {
        $company = $actor->getCompany();
        if (!$actor->isActive() || !$actor->isAdmin() || null === $company || $target->getCompany()?->getId() !== $company->getId()) {
            return self::ERROR_NOT_ALLOWED;
        }
        if ($actor->getId() === $target->getId()) {
            return self::ERROR_OWN_LIMITS;
        }
        $order = LimitAmount::parse($orderLimit);
        $approval = LimitAmount::parse($approvalLimit);
        if (false === $order || false === $approval) {
            return self::ERROR_INVALID_AMOUNT;
        }
        if (!in_array($currencyCode, $allowedCurrencies, true)) {
            return self::ERROR_INVALID_CURRENCY;
        }

        $limit = $this->limitRepository->findOneByMember($target);
        if (null === $limit) {
            $limit = $this->limitFactory->createNew();
            $limit->setMember($target);
            $this->entityManager->persist($limit);
        }
        $limit->setOrderLimit($order);
        $limit->setApprovalLimit($approval);
        $limit->setCurrencyCode((string) $currencyCode);
        $limit->setUpdatedAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        return null;
    }
}
