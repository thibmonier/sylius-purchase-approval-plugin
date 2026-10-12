<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusPurchaseApprovalPlugin\Integration;

use Sylius\Component\Core\Model\OrderInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Tmonier\SyliusPurchaseApprovalPlugin\Entity\OrderApprovalInterface;

/** `tmonier:approval:expire` with the default 14 days. */
final class ExpireApprovalsCommandTest extends KernelTestCase
{
    use IntegrationTestTrait;

    public function testOldPendingApprovalsExpireAndTheirOrdersAreCancelled(): void
    {
        $company = $this->catalog()->company();
        $old = $this->catalog()->orderAgainstLimit($this->catalog()->member($company), -1);
        $recent = $this->catalog()->orderAgainstLimit($this->catalog()->member($company), -1);
        $this->requestedDaysAgo($old, 15);
        $this->requestedDaysAgo($recent, 13);

        $dryRun = $this->expire(['--dry-run' => true]);
        self::assertStringContainsString((string) $old->getNumber(), $dryRun);
        self::assertStringNotContainsString((string) $recent->getNumber(), $dryRun);
        self::assertSame([OrderApprovalInterface::STATE_PENDING, OrderInterface::STATE_NEW], $this->states($old), 'Dry run.');

        $this->expire();

        self::assertSame([OrderApprovalInterface::STATE_EXPIRED, OrderInterface::STATE_CANCELLED], $this->states($old));
        self::assertSame([OrderApprovalInterface::STATE_PENDING, OrderInterface::STATE_NEW], $this->states($recent));
    }

    public function testPendingApprovalsOfOrdersCancelledOutsideTheWorkflowAreClosed(): void
    {
        $company = $this->catalog()->company();
        $cancelled = $this->catalog()->orderAgainstLimit($this->catalog()->member($company), -1);
        $open = $this->catalog()->orderAgainstLimit($this->catalog()->member($company), -1);
        $this->connection()->executeStatement("UPDATE sylius_order SET state = 'cancelled' WHERE id = ?", [$cancelled->getId()]);

        self::assertStringContainsString((string) $cancelled->getNumber(), $this->expire(['--dry-run' => true]));
        self::assertSame(OrderApprovalInterface::STATE_PENDING, $this->states($cancelled)[0], 'Dry run.');
        $this->expire();

        self::assertSame([OrderApprovalInterface::STATE_CANCELLED, OrderInterface::STATE_CANCELLED], $this->states($cancelled));
        self::assertSame([OrderApprovalInterface::STATE_PENDING, OrderInterface::STATE_NEW], $this->states($open));
        self::assertSame(OrderApprovalInterface::DECIDED_BY_SYSTEM, $this->connection()->fetchOne('SELECT decider_type FROM tmonier_purchase_approval_order_approval WHERE order_id = ?', [$cancelled->getId()]));
    }

    private function requestedDaysAgo(OrderInterface $order, int $days): void
    {
        $this->connection()->executeStatement(
            'UPDATE tmonier_purchase_approval_order_approval SET created_at = ? WHERE order_id = ?',
            [(new \DateTime(sprintf('-%d days', $days)))->format('Y-m-d H:i:s'), $order->getId()],
        );
    }

    /** @param array<string, mixed> $options */
    private function expire(array $options = []): string
    {
        $this->entityManager()->clear();
        $tester = new CommandTester((new Application(self::$kernel ?? throw new \LogicException()))->find('tmonier:approval:expire'));
        self::assertSame(0, $tester->execute($options));

        return $tester->getDisplay();
    }

    /** @return list<mixed> */
    private function states(OrderInterface $order): array
    {
        return [
            $this->connection()->fetchOne('SELECT state FROM tmonier_purchase_approval_order_approval WHERE order_id = ?', [$order->getId()]),
            $this->connection()->fetchOne('SELECT state FROM sylius_order WHERE id = ?', [$order->getId()]),
        ];
    }
}
