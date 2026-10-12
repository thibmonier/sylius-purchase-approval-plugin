<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusPurchaseApprovalPlugin\Functional;

use Sylius\Component\Core\Model\OrderInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Tmonier\SyliusPurchaseApprovalPlugin\Entity\OrderApprovalInterface;
use Tmonier\SyliusPurchaseApprovalPlugin\Repository\OrderApprovalRepositoryInterface;

final class ShopApprovalsTest extends WebTestCase
{
    use FunctionalTestTrait;

    public function testTheApprovalsListIsForApproversOfTheCompany(): void
    {
        $s = new ApprovalScenario($this->catalog());
        $otherOrder = $this->catalog()->orderAgainstLimit($this->catalog()->member($s->approverB->getCompany() ?? throw new \LogicException()), -1);
        $matrix = [
            'visitor' => [null, 302],
            'customer in no company' => [$s->nonMember, 404],
            'buyer' => [ApprovalScenario::customerOf($s->buyerA), 403],
            'company admin' => [ApprovalScenario::customerOf($s->adminA), 403],
            'disabled approver' => [ApprovalScenario::customerOf($s->disabledApproverA), 403],
            'approver' => [ApprovalScenario::customerOf($s->approverA), 200],
        ];
        foreach ($matrix as $label => [$customer, $expected]) {
            $this->logout();
            if (null !== $customer) {
                $this->loginShopUser($customer);
            }
            $crawler = $this->client->request('GET', $this->shopUrl('/account/company/approvals'));
            self::assertSame($expected, $this->client->getResponse()->getStatusCode(), $label);
            if (200 === $expected) {
                self::assertCount(1, $crawler->filter(sprintf('[data-test-tmonier-approval="%s"]', (string) $s->pendingOrder->getNumber())));
                self::assertCount(0, $crawler->filter(sprintf('[data-test-tmonier-approval="%s"]', (string) $otherOrder->getNumber())), 'Orders of another company.');
                self::assertStringContainsString('Bob Buyer', $crawler->filter('[data-test-tmonier-approvals]')->text());
            }
        }
    }

    public function testAnApprovalIsSeenByItsBuyerAndTheApproversOnly(): void
    {
        $s = new ApprovalScenario($this->catalog());
        $path = $this->shopUrl('/account/company/approvals/' . $s->pendingOrder->getNumber());
        $matrix = [
            'buyer of the order' => [ApprovalScenario::customerOf($s->buyerA), 200],
            'approver' => [ApprovalScenario::customerOf($s->approverA), 200],
            'colleague buyer' => [ApprovalScenario::customerOf($s->colleagueA), 404],
            'disabled approver' => [ApprovalScenario::customerOf($s->disabledApproverA), 404],
            'approver of another company' => [ApprovalScenario::customerOf($s->approverB), 404],
            'customer in no company' => [$s->nonMember, 404],
        ];
        foreach ($matrix as $label => [$customer, $expected]) {
            $this->logout();
            $this->loginShopUser($customer);
            $crawler = $this->client->request('GET', $path);
            self::assertSame($expected, $this->client->getResponse()->getStatusCode(), $label);
            if (200 === $expected) {
                self::assertSame('pending', $crawler->filter('[data-test-tmonier-approval-state]')->attr('data-test-tmonier-approval-state'));
                self::assertCount('approver' === $label ? 1 : 0, $crawler->filter('[data-test-tmonier-approval-decision]'), $label);
            }
        }
        $this->client->request('GET', $this->shopUrl('/account/company/approvals/NOPE'));
        self::assertResponseStatusCodeSame(404);
    }

    public function testAnApproverApprovesWithAComment(): void
    {
        $s = new ApprovalScenario($this->catalog());
        $this->loginShopUser(ApprovalScenario::customerOf($s->approverA));

        $crawler = $this->client->request('GET', $this->shopUrl('/account/company/approvals/' . $s->pendingOrder->getNumber()));
        $form = $crawler->filter('[data-test-tmonier-approve]')->form(['comment' => 'Fine for Q4']);
        $this->client->submit($form);

        self::assertResponseRedirects($this->shopUrl('/account/company/approvals/' . $s->pendingOrder->getNumber()));
        $crawler = $this->client->followRedirect();
        self::assertSame(['The order has been approved.'], $this->flashes($crawler));
        $approval = $this->approvalOf($s->pendingOrder);
        self::assertSame([OrderApprovalInterface::STATE_APPROVED, 'Fine for Q4', 'Ann Approver'], [$approval->getState(), $approval->getComment(), $approval->getDeciderName()]);
    }

    public function testARejectionCancelsTheOrderAndTheBuyerSeesWhy(): void
    {
        $s = new ApprovalScenario($this->catalog());
        $this->loginShopUser(ApprovalScenario::customerOf($s->approverA));
        $crawler = $this->client->request('GET', $this->shopUrl('/account/company/approvals/' . $s->pendingOrder->getNumber()));
        $this->client->submit($crawler->filter('[data-test-tmonier-reject]')->form(['comment' => 'Use the framework contract']));
        self::assertResponseRedirects();
        self::assertSame(OrderInterface::STATE_CANCELLED, $this->approvalOf($s->pendingOrder)->getOrder()?->getState());

        $this->logout();
        $this->loginShopUser(ApprovalScenario::customerOf($s->buyerA));
        $crawler = $this->client->request('GET', $this->shopUrl('/account/orders/' . $s->pendingOrder->getNumber()));
        self::assertResponseIsSuccessful();
        $notice = $crawler->filter('[data-test-tmonier-order-approval]');
        self::assertSame('rejected', $notice->attr('data-test-tmonier-order-approval'));
        self::assertStringContainsString('Use the framework contract', $notice->text());
    }

