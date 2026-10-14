<?php

declare(strict_types=1);

namespace Tmonier\SyliusPurchaseApprovalPlugin\Security;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Tmonier\SyliusCompanyAccountsPlugin\Entity\CompanyInterface;
use Tmonier\SyliusCompanyAccountsPlugin\Member\CurrentMemberResolverInterface;
use Tmonier\SyliusPurchaseApprovalPlugin\Approval\ApprovalPolicy;
use Tmonier\SyliusPurchaseApprovalPlugin\Entity\OrderApprovalInterface;

/** @extends Voter<string, CompanyInterface|OrderApprovalInterface> */
final class ApprovalVoter extends Voter
{
    public function __construct(
        private readonly CurrentMemberResolverInterface $currentMemberResolver,
        private readonly ApprovalPolicy $policy,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return match ($attribute) {
            ApprovalPermissions::APPROVAL_LIST, ApprovalPermissions::LIMITS_MANAGE => $subject instanceof CompanyInterface,
            ApprovalPermissions::APPROVAL_VIEW, ApprovalPermissions::APPROVAL_DECIDE => $subject instanceof OrderApprovalInterface,
            default => false,
        };
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, mixed $vote = null): bool
    {
        if ($subject instanceof OrderApprovalInterface && ApprovalPermissions::APPROVAL_VIEW === $attribute) {
            $customer = $this->currentMemberResolver->getCustomer($token);
            $buyer = $subject->getOrder()?->getCustomer();
            if ($customer instanceof \Sylius\Component\Core\Model\CustomerInterface && $buyer instanceof \Sylius\Component\Customer\Model\CustomerInterface && $customer->getId() === $buyer->getId()) {
                return true;
            }
        }

        $member = $this->currentMemberResolver->getMember($token);
        $company = $subject instanceof OrderApprovalInterface ? $subject->getCompany() : $subject;
        if (!$member instanceof \Tmonier\SyliusCompanyAccountsPlugin\Entity\CompanyMemberInterface || null === $company || null === $company->getId() || $member->getCompany()?->getId() !== $company->getId() || !$member->isActive()) {
            return false;
        }

        return ApprovalPermissions::LIMITS_MANAGE === $attribute ? $member->isAdmin() : $this->policy->isApprover($member);
    }
}
