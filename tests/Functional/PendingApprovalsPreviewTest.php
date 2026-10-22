<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusPurchaseApprovalPlugin\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Tmonier\SyliusCompanyAccountsPlugin\Entity\CompanyMemberInterface;
use Tmonier\SyliusPurchaseApprovalPlugin\Entity\OrderApprovalInterface;
use Tmonier\SyliusPurchaseApprovalPlugin\Repository\OrderApprovalRepositoryInterface;

final class PendingApprovalsPreviewTest extends WebTestCase
{
    use FunctionalTestTrait;

    private const PREVIEW = '/account/company/approvals-preview';

    public function testTheHeaderEntryPointsToThePreviewWithoutLoadingIt(): void
    {
        $s = new ApprovalScenario($this->catalog());
        $this->loginShopUser(ApprovalScenario::customerOf($s->approverA));

        $crawler = $this->client->request('GET', $this->shopUrl('/'));

        foreach (['desktop', 'mobile'] as $where) {
            $entry = $crawler->filter(sprintf('[data-test-tmonier-header-approvals="%s"]', $where));
            self::assertCount(1, $entry, $where);
            self::assertStringEndsWith(self::PREVIEW, (string) $entry->attr('data-tmonier-preview-url'), $where);
        }
        self::assertStringNotContainsString((string) $s->pendingOrder->getNumber(), (string) $this->client->getResponse()->getContent(), 'The panel content is not part of the page.');
    }

    public function testTheScriptIsAStaticFileSoThatAStrictContentSecurityPolicyWorks(): void
    {
        $s = new ApprovalScenario($this->catalog());
        $this->loginShopUser(ApprovalScenario::customerOf($s->approverA));

        $crawler = $this->client->request('GET', $this->shopUrl('/'));

        $scripts = $crawler->filter('script[src$="/header-approvals.js"][defer]');
        self::assertCount(1, $scripts, 'One deferred script for the desktop and the mobile entries.');
        self::assertStringContainsString('tmoniersyliuspurchaseapprovalplugin', (string) $scripts->attr('src'));
        self::assertStringNotContainsString('tmonierApprovalsPanel', (string) $this->client->getResponse()->getContent(), 'No inline script.');
        self::assertFileExists(dirname(__DIR__, 2) . '/public/header-approvals.js');
    }

    public function testThePanelListsTheFiveOldestInOrderWithASeeAllLinkToTheSeven(): void
    {
        $s = new ApprovalScenario($this->catalog());
        $numbers = [$this->age($s->pendingOrder, 1)];
        foreach ([6, 5, 4, 3, 2, 7] as $days) {
            $numbers[(int) $days] = $this->age($this->catalog()->orderAgainstLimit($s->colleagueA, -1), $days);
        }
        $this->loginShopUser(ApprovalScenario::customerOf($s->approverA));

        $crawler = $this->client->request('GET', $this->shopUrl(self::PREVIEW));

        self::assertResponseIsSuccessful();
        $shown = $crawler->filter('[data-test-tmonier-preview-item]')->each(static fn (Crawler $item): string => (string) $item->attr('data-test-tmonier-preview-item'));
        self::assertSame([$numbers[7], $numbers[6], $numbers[5], $numbers[4], $numbers[3]], $shown, 'The five oldest, the oldest first.');
        $all = $crawler->filter('[data-test-tmonier-preview-all]');
        self::assertCount(1, $all);
        self::assertStringContainsString('See all (7)', $all->text());
        self::assertStringContainsString('decidable=1', (string) $all->attr('href'));
    }

