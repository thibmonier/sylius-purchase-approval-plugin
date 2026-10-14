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
final readonly class ApprovalAwareInvoiceCreator implements InvoiceCreatorInterface
{
    public function __construct(
        private InvoiceCreatorInterface $decorated,
        private OrderApprovalRepositoryInterface $approvalRepository,
    ) {
    }

    public function __invoke(string $orderNumber, \DateTimeInterface $dateTime): void
    {
        $approval = $this->approvalRepository->findOneByOrderNumber($orderNumber);
        if ($approval instanceof \Tmonier\SyliusPurchaseApprovalPlugin\Entity\OrderApprovalInterface && !$approval->isApproved()) {
            return;
        }

        ($this->decorated)($orderNumber, $dateTime);
    }
}
