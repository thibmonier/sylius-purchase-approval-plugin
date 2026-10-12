<?php

declare(strict_types=1);

namespace Tmonier\SyliusPurchaseApprovalPlugin\Entity;

use Sylius\Resource\Model\ResourceInterface;
use Tmonier\SyliusCompanyAccountsPlugin\Entity\CompanyMemberInterface;

/**
 * Purchase limits of a company member, in minor units of one currency.
 *
 * - Order limit: orders of the member above it (or in another currency) wait for an approval;
 *   null means no approval is ever needed. Without a limit row, no approval either.
 * - Approval limit: the largest order the member may approve; null means no maximum.
 */
interface MemberLimitInterface extends ResourceInterface
{
    public function getId(): ?int;

    public function getMember(): ?CompanyMemberInterface;

    public function setMember(?CompanyMemberInterface $member): void;

    public function getOrderLimit(): ?int;

    public function setOrderLimit(?int $orderLimit): void;

    public function getApprovalLimit(): ?int;

    public function setApprovalLimit(?int $approvalLimit): void;

    public function getCurrencyCode(): ?string;

    public function setCurrencyCode(?string $currencyCode): void;

    public function getUpdatedAt(): ?\DateTimeImmutable;

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): void;
}