    public function testAnItemShowsBuyerAmountAgeAndLeadsToTheOrderPageNotToADecision(): void
    {
        $s = new ApprovalScenario($this->catalog());
        $this->age($s->pendingOrder, 4);
        $this->loginShopUser(ApprovalScenario::customerOf($s->approverA));

        $crawler = $this->client->request('GET', $this->shopUrl(self::PREVIEW));

        $item = $crawler->filter('[data-test-tmonier-preview-item]');
        self::assertCount(1, $item);
        self::assertStringContainsString('Bob Buyer', $item->text());
        self::assertStringContainsString('4 days ago', $item->text());
        self::assertStringContainsString((string) $s->pendingOrder->getNumber(), $item->text());
        self::assertMatchesRegularExpression('#/account/company/approvals/[^/]+$#', (string) $item->filter('a')->attr('href'));
        self::assertCount(0, $crawler->filter('form, button[type="submit"], input[type="submit"]'), 'No decision from the panel.');
    }

    public function testAnApprovalCloseToItsExpiryIsFlagged(): void
    {
        $s = new ApprovalScenario($this->catalog());
        $this->age($s->pendingOrder, 12);
        $late = $this->age($this->catalog()->orderAgainstLimit($s->colleagueA, -1), 2);
        $this->loginShopUser(ApprovalScenario::customerOf($s->approverA));

        $crawler = $this->client->request('GET', $this->shopUrl(self::PREVIEW));

        $flagged = $crawler->filter(sprintf('[data-test-tmonier-preview-item="%s"]', (string) $s->pendingOrder->getNumber()));
        self::assertStringContainsString('Expires in 2 days', $flagged->text());
        self::assertCount(1, $flagged->filter('[data-test-tmonier-preview-expiry]'));
        self::assertCount(0, $crawler->filter(sprintf('[data-test-tmonier-preview-item="%s"] [data-test-tmonier-preview-expiry]', $late)), 'No alert far from the expiry.');
    }

