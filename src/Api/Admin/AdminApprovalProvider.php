<?php

declare(strict_types=1);

namespace Tmonier\SyliusPurchaseApprovalPlugin\Api\Admin;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\TraversablePaginator;
use ApiPlatform\State\ProviderInterface;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tmonier\SyliusPurchaseApprovalPlugin\Entity\OrderApprovalInterface;
use Tmonier\SyliusPurchaseApprovalPlugin\Repository\OrderApprovalRepositoryInterface;

/** @implements ProviderInterface<AdminApprovalView> */
final readonly class AdminApprovalProvider implements ProviderInterface
{
    private const PER_PAGE = 30;

    public function __construct(private OrderApprovalRepositoryInterface $approvalRepository)
    {
    }

    /** @return AdminApprovalView|TraversablePaginator<AdminApprovalView> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): AdminApprovalView|TraversablePaginator
    {
        if ($operation instanceof CollectionOperationInterface) {
            $filters = is_array($context['filters'] ?? null) ? $context['filters'] : [];
            $page = max(1, is_numeric($filters['page'] ?? null) ? (int) $filters['page'] : 1);
            $state = $filters['state'] ?? null;
            $paginator = new Paginator($this->approvalRepository
                ->createAdminListQueryBuilder(is_string($state) && in_array($state, OrderApprovalInterface::STATES, true) ? $state : null)
                ->setFirstResult(($page - 1) * self::PER_PAGE)
                ->setMaxResults(self::PER_PAGE));
            $views = [];
            foreach ($paginator as $approval) {
                if ($approval instanceof OrderApprovalInterface) {
                    $views[] = AdminApprovalView::fromApproval($approval);
                }
            }

            return new TraversablePaginator(new \ArrayIterator($views), $page, self::PER_PAGE, count($paginator));
        }

        return AdminApprovalView::fromApproval($this->find($uriVariables));
    }

    public function find(array $uriVariables): OrderApprovalInterface
    {
        $number = $uriVariables['orderNumber'] ?? null;
        $approval = is_string($number) ? $this->approvalRepository->findOneByOrderNumber($number) : null;
        if (!$approval instanceof \Tmonier\SyliusPurchaseApprovalPlugin\Entity\OrderApprovalInterface) {
            throw new NotFoundHttpException('Approval not found.');
        }

        return $approval;
    }
}
