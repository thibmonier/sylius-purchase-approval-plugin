<?php

declare(strict_types=1);

namespace Tmonier\SyliusPurchaseApprovalPlugin\Repository;

use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Tmonier\SyliusCompanyAccountsPlugin\Entity\CompanyInterface;
use Tmonier\SyliusCompanyAccountsPlugin\Entity\CompanyMemberInterface;
use Tmonier\SyliusPurchaseApprovalPlugin\Entity\MemberLimitInterface;

/** @extends RepositoryInterface<MemberLimitInterface> */
interface MemberLimitRepositoryInterface extends RepositoryInterface
{
    public function findOneByMember(CompanyMemberInterface $member): ?MemberLimitInterface;

    /** @return array<int, MemberLimitInterface> limits of the company members, by member id */
    public function findByCompanyIndexedByMemberId(CompanyInterface $company): array;
}
