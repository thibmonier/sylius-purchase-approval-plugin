<?php

declare(strict_types=1);

namespace Tmonier\SyliusPurchaseApprovalPlugin\Mailer;

use Sylius\Bundle\CoreBundle\Mailer\OrderEmailManagerInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Mailer\Sender\SenderInterface;
use Tmonier\SyliusCompanyAccountsPlugin\Entity\CompanyMemberInterface;
use Tmonier\SyliusCompanyAccountsPlugin\Repository\CompanyMemberRepositoryInterface;
use Tmonier\SyliusPurchaseApprovalPlugin\Approval\ApprovalPolicy;
use Tmonier\SyliusPurchaseApprovalPlugin\Event\OrderApprovalEvent;

/**
 * - requested: the approvers of the company who may decide on it (not the buyer);
 * - approved: the regular order confirmation to the buyer;
 * - rejected / expired: the buyer, with the comment of the decision.
 */
final class ApprovalEmailListener
{
    public function __construct(
        private readonly SenderInterface $sender,
        private readonly OrderEmailManagerInterface $orderEmailManager,
        private readonly CompanyMemberRepositoryInterface $memberRepository,
        private readonly ApprovalPolicy $policy,
    ) {
    }

    public function onRequested(OrderApprovalEvent $event): void
    {
        $approval = $event->getApproval();
        $order = $approval->getOrder();
        $company = $approval->getCompany();
        if (!$order instanceof OrderInterface || null === $company) {
            return;
        }
        $buyerId = $order->getCustomer()?->getId();
        /** @var CompanyMemberInterface $member */
        foreach ($this->memberRepository->findByCompany($company) as $member) {
            $customer = $member->getCustomer();
            $email = $customer?->getEmail();
            if (null === $customer || null === $email || !$this->policy->isApprover($member) || $customer->getId() === $buyerId) {
                continue;
            }
            $this->sender->send(Emails::APPROVAL_REQUESTED, [$email], $this->data($order) + ['approval' => $approval, 'approver' => $customer]);
        }
    }

    public function onApproved(OrderApprovalEvent $event): void
    {
        $order = $event->getApproval()->getOrder();
        if ($order instanceof OrderInterface && null !== $this->buyerEmail($order)) {
            $this->orderEmailManager->sendConfirmationEmail($order);
        }
    }

    public function onRejected(OrderApprovalEvent $event): void
    {
        $this->sendToBuyer(Emails::REJECTED, $event);
    }

    public function onExpired(OrderApprovalEvent $event): void
    {
        $this->sendToBuyer(Emails::EXPIRED, $event);
    }

    private function sendToBuyer(string $code, OrderApprovalEvent $event): void
    {
        $approval = $event->getApproval();
        $order = $approval->getOrder();
        $email = null === $order ? null : $this->buyerEmail($order);
        if (null === $order || null === $email) {
            return;
        }
        $this->sender->send($code, [$email], $this->data($order) + ['approval' => $approval]);
    }

    private function buyerEmail(OrderInterface $order): ?string
    {
        $customer = $order->getCustomer();

        return $customer instanceof CustomerInterface ? $customer->getEmail() : null;
    }

    /** @return array{order: OrderInterface, channel: mixed, localeCode: ?string} */
    private function data(OrderInterface $order): array
    {
        return ['order' => $order, 'channel' => $order->getChannel(), 'localeCode' => $order->getLocaleCode()];
    }
}
