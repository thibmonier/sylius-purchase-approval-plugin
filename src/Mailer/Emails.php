<?php

declare(strict_types=1);

namespace Tmonier\SyliusPurchaseApprovalPlugin\Mailer;

final class Emails
{
    /** To the buyer instead of the order confirmation while the order waits for approval. */
    public const ACKNOWLEDGEMENT = 'tmonier_purchase_approval_acknowledgement';

    /** To the approvers of the company. */
    public const APPROVAL_REQUESTED = 'tmonier_purchase_approval_requested';

    public const REJECTED = 'tmonier_purchase_approval_rejected';

    public const EXPIRED = 'tmonier_purchase_approval_expired';

    private function __construct()
    {
    }
}