    public function testNothingToDecideSaysSoWithoutSeeAllCount(): void
    {
        $s = new ApprovalScenario($this->catalog());
        $this->loginShopUser(ApprovalScenario::customerOf($s->approverB));

        $crawler = $this->client->request('GET', $this->shopUrl(self::PREVIEW));

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-test-tmonier-preview-item]'));
        self::assertStringContainsString('No approval pending', $crawler->text());
    }

    public function testAMemberNeverSeesAnotherCompany(): void
    {
        $s = new ApprovalScenario($this->catalog());
        $this->loginShopUser(ApprovalScenario::customerOf($s->approverB));

        $this->client->request('GET', $this->shopUrl(self::PREVIEW));

        self::assertStringNotContainsString((string) $s->pendingOrder->getNumber(), (string) $this->client->getResponse()->getContent());
        self::assertStringNotContainsString('Bob Buyer', (string) $this->client->getResponse()->getContent());
    }

    public function testABuyerNameIsEscapedInThePanel(): void
    {
        $s = new ApprovalScenario($this->catalog());
        $hostile = $this->catalog()->member($s->companyA, $this->catalog()->customer('hostile-a', true, '<script>alert(1)</script>', 'Buyer'));
        $this->catalog()->orderAgainstLimit($hostile, -1);
        $this->loginShopUser(ApprovalScenario::customerOf($s->approverA));

        $this->client->request('GET', $this->shopUrl(self::PREVIEW));

        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('<script>alert(1)', $content);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $content);
    }

    public function testAnApproverOfAnotherCompanySeesOnlyTheirOwnCompanyOrders(): void
    {
        $s = new ApprovalScenario($this->catalog());
        $companyB = $s->approverB->getCompany();
        self::assertInstanceOf(\Tmonier\SyliusCompanyAccountsPlugin\Entity\CompanyInterface::class, $companyB);
        $buyerB = $this->catalog()->member($companyB, $this->catalog()->customer('buyer-b', true, 'Barb', 'Beta'));
        $orderB = $this->catalog()->orderAgainstLimit($buyerB, -1);
        $this->loginShopUser(ApprovalScenario::customerOf($s->approverB));

        $crawler = $this->client->request('GET', $this->shopUrl(self::PREVIEW));

        $shown = $crawler->filter('[data-test-tmonier-preview-item]')->each(static fn (Crawler $item): string => (string) $item->attr('data-test-tmonier-preview-item'));
        self::assertSame([(string) $orderB->getNumber()], $shown);
        self::assertStringNotContainsString((string) $s->pendingOrder->getNumber(), (string) $this->client->getResponse()->getContent());
        self::assertStringContainsString('See all (1)', $crawler->text());
    }

    public function testTheirOwnOrdersAndThoseAboveTheirLimitAreNotListed(): void
    {
        $s = new ApprovalScenario($this->catalog());
        $own = $this->catalog()->orderAgainstLimit($s->approverA, -1);
        $this->catalog()->limit($s->approverA, null, 1);
        $this->loginShopUser(ApprovalScenario::customerOf($s->approverA));

        $crawler = $this->client->request('GET', $this->shopUrl(self::PREVIEW));

        self::assertCount(0, $crawler->filter('[data-test-tmonier-preview-item]'), 'Bob\'s order is above the 0.01 limit, the approver\'s own is theirs.');
        self::assertStringNotContainsString((string) $own->getNumber(), $crawler->text());
    }

    public function testAMemberWithoutTheListPermissionGets403(): void
    {
        $s = new ApprovalScenario($this->catalog());
        foreach ([$s->buyerA, $s->adminA, $s->disabledApproverA] as $member) {
            $this->logout();
            $this->loginShopUser(ApprovalScenario::customerOf($member));

            $this->client->request('GET', $this->shopUrl(self::PREVIEW));

            self::assertResponseStatusCodeSame(403, $this->describe($member));
        }
    }

    public function testACustomerInNoCompanyGets404AndAVisitorIsSentToLogin(): void
    {
        $s = new ApprovalScenario($this->catalog());
        $this->loginShopUser($s->nonMember);
        $this->client->request('GET', $this->shopUrl(self::PREVIEW));
        self::assertResponseStatusCodeSame(404);

        $this->logout();
        $this->client->request('GET', $this->shopUrl(self::PREVIEW));
        self::assertResponseRedirects();
        self::assertStringContainsString('login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testTheNumberOfQueriesDoesNotGrowWithTheNumberOfApprovals(): void
    {
        $s = new ApprovalScenario($this->catalog());
        $this->loginShopUser(ApprovalScenario::customerOf($s->approverA));
        $this->client->request('GET', $this->shopUrl(self::PREVIEW));
        $this->client->enableProfiler();
        $this->client->request('GET', $this->shopUrl(self::PREVIEW));
        $one = $this->queryCount();
        $this->client->enableProfiler();

        foreach (range(1, 4) as $_) {
            $this->catalog()->orderAgainstLimit($s->colleagueA, -1);
        }
        $this->client->request('GET', $this->shopUrl(self::PREVIEW));

        self::assertSame($one, $this->queryCount(), 'Buyers and orders are joined: no query per row.');
    }

    private function queryCount(): int
    {
        $profile = $this->client->getProfile();
        self::assertInstanceOf(\Symfony\Component\HttpKernel\Profiler\Profile::class, $profile);
        $collector = $profile->getCollector('db');
        self::assertInstanceOf(\Doctrine\Bundle\DoctrineBundle\DataCollector\DoctrineDataCollector::class, $collector);

        return $collector->getQueryCount();
    }

    /** Backdates the approval of the order; returns the order number. */
    private function age(\Sylius\Component\Core\Model\OrderInterface $order, int $days): string
    {
        $repository = self::getContainer()->get('tmonier_purchase_approval.repository.order_approval');
        self::assertInstanceOf(OrderApprovalRepositoryInterface::class, $repository);
        $approval = $repository->findOneByOrderNumber((string) $order->getNumber());
        self::assertInstanceOf(OrderApprovalInterface::class, $approval);
        $approval->setCreatedAt(new \DateTimeImmutable(sprintf('-%d days -1 hour', $days)));
        $this->entityManager()->flush();

        return (string) $order->getNumber();
    }

    private function describe(CompanyMemberInterface $member): string
    {
        return sprintf('%s (%s)', $member->getRole(), $member->isActive() ? 'active' : 'disabled');
    }
}
