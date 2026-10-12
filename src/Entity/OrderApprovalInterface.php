<?php

declare(strict_types=1);

namespace Tmonier\SyliusPurchaseApprovalPlugin\Entity;

use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Resource\Model\ResourceInterface;
use Tmonier\SyliusCompanyAccountsPlugin\Entity\CompanyInterface;

/**
 * Approval of a placed order (workflow `tmonier_order_approval`): created pending in the flush
 * that completes the checkout when the order is above the buyer's limit.
 */
interface OrderApprovalInterface extends ResourceInterface
{
    public const STATE_PENDING = 'pending';

    public const STATE_APPROVED = 'approved';

    public const STATE_REJECTED = 'rejected';

    public const STATE_EXPIRED = 'expired';

    public const STATES = [self::STATE_PENDING, self::STATE_APPROVED, self::STATE_REJECTED, self::STATE_EXPIRED];

    /** Approved by a member of the company. */
    public const DECIDED_BY_MEMBER = 'member';

    /** Decided by a store administrator. */
    public const DECIDED_BY_ADMINISTRATOR = 'administrator';

    /** Expired by `tmonier:approval:expire`. */
    public const DECIDED_BY_SYSTEM = 'system';

    public function getId(): ?int;

    public function getOrder(): ?OrderInterface;

    public function setOrder(?OrderInterface $order): void;

    public function getCompany(): ?CompanyInterface;

    public function setCompany(?CompanyInterface $company): void;

    public function getState(): string;

    public function setState(string $state): void;

    public function isPending(): bool;

    public function isApproved(): bool;

    /** Total of the order when it was placed, in minor units of the order currency. */
    public function getTotal(): int;

    public function setTotal(int $total): void;

    public function getCurrencyCode(): ?string;

    public function setCurrencyCode(?string $currencyCode): void;

    /** The buyer's order limit when the order was placed (null: the currency did not match). */
    public function getOrderLimit(): ?int;

    public function setOrderLimit(?int $orderLimit): void;

    public function getDecidedBy(): ?CustomerInterface;

    public function setDecidedBy(?CustomerInterface $decidedBy): void;

    /** Name of the person who decided, kept after their account is deleted. */
    public function getDeciderName(): ?string;

    public function setDeciderName(?string $deciderName): void;

    /** One of the DECIDED_BY_* constants, null while pending. */
    public function getDeciderType(): ?string;

    public function setDeciderType(?string $deciderType): void;

    public function getComment(): ?string;

    public function setComment(?string $comment): void;

    public function getCreatedAt(): \DateTimeImmutable;

    public function setCreatedAt(\DateTimeImmutable $createdAt): void;

    public function getDecidedAt(): ?\DateTimeImmutable;

    public function setDecidedAt(?\DateTimeImmutable $decidedAt): void;
}
