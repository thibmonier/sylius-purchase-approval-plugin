<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusPurchaseApprovalPlugin\Integration;

use Sylius\Bundle\ApiBundle\Command\Checkout\SendOrderConfirmation;
use Sylius\Bundle\CoreBundle\Command\ResendOrderConfirmationEmail;
use Sylius\Bundle\CoreBundle\Mailer\OrderEmailManagerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\GenericEvent;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Mime\Email;
use Tests\Tmonier\SyliusPurchaseApprovalPlugin\AsyncMessagesTrait;
use Tmonier\SyliusCompanyAccountsPlugin\Entity\CompanyMemberInterface;
use Tmonier\SyliusPurchaseApprovalPlugin\Approval\ApprovalDecisionMaker;
use Tmonier\SyliusPurchaseApprovalPlugin\Approval\Decider;
use Tmonier\SyliusPurchaseApprovalPlugin\Entity\OrderApprovalInterface;
use Tmonier\SyliusPurchaseApprovalPlugin\Repository\OrderApprovalRepositoryInterface;

/**
 * Acknowledgement of receipt instead of the confirmation while the order waits, on both
 * confirmation paths; confirmation once approved; approvers notified; buyer told of a refusal.
 */
final class ApprovalEmailsTest extends KernelTestCase
{
    use IntegrationTestTrait;
    use AsyncMessagesTrait;

    public function testTheShopConfirmationBecomesAnAcknowledgement(): void
    {
        $order = $this->catalog()->orderAgainstLimit($this->catalog()->member($this->catalog()->company()), -1);

        // What the shop checkout dispatches after completing the order.
        $this->service('event_dispatcher', EventDispatcherInterface::class)->dispatch(new GenericEvent($order), 'sylius.order.post_complete');

        self::assertSame([$this->acknowledgementSubject($order)], $this->subjectsTo($order));
    }

    public function testTheApiConfirmationBecomesAnAcknowledgement(): void
    {
        $order = $this->catalog()->orderAgainstLimit($this->catalog()->member($this->catalog()->company()), -1);

        // What the API dispatches after CompleteOrder, consumed by the worker.
        $this->service('sylius.command_bus', MessageBusInterface::class)->dispatch(new SendOrderConfirmation((string) $order->getTokenValue()));
        self::assertSame([], $this->subjectsTo($order), 'Asynchronous.');
        self::assertSame(1, $this->runWorker());

        self::assertSame([$this->acknowledgementSubject($order)], $this->subjectsTo($order));
    }

    public function testALateAsynchronousConfirmationIsNotSentTwice(): void
    {
        $company = $this->catalog()->company();
        $order = $this->catalog()->orderAgainstLimit($this->catalog()->member($company), -1);
        $this->service('sylius.command_bus', MessageBusInterface::class)->dispatch(new SendOrderConfirmation((string) $order->getTokenValue()));

        // The worker is late: the approver decides first.
        $this->decisionMaker()->approve($this->approvalOf($order), Decider::member($this->catalog()->member($company, null, CompanyMemberInterface::ROLE_APPROVER)));
        self::assertSame(1, $this->runWorker());

        self::assertSame([$this->confirmationSubject($order)], $this->subjectsTo($order));
    }

    public function testOrdersWithinTheLimitGetTheRegularConfirmation(): void
    {
        $order = $this->catalog()->orderAgainstLimit($this->catalog()->member($this->catalog()->company()), 0);

        $this->service('event_dispatcher', EventDispatcherInterface::class)->dispatch(new GenericEvent($order), 'sylius.order.post_complete');

        self::assertSame([$this->confirmationSubject($order)], $this->subjectsTo($order));
    }

    public function testApproversAreNotifiedAndTheBuyerConfirmedOnceApproved(): void
    {
        $company = $this->catalog()->company();
        $approver = $this->catalog()->member($company, null, CompanyMemberInterface::ROLE_APPROVER);
        $this->catalog()->member($company, null, CompanyMemberInterface::ROLE_ADMIN);
        $colleague = $this->catalog()->member($company);
        $order = $this->catalog()->orderAgainstLimit($this->catalog()->member($company), -1);

        $requested = array_values(array_filter($this->messages(), static fn (Email $email): bool => str_contains((string) $email->getSubject(), (string) $order->getNumber())));
        self::assertCount(1, $requested, 'Only the approvers of the company.');
        self::assertSame((string) $approver->getCustomer()?->getEmail(), $requested[0]->getTo()[0]->getAddress());
        self::assertSame([], $this->subjectsTo($order), 'Not the buyer.');
        self::assertNotNull($colleague->getId());

        $this->decisionMaker()->approve($this->approvalOf($order), Decider::member($approver));
        self::assertSame([$this->confirmationSubject($order)], $this->subjectsTo($order));
    }

