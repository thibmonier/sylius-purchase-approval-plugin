<?php

declare(strict_types=1);

namespace Tmonier\SyliusPurchaseApprovalPlugin\Api\Admin;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use Tmonier\SyliusPurchaseApprovalPlugin\Api\ApprovalDecisionInput;
use Tmonier\SyliusPurchaseApprovalPlugin\Entity\OrderApprovalInterface;

#[ApiResource(
    shortName: 'AdminOrderApproval',
    operations: [
        new GetCollection(
            uriTemplate: '/admin/order-approvals',
            description: 'Order approvals, most recent first. `?state=pending|approved|rejected|expired` filters them.',
            security: "is_granted('ROLE_API_ACCESS')",
            provider: AdminApprovalProvider::class,
            name: 'tmonier_purchase_approval_admin_approvals_get',
        ),
        new Get(
            uriTemplate: '/admin/order-approvals/{orderNumber}',
            requirements: ['orderNumber' => '[A-Za-z0-9_-]{1,64}'],
            security: "is_granted('ROLE_API_ACCESS')",
            provider: AdminApprovalProvider::class,
            name: 'tmonier_purchase_approval_admin_approval_get',
        ),
        new Post(
            uriTemplate: '/admin/order-approvals/{orderNumber}/approve',
            requirements: ['orderNumber' => '[A-Za-z0-9_-]{1,64}'],
            status: 200,
            description: 'Approves a pending order on behalf of the store (company limits do not apply). 422 when not pending.',
            security: "is_granted('ROLE_API_ACCESS')",
            input: ApprovalDecisionInput::class,
            read: false,
            processor: AdminApprovalDecisionProcessor::class,
            name: 'tmonier_purchase_approval_admin_approval_approve',
        ),
        new Post(
            uriTemplate: '/admin/order-approvals/{orderNumber}/reject',
            requirements: ['orderNumber' => '[A-Za-z0-9_-]{1,64}'],
            status: 200,
            description: 'Rejects a pending order, which is cancelled. 422 when not pending.',
            security: "is_granted('ROLE_API_ACCESS')",
            input: ApprovalDecisionInput::class,
            read: false,
            processor: AdminApprovalDecisionProcessor::class,
            name: 'tmonier_purchase_approval_admin_approval_reject',
        ),
    ],
)]
final class AdminApprovalView
{
    public function __construct(
        #[ApiProperty(identifier: true)]
        public readonly string $orderNumber,
        public readonly ?int $orderId,
        public readonly ?string $companyCode,
        public readonly string $state,
        public readonly int $total,
        public readonly ?string $currencyCode,
        public readonly ?int $orderLimit,
        public readonly \DateTimeImmutable $requestedAt,
        public readonly ?\DateTimeImmutable $decidedAt,
        public readonly ?string $decidedBy,
        public readonly ?string $deciderName,
        public readonly ?string $comment,
    ) {
    }

    public static function fromApproval(OrderApprovalInterface $approval): self
    {
        return new self(
            (string) $approval->getOrder()?->getNumber(),
            is_int($approval->getOrder()?->getId()) ? $approval->getOrder()->getId() : null,
            $approval->getCompany()?->getCode(),
            $approval->getState(),
            $approval->getTotal(),
            $approval->getCurrencyCode(),
            $approval->getOrderLimit(),
            $approval->getCreatedAt(),
            $approval->getDecidedAt(),
            $approval->getDeciderType(),
            $approval->getDeciderName(),
            $approval->getComment(),
        );
    }
}
