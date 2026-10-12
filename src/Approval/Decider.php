<?php

declare(strict_types=1);

namespace Tmonier\SyliusPurchaseApprovalPlugin\Approval;

use Sylius\Component\Core\Model\AdminUserInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Tmonier\SyliusCompanyAccountsPlugin\Entity\CompanyMemberInterface;
use Tmonier\SyliusPurchaseApprovalPlugin\Entity\OrderApprovalInterface;

/** Who takes a decision: a company member (bound by ApprovalPolicy), an administrator or the system. */
final class Decider
{
    private function __construct(
        public readonly string $type,
        public readonly ?CompanyMemberInterface $member,
        public readonly ?CustomerInterface $customer,
        public readonly ?string $name,
    ) {
    }

    public static function member(CompanyMemberInterface $member): self
    {
        $customer = $member->getCustomer();
        $customer = $customer instanceof CustomerInterface ? $customer : null;
        $name = null === $customer ? null : trim($customer->getFullName());

        return new self(OrderApprovalInterface::DECIDED_BY_MEMBER, $member, $customer, '' === $name ? null : $name);
    }

    public static function administrator(?AdminUserInterface $adminUser): self
    {
        $name = null === $adminUser ? '' : trim(sprintf('%s %s', (string) $adminUser->getFirstName(), (string) $adminUser->getLastName()));
        if ('' === $name) {
            $name = $adminUser?->getUsername();
        }

        return new self(OrderApprovalInterface::DECIDED_BY_ADMINISTRATOR, null, null, $name);
    }

    public static function system(): self
    {
        return new self(OrderApprovalInterface::DECIDED_BY_SYSTEM, null, null, null);
    }
}
