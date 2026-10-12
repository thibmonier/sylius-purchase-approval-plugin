<?php

declare(strict_types=1);

namespace Tmonier\SyliusPurchaseApprovalPlugin\Entity;

use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Tmonier\SyliusCompanyAccountsPlugin\Entity\CompanyInterface;

class OrderApproval implements OrderApprovalInterface
{
    protected ?int $id = null;

    protected ?OrderInterface $order = null;

    protected ?CompanyInterface $company = null;

    protected string $state = self::STATE_PENDING;

    protected int $total = 0;

    protected ?string $currencyCode = null;

    protected ?int $orderLimit = null;

    protected ?CustomerInterface $decidedBy = null;

    protected ?string $deciderName = null;

    protected ?string $deciderType = null;

    protected ?string $comment = null;

    protected \DateTimeImmutable $createdAt;

    protected ?\DateTimeImmutable $decidedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrder(): ?OrderInterface
    {
        return $this->order;
    }

    public function setOrder(?OrderInterface $order): void
    {
        $this->order = $order;
    }

    public function getCompany(): ?CompanyInterface
    {
        return $this->company;
    }

    public function setCompany(?CompanyInterface $company): void
    {
        $this->company = $company;
    }

    public function getState(): string
    {
        return $this->state;
    }

    public function setState(string $state): void
    {
        $this->state = $state;
    }

    public function isPending(): bool
    {
        return self::STATE_PENDING === $this->state;
    }

    public function isApproved(): bool
    {
        return self::STATE_APPROVED === $this->state;
    }

    public function getTotal(): int
    {
        return $this->total;
    }

    public function setTotal(int $total): void
    {
        $this->total = $total;
    }

    public function getCurrencyCode(): ?string
    {
        return $this->currencyCode;
    }

    public function setCurrencyCode(?string $currencyCode): void
    {
        $this->currencyCode = $currencyCode;
    }

    public function getOrderLimit(): ?int
    {
        return $this->orderLimit;
    }

    public function setOrderLimit(?int $orderLimit): void
    {
        $this->orderLimit = $orderLimit;
    }

    public function getDecidedBy(): ?CustomerInterface
    {
        return $this->decidedBy;
    }

    public function setDecidedBy(?CustomerInterface $decidedBy): void
    {
        $this->decidedBy = $decidedBy;
    }

    public function getDeciderName(): ?string
    {
        return $this->deciderName;
    }

    public function setDeciderName(?string $deciderName): void
    {
        $this->deciderName = $deciderName;
    }

    public function getDeciderType(): ?string
    {
        return $this->deciderType;
    }

    public function setDeciderType(?string $deciderType): void
    {
        $this->deciderType = $deciderType;
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }

    public function setComment(?string $comment): void
    {
        $this->comment = $comment;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): void
    {
        $this->createdAt = $createdAt;
    }

    public function getDecidedAt(): ?\DateTimeImmutable
    {
        return $this->decidedAt;
    }

    public function setDecidedAt(?\DateTimeImmutable $decidedAt): void
    {
        $this->decidedAt = $decidedAt;
    }
}
