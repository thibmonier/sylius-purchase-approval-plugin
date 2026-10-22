<?php

declare(strict_types=1);

namespace Tmonier\SyliusPurchaseApprovalPlugin\Repository;

use Doctrine\ORM\QueryBuilder;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Tmonier\SyliusCompanyAccountsPlugin\Entity\CompanyInterface;
use Tmonier\SyliusCompanyAccountsPlugin\Entity\CompanyMemberInterface;
use Tmonier\SyliusPurchaseApprovalPlugin\Entity\MemberLimitInterface;
use Tmonier\SyliusPurchaseApprovalPlugin\Entity\OrderApprovalInterface;

/** @extends RepositoryInterface<OrderApprovalInterface> */
interface OrderApprovalRepositoryInterface extends RepositoryInterface
{
    /** Also an approval persisted in the flush running now (not inserted yet). */
    public function findOneByOrder(OrderInterface $order): ?OrderApprovalInterface;

    /**
     * Approvals of several orders in one query (lists, grids).
     *
     * @param list<int> $orderIds
     *
     * @return array<int, OrderApprovalInterface> by order id
     */
    public function findByOrderIds(array $orderIds): array;

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
     * Pending approvals of the member's company that the member may approve (ApprovalPolicy):
     * not their own orders, none above their approval limit (same currency as the order).
     * Most recent first.
     */
    public function createPendingDecidableByQueryBuilder(CompanyMemberInterface $member, ?MemberLimitInterface $limit): QueryBuilder;

    /** Same approvals as createPendingDecidableByQueryBuilder(), with one light COUNT query. */
    public function countPendingDecidableBy(CompanyMemberInterface $member, ?MemberLimitInterface $limit): int;

    /**
     * Ids of the approvals still pending whose order is no longer new (cancelled by a path that
     * bypassed the order workflow).
     *
     * @return list<int>
     */
    public function findPendingIdsOfClosedOrders(int $limit): array;

    /**
     * Ids of the approvals still pending that were requested before the date.
     *
     * @return list<int>
     */
    public function findPendingIdsRequestedBefore(\DateTimeImmutable $date, int $limit): array;
}
