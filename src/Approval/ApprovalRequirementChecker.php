<?php

declare(strict_types=1);

namespace Tmonier\SyliusPurchaseApprovalPlugin\Approval;

use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Tmonier\SyliusCompanyAccountsPlugin\Member\CompanyOrderEligibilityCheckerInterface;
use Tmonier\SyliusCompanyAccountsPlugin\Repository\CompanyMemberRepositoryInterface;
use Tmonier\SyliusPurchaseApprovalPlugin\Repository\MemberLimitRepositoryInterface;

/**
 * An order needs an approval when it is placed for an active member of an active company
 * (signed in, or by staff on their behalf: company-accounts eligibility, never a guest
 * checkout) whose order limit it exceeds. A limit in another currency than the order cannot
 * be compared: the order needs an approval. No limit configured: no approval.
 */
final class ApprovalRequirementChecker
{
    public function __construct(
        private readonly CompanyMemberRepositoryInterface $memberRepository,
        private readonly MemberLimitRepositoryInterface $limitRepository,
        private readonly CompanyOrderEligibilityCheckerInterface $eligibilityChecker,
    ) {
    }

    public function getRequirement(OrderInterface $order): ?ApprovalRequirement
    {
        $customer = $order->getCustomer();
        if (!$customer instanceof CustomerInterface || null === $customer->getId()) {
            return null;
        }
        $member = $this->memberRepository->findOneByCustomer($customer);
        if (null === $member || !$member->isActive() || !$this->eligibilityChecker->isEligible($order)) {
            return null;
        }
        $limit = $this->limitRepository->findOneByMember($member);
        $orderLimit = $limit?->getOrderLimit();
        if (null === $limit || null === $orderLimit) {
            return null;
        }
        if ($limit->getCurrencyCode() !== $order->getCurrencyCode()) {
            return new ApprovalRequirement($member, null);
        }

        return $order->getTotal() > $orderLimit ? new ApprovalRequirement($member, $orderLimit) : null;
    }
}
