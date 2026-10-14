<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusPurchaseApprovalPlugin\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Tmonier\SyliusPurchaseApprovalPlugin\Entity\OrderApprovalInterface;
use Tmonier\SyliusPurchaseApprovalPlugin\Repository\OrderApprovalRepositoryInterface;

final class ApiApprovalTest extends WebTestCase
{
    use FunctionalTestTrait;

    public function testShopAccessMatrix(): void
    {
        $s = new ApprovalScenario($this->catalog());
        $item = '/api/v2/shop/company/approvals/' . $s->pendingOrder->getNumber();
        $matrix = [
            // [customer, list status, item status]
            'visitor' => [null, 401, 401],
            'customer in no company' => [$s->nonMember, 404, 404],
            'buyer of the order' => [ApprovalScenario::customerOf($s->buyerA), 403, 200],
            'colleague buyer' => [ApprovalScenario::customerOf($s->colleagueA), 403, 404],
            'disabled approver' => [ApprovalScenario::customerOf($s->disabledApproverA), 403, 404],
            'approver of another company' => [ApprovalScenario::customerOf($s->approverB), 200, 404],
            'approver' => [ApprovalScenario::customerOf($s->approverA), 200, 200],
        ];
        foreach ($matrix as $label => [$customer, $list, $get]) {
            $this->client->request('GET', '/api/v2/shop/company/approvals', server: $this->shopApiHeaders($customer));
            self::assertSame($list, $this->client->getResponse()->getStatusCode(), $label . ' (list)');
            if (200 === $list) {
                $numbers = array_column((array) ($this->json()['hydra:member'] ?? $this->json()['member'] ?? []), 'orderNumber');
                self::assertSame('approver' === $label, in_array((string) $s->pendingOrder->getNumber(), $numbers, true), $label);
            }
            $this->client->request('GET', $item, server: $this->shopApiHeaders($customer));
            self::assertSame($get, $this->client->getResponse()->getStatusCode(), $label . ' (item)');
        }

        $this->client->request('GET', $item, server: $this->shopApiHeaders(ApprovalScenario::customerOf($s->approverA)));
        $data = $this->json();
        self::assertSame(['pending', true, true, 'Bob', 'Buyer'], [$data['state'], $data['canApprove'], $data['canReject'], $data['buyerFirstName'], $data['buyerLastName']]);
        self::assertStringNotContainsString('buyer-a-', (string) $this->client->getResponse()->getContent(), 'No e-mail of a colleague.');
    }

    public function testAnApproverDecidesThroughTheShopApi(): void
    {
        $s = new ApprovalScenario($this->catalog());
        $base = '/api/v2/shop/company/approvals/' . $s->pendingOrder->getNumber();
        $post = fn (string $path, ?\Sylius\Component\Core\Model\CustomerInterface $as, array $body = []): \Symfony\Component\DomCrawler\Crawler => $this->client->request('POST', $path, server: $this->shopApiHeaders($as), content: json_encode($body, \JSON_THROW_ON_ERROR));

        $post($base . '/approve', ApprovalScenario::customerOf($s->buyerA));
        self::assertResponseStatusCodeSame(403, 'The buyer.');
        $post($base . '/approve', ApprovalScenario::customerOf($s->approverB));
        self::assertResponseStatusCodeSame(404, 'Another company.');
        $post($base . '/approve', ApprovalScenario::customerOf($s->approverA), ['comment' => str_repeat('x', 1001)]);
        self::assertResponseStatusCodeSame(422);

        $post($base . '/approve', ApprovalScenario::customerOf($s->approverA), ['comment' => 'OK']);
        self::assertResponseIsSuccessful();
        self::assertSame(['approved', 'OK', 'member'], [$this->json()['state'], $this->json()['comment'], $this->json()['decidedBy']]);

        $post($base . '/reject', ApprovalScenario::customerOf($s->approverA));
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('no longer waiting for an approval', (string) $this->client->getResponse()->getContent());

        $own = $this->catalog()->orderAgainstLimit($s->approverA, -1);
        $post('/api/v2/shop/company/approvals/' . $own->getNumber() . '/reject', ApprovalScenario::customerOf($s->approverA));
        self::assertResponseStatusCodeSame(422);
        self::assertSame(OrderApprovalInterface::STATE_PENDING, $this->stateOf((string) $own->getNumber()));
    }

    public function testTheStoreUsesTheAdminApi(): void
    {
        $s = new ApprovalScenario($this->catalog());
        $headers = $this->adminApiHeaders();

        $this->client->request('GET', '/api/v2/admin/order-approvals?state=pending', server: $headers);
        self::assertResponseIsSuccessful();
        $numbers = array_column((array) ($this->json()['hydra:member'] ?? $this->json()['member'] ?? []), 'orderNumber');
        self::assertContains((string) $s->pendingOrder->getNumber(), $numbers);

        $this->client->request('POST', '/api/v2/admin/order-approvals/' . $s->pendingOrder->getNumber() . '/reject', server: $headers, content: '{"comment": "Duplicate order"}');
        self::assertResponseIsSuccessful();
        self::assertSame(['rejected', 'administrator', $s->companyA->getCode()], [$this->json()['state'], $this->json()['decidedBy'], $this->json()['companyCode']]);
        $this->client->request('POST', '/api/v2/admin/order-approvals/' . $s->pendingOrder->getNumber() . '/approve', server: $headers, content: '{}');
        self::assertResponseStatusCodeSame(422);

        $this->client->request('GET', '/api/v2/admin/order-approvals/' . $s->pendingOrder->getNumber(), server: $this->shopApiHeaders(ApprovalScenario::customerOf($s->approverA)));
        self::assertContains($this->client->getResponse()->getStatusCode(), [401, 403], 'Shop customers cannot use the admin API.');
    }

    public function testTheStoreManagesTheLimitsThroughTheAdminApi(): void
    {
        $s = new ApprovalScenario($this->catalog());
        $headers = $this->adminApiHeaders();

        $this->client->request('POST', '/api/v2/admin/company-member-limits', server: $headers, content: json_encode([
            'member' => '/api/v2/admin/company-members/' . $this->idOf($s->approverA),
            'orderLimit' => 10000,
            'approvalLimit' => 500000,
            'currencyCode' => $this->catalog()->currencyCode(),
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);
        $id = $this->json()['id'] ?? null;
        self::assertIsInt($id);

        $this->client->request('POST', '/api/v2/admin/company-member-limits', server: $headers, content: json_encode([
            'member' => '/api/v2/admin/company-members/' . $this->idOf($s->approverA),
            'orderLimit' => -1,
            'currencyCode' => 'NOPE',
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422);

        $this->client->request('PUT', '/api/v2/admin/company-member-limits/' . $id, server: $headers, content: '{"orderLimit": null}');
        self::assertResponseIsSuccessful();
        self::assertNull($this->json()['orderLimit'] ?? null);
        $this->client->request('DELETE', '/api/v2/admin/company-member-limits/' . $id, server: $headers);
        self::assertResponseStatusCodeSame(204);
    }

    private function stateOf(string $number): ?string
    {
        $this->entityManager()->clear();
        $repository = self::getContainer()->get('tmonier_purchase_approval.repository.order_approval');
        self::assertInstanceOf(OrderApprovalRepositoryInterface::class, $repository);

        return $repository->findOneByOrderNumber($number)?->getState();
    }
}
