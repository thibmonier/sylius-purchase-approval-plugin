<?php

declare(strict_types=1);

namespace Tmonier\SyliusPurchaseApprovalPlugin\Repository;

use Doctrine\ORM\QueryBuilder;
use Sylius\Bundle\ResourceBundle\Doctrine\ORM\EntityRepository;
use Sylius\Component\Core\Model\OrderInterface;
use Tmonier\SyliusCompanyAccountsPlugin\Entity\CompanyInterface;
use Tmonier\SyliusCompanyAccountsPlugin\Entity\CompanyMemberInterface;
use Tmonier\SyliusPurchaseApprovalPlugin\Entity\MemberLimitInterface;
use Tmonier\SyliusPurchaseApprovalPlugin\Entity\OrderApprovalInterface;

class OrderApprovalRepository extends EntityRepository implements OrderApprovalRepositoryInterface
{
    public function findOneByOrder(OrderInterface $order): ?OrderApprovalInterface
    {
        foreach ($this->getEntityManager()->getUnitOfWork()->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof OrderApprovalInterface && $entity->getOrder() === $order) {
                return $entity;
            }
        }
        if (null === $order->getId()) {
            return null;
        }

        $approval = $this->findOneBy(['order' => $order]);

        return $approval instanceof OrderApprovalInterface ? $approval : null;
    }

    public function findByOrderIds(array $orderIds): array
    {
        if ([] === $orderIds) {
            return [];
        }
        /** @var list<OrderApprovalInterface> $approvals */
        $approvals = $this->createQueryBuilder('o')
            ->andWhere('IDENTITY(o.order) IN (:orders)')
            ->setParameter('orders', $orderIds)
            ->getQuery()
            ->getResult()
        ;
        $indexed = [];
        foreach ($approvals as $approval) {
            $id = $approval->getOrder()?->getId();
            if (is_int($id)) {
                $indexed[$id] = $approval;
            }
        }

        return $indexed;
    }

    public function findOneByOrderNumberAndCompany(string $number, CompanyInterface $company): ?OrderApprovalInterface
    {
        $approval = $this->createQueryBuilder('o')
            ->innerJoin('o.order', 'ord')
            ->andWhere('ord.number = :number')
            ->andWhere('o.company = :company')
            ->setParameter('number', $number)
            ->setParameter('company', $company)
            ->getQuery()
            ->getOneOrNullResult()
        ;

        return $approval instanceof OrderApprovalInterface ? $approval : null;
    }

    public function findOneByOrderNumber(string $number): ?OrderApprovalInterface
    {
        foreach ($this->getEntityManager()->getUnitOfWork()->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof OrderApprovalInterface && $entity->getOrder()?->getNumber() === $number) {
                return $entity;
            }
        }
        $approval = $this->createQueryBuilder('o')
            ->innerJoin('o.order', 'ord')
            ->andWhere('ord.number = :number')
            ->setParameter('number', $number)
            ->getQuery()
            ->getOneOrNullResult()
        ;

        return $approval instanceof OrderApprovalInterface ? $approval : null;
    }

    public function createListQueryBuilderByCompany(CompanyInterface $company, bool $pendingOnly): QueryBuilder
    {
        $queryBuilder = $this->createQueryBuilder('o')
            ->addSelect('ord', 'customer')
            ->innerJoin('o.order', 'ord')
            ->leftJoin('ord.customer', 'customer')
            ->andWhere('o.company = :company')
            ->setParameter('company', $company)
            ->addSelect(sprintf("CASE WHEN o.state = '%s' THEN 0 ELSE 1 END AS HIDDEN pendingFirst", OrderApprovalInterface::STATE_PENDING))
            ->addOrderBy('pendingFirst', 'ASC')
            ->addOrderBy('o.createdAt', 'DESC')
            ->addOrderBy('o.id', 'DESC')
        ;
        if ($pendingOnly) {
            $queryBuilder->andWhere('o.state = :pending')->setParameter('pending', OrderApprovalInterface::STATE_PENDING);
        }

        return $queryBuilder;
    }

    public function markConfirmationSent(OrderApprovalInterface $approval, \DateTimeImmutable $date): void
    {
        $approval->setConfirmationSentAt($date);
        if (null !== $approval->getId()) {
            $this->getEntityManager()->getConnection()->update(
                $this->getClassMetadata()->getTableName(),
                ['confirmation_sent_at' => $date],
                ['id' => $approval->getId()],
                ['confirmation_sent_at' => \Doctrine\DBAL\Types\Types::DATETIME_IMMUTABLE],
            );
        }
    }

    public function createAdminListQueryBuilder(?string $state): QueryBuilder
    {
        $queryBuilder = $this->createQueryBuilder('o')
            ->addSelect('ord')
            ->innerJoin('o.order', 'ord')
            ->orderBy('o.createdAt', 'DESC')
            ->addOrderBy('o.id', 'DESC')
        ;
        if (null !== $state) {
            $queryBuilder->andWhere('o.state = :state')->setParameter('state', $state);
        }

        return $queryBuilder;
    }

    public function countPendingByCompany(CompanyInterface $company): int
    {
        return (int) $this->createQueryBuilder('o')
            ->select('COUNT(o.id)')
            ->andWhere('o.company = :company')
            ->andWhere('o.state = :pending')
            ->setParameter('company', $company)
            ->setParameter('pending', OrderApprovalInterface::STATE_PENDING)
            ->getQuery()
            ->getSingleScalarResult()
        ;
    }

    public function createPendingDecidableByQueryBuilder(CompanyMemberInterface $member, ?MemberLimitInterface $limit): QueryBuilder
    {
        return $this->decidableBy($this->createQueryBuilder('o')->addSelect('ord', 'customer'), $member, $limit)
            ->leftJoin('ord.customer', 'customer')
            ->addOrderBy('o.createdAt', 'DESC')
            ->addOrderBy('o.id', 'DESC')
        ;
    }

    public function findOldestPendingDecidableBy(CompanyMemberInterface $member, ?MemberLimitInterface $limit, int $max): array
    {
        // customer.user is the inverse side of a one-to-one: fetched here, or one query per buyer.
        /** @var list<OrderApprovalInterface> $approvals */
        $approvals = $this->createPendingDecidableByQueryBuilder($member, $limit)
            ->addSelect('shopUser')
            ->leftJoin('customer.user', 'shopUser')
            ->orderBy('o.createdAt', 'ASC')
            ->addOrderBy('o.id', 'ASC')
            ->setMaxResults($max)
            ->getQuery()
            ->getResult()
        ;

        return $approvals;
    }

    public function countPendingDecidableBy(CompanyMemberInterface $member, ?MemberLimitInterface $limit): int
    {
        return (int) $this->decidableBy($this->createQueryBuilder('o')->select('COUNT(o.id)'), $member, $limit)
            ->getQuery()
            ->getSingleScalarResult()
        ;
    }

    public function findPendingIdsOfClosedOrders(int $limit): array
    {
        /** @var list<int|string> $ids */
        $ids = $this->createQueryBuilder('o')
            ->select('o.id')
            ->innerJoin('o.order', 'ord')
            ->andWhere('o.state = :pending')
            ->andWhere('ord.state <> :new')
            ->setParameter('pending', OrderApprovalInterface::STATE_PENDING)
            ->setParameter('new', \Sylius\Component\Core\Model\OrderInterface::STATE_NEW)
            ->orderBy('o.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getSingleColumnResult()
        ;

        return array_map(static fn (int|string $id): int => (int) $id, $ids);
    }

    public function findPendingIdsRequestedBefore(\DateTimeImmutable $date, int $limit): array
    {
        /** @var list<int|string> $ids */
        $ids = $this->createQueryBuilder('o')
            ->select('o.id')
            ->andWhere('o.state = :pending')
            ->andWhere('o.createdAt < :date')
            ->setParameter('pending', OrderApprovalInterface::STATE_PENDING)
            ->setParameter('date', $date)
            ->orderBy('o.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getSingleColumnResult()
        ;

        return array_map(static fn (int|string $id): int => (int) $id, $ids);
    }

    /** The rules of ApprovalPolicy::checkApprove(), in SQL. */
    private function decidableBy(QueryBuilder $queryBuilder, CompanyMemberInterface $member, ?MemberLimitInterface $limit): QueryBuilder
    {
        $queryBuilder
            ->innerJoin('o.order', 'ord')
            ->andWhere('o.company = :company')
            ->andWhere('o.state = :pending')
            ->setParameter('company', $member->getCompany())
            ->setParameter('pending', OrderApprovalInterface::STATE_PENDING)
        ;
        $customer = $member->getCustomer();
        if ($customer instanceof \Sylius\Component\Core\Model\CustomerInterface) {
            $queryBuilder->andWhere('ord.customer IS NULL OR ord.customer <> :customer')->setParameter('customer', $customer);
        }
        $approvalLimit = $limit?->getApprovalLimit();
        if (null !== $approvalLimit) {
            $queryBuilder
                ->andWhere('o.currencyCode = :currency AND o.total <= :approvalLimit')
                ->setParameter('currency', $limit->getCurrencyCode())
                ->setParameter('approvalLimit', $approvalLimit)
            ;
        }

        return $queryBuilder;
    }
}
