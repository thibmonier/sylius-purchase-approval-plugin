<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusPurchaseApprovalPlugin\Functional;

use Payum\Core\Payum;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Tmonier\SyliusCompanyAccountsPlugin\Entity\CompanyMemberInterface;
use Tmonier\SyliusPurchaseApprovalPlugin\Entity\MemberLimitInterface;
use Tmonier\SyliusPurchaseApprovalPlugin\Repository\MemberLimitRepositoryInterface;

final class ShopLimitsTest extends WebTestCase
{
    use FunctionalTestTrait;

    public function testOnlyCompanyAdminsManageTheLimits(): void
    {
        $s = new ApprovalScenario($this->catalog());
        $matrix = [
            'visitor' => [null, 302],
            'customer in no company' => [$s->nonMember, 404],
            'buyer' => [ApprovalScenario::customerOf($s->buyerA), 403],
            'approver' => [ApprovalScenario::customerOf($s->approverA), 403],
            'company admin' => [ApprovalScenario::customerOf($s->adminA), 200],
        ];
        foreach ($matrix as $label => [$customer, $expected]) {
            $this->logout();
            if ($customer instanceof \Sylius\Component\Core\Model\CustomerInterface) {
                $this->loginShopUser($customer);
            }
            $this->client->request('GET', $this->shopUrl('/account/company/limits'));
            self::assertSame($expected, $this->client->getResponse()->getStatusCode(), $label);
        }
    }

    public function testACompanyAdminSetsTheLimitsOfAColleague(): void
    {
        $s = new ApprovalScenario($this->catalog());
        $this->loginShopUser(ApprovalScenario::customerOf($s->adminA));

        $crawler = $this->client->request('GET', $this->shopUrl('/account/company/limits'));
        self::assertCount(0, $crawler->filter(sprintf('[data-test-tmonier-limit-form="%d"]', $this->idOf($s->adminA))), 'Not their own limits.');
        self::assertSame('Order limit of Ann Approver', $crawler->filter(sprintf('[data-test-tmonier-limit="%d"] input[name="order_limit"]', $this->idOf($s->approverA)))->attr('aria-label'));
        $form = $crawler->filter(sprintf('[data-test-tmonier-limit-form="%d"]', $this->idOf($s->approverA)))->form([
            'order_limit' => '1 200,50',
            'approval_limit' => '5000',
        ]);
        $this->client->submit($form);
        self::assertResponseRedirects($this->shopUrl('/account/company/limits'));
        $crawler = $this->client->followRedirect();
        self::assertSame(['The limits have been updated.'], $this->flashes($crawler));

        $limit = $this->limitOf($s->approverA);
        self::assertSame([120050, 500000, $this->catalog()->currencyCode()], [$limit?->getOrderLimit(), $limit?->getApprovalLimit(), $limit?->getCurrencyCode()]);

        // Empty: no limit any more.
        $form = $crawler->filter(sprintf('[data-test-tmonier-limit-form="%d"]', $this->idOf($s->approverA)))->form(['order_limit' => '', 'approval_limit' => '']);
        $this->client->submit($form);
        self::assertSame([null, null], [$this->limitOf($s->approverA)?->getOrderLimit(), $this->limitOf($s->approverA)?->getApprovalLimit()]);
    }

    public function testInvalidChangesAreRefused(): void
    {
        $s = new ApprovalScenario($this->catalog());
        $other = $this->catalog()->member($this->catalog()->company('Gamma'));
        $this->loginShopUser(ApprovalScenario::customerOf($s->adminA));
        $before = $this->limitOf($s->buyerA)?->getOrderLimit();

        $cases = [
            'own limits' => [$s->adminA, ['order_limit' => '1', 'currency' => $this->catalog()->currencyCode()], 'You cannot change your own limits.'],
            'invalid amount' => [$s->buyerA, ['order_limit' => '-5', 'currency' => $this->catalog()->currencyCode()], 'Enter a positive amount with at most two decimals, or leave it empty.'],
            'currency of no channel' => [$s->buyerA, ['order_limit' => '5', 'currency' => 'XTS'], 'Choose a currency of the store for the limits.'],
        ];
        foreach ($cases as $label => [$member, $fields, $message]) {
            $token = $this->sessionCsrfToken('tmonier_member_limit_' . $this->idOf($member));
            $this->client->request('POST', $this->shopUrl('/account/company/limits/' . $this->idOf($member)), $fields + ['_csrf_token' => $token]);
            $crawler = $this->client->followRedirect();
            self::assertSame([$message], $this->flashes($crawler), $label);
        }
        self::assertSame($before, $this->limitOf($s->buyerA)?->getOrderLimit());
        self::assertNull($this->limitOf($s->adminA));

        // A member of another company: not found.
        $token = $this->sessionCsrfToken('tmonier_member_limit_' . $this->idOf($other));
        $this->client->request('POST', $this->shopUrl('/account/company/limits/' . $this->idOf($other)), ['order_limit' => '1', 'currency' => $this->catalog()->currencyCode(), '_csrf_token' => $token]);
        self::assertResponseStatusCodeSame(404);
        self::assertNull($this->limitOf($other));
    }

    public function testAPayumTokenOfAWaitingOrderCannotBeUsed(): void
    {
        // A gateway token created before the block (e.g. by a gateway redirect during checkout).
        $s = new ApprovalScenario($this->catalog());
        $payment = $s->pendingOrder->getLastPayment(PaymentInterface::STATE_NEW);
        self::assertInstanceOf(PaymentInterface::class, $payment);
        $method = $payment->getMethod();
        self::assertInstanceOf(PaymentMethodInterface::class, $method);
        $payum = self::getContainer()->get('payum');
        self::assertInstanceOf(Payum::class, $payum);
        $token = $payum->getTokenFactory()->createCaptureToken((string) $method->getGatewayConfig()?->getGatewayName(), $payment, 'sylius_shop_order_after_pay', ['_locale' => $this->catalog()->localeCode()]);
        $this->entityManager()->flush();
        $this->loginShopUser(ApprovalScenario::customerOf($s->buyerA));

        $this->client->request('GET', (string) parse_url($token->getTargetUrl(), \PHP_URL_PATH));

        self::assertResponseRedirects($this->shopUrl('/order/thank-you'));
        self::assertSame(PaymentInterface::STATE_NEW, $this->connection()->fetchOne('SELECT state FROM sylius_payment WHERE id = ?', [$payment->getId()]));
    }

    private function limitOf(CompanyMemberInterface $member): ?MemberLimitInterface
    {
        $this->entityManager()->clear();
        $repository = self::getContainer()->get('tmonier_purchase_approval.repository.member_limit');
        self::assertInstanceOf(MemberLimitRepositoryInterface::class, $repository);
        $member = $this->reload($member);

        return $repository->findOneByMember($member);
    }
}
