<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusPurchaseApprovalPlugin\Unit\Mailer;

use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Mailer\Sender\SenderInterface;
use Tmonier\SyliusPurchaseApprovalPlugin\Entity\OrderApproval;
use Tmonier\SyliusPurchaseApprovalPlugin\Entity\OrderApprovalInterface;
use Tmonier\SyliusPurchaseApprovalPlugin\Mailer\AcknowledgementEmailSender;
use Tmonier\SyliusPurchaseApprovalPlugin\Repository\OrderApprovalRepositoryInterface;

final class AcknowledgementEmailSenderTest extends TestCase
{
    public function testTheConfirmationIsRecordedOnlyOnceSent(): void
    {
        $approval = new OrderApproval();
        $approval->setState(OrderApprovalInterface::STATE_APPROVED);
        $repository = $this->createMock(OrderApprovalRepositoryInterface::class);
        $repository->method('findOneByOrder')->willReturn($approval);
        $failing = $this->createMock(SenderInterface::class);
        $failing->method('send')->willThrowException(new \RuntimeException('SMTP down'));

        $repository->expects(self::never())->method('markConfirmationSent');

        try {
            (new AcknowledgementEmailSender($failing, $repository))->send('order_confirmation', ['buyer@example.com'], ['order' => new Order()]);
            self::fail('The failure is not hidden.');
        } catch (\RuntimeException) {
        }
    }

    public function testASuccessfulConfirmationIsRecorded(): void
    {
        $approval = new OrderApproval();
        $approval->setState(OrderApprovalInterface::STATE_APPROVED);
        $repository = $this->createMock(OrderApprovalRepositoryInterface::class);
        $repository->method('findOneByOrder')->willReturn($approval);

        $repository->expects(self::once())->method('markConfirmationSent')->with($approval);
        (new AcknowledgementEmailSender($this->createMock(SenderInterface::class), $repository))->send('order_confirmation', ['buyer@example.com'], ['order' => new Order()]);
    }
}
