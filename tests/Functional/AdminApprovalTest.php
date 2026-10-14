<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusPurchaseApprovalPlugin\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Tmonier\SyliusPurchaseApprovalPlugin\Entity\MemberLimitInterface;
use Tmonier\SyliusPurchaseApprovalPlugin\Entity\OrderApprovalInterface;
use Tmonier\SyliusPurchaseApprovalPlugin\Repository\MemberLimitRepositoryInterface;
use Tmonier\SyliusPurchaseApprovalPlugin\Repository\OrderApprovalRepositoryInterface;

final class AdminApprovalTest extends WebTestCase
{
    use FunctionalTestTrait;

    public function testTheOrderPageShowsTheApprovalAndTheStoreDecides(): void
    {
        $s = new ApprovalScenario($this->catalog());
        $admin = $this->loginAdmin();

        $crawler = $this->client->request('GET', sprintf('/admin/orders/%d', $this->idOf($s->pendingOrder)));
        self::assertResponseIsSuccessful();
        self::assertSame('pending', $crawler->filter('[data-test-tmonier-admin-order-approval]')->attr('data-test-tmonier-admin-order-approval'));
        self::assertStringContainsString((string) $s->companyA->getName(), $crawler->filter('[data-test-tmonier-admin-order-approval]')->text());

        $this->client->submit($crawler->filter('[data-test-tmonier-admin-approve]')->form(['comment' => 'Confirmed by phone']));
        self::assertResponseRedirects(sprintf('/admin/orders/%d', $this->idOf($s->pendingOrder)));
        $crawler = $this->client->followRedirect();
        self::assertSame('approved', $crawler->filter('[data-test-tmonier-admin-order-approval]')->attr('data-test-tmonier-admin-order-approval'));
        self::assertCount(0, $crawler->filter('[data-test-tmonier-admin-approval-decision]'));

        $approval = $this->approvalOf($this->idOf($s->pendingOrder));
        self::assertSame(OrderApprovalInterface::DECIDED_BY_ADMINISTRATOR, $approval->getDeciderType());
        self::assertNotNull($approval->getDeciderName());
        self::assertStringContainsString((string) ($admin->getFirstName() ?? $admin->getUsername()), $approval->getDeciderName());
        self::assertSame('Confirmed by phone', $approval->getComment());

        // An invalid token is refused.
        $other = $this->catalog()->orderAgainstLimit($s->colleagueA, -1);
        $this->client->request('POST', sprintf('/admin/orders/%d/approval/reject', $this->idOf($other)), ['_csrf_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);
        self::assertTrue($this->approvalOf($this->idOf($other))->isPending());
    }

    public function testTheOrdersGridFiltersByApprovalState(): void
    {
        $s = new ApprovalScenario($this->catalog());
        $within = $this->catalog()->orderAgainstLimit($s->colleagueA, 0);
        $this->loginAdmin();

        $numbers = function (string $state): string {
            $crawler = $this->client->request('GET', '/admin/orders/?limit=100&criteria[tmonier_approval_state]=' . $state);
            self::assertResponseIsSuccessful();
            $table = $crawler->filter('table');

            return 0 === $table->count() ? '' : $table->text();
        };

        self::assertStringContainsString((string) $s->pendingOrder->getNumber(), $numbers('pending'));
        self::assertStringNotContainsString((string) $within->getNumber(), $numbers('pending'));
        self::assertStringNotContainsString((string) $s->pendingOrder->getNumber(), $numbers('approved'));
        self::assertStringNotContainsString((string) $s->pendingOrder->getNumber(), $numbers('none'));
        self::assertStringContainsString((string) $within->getNumber(), $numbers('none'));
    }

    public function testTheOrdersGridShowsTheApprovalWithOneQueryForThePage(): void
    {
        $s = new ApprovalScenario($this->catalog());
        $this->loginAdmin();
        $grid = function (): array {
            // Measured on a second request: the first one also collects the queries made by the
            // test between requests.
            $this->client->request('GET', '/admin/orders/?limit=100&criteria[tmonier_approval_state]=pending');
            $this->client->enableProfiler();
            $crawler = $this->client->request('GET', '/admin/orders/?limit=100&criteria[tmonier_approval_state]=pending');
            self::assertResponseIsSuccessful();
            $profile = $this->client->getProfile();
            self::assertInstanceOf(\Symfony\Component\HttpKernel\Profiler\Profile::class, $profile);
            $collector = $profile->getCollector('db');
            self::assertInstanceOf(\Doctrine\Bundle\DoctrineBundle\DataCollector\DoctrineDataCollector::class, $collector);

            return [$crawler, $collector->getQueryCount()];
        };

        [$crawler, $queriesForOne] = $grid();
        self::assertSame('pending', $crawler->filter('table [data-test-tmonier-approval-state]')->first()->attr('data-test-tmonier-approval-state'));

        $this->catalog()->orderAgainstLimit($s->colleagueA, -1);
        $this->catalog()->orderAgainstLimit($s->colleagueA, -1);
        $this->catalog()->orderAgainstLimit($s->colleagueA, -1);
        [$crawler, $queriesForFour] = $grid();
        self::assertGreaterThanOrEqual(4, $crawler->filter('table [data-test-tmonier-approval-state="pending"]')->count());
        // Three more rows: at most one query of difference (cache warm-up), never one per row.
        self::assertLessThanOrEqual($queriesForOne + 1, $queriesForFour, 'No query per row.');
    }

    public function testTheMemberFormHoldsThePurchaseLimits(): void
    {
        $s = new ApprovalScenario($this->catalog());
        $this->loginAdmin();
        $path = sprintf('/admin/companies/%d/members/%d/edit', $this->idOf($s->companyA), $this->idOf($s->approverA));

        $crawler = $this->client->request('GET', $path);
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form[name="tmonier_company_accounts_company_member"]')->form([
            'tmonier_company_accounts_company_member[approvalOrderLimit]' => '250',
            'tmonier_company_accounts_company_member[approvalApprovalLimit]' => '10000.50',
            'tmonier_company_accounts_company_member[approvalCurrency]' => $this->catalog()->currencyCode(),
        ]);
        $this->client->submit($form);
        self::assertResponseRedirects();

        $limit = $this->limitOfApprover($s);
        self::assertSame([25000, 1000050, $this->catalog()->currencyCode()], [$limit?->getOrderLimit(), $limit?->getApprovalLimit(), $limit?->getCurrencyCode()]);

        // Shown again, then an invalid amount is refused.
        $crawler = $this->client->request('GET', $path);
        self::assertSame('250.00', $crawler->filter('[name="tmonier_company_accounts_company_member[approvalOrderLimit]"]')->attr('value'));
        $form = $crawler->filter('form[name="tmonier_company_accounts_company_member"]')->form([
            'tmonier_company_accounts_company_member[approvalOrderLimit]' => 'abc',
        ]);
        $this->client->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(25000, $this->limitOfApprover($s)?->getOrderLimit());

        // Both limits empty: the limits are removed.
        $crawler = $this->client->request('GET', $path);
        $form = $crawler->filter('form[name="tmonier_company_accounts_company_member"]')->form([
            'tmonier_company_accounts_company_member[approvalOrderLimit]' => '',
            'tmonier_company_accounts_company_member[approvalApprovalLimit]' => '',
        ]);
        $this->client->submit($form);
        self::assertResponseRedirects();
        self::assertNull($this->limitOfApprover($s));
    }

    private function limitOfApprover(ApprovalScenario $s): ?MemberLimitInterface
    {
        $this->entityManager()->clear();
        $repository = self::getContainer()->get('tmonier_purchase_approval.repository.member_limit');
        self::assertInstanceOf(MemberLimitRepositoryInterface::class, $repository);

        return $repository->findOneByMember($this->reload($s->approverA));
    }

    private function approvalOf(int $orderId): OrderApprovalInterface
    {
        $this->entityManager()->clear();
        $repository = self::getContainer()->get('tmonier_purchase_approval.repository.order_approval');
        self::assertInstanceOf(OrderApprovalRepositoryInterface::class, $repository);
        $approval = $repository->findOneBy(['order' => $orderId]);
        self::assertInstanceOf(OrderApprovalInterface::class, $approval);

        return $approval;
    }
}
