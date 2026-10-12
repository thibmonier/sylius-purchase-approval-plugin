<?php

declare(strict_types=1);

namespace Tmonier\SyliusPurchaseApprovalPlugin\Api\Shop;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\TraversablePaginator;
use ApiPlatform\State\ProviderInterface;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Tmonier\SyliusCompanyAccountsPlugin\Member\CurrentMemberResolverInterface;
use Tmonier\SyliusPurchaseApprovalPlugin\Approval\ApprovalPolicy;
use Tmonier\SyliusPurchaseApprovalPlugin\Entity\OrderApprovalInterface;
use Tmonier\SyliusPurchaseApprovalPlugin\Repository\OrderApprovalRepositoryInterface;
use Tmonier\SyliusPurchaseApprovalPlugin\Security\ApprovalPermissions;

/**
 * Same rules as the account pages: no membership 404, not an approver 403, an approval out of
 * reach (other company, colleague's order for a buyer) 404.
 *
 * @implements ProviderInterface<ShopApprovalView>
 */
final class ShopApprovalProvider implements ProviderInterface
{
    public function __construct(
        private readonly CurrentMemberResolverInterface $currentMemberResolver,
        private readonly OrderApprovalRepositoryInterface $approvalRepository,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
        private readonly ApprovalPolicy $policy,
    ) {
    }

    /** @return ShopApprovalView|TraversablePaginator<ShopApprovalView> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ShopApprovalView|TraversablePaginator
    {
        if ($operation instanceof CollectionOperationInterface) {
            $member = $this->currentMemberResolver->getMember();
            $company = $member?->getCompany();
            if (null === $company) {
                throw new NotFoundHttpException('No company.');
            }
            if (!$this->authorizationChecker->isGranted(ApprovalPermissions::APPROVAL_LIST, $company)) {
                throw new AccessDeniedHttpException('Access denied.');
            }
            $filters = $context['filters'] ?? [];
            $pendingOnly = is_array($filters) && OrderApprovalInterface::STATE_PENDING === ($filters['state'] ?? null);
            $page = max(1, (int) (is_array($filters) && is_numeric($filters['page'] ?? null) ? $filters['page'] : 1));
            $limit = 30;
            $paginator = new Paginator($this->approvalRepository->createListQueryBuilderByCompany($company, $pendingOnly)->setFirstResult(($page - 1) * $limit)->setMaxResults($limit));
            $views = [];
            foreach ($paginator as $approval) {
                if ($approval instanceof OrderApprovalInterface) {
                    $views[] = $this->view($approval);
                }
            }

            return new TraversablePaginator(new \ArrayIterator($views), $page, $limit, count($paginator));
        }

        return $this->view($this->find($uriVariables));
    }

    /** The approval of the URI, visible to the signed-in customer, else 404. */
    public function find(array $uriVariables): OrderApprovalInterface
    {
        $number = $uriVariables['orderNumber'] ?? null;
        $approval = is_string($number) ? $this->approvalRepository->findOneByOrderNumber($number) : null;
        if (null === $approval || !$this->authorizationChecker->isGranted(ApprovalPermissions::APPROVAL_VIEW, $approval)) {
            throw new NotFoundHttpException('Approval not found.');
        }

        return $approval;
    }

    public function view(OrderApprovalInterface $approval): ShopApprovalView
    {
        $member = $this->currentMemberResolver->getMember();

        return ShopApprovalView::fromApproval(
            $approval,
            null !== $member && null === $this->policy->checkApprove($member, $approval),
            null !== $member && null === $this->policy->checkReject($member, $approval),
        );
    }
}
