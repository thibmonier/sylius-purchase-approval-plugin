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
 * checkout) whose order limit it exceeds (the amount compared is the one of the amount resolver: total tax included or not). A limit in another currency than the order cannot
 * be compared: the order needs an approval. No limit configured: no approval.
 */
final readonly class ApprovalRequirementChecker
{
    public function __construct(
        private CompanyMemberRepositoryInterface $memberRepository,
        private MemberLimitRepositoryInterface $limitRepository,
        private CompanyOrderEligibilityCheckerInterface $eligibilityChecker,
        private ApprovalAmountResolverInterface $amountResolver,
    ) {
    }

    public function getRequirement(OrderInterface $order): ?ApprovalRequirement
    {
        $customer = $order->getCustomer();
        if (!$customer instanceof CustomerInterface || null === $customer->getId()) {
            return null;
        }
        $member = $this->memberRepository->findOneByCustomer($customer);
        if (!$member instanceof \Tmonier\SyliusCompanyAccountsPlugin\Entity\CompanyMemberInterface || !$member->isActive() || !$this->eligibilityChecker->isEligible($order)) {
            return null;
        }
        $limit = $this->limitRepository->findOneByMember($member);
        $orderLimit = $limit?->getOrderLimit();
        if (!$limit instanceof \Tmonier\SyliusPurchaseApprovalPlugin\Entity\MemberLimitInterface || null === $orderLimit) {
            return null;
        }
        if ($limit->getCurrencyCode() !== $order->getCurrencyCode()) {
            return new ApprovalRequirement($member, null);
        }

        return $this->amountResolver->resolve($order) > $orderLimit ? new ApprovalRequirement($member, $orderLimit) : null;
    }
}
