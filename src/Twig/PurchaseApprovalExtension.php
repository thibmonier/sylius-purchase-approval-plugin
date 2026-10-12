<?php

declare(strict_types=1);

namespace Tmonier\SyliusPurchaseApprovalPlugin\Twig;

use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Symfony\Contracts\Service\ResetInterface;
use Tmonier\SyliusCompanyAccountsPlugin\Entity\CompanyInterface;
use Tmonier\SyliusCompanyAccountsPlugin\Entity\CompanyMemberInterface;
use Tmonier\SyliusCompanyAccountsPlugin\Member\CurrentMemberResolverInterface;
use Tmonier\SyliusPurchaseApprovalPlugin\Approval\ApprovalPolicy;
use Tmonier\SyliusPurchaseApprovalPlugin\Entity\MemberLimitInterface;
use Tmonier\SyliusPurchaseApprovalPlugin\Entity\OrderApprovalInterface;
use Tmonier\SyliusPurchaseApprovalPlugin\Repository\MemberLimitRepositoryInterface;
use Tmonier\SyliusPurchaseApprovalPlugin\Repository\OrderApprovalRepositoryInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class PurchaseApprovalExtension extends AbstractExtension implements ResetInterface
{
    /**
     * Approvals already looked up in this request, by order id (null: none).
     *
     * @var array<int, OrderApprovalInterface|null>
     */
    private array $approvals = [];

    public function __construct(
        private readonly OrderApprovalRepositoryInterface $approvalRepository,
        private readonly MemberLimitRepositoryInterface $limitRepository,
        private readonly CurrentMemberResolverInterface $currentMemberResolver,
        private readonly ApprovalPolicy $policy,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('tmonier_order_approval', $this->approval(...)),
            new TwigFunction('tmonier_order_approval_in_list', $this->approvalInList(...)),
            new TwigFunction('tmonier_pending_approvals_count', $this->pendingCount(...)),
            new TwigFunction('tmonier_member_limit', $this->limit(...)),
            new TwigFunction('tmonier_approval_refusal', $this->refusal(...)),
        ];
    }

    public function approval(mixed $order): ?OrderApprovalInterface
    {
        return $order instanceof OrderInterface && null !== $order->getId() ? $this->approvalRepository->findOneByOrder($order) : null;
    }

    /**
     * Same as tmonier_order_approval(), for rows of a list (orders grid): the first call loads
     * the approvals of every order loaded in the request with one query, then each row reads
     * from that cache instead of querying.
     */
    public function approvalInList(mixed $order): ?OrderApprovalInterface
    {
        if (!$order instanceof OrderInterface || !is_int($order->getId())) {
            return null;
        }
        $id = $order->getId();
        if (!array_key_exists($id, $this->approvals)) {
            $ids = [$id];
            $class = $this->entityManager->getClassMetadata($order::class)->getName();
            foreach ($this->entityManager->getUnitOfWork()->getIdentityMap()[$class] ?? [] as $loaded) {
                if ($loaded instanceof OrderInterface && is_int($loaded->getId()) && !array_key_exists($loaded->getId(), $this->approvals)) {
                    $ids[] = $loaded->getId();
                }
            }
            $found = $this->approvalRepository->findByOrderIds(array_values(array_unique($ids)));
            foreach ($ids as $orderId) {
                $this->approvals[$orderId] = $found[$orderId] ?? null;
            }
        }

        return $this->approvals[$id];
    }

    public function reset(): void
    {
        $this->approvals = [];
    }

    public function pendingCount(mixed $company): int
    {
        return $company instanceof CompanyInterface ? $this->approvalRepository->countPendingByCompany($company) : 0;
    }

    public function limit(mixed $member): ?MemberLimitInterface
    {
        return $member instanceof CompanyMemberInterface ? $this->limitRepository->findOneByMember($member) : null;
    }

    /**
     * Why the signed-in member may not approve (or reject) the approval, as a translation key
     * of the `validators` domain; null when they may.
     */
    public function refusal(OrderApprovalInterface $approval, string $decision = 'approve'): ?string
    {
        $member = $this->currentMemberResolver->getMember();
        if (null === $member) {
            return ApprovalPolicy::ERROR_NOT_ALLOWED;
        }

        return 'reject' === $decision ? $this->policy->checkReject($member, $approval) : $this->policy->checkApprove($member, $approval);
    }
}
