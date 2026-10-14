<?php

declare(strict_types=1);

namespace Tmonier\SyliusPurchaseApprovalPlugin\Menu;

use Sylius\Bundle\UiBundle\Menu\Event\MenuBuilderEvent;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Tmonier\SyliusCompanyAccountsPlugin\Member\CurrentMemberResolverInterface;
use Tmonier\SyliusPurchaseApprovalPlugin\Security\ApprovalPermissions;

/** Account menu: "Approvals" for approvers, "Purchase limits" for company admins. */
final readonly class AccountMenuListener
{
    public function __construct(
        private CurrentMemberResolverInterface $currentMemberResolver,
        private AuthorizationCheckerInterface $authorizationChecker,
    ) {
    }

    public function __invoke(MenuBuilderEvent $event): void
    {
        $company = $this->currentMemberResolver->getMember()?->getCompany();
        if (!$company instanceof \Tmonier\SyliusCompanyAccountsPlugin\Entity\CompanyInterface) {
            return;
        }

        if ($this->authorizationChecker->isGranted(ApprovalPermissions::APPROVAL_LIST, $company)) {
            $event->getMenu()
                ->addChild('tmonier_approvals', ['route' => 'tmonier_purchase_approval_shop_approvals'])
                ->setLabel('tmonier_purchase_approval.ui.approvals')
                ->setLabelAttribute('icon', 'tabler:checklist')
            ;
        }
        if ($this->authorizationChecker->isGranted(ApprovalPermissions::LIMITS_MANAGE, $company)) {
            $event->getMenu()
                ->addChild('tmonier_purchase_limits', ['route' => 'tmonier_purchase_approval_shop_limits'])
                ->setLabel('tmonier_purchase_approval.ui.purchase_limits')
                ->setLabelAttribute('icon', 'tabler:coins')
            ;
        }
    }
}
