<?php

declare(strict_types=1);

namespace Tmonier\SyliusPurchaseApprovalPlugin\Security;

/** Shop permissions (signed-in customers). Store administrators use the back office. */
final class ApprovalPermissions
{
    /** Subject: a company. Lists the approvals of the company (active approvers). */
    public const APPROVAL_LIST = 'APPROVAL_LIST';

    /** Subject: an approval. The buyer of the order, or an approver of its company. */
    public const APPROVAL_VIEW = 'APPROVAL_VIEW';

    /** Subject: an approval. An approver of its company (ApprovalPolicy refines: own orders, limits, state). */
    public const APPROVAL_DECIDE = 'APPROVAL_DECIDE';

    /** Subject: a company. Company admins set the limits of their colleagues. */
    public const LIMITS_MANAGE = 'APPROVAL_LIMITS_MANAGE';

    public const ALL = [self::APPROVAL_LIST, self::APPROVAL_VIEW, self::APPROVAL_DECIDE, self::LIMITS_MANAGE];

    private function __construct()
    {
    }
}
