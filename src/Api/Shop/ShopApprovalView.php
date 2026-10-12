<?php

declare(strict_types=1);

namespace Tmonier\SyliusPurchaseApprovalPlugin\Api\Shop;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use Tmonier\SyliusPurchaseApprovalPlugin\Api\ApprovalDecisionInput;
use Tmonier\SyliusPurchaseApprovalPlugin\Entity\OrderApprovalInterface;

/**
 * An order approval as seen in the shop: approvers of the company (list, decisions) and the
 * buyer (their own order). Colleagues are named, never with their e-mail or phone number.
 */
#[ApiResource(
    shortName: 'ShopOrderApproval',
    operations: [
        new GetCollection(
            uriTemplate: '/shop/company/approvals',
            description: 'Approvals of the company, pending first (approvers). `?state=pending` lists the pending ones only.',
            security: "is_granted('ROLE_USER')",
            provider: ShopApprovalProvider::class,
            name: 'tmonier_purchase_approval_shop_approvals_get',
        ),
        new Get(
            uriTemplate: '/shop/company/approvals/{orderNumber}',
            requirements: ['orderNumber' => '[A-Za-z0-9_-]{1,64}'],
            description: 'An approval: approvers of the company, or the buyer of the order.',
            security: "is_granted('ROLE_USER')",
            provider: ShopApprovalProvider::class,
            name: 'tmonier_purchase_approval_shop_approval_get',
        ),
        new Post(
            uriTemplate: '/shop/company/approvals/{orderNumber}/approve',
            requirements: ['orderNumber' => '[A-Za-z0-9_-]{1,64}'],
            status: 200,
            description: 'Approves the order (approvers, within their approval limit, never their own orders). 422 when refused.',
            security: "is_granted('ROLE_USER')",
            input: ApprovalDecisionInput::class,
            read: false,
            processor: ShopApprovalDecisionProcessor::class,
            name: 'tmonier_purchase_approval_shop_approval_approve',
        ),
        new Post(
            uriTemplate: '/shop/company/approvals/{orderNumber}/reject',
            requirements: ['orderNumber' => '[A-Za-z0-9_-]{1,64}'],
            status: 200,
            description: 'Rejects the order, which is cancelled (approvers, never their own orders). 422 when refused.',
            security: "is_granted('ROLE_USER')",
            input: ApprovalDecisionInput::class,
            read: false,
            processor: ShopApprovalDecisionProcessor::class,
            name: 'tmonier_purchase_approval_shop_approval_reject',
        ),
    ],
)]
final class ShopApprovalView
{
    public function __construct(
        #[ApiProperty(identifier: true)]
        public readonly string $orderNumber,
        public readonly string $state,
        /** Order total in minor units of the currency. */
        public readonly int $total,
        public readonly ?string $currencyCode,
        /** The buyer's order limit when the order was placed (null: limit in another currency). */
        public readonly ?int $orderLimit,
        public readonly ?string $buyerFirstName,
        public readonly ?string $buyerLastName,
        public readonly \DateTimeImmutable $requestedAt,
        public readonly ?\DateTimeImmutable $decidedAt,
        /** member, administrator or system. */
        public readonly ?string $decidedBy,
        public readonly ?string $deciderName,
        public readonly ?string $comment,
        /** The signed-in customer may approve / reject it now. */
        public readonly bool $canApprove,
        public readonly bool $canReject,
    ) {
    }

    public static function fromApproval(OrderApprovalInterface $approval, bool $canApprove, bool $canReject): self
    {
        $buyer = $approval->getOrder()?->getCustomer();

        return new self(
            (string) $approval->getOrder()?->getNumber(),
            $approval->getState(),
            $approval->getTotal(),
            $approval->getCurrencyCode(),
            $approval->getOrderLimit(),
            $buyer?->getFirstName(),
            $buyer?->getLastName(),
            $approval->getCreatedAt(),
            $approval->getDecidedAt(),
            $approval->getDeciderType(),
            $approval->getDeciderName(),
            $approval->getComment(),
            $canApprove,
            $canReject,
        );
    }
}
