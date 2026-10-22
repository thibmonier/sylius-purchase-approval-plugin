<?php

declare(strict_types=1);

namespace Tmonier\SyliusPurchaseApprovalPlugin\Approval;

final readonly class PendingApprovalPreviewItem
{
    /**
     * @param int|null $expiresInDays null while the expiry is far; 0 when it is today (or overdue)
     */
    public function __construct(
        public string $number,
        public string $buyerName,
        public int $total,
        public ?string $currencyCode,
        public int $ageInDays,
        public ?int $expiresInDays,
    ) {
    }
}
