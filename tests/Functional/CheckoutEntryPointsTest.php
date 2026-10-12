<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusPurchaseApprovalPlugin\Functional;

use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mime\Email;
use Tests\Tmonier\SyliusPurchaseApprovalPlugin\AsyncMessagesTrait;
use Tmonier\SyliusCompanyAccountsPlugin\Entity\CompanyMemberInterface;
use Tmonier\SyliusPurchaseApprovalPlugin\Approval\ApprovalDecisionMaker;
use Tmonier\SyliusPurchaseApprovalPlugin\Approval\Decider;
use Tmonier\SyliusPurchaseApprovalPlugin\Entity\OrderApprovalInterface;
use Tmonier\SyliusPurchaseApprovalPlugin\Repository\OrderApprovalRepositoryInterface;

/** The real entry points: shop checkout, API checkout, API payment requests, pay buttons. */
final class CheckoutEntryPointsTest extends WebTestCase
{
    use FunctionalTestTrait;
    use AsyncMessagesTrait;

    public function testTheShopCheckoutPlacesTheOrderForApprovalAndAcknowledgesIt(): void
    {
        $buyer = $this->catalog()->member($this->catalog()->company());
        $cart = $this->cartAboveTheLimit($buyer);
        $this->loginShopUser(ApprovalScenario::customerOf($buyer));

        $crawler = $this->client->request('GET', $this->shopUrl('/checkout/complete'));
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->filter('form[action$="/checkout/complete"]')->form());

        self::assertSame([sprintf('Order #%s received, awaiting approval', (string) $this->numberOf($cart))], $this->subjectsTo($cart));
        self::assertSame(OrderApprovalInterface::STATE_PENDING, $this->approvalOf($cart)->getState());
        // Sylius sends the customer to the pay page: refused, thank-you page with the reason.
        $this->client->followRedirect();
        self::assertResponseRedirects($this->shopUrl('/order/thank-you'));
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('is waiting for the approval of your company', implode(' ', $this->flashes($crawler)));
    }

    public function testTheApiCheckoutPlacesTheOrderForApprovalAndRefusesPaymentRequests(): void
    {
        $buyer = $this->catalog()->member($this->catalog()->company());
        $cart = $this->cartAboveTheLimit($buyer);
        $headers = $this->shopApiHeaders(ApprovalScenario::customerOf($buyer), 'application/merge-patch+json');

        $this->client->request('PATCH', sprintf('/api/v2/shop/orders/%s/complete', (string) $cart->getTokenValue()), server: $headers, content: '{}');
        self::assertResponseIsSuccessful();
        self::assertSame(OrderApprovalInterface::STATE_PENDING, $this->approvalOf($cart)->getState());
        self::assertSame(1, $this->runWorker());
        self::assertSame([sprintf('Order #%s received, awaiting approval', (string) $this->numberOf($cart))], $this->subjectsTo($cart));

        $payment = $this->reload($cart)->getLastPayment(PaymentInterface::STATE_NEW);
        self::assertInstanceOf(PaymentInterface::class, $payment);
        $this->client->request('POST', sprintf('/api/v2/shop/orders/%s/payment-requests', (string) $cart->getTokenValue()), server: $this->shopApiHeaders(ApprovalScenario::customerOf($buyer)), content: (string) json_encode([
            'paymentId' => $payment->getId(),
            'paymentMethodCode' => $payment->getMethod()?->getCode(),
        ]));
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('is waiting for the approval of your company', (string) $this->client->getResponse()->getContent());
    }

    public function testNoPayButtonWhileTheOrderWaits(): void
    {
        $company = $this->catalog()->company();
        $buyer = $this->catalog()->member($company);
        $approver = $this->catalog()->member($company, null, CompanyMemberInterface::ROLE_APPROVER);
        $order = $this->catalog()->orderAgainstLimit($buyer, -1);
        $this->loginShopUser(ApprovalScenario::customerOf($buyer));

        $crawler = $this->client->request('GET', $this->shopUrl('/account/orders/' . $order->getNumber()));
        self::assertCount(0, $crawler->filter(sprintf('a[href$="/order/%s"]', (string) $order->getTokenValue())));
        $crawler = $this->client->request('GET', $this->shopUrl('/order/' . $order->getTokenValue()));
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-test-pay-link]'));
        self::assertSame('pending', $crawler->filter('[data-test-tmonier-order-approval]')->attr('data-test-tmonier-order-approval'));

        $decisionMaker = self::getContainer()->get('tmonier_purchase_approval.decision_maker');
        self::assertInstanceOf(ApprovalDecisionMaker::class, $decisionMaker);
        $decisionMaker->approve($this->approvalOf($order), Decider::member($approver));

        $crawler = $this->client->request('GET', $this->shopUrl('/account/orders/' . $order->getNumber()));
        self::assertCount(1, $crawler->filter(sprintf('a[href$="/order/%s"]', (string) $order->getTokenValue())));
        $crawler = $this->client->request('GET', $this->shopUrl('/order/' . $order->getTokenValue()));
        self::assertCount(1, $crawler->filter('[data-test-pay-link]'));
    }

    /** A cart of the member at the payment step, with an order limit just below its total. */
    private function cartAboveTheLimit(CompanyMemberInterface $member): OrderInterface
    {
        $cart = $this->catalog()->placedOrder(ApprovalScenario::customerOf($member), checkoutState: 'payment_selected');
        $this->catalog()->limit($member, $cart->getTotal() - 1);

        return $cart;
    }

    private function numberOf(OrderInterface $order): string
    {
        return (string) $this->reload($order)->getNumber();
    }

    /** @return list<string> */
    private function subjectsTo(OrderInterface $order): array
    {
        $address = (string) $order->getCustomer()?->getEmail();
        $subjects = [];
        foreach (self::getMailerMessages() as $message) {
            if ($message instanceof Email && $address === $message->getTo()[0]->getAddress()) {
                $subjects[] = trim((string) $message->getSubject());
            }
        }

        return $subjects;
    }

    private function approvalOf(OrderInterface $order): OrderApprovalInterface
    {
        $this->entityManager()->clear();
        $repository = self::getContainer()->get('tmonier_purchase_approval.repository.order_approval');
        self::assertInstanceOf(OrderApprovalRepositoryInterface::class, $repository);
        $approval = $repository->findOneBy(['order' => $order->getId()]);
        self::assertInstanceOf(OrderApprovalInterface::class, $approval);

        return $approval;
    }
}
