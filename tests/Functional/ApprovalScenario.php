<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusPurchaseApprovalPlugin\Functional;

use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Tests\Tmonier\SyliusPurchaseApprovalPlugin\TestCatalog;
use Tmonier\SyliusCompanyAccountsPlugin\Entity\CompanyInterface;
use Tmonier\SyliusCompanyAccountsPlugin\Entity\CompanyMemberInterface;

/**
 * Company A with an admin, a buyer whose order waits for approval, a colleague buyer, an
 * approver and a disabled approver; company B with an approver; a customer in no company.
 */
final readonly class ApprovalScenario
{
    public CompanyInterface $companyA;

    public CompanyMemberInterface $adminA;

    public CompanyMemberInterface $buyerA;

    public CompanyMemberInterface $colleagueA;

    public CompanyMemberInterface $approverA;

    public CompanyMemberInterface $disabledApproverA;

    public CompanyMemberInterface $approverB;

    public CustomerInterface $nonMember;

    public OrderInterface $pendingOrder;

    public function __construct(TestCatalog $catalog)
    {
        $this->companyA = $catalog->company('Alpha');
        $this->adminA = $catalog->member($this->companyA, $catalog->customer('admin-a', true, 'Alice', 'Admin'), CompanyMemberInterface::ROLE_ADMIN);
        $this->buyerA = $catalog->member($this->companyA, $catalog->customer('buyer-a', true, 'Bob', 'Buyer'));
        $this->colleagueA = $catalog->member($this->companyA, $catalog->customer('colleague-a', true, 'Carl', 'Colleague'));
        $this->approverA = $catalog->member($this->companyA, $catalog->customer('approver-a', true, 'Ann', 'Approver'), CompanyMemberInterface::ROLE_APPROVER);
        $this->disabledApproverA = $catalog->member($this->companyA, $catalog->customer('disabled-a', true, 'Dan', 'Disabled'), CompanyMemberInterface::ROLE_APPROVER, false);
        $this->approverB = $catalog->member($catalog->company('Beta'), $catalog->customer('approver-b', true, 'Bea', 'Other'), CompanyMemberInterface::ROLE_APPROVER);
        $this->nonMember = $catalog->customer('loner', true, 'Lou', 'Loner');
        $this->pendingOrder = $catalog->orderAgainstLimit($this->buyerA, -1);
    }

    public static function customerOf(CompanyMemberInterface $member): CustomerInterface
    {
        $customer = $member->getCustomer();
        if (!$customer instanceof CustomerInterface) {
            throw new \LogicException('Member without customer.');
        }

        return $customer;
    }
}
