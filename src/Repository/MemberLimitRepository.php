<?php

declare(strict_types=1);

namespace Tmonier\SyliusPurchaseApprovalPlugin\Repository;

use Sylius\Bundle\ResourceBundle\Doctrine\ORM\EntityRepository;
use Tmonier\SyliusCompanyAccountsPlugin\Entity\CompanyInterface;
use Tmonier\SyliusCompanyAccountsPlugin\Entity\CompanyMemberInterface;
use Tmonier\SyliusPurchaseApprovalPlugin\Entity\MemberLimitInterface;

class MemberLimitRepository extends EntityRepository implements MemberLimitRepositoryInterface
{
    public function findOneByMember(CompanyMemberInterface $member): ?MemberLimitInterface
    {
        if (null === $member->getId()) {
            return null;
        }
        $limit = $this->findOneBy(['member' => $member]);

        return $limit instanceof MemberLimitInterface ? $limit : null;
    }

    public function findByCompanyIndexedByMemberId(CompanyInterface $company): array
    {
        /** @var list<MemberLimitInterface> $limits */
        $limits = $this->createQueryBuilder('o')
            ->innerJoin('o.member', 'membership')
            ->andWhere('membership.company = :company')
            ->setParameter('company', $company)
            ->getQuery()
            ->getResult()
        ;
        $indexed = [];
        foreach ($limits as $limit) {
            $id = $limit->getMember()?->getId();
            if (null !== $id) {
                $indexed[$id] = $limit;
            }
        }

        return $indexed;
    }
}
