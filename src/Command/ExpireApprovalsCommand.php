<?php

declare(strict_types=1);

namespace Tmonier\SyliusPurchaseApprovalPlugin\Command;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Tmonier\SyliusPurchaseApprovalPlugin\Approval\ApprovalDecisionMaker;
use Tmonier\SyliusPurchaseApprovalPlugin\Entity\OrderApprovalInterface;
use Tmonier\SyliusPurchaseApprovalPlugin\Repository\OrderApprovalRepositoryInterface;

/**
 * Expires the approvals still pending after `expire_after_days` and cancels their orders, and
 * closes (`cancelled`) the pending approvals whose order was cancelled without the order
 * workflow (raw SQL, imports). Run it daily (cron). Each approval is decided and flushed on its own; one that fails is logged and
 * skipped, the others are still expired.
 */
#[AsCommand(name: 'tmonier:approval:expire', description: 'Expire the purchase approvals pending for too long and cancel their orders')]
final class ExpireApprovalsCommand extends Command
{
    private const BATCH_SIZE = 50;

    public function __construct(
        private readonly OrderApprovalRepositoryInterface $approvalRepository,
        private readonly ApprovalDecisionMaker $decisionMaker,
        private readonly EntityManagerInterface $entityManager,
        private readonly ManagerRegistry $managerRegistry,
        private readonly LoggerInterface $logger,
        private readonly int $expireAfterDays,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'List the approvals that would expire, change nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $before = new \DateTimeImmutable(sprintf('-%d days', $this->expireAfterDays));
        $dryRun = (bool) $input->getOption('dry-run');

        $closed = 0;
        foreach ($this->approvalRepository->findPendingIdsOfClosedOrders(1000) as $id) {
            $approval = $this->approvalRepository->find($id);
            if (!$approval instanceof OrderApprovalInterface) {
                continue;
            }
            if ($dryRun) {
                $io->writeln(sprintf('Order %s is no longer open: its approval would be closed.', (string) $approval->getOrder()?->getNumber()));
                ++$closed;
            } elseif ($this->decisionMaker->closeForClosedOrder($approval)) {
                ++$closed;
            }
        }
        $this->entityManager->clear();

        $expired = 0;
        $failed = 0;
        $seen = [];
        do {
            $ids = array_values(array_diff($this->approvalRepository->findPendingIdsRequestedBefore($before, self::BATCH_SIZE + count($seen)), $seen));
            foreach ($ids as $id) {
                $seen[] = $id;
                $approval = $this->approvalRepository->find($id);
                if (!$approval instanceof OrderApprovalInterface || !$approval->isPending()) {
                    continue;
                }
                $number = (string) $approval->getOrder()?->getNumber();
                if ($dryRun) {
                    $io->writeln(sprintf('Order %s would expire (requested on %s).', $number, $approval->getCreatedAt()->format('Y-m-d')));
                    ++$expired;

                    continue;
                }

                try {
                    if (null === $this->decisionMaker->expire($approval)) {
                        ++$expired;
                    }
                } catch (\Throwable $exception) {
                    ++$failed;
                    $this->logger->error('Could not expire the approval of order {order}: {message}', ['order' => $number, 'message' => $exception->getMessage(), 'exception' => $exception]);
                    if (!$this->entityManager->isOpen()) {
                        // The failed transaction closed the entity manager (same service, reset in place).
                        $this->managerRegistry->resetManager();
                    }
                }
            }
            $this->entityManager->clear();
        } while ([] !== $ids);

        $io->success(sprintf('%d approval(s) %s, %d closed (order no longer open), %d failed.', $expired, $dryRun ? 'would expire' : 'expired', $closed, $failed));

        return 0 === $failed ? Command::SUCCESS : Command::FAILURE;
    }
}
