<?php

declare(strict_types=1);

namespace Tmonier\SyliusPurchaseApprovalPlugin\Approval;

use Doctrine\ORM\QueryBuilder;
use Symfony\Contracts\Service\ResetInterface;
use Tmonier\SyliusCompanyAccountsPlugin\Entity\CompanyMemberInterface;
use Tmonier\SyliusPurchaseApprovalPlugin\Repository\MemberLimitRepositoryInterface;
use Tmonier\SyliusPurchaseApprovalPlugin\Repository\OrderApprovalRepositoryInterface;

/**
 * Pending approvals a member may decide (header entry, "to decide" list): one COUNT per member
 * and request, and none for a member who cannot approve.
 */
final class DecidableApprovalCounter implements ResetInterface
{
    /** @var array<int, int> by member id */
    private array $counts = [];

    public function __construct(
        private readonly OrderApprovalRepositoryInterface $approvalRepository,
        private readonly MemberLimitRepositoryInterface $limitRepository,
        private readonly ApprovalPolicy $policy,
    ) {
    }

    /** @return int|null null when the member does not approve (no entry to show) */
    public function count(?CompanyMemberInterface $member): ?int
    {
        if (!$member instanceof CompanyMemberInterface || !$this->policy->isApprover($member)) {
            return null;
        }
        $key = (int) $member->getId();

        return $this->counts[$key] ??= $this->approvalRepository->countPendingDecidableBy($member, $this->limitRepository->findOneByMember($member));
    }

    public function listQueryBuilder(CompanyMemberInterface $member): QueryBuilder
    {
        return $this->approvalRepository->createPendingDecidableByQueryBuilder($member, $this->limitRepository->findOneByMember($member));
    }

    public function reset(): void
    {
        $this->counts = [];
    }
}