    public function testTheBuyerSeesThePendingApprovalOnTheOrderPage(): void
    {
        $s = new ApprovalScenario($this->catalog());
        $this->loginShopUser(ApprovalScenario::customerOf($s->buyerA));

        $crawler = $this->client->request('GET', $this->shopUrl('/account/orders/' . $s->pendingOrder->getNumber()));

        self::assertSame('pending', $crawler->filter('[data-test-tmonier-order-approval]')->attr('data-test-tmonier-order-approval'));
    }

    public function testAnApproverCannotDecideOnTheirOwnOrder(): void
    {
        $s = new ApprovalScenario($this->catalog());
        $own = $this->catalog()->orderAgainstLimit($s->approverA, -1);
        $this->loginShopUser(ApprovalScenario::customerOf($s->approverA));

        $crawler = $this->client->request('GET', $this->shopUrl('/account/company/approvals/' . $own->getNumber()));
        self::assertCount(0, $crawler->filter('[data-test-tmonier-approval-decision]'));
        self::assertStringContainsString('You cannot approve or reject your own order.', $crawler->filter('[data-test-tmonier-approval-refusal]')->text());

        // Posting anyway (valid token): refused, still pending.
        $token = $this->sessionCsrfToken('tmonier_approval_' . $own->getNumber());
        $this->client->request('POST', $this->shopUrl(sprintf('/account/company/approvals/%s/approve', (string) $own->getNumber())), ['_csrf_token' => $token]);
        $crawler = $this->client->followRedirect();
        self::assertSame(['You cannot approve or reject your own order.'], $this->flashes($crawler));
        self::assertTrue($this->approvalOf($own)->isPending());
    }

    public function testDecisionsNeedTheRightAndAValidToken(): void
    {
        $s = new ApprovalScenario($this->catalog());
        $path = $this->shopUrl(sprintf('/account/company/approvals/%s/approve', (string) $s->pendingOrder->getNumber()));
        $tokenId = 'tmonier_approval_' . $s->pendingOrder->getNumber();
        $matrix = [
            // A valid token of the actor's own session: a 403 is the permission, not the CSRF check.
            'buyer of the order' => [ApprovalScenario::customerOf($s->buyerA), 403],
            'company admin (not an approver: does not see it)' => [ApprovalScenario::customerOf($s->adminA), 404],
            'colleague buyer' => [ApprovalScenario::customerOf($s->colleagueA), 404],
            'approver of another company' => [ApprovalScenario::customerOf($s->approverB), 404],
        ];
        foreach ($matrix as $label => [$customer, $expected]) {
            $this->logout();
            $this->loginShopUser($customer);
            $token = $this->sessionCsrfToken($tokenId);
            $this->client->request('POST', $path, ['_csrf_token' => $token]);
            self::assertSame($expected, $this->client->getResponse()->getStatusCode(), $label);
        }

        $this->logout();
        $this->loginShopUser(ApprovalScenario::customerOf($s->approverA));
        $this->client->request('POST', $path, ['_csrf_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);
        self::assertTrue($this->approvalOf($s->pendingOrder)->isPending());
    }

    public function testPaymentIsBlockedWhileTheOrderWaits(): void
    {
        $s = new ApprovalScenario($this->catalog());
        $this->loginShopUser(ApprovalScenario::customerOf($s->buyerA));

        $this->client->request('GET', $this->shopUrl(sprintf('/order/%s/pay', (string) $s->pendingOrder->getTokenValue())));

        self::assertResponseRedirects($this->shopUrl('/order/thank-you'));
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString(sprintf('Order #%s is waiting for the approval of your company', (string) $s->pendingOrder->getNumber()), implode(' ', $this->flashes($crawler)));
    }

    public function testApproversFindTheApprovalsFromTheCompanyPages(): void
    {
        $s = new ApprovalScenario($this->catalog());
        $this->loginShopUser(ApprovalScenario::customerOf($s->approverA));

        $crawler = $this->client->request('GET', $this->shopUrl('/account/company'));

        self::assertStringContainsString('1', $crawler->filter('[data-test-tmonier-approvals-link]')->text());
        self::assertCount(0, $crawler->filter('[data-test-tmonier-limits-link]'));
        self::assertGreaterThan(0, $crawler->filter(sprintf('a[href="%s"]', $this->shopUrl('/account/company/approvals')))->count());

        $this->logout();
        $this->loginShopUser(ApprovalScenario::customerOf($s->adminA));
        $crawler = $this->client->request('GET', $this->shopUrl('/account/company'));
        self::assertCount(1, $crawler->filter('[data-test-tmonier-limits-link]'));
        self::assertCount(0, $crawler->filter('[data-test-tmonier-approvals-link]'));
    }

    private function approvalOf(OrderInterface $order): OrderApprovalInterface
    {
        $this->entityManager()->clear();
        $repository = self::getContainer()->get('tmonier_purchase_approval.repository.order_approval');
        self::assertInstanceOf(OrderApprovalRepositoryInterface::class, $repository);
        $approval = $repository->findOneByOrderNumber((string) $order->getNumber());
        self::assertInstanceOf(OrderApprovalInterface::class, $approval);

        return $approval;
    }
}
