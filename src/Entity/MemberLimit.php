<?php

declare(strict_types=1);

namespace Tmonier\SyliusPurchaseApprovalPlugin\Entity;

use Tmonier\SyliusCompanyAccountsPlugin\Entity\CompanyMemberInterface;

class MemberLimit implements MemberLimitInterface
{
    protected ?int $id = null;

    protected ?CompanyMemberInterface $member = null;

    protected ?int $orderLimit = null;

    protected ?int $approvalLimit = null;

    protected ?string $currencyCode = null;

    protected ?\DateTimeImmutable $updatedAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMember(): ?CompanyMemberInterface
    {
        return $this->member;
    }

    public function setMember(?CompanyMemberInterface $member): void
    {
        $this->member = $member;
    }

    public function getOrderLimit(): ?int
    {
        return $this->orderLimit;
    }

    public function setOrderLimit(?int $orderLimit): void
    {
        $this->orderLimit = $orderLimit;
    }

    public function getApprovalLimit(): ?int
    {
        return $this->approvalLimit;
    }

    public function setApprovalLimit(?int $approvalLimit): void
    {
        $this->approvalLimit = $approvalLimit;
    }

    public function getCurrencyCode(): ?string
    {
        return $this->currencyCode;
    }

    public function setCurrencyCode(?string $currencyCode): void
    {
        $this->currencyCode = $currencyCode;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): void
    {
        $this->updatedAt = $updatedAt;
    }
}
