<?php

declare(strict_types=1);

namespace Tmonier\SyliusPurchaseApprovalPlugin\Approval;

final readonly class PendingApprovalPreview
{
    /**
     * @param list<PendingApprovalPreviewItem> $items the oldest first
     * @param int                              $total everything the member may decide, shown or not
     */
    public function __construct(
        public array $items,
        public int $total,
    ) {
    }
}
