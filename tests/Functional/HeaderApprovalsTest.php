<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusPurchaseApprovalPlugin\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class HeaderApprovalsTest extends WebTestCase
{
    use FunctionalTestTrait;

    public function testAnApproverSeesTheNumberOfOrdersWaitingForThem(): void
    {
        $s = new ApprovalScenario($this->catalog());
        $this->catalog()->orderAgainstLimit($s->colleagueA, -1);
        $this->loginShopUser(ApprovalScenario::customerOf($s->approverA));

        $crawler = $this->client->request('GET', $this->shopUrl('/'));

        foreach (['desktop', 'mobile'] as $where) {
            $entry = $crawler->filter(sprintf('[data-test-tmonier-header-approvals="%s"]', $where));
            self::assertCount(1, $entry, $where);
            self::assertSame('2', trim($entry->filter('[data-test-tmonier-header-approvals-badge]')->text()), $where);
            self::assertStringContainsString('2 approvals pending', $entry->text(), 'Announced as text, not by colour only.');
            self::assertStringContainsString('decidable=1', (string) $entry->filter('a')->attr('href'));
        }
    }

    public function testTheEntryStaysWithoutBadgeWhenNothingWaits(): void
    {
        $s = new ApprovalScenario($this->catalog());
        // Their own order is the only pending one for the approver of company B.
        $this->loginShopUser(ApprovalScenario::customerOf($s->approverB));

        $crawler = $this->client->request('GET', $this->shopUrl('/'));

        $entry = $crawler->filter('[data-test-tmonier-header-approvals="desktop"]');
        self::assertCount(1, $entry);
        self::assertCount(0, $entry->filter('[data-test-tmonier-header-approvals-badge]'));
        self::assertStringContainsString('No approval pending', $entry->text());
    }

    public function testOthersSeeNoEntryAndCostNoCountQuery(): void
    {
        $s = new ApprovalScenario($this->catalog());
        $viewers = [
            'visitor' => null,
            'customer in no company' => $s->nonMember,
            'buyer' => ApprovalScenario::customerOf($s->buyerA),
            'company admin' => ApprovalScenario::customerOf($s->adminA),
            'disabled approver' => ApprovalScenario::customerOf($s->disabledApproverA),
        ];
        foreach ($viewers as $label => $customer) {
            $this->logout();
            if ($customer instanceof \Sylius\Component\Core\Model\CustomerInterface) {
                $this->loginShopUser($customer);
            }
            // Measured on a second request: the first one also collects the queries of the test.
            $this->client->request('GET', $this->shopUrl('/'));
            $this->client->enableProfiler();
            $crawler = $this->client->request('GET', $this->shopUrl('/'));

            self::assertResponseIsSuccessful();
            self::assertCount(0, $crawler->filter('[data-test-tmonier-header-approvals]'), $label);
            self::assertSame([], $this->approvalCountQueries(), $label);
        }
    }

    public function testAnApproverCostsOneCountQueryPerPage(): void
    {
        $s = new ApprovalScenario($this->catalog());
        $this->loginShopUser(ApprovalScenario::customerOf($s->approverA));
        $this->client->request('GET', $this->shopUrl('/'));
        $this->client->enableProfiler();

        $this->client->request('GET', $this->shopUrl('/'));

        self::assertCount(1, $this->approvalCountQueries(), 'Desktop and mobile entries share one query.');
    }

    public function testTheEntryLeadsToTheListOfWhatTheMemberDecides(): void
    {
        $s = new ApprovalScenario($this->catalog());
        $own = $this->catalog()->orderAgainstLimit($s->approverA, -1);
        $this->loginShopUser(ApprovalScenario::customerOf($s->approverA));

        $crawler = $this->client->request('GET', $this->shopUrl('/'));
        $crawler = $this->client->request('GET', (string) $crawler->filter('[data-test-tmonier-header-approvals="desktop"] a')->attr('href'));

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-test-tmonier-approval]'), 'Only the order of Bob: not the approver\'s own order.');
        self::assertCount(1, $crawler->filter(sprintf('[data-test-tmonier-approval="%s"]', (string) $s->pendingOrder->getNumber())));
        self::assertCount(0, $crawler->filter(sprintf('[data-test-tmonier-approval="%s"]', (string) $own->getNumber())));
        $all = $this->client->request('GET', $this->shopUrl('/account/company/approvals'));
        self::assertCount(2, $all->filter('[data-test-tmonier-approval]'), 'The plain list still shows every pending approval.');
    }

    public function testTheBadgeIsCappedAtNinetyNinePlus(): void
    {
        $twig = self::getContainer()->get('twig');
        self::assertInstanceOf(\Twig\Environment::class, $twig);
        $render = static fn (int $count): string => $twig->render('@TmonierSyliusPurchaseApprovalPlugin/shop/header/_entry.html.twig', ['count' => $count, 'layout' => 'desktop']);

        $capped = new Crawler($render(100));
        self::assertSame('99+', trim($capped->filter('[data-test-tmonier-header-approvals-badge]')->text()));
        self::assertStringContainsString('More than 99 approvals pending', $capped->text());
        self::assertSame('99', trim((new Crawler($render(99)))->filter('[data-test-tmonier-header-approvals-badge]')->text()));
        self::assertStringContainsString('1 approval pending', (new Crawler($render(1)))->text());
    }

    /** @return list<string> COUNT queries on the approvals table of the last request */
    private function approvalCountQueries(): array
    {
        $profile = $this->client->getProfile();
        self::assertInstanceOf(\Symfony\Component\HttpKernel\Profiler\Profile::class, $profile);
        $collector = $profile->getCollector('db');
        self::assertInstanceOf(\Doctrine\Bundle\DoctrineBundle\DataCollector\DoctrineDataCollector::class, $collector);
        $sql = [];
        /** @var array<string, list<array{sql: string}>> $queries */
        $queries = $collector->getQueries();
        foreach ($queries['default'] ?? [] as $query) {
            if (preg_match('/SELECT COUNT\(.*tmonier_purchase_approval/is', $query['sql'])) {
                $sql[] = $query['sql'];
            }
        }

        return $sql;
    }
}
