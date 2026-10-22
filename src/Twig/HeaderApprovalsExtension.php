<?php

declare(strict_types=1);

namespace Tmonier\SyliusPurchaseApprovalPlugin\Twig;

use Symfony\Contracts\Service\ResetInterface;
use Tmonier\SyliusCompanyAccountsPlugin\Member\CurrentMemberResolverInterface;
use Tmonier\SyliusPurchaseApprovalPlugin\Approval\DecidableApprovalCounter;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class HeaderApprovalsExtension extends AbstractExtension implements ResetInterface
{
    /** Resolved once per request: the desktop and the mobile entries share it. */
    private ?int $count = null;

    private bool $resolved = false;

    public function __construct(
        private readonly CurrentMemberResolverInterface $currentMemberResolver,
        private readonly DecidableApprovalCounter $counter,
        private readonly bool $enabled,
    ) {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('tmonier_header_approvals', $this->headerApprovals(...))];
    }

    /** Pending approvals the signed-in member may decide; null: no entry in the header. */
    public function headerApprovals(): ?int
    {
        if (!$this->enabled) {
            return null;
        }
        if (!$this->resolved) {
            $this->count = $this->counter->count($this->currentMemberResolver->getMember());
            $this->resolved = true;
        }

        return $this->count;
    }

    public function reset(): void
    {
        $this->count = null;
        $this->resolved = false;
    }
}
