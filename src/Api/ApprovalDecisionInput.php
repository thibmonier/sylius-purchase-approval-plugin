<?php

declare(strict_types=1);

namespace Tmonier\SyliusPurchaseApprovalPlugin\Api;

/** Body of the approve / reject operations. */
final class ApprovalDecisionInput
{
    /** Optional, at most 1000 characters; shown to the buyer (rejection e-mail, order page). */
    public ?string $comment = null;
}
