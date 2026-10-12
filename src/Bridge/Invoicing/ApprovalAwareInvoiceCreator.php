<?php

declare(strict_types=1);

namespace Tmonier\SyliusPurchaseApprovalPlugin\Bridge\Invoicing;

use Sylius\InvoicingPlugin\Creator\InvoiceCreatorInterface;
use Tmonier\SyliusPurchaseApprovalPlugin\Repository\OrderApprovalRepositoryInterface;

/**
 * sylius/invoicing-plugin issues the invoice as soon as the checkout completes. An order that
 * waits for its approval gets its invoice when it is approved (InvoiceOnApprovalListener), and
 * a rejected or expired one never gets one, so no credit note is needed. Also covers the mass
 * invoice generation command.
 */
final class ApprovalAwareInvoiceCreator implements InvoiceCreatorInterface
{
    public function __construct(
        private readonly InvoiceCreatorInterface $decorated,
        private readonly OrderApprovalRepositoryInterface $approvalRepository,
    ) {
    }

    public function __invoke(string $orderNumber, \DateTimeInterface $dateTime): void
    {
        $approval = $this->approvalRepository->findOneByOrderNumber($orderNumber);
        if (null !== $approval && !$approval->isApproved()) {
            return;
        }

        ($this->decorated)($orderNumber, $dateTime);
    }
}