    public function testAConfirmationSentOnPurposeAfterTheApprovalGoesOut(): void
    {
        $order = $this->catalog()->orderAgainstLimit($this->catalog()->member($this->catalog()->company()), -1);
        $this->decisionMaker()->approve($this->approvalOf($order), Decider::administrator(null));

        // Code calling the order e-mail manager, and the admin "Resend confirmation email".
        $this->service('sylius.mailer.order_email_manager', OrderEmailManagerInterface::class)->sendConfirmationEmail($order);
        $this->service('sylius.command_bus', MessageBusInterface::class)->dispatch(new ResendOrderConfirmationEmail((string) $order->getTokenValue()));

        self::assertCount(3, $this->messagesTo($order));
        self::assertSame([$this->confirmationSubject($order), $this->confirmationSubject($order)], array_slice($this->subjectsTo($order), 0, 2));
    }

    public function testAnApproverPlacingAnOrderIsNotAskedToApproveIt(): void
    {
        $company = $this->catalog()->company();
        $other = $this->catalog()->member($company, null, CompanyMemberInterface::ROLE_APPROVER);
        $order = $this->catalog()->orderAgainstLimit($this->catalog()->member($company, null, CompanyMemberInterface::ROLE_APPROVER), -1);

        $requested = array_values(array_filter($this->messages(), static fn (Email $email): bool => str_contains((string) $email->getSubject(), (string) $order->getNumber())));
        self::assertSame([(string) $other->getCustomer()?->getEmail()], array_map(static fn (Email $email): string => $email->getTo()[0]->getAddress(), $requested));
    }

    public function testTheBuyerIsToldOfARejectionWithTheComment(): void
    {
        $order = $this->catalog()->orderAgainstLimit($this->catalog()->member($this->catalog()->company()), -1);

        $this->decisionMaker()->reject($this->approvalOf($order), Decider::administrator(null), 'Please split the order.');

        $emails = $this->messagesTo($order);
        self::assertCount(1, $emails);
        self::assertStringContainsString((string) $order->getNumber(), (string) $emails[0]->getSubject());
        self::assertStringContainsString('Please split the order.', (string) $emails[0]->getHtmlBody());
        // A late confirmation (asynchronous API path) is not sent for a rejected order.
        $this->service('sylius.command_bus', MessageBusInterface::class)->dispatch(new SendOrderConfirmation((string) $order->getTokenValue()));
        $this->runWorker();
        self::assertCount(1, $this->messagesTo($order));
    }

    private function acknowledgementSubject(OrderInterface $order): string
    {
        return sprintf('Order #%s received, awaiting approval', (string) $order->getNumber());
    }

    private function confirmationSubject(OrderInterface $order): string
    {
        return 'Order confirmation';
    }

    /** @return list<string> */
    private function subjectsTo(OrderInterface $order): array
    {
        return array_map(static fn (Email $email): string => trim((string) $email->getSubject()), $this->messagesTo($order));
    }

    /** @return list<Email> */
    private function messagesTo(OrderInterface $order): array
    {
        $address = (string) $order->getCustomer()?->getEmail();

        return array_values(array_filter($this->messages(), static fn (Email $email): bool => $address === $email->getTo()[0]->getAddress()));
    }

    /** @return list<Email> */
    private function messages(): array
    {
        return array_values(array_filter(self::getMailerMessages(), static fn ($message): bool => $message instanceof Email));
    }

    private function approvalOf(OrderInterface $order): OrderApprovalInterface
    {
        $approval = $this->service('tmonier_purchase_approval.repository.order_approval', OrderApprovalRepositoryInterface::class)->findOneByOrder($order);
        self::assertInstanceOf(OrderApprovalInterface::class, $approval);

        return $approval;
    }

    private function decisionMaker(): ApprovalDecisionMaker
    {
        return $this->service('tmonier_purchase_approval.decision_maker', ApprovalDecisionMaker::class);
    }
}
