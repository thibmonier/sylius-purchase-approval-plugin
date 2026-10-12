<?php

declare(strict_types=1);

namespace Tmonier\SyliusPurchaseApprovalPlugin\Twig;

use Sylius\Component\Core\Model\OrderInterface;
use Tmonier\SyliusCompanyAccountsPlugin\Entity\CompanyInterface;
use Tmonier\SyliusCompanyAccountsPlugin\Entity\CompanyMemberInterface;
use Tmonier\SyliusCompanyAccountsPlugin\Member\CurrentMemberResolverInterface;
use Tmonier\SyliusPurchaseApprovalPlugin\Approval\ApprovalPolicy;
use Tmonier\SyliusPurchaseApprovalPlugin\Entity\MemberLimitInterface;
use Tmonier\SyliusPurchaseApprovalPlugin\Entity\OrderApprovalInterface;
use Tmonier\SyliusPurchaseApprovalPlugin\Repository\MemberLimitRepositoryInterface;
use Tmonier\SyliusPurchaseApprovalPlugin\Repository\OrderApprovalRepositoryInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class PurchaseApprovalExtension extends AbstractExtension
{
    public function __construct(
        private readonly OrderApprovalRepositoryInterface $approvalRepository,
        private readonly MemberLimitRepositoryInterface $limitRepository,
        private readonly CurrentMemberResolverInterface $currentMemberResolver,
        private readonly ApprovalPolicy $policy,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('tmonier_order_approval', $this->approval(...)),
            new TwigFunction('tmonier_pending_approvals_count', $this->pendingCount(...)),
            new TwigFunction('tmonier_member_limit', $this->limit(...)),
            new TwigFunction('tmonier_approval_refusal', $this->refusal(...)),
        ];
    }

    public function approval(mixed $order): ?OrderApprovalInterface
    {
        return $order instanceof OrderInterface && null !== $order->getId() ? $this->approvalRepository->findOneByOrder($order) : null;
    }

    public function pendingCount(mixed $company): int
    {
        return $company instanceof CompanyInterface ? $this->approvalRepository->countPendingByCompany($company) : 0;
    }

    public function limit(mixed $member): ?MemberLimitInterface
    {
        return $member instanceof CompanyMemberInterface ? $this->limitRepository->findOneByMember($member) : null;
    }

    /**
     * Why the signed-in member may not approve (or reject) the approval, as a translation key
     * of the `validators` domain; null when they may.
     */
    public function refusal(OrderApprovalInterface $approval, string $decision = 'approve'): ?string
    {
        $member = $this->currentMemberResolver->getMember();
        if (null === $member) {
            return ApprovalPolicy::ERROR_NOT_ALLOWED;
        }

        return 'reject' === $decision ? $this->policy->checkReject($member, $approval) : $this->policy->checkApprove($member, $approval);
    }
}
