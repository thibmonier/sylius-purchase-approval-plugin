<?php

declare(strict_types=1);

namespace Tmonier\SyliusPurchaseApprovalPlugin\Grid\Filter;

use Sylius\Bundle\GridBundle\Doctrine\ORM\DataSource;
use Sylius\Component\Grid\Data\DataSourceInterface;
use Sylius\Component\Grid\Filtering\FilterInterface;
use Tmonier\SyliusPurchaseApprovalPlugin\Entity\OrderApprovalInterface;

/** Orders grid: orders whose approval is in a state, or orders that needed none (`none`). */
final class ApprovalStateFilter implements FilterInterface
{
    public const NONE = 'none';

    /** @param class-string<OrderApprovalInterface> $approvalClass */
    public function __construct(private readonly string $approvalClass)
    {
    }

    public function apply(DataSourceInterface $dataSource, string $name, $data, array $options): void
    {
        if (!$dataSource instanceof DataSource || !is_string($data) || !in_array($data, [...OrderApprovalInterface::STATES, self::NONE], true)) {
            return;
        }

        $queryBuilder = $dataSource->getQueryBuilder();
        $root = $queryBuilder->getRootAliases()[0];
        $alias = 'tmonier_approval_filter';
        $subquery = sprintf('SELECT 1 FROM %s %s WHERE %2$s.order = %s', $this->approvalClass, $alias, $root);
        if (self::NONE === $data) {
            $dataSource->restrict(sprintf('NOT EXISTS (%s)', $subquery));

            return;
        }

        $queryBuilder->setParameter('tmonier_approval_state', $data);
        $dataSource->restrict(sprintf('EXISTS (%s AND %s.state = :tmonier_approval_state)', $subquery, $alias));
    }
}
