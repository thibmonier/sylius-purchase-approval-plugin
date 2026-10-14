<?php

declare(strict_types=1);

namespace Tmonier\SyliusPurchaseApprovalPlugin\Approval;

use Sylius\Component\Core\Model\AdminUserInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Tmonier\SyliusCompanyAccountsPlugin\Entity\CompanyMemberInterface;
use Tmonier\SyliusPurchaseApprovalPlugin\Entity\OrderApprovalInterface;

/** Who takes a decision: a company member (bound by ApprovalPolicy), an administrator or the system. */
final readonly class Decider
{
    private function __construct(
        public string $type,
        public ?CompanyMemberInterface $member,
        public ?CustomerInterface $customer,
        public ?string $name,
    ) {
    }

    public static function member(CompanyMemberInterface $member): self
    {
        $customer = $member->getCustomer();
        $customer = $customer instanceof CustomerInterface ? $customer : null;
        $name = $customer instanceof \Sylius\Component\Core\Model\CustomerInterface ? trim($customer->getFullName()) : null;

        return new self(OrderApprovalInterface::DECIDED_BY_MEMBER, $member, $customer, '' === $name ? null : $name);
    }

    public static function administrator(?AdminUserInterface $adminUser): self
    {
        $name = $adminUser instanceof \Sylius\Component\Core\Model\AdminUserInterface ? trim(sprintf('%s %s', (string) $adminUser->getFirstName(), (string) $adminUser->getLastName())) : '';
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
