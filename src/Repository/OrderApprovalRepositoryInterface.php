<?php

declare(strict_types=1);

namespace Tmonier\SyliusPurchaseApprovalPlugin\Repository;

use Doctrine\ORM\QueryBuilder;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Tmonier\SyliusCompanyAccountsPlugin\Entity\CompanyInterface;
use Tmonier\SyliusPurchaseApprovalPlugin\Entity\OrderApprovalInterface;

/** @extends RepositoryInterface<OrderApprovalInterface> */
interface OrderApprovalRepositoryInterface extends RepositoryInterface
{
    /** Also an approval persisted in the flush running now (not inserted yet). */
    public function findOneByOrder(OrderInterface $order): ?OrderApprovalInterface;

    public function findOneByOrderNumberAndCompany(string $number, CompanyInterface $company): ?OrderApprovalInterface;

    /** Also an approval persisted in the flush running now (not inserted yet). */
    public function findOneByOrderNumber(string $number): ?OrderApprovalInterface;

    /** Approvals of the company, pending first, then the most recent decisions. */
    public function createListQueryBuilderByCompany(CompanyInterface $company, bool $pendingOnly): QueryBuilder;

    /**
     * Records, without flushing the unit of work, that the confirmation went out: a late
     * asynchronous confirmation of the same order is then dropped.
     */
    public function markConfirmationSent(OrderApprovalInterface $approval, \DateTimeImmutable $date): void;

    /** All approvals for the store, most recent first, optionally in one state. */
    public function createAdminListQueryBuilder(?string $state): QueryBuilder;

    public function countPendingByCompany(CompanyInterface $company): int;

    /**
     * Ids of the approvals still pending that were requested before the date.
     *
     * @return list<int>
     */
    public function findPendingIdsRequestedBefore(\DateTimeImmutable $date, int $limit): array;
}
