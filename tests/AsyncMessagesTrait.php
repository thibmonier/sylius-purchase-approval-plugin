<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusPurchaseApprovalPlugin;

use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * The test application routes the API order confirmation to an in-memory transport: this
 * consumes it like a worker (handled on the Sylius command bus).
 */
trait AsyncMessagesTrait
{
    /** @return int messages handled */
    private function runWorker(): int
    {
        $transport = self::getContainer()->get('messenger.transport.tmonier_test_async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $bus = self::getContainer()->get('sylius.command_bus');
        self::assertInstanceOf(MessageBusInterface::class, $bus);

        $handled = 0;
        foreach ($transport->get() as $envelope) {
            $bus->dispatch($envelope->with(new ReceivedStamp('tmonier_test_async')));
            $transport->ack($envelope);
            ++$handled;
        }

        return $handled;
    }
}
