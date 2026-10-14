<?php

declare(strict_types=1);

namespace Tmonier\SyliusPurchaseApprovalPlugin\Approval;

use Tmonier\SyliusCompanyAccountsPlugin\Entity\CompanyMemberInterface;
use Tmonier\SyliusPurchaseApprovalPlugin\Entity\OrderApprovalInterface;
use Tmonier\SyliusPurchaseApprovalPlugin\Repository\MemberLimitRepositoryInterface;

/**
 * Who may decide on an approval in the shop: an active member of the order's company with an
 * approver role, never for an order of their own (no self-approval), and, to approve, within
 * their approval limit (same currency as the order). Store administrators are not bound.
 */
final readonly class ApprovalPolicy
{
    public const ERROR_NOT_PENDING = 'tmonier_purchase_approval.approval.not_pending';

    public const ERROR_NOT_ALLOWED = 'tmonier_purchase_approval.approval.not_allowed';

    public const ERROR_OWN_ORDER = 'tmonier_purchase_approval.approval.own_order';

    public const ERROR_ABOVE_LIMIT = 'tmonier_purchase_approval.approval.above_limit';

    /** @param list<string> $approverRoles */
    public function __construct(
        private MemberLimitRepositoryInterface $limitRepository,
        private array $approverRoles,
    ) {
    }

    /** Member of the order's company who may see and decide on its approvals at all. */
    public function isApprover(CompanyMemberInterface $member): bool
    {
        return $member->isActive() && in_array($member->getRole(), $this->approverRoles, true);
    }

    /** @return string|null the refusal (translation key, domain `validators`), null when allowed */
    public function checkApprove(CompanyMemberInterface $member, OrderApprovalInterface $approval): ?string
    {
        $error = $this->checkReject($member, $approval);
        if (null !== $error) {
            return $error;
        }
        $limit = $this->limitRepository->findOneByMember($member);
        $approvalLimit = $limit?->getApprovalLimit();
        if (!$limit instanceof \Tmonier\SyliusPurchaseApprovalPlugin\Entity\MemberLimitInterface || null === $approvalLimit) {
            return null;
        }

        return $limit->getCurrencyCode() !== $approval->getCurrencyCode() || $approval->getTotal() > $approvalLimit
            ? self::ERROR_ABOVE_LIMIT
            : null;
    }

    /** @return string|null the refusal (translation key, domain `validators`), null when allowed */
    public function checkReject(CompanyMemberInterface $member, OrderApprovalInterface $approval): ?string
    {
        $company = $approval->getCompany();
        if (!$this->isApprover($member) || !$company instanceof \Tmonier\SyliusCompanyAccountsPlugin\Entity\CompanyInterface || $member->getCompany()?->getId() !== $company->getId()) {
            return self::ERROR_NOT_ALLOWED;
        }
        if (!$approval->isPending()) {
            return self::ERROR_NOT_PENDING;
        }
        $buyer = $approval->getOrder()?->getCustomer();
        $self = $member->getCustomer();
        if ($buyer instanceof \Sylius\Component\Customer\Model\CustomerInterface && $self instanceof \Sylius\Component\Core\Model\CustomerInterface && $buyer->getId() === $self->getId()) {
            return self::ERROR_OWN_ORDER;
        }

        return null;
    }
}
