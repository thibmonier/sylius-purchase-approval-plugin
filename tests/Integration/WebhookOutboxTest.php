<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusPurchaseApprovalPlugin\Integration;

use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Tmonier\SyliusPurchaseApprovalPlugin\Approval\ApprovalDecisionMaker;
use Tmonier\SyliusPurchaseApprovalPlugin\Approval\Decider;
use Tmonier\SyliusPurchaseApprovalPlugin\Entity\OrderApprovalInterface;
use Tmonier\SyliusPurchaseApprovalPlugin\Repository\OrderApprovalRepositoryInterface;
use Tmonier\SyliusWebhookOutboxPlugin\Entity\WebhookEndpointInterface;

/** tmonier/sylius-webhook-outbox-plugin is enabled in the test application. */
final class WebhookOutboxTest extends KernelTestCase
{
    use IntegrationTestTrait;

    public function testApprovalEventsAreRecordedWithTheChange(): void
    {
        $this->subscribedEndpoint();
        $company = $this->catalog()->company();
        $approved = $this->catalog()->orderAgainstLimit($this->catalog()->member($company), -1);
        $rejected = $this->catalog()->orderAgainstLimit($this->catalog()->member($company), -1);
        self::assertEqualsCanonicalizing(['order.placed', 'order.approval_requested'], $this->types($approved));

        $this->decisionMaker()->approve($this->approvalOf($approved), Decider::administrator(null), 'Private note');
        $this->decisionMaker()->reject($this->approvalOf($rejected), Decider::administrator(null));

        self::assertEqualsCanonicalizing(['order.placed', 'order.approval_requested', 'order.approved'], $this->types($approved));
        self::assertEqualsCanonicalizing(['order.placed', 'order.approval_requested', 'order.approval_rejected', 'order.cancelled'], $this->types($rejected));
        $payload = $this->connection()->fetchOne("SELECT payload FROM tmonier_webhook_outbox_event WHERE type = 'order.approved' AND resource_id = ?", [$this->idOf($approved)]);
        self::assertIsString($payload);
        self::assertStringContainsString('"approval"', $payload);
        self::assertStringNotContainsString('Private note', $payload);
    }

    private function subscribedEndpoint(): void
    {
        /** @var FactoryInterface<WebhookEndpointInterface> $factory */
        $factory = self::getContainer()->get('tmonier_webhook.factory.webhook_endpoint');
        $endpoint = $factory->createNew();
        $endpoint->setName('ERP');
        $endpoint->setUrl('https://erp.example.com/webhooks');
        $endpoint->setSecret('test-' . 'secret-of-the-endpoint');
        $endpoint->setEnabled(true);
        $endpoint->setEventTypes(['order.placed', 'order.cancelled', 'order.approval_requested', 'order.approved', 'order.approval_rejected']);
        $this->entityManager()->persist($endpoint);
        $this->entityManager()->flush();
    }

    /** @return list<string> */
    private function types(OrderInterface $order): array
    {
        /** @var list<string> $types */
        $types = $this->connection()->fetchFirstColumn(
            "SELECT type FROM tmonier_webhook_outbox_event WHERE resource_type = 'order' AND resource_id = ? ORDER BY occurred_at, type",
            [$this->idOf($order)],
        );

        return $types;
    }

    private function approvalOf(OrderInterface $order): OrderApprovalInterface
    {
        $approval = $this->service('tmonier_purchase_approval.repository.order_approval', OrderApprovalRepositoryInterface::class)->findOneByOrder($order);
        self::assertInstanceOf(OrderApprovalInterface::class, $approval);

        return $approval;
    }

    private function decisionMaker(): ApprovalDecisionMaker
    {
        return $this->service('tmonier_purchase_approval.decision_maker', ApprovalDecisionMaker::class);
    }
}
