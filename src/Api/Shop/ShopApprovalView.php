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
            name: 'tmonier_purchase_approval_shop_approvals_get',
            provider: ShopApprovalProvider::class,
        ),
        new Get(
            uriTemplate: '/shop/company/approvals/{orderNumber}',
            requirements: ['orderNumber' => '[A-Za-z0-9_-]{1,64}'],
            description: 'An approval: approvers of the company, or the buyer of the order.',
            security: "is_granted('ROLE_USER')",
            name: 'tmonier_purchase_approval_shop_approval_get',
            provider: ShopApprovalProvider::class,
        ),
        new Post(
            uriTemplate: '/shop/company/approvals/{orderNumber}/approve',
            requirements: ['orderNumber' => '[A-Za-z0-9_-]{1,64}'],
            status: 200,
            description: 'Approves the order (approvers, within their approval limit, never their own orders). 422 when refused.',
            security: "is_granted('ROLE_USER')",
            input: ApprovalDecisionInput::class,
            read: false,
            name: 'tmonier_purchase_approval_shop_approval_approve',
            processor: ShopApprovalDecisionProcessor::class,
        ),
        new Post(
            uriTemplate: '/shop/company/approvals/{orderNumber}/reject',
            requirements: ['orderNumber' => '[A-Za-z0-9_-]{1,64}'],
            status: 200,
            description: 'Rejects the order, which is cancelled (approvers, never their own orders). 422 when refused.',
            security: "is_granted('ROLE_USER')",
            input: ApprovalDecisionInput::class,
            read: false,
            name: 'tmonier_purchase_approval_shop_approval_reject',
            processor: ShopApprovalDecisionProcessor::class,
        ),
    ],
)]
final readonly class ShopApprovalView
{
    public function __construct(
        #[ApiProperty(identifier: true)]
        public string $orderNumber,
        public string $state,
        /** Order total in minor units of the currency. */
        public int $total,
        public ?string $currencyCode,
        /** The buyer's order limit when the order was placed (null: limit in another currency). */
        public ?int $orderLimit,
        public ?string $buyerFirstName,
        public ?string $buyerLastName,
        public \DateTimeImmutable $requestedAt,
        public ?\DateTimeImmutable $decidedAt,
        /** member, administrator or system. */
        public ?string $decidedBy,
        public ?string $deciderName,
        public ?string $comment,
        /** The signed-in customer may approve / reject it now. */
        public bool $canApprove,
        public bool $canReject,
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
