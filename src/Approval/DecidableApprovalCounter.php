<?php

declare(strict_types=1);

namespace Tmonier\SyliusPurchaseApprovalPlugin\Approval;

use Doctrine\ORM\QueryBuilder;
use Symfony\Contracts\Service\ResetInterface;
use Tmonier\SyliusCompanyAccountsPlugin\Entity\CompanyMemberInterface;
use Tmonier\SyliusPurchaseApprovalPlugin\Entity\MemberLimitInterface;
use Tmonier\SyliusPurchaseApprovalPlugin\Entity\OrderApprovalInterface;
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

    /** @var array<int, MemberLimitInterface|null> by member id: the count and the rows share one query */
    private array $limits = [];

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

        return $this->counts[$key] ??= $this->approvalRepository->countPendingDecidableBy($member, $this->limitOf($member));
    }

    /**
     * The oldest approvals the member may decide (they expire first); the caller checks that the
     * member approves (count() is not null).
     *
     * @return list<OrderApprovalInterface>
     */
    public function oldest(CompanyMemberInterface $member, int $max): array
    {
        return $this->approvalRepository->findOldestPendingDecidableBy($member, $this->limitOf($member), $max);
    }

    public function listQueryBuilder(CompanyMemberInterface $member): QueryBuilder
    {
        return $this->approvalRepository->createPendingDecidableByQueryBuilder($member, $this->limitOf($member));
    }

    public function reset(): void
    {
        $this->counts = [];
        $this->limits = [];
    }

    private function limitOf(CompanyMemberInterface $member): ?MemberLimitInterface
    {
        $key = (int) $member->getId();
        if (!array_key_exists($key, $this->limits)) {
            $this->limits[$key] = $this->limitRepository->findOneByMember($member);
        }

        return $this->limits[$key];
    }
}
