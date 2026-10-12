<?php

declare(strict_types=1);

namespace Tmonier\SyliusPurchaseApprovalPlugin\Approval;

use Tmonier\SyliusCompanyAccountsPlugin\Entity\CompanyMemberInterface;

/** Why an order waits for an approval. */
final class ApprovalRequirement
{
    /**
     * @param int|null $orderLimit the buyer's order limit, null when it is in another currency
     *                             than the order (then any amount needs an approval)
     */
    public function __construct(
        public readonly CompanyMemberInterface $member,
        public readonly ?int $orderLimit,
    ) {
    }
}
