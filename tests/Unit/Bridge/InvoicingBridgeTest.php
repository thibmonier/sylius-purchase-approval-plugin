<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusPurchaseApprovalPlugin\Unit\Bridge;

use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\Order;
use Sylius\InvoicingPlugin\Creator\InvoiceCreatorInterface;
use Sylius\InvoicingPlugin\Event\OrderPlaced;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Tmonier\SyliusPurchaseApprovalPlugin\Bridge\Invoicing\ApprovalAwareInvoiceCreator;
use Tmonier\SyliusPurchaseApprovalPlugin\Bridge\Invoicing\InvoiceOnApprovalListener;
use Tmonier\SyliusPurchaseApprovalPlugin\Entity\OrderApproval;
use Tmonier\SyliusPurchaseApprovalPlugin\Entity\OrderApprovalInterface;
use Tmonier\SyliusPurchaseApprovalPlugin\Event\OrderApprovalEvent;
use Tmonier\SyliusPurchaseApprovalPlugin\Repository\OrderApprovalRepositoryInterface;

/** sylius/invoicing-plugin: no invoice before the approval, the invoice when approved. */
final class InvoicingBridgeTest extends TestCase
{
    public function testInvoicesWaitForTheApproval(): void
    {
        $inner = new class() implements InvoiceCreatorInterface {
            /** @var list<string> */
            public array $created = [];

            public function __invoke(string $orderNumber, \DateTimeInterface $dateTime): void
            {
                $this->created[] = $orderNumber;
            }
        };
        $approvals = [
            'PENDING' => $this->approval(OrderApprovalInterface::STATE_PENDING),
            'REJECTED' => $this->approval(OrderApprovalInterface::STATE_REJECTED),
            'APPROVED' => $this->approval(OrderApprovalInterface::STATE_APPROVED),
        ];
        $repository = $this->createMock(OrderApprovalRepositoryInterface::class);
        $repository->method('findOneByOrderNumber')->willReturnCallback(static fn (string $number): ?OrderApprovalInterface => $approvals[$number] ?? null);
        $creator = new ApprovalAwareInvoiceCreator($inner, $repository);

        foreach (['PENDING', 'REJECTED', 'APPROVED', 'NO_APPROVAL_NEEDED'] as $number) {
            $creator($number, new \DateTimeImmutable());
        }

        self::assertSame(['APPROVED', 'NO_APPROVAL_NEEDED'], $inner->created);
    }

    public function testTheApprovalPlacesTheOrderForInvoicing(): void
    {
        $bus = new class() implements MessageBusInterface {
            /** @var list<object> */
            public array $messages = [];

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $this->messages[] = $message;

                return new Envelope($message);
            }
        };
        $approval = $this->approval(OrderApprovalInterface::STATE_APPROVED);
        $approval->setDecidedAt(new \DateTimeImmutable('2026-10-01 10:00:00'));

        (new InvoiceOnApprovalListener($bus))(new OrderApprovalEvent($approval));

        self::assertCount(1, $bus->messages);
        $message = $bus->messages[0];
        self::assertInstanceOf(OrderPlaced::class, $message);
        self::assertSame('000042', $message->orderNumber());
        self::assertSame('2026-10-01 10:00:00', $message->date()->format('Y-m-d H:i:s'));
    }

    private function approval(string $state): OrderApprovalInterface
    {
        $order = new Order();
        $order->setNumber('000042');
        $approval = new OrderApproval();
        $approval->setOrder($order);
        $approval->setState($state);

        return $approval;
    }
}
