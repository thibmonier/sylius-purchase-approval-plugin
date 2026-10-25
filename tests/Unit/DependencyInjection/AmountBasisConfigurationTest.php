<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusPurchaseApprovalPlugin\Unit\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Tmonier\SyliusPurchaseApprovalPlugin\DependencyInjection\Configuration;

final class AmountBasisConfigurationTest extends TestCase
{
    public function testTheDefaultBasisIsTheTotalTaxIncluded(): void
    {
        self::assertSame('gross', $this->basis([]));
    }

    public function testTheNetBasisCanBeChosen(): void
    {
        self::assertSame('net', $this->basis(['amount_basis' => 'net']));
    }

    public function testAnUnknownBasisIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->basis(['amount_basis' => 'half']);
    }

    /** @param array<string, mixed> $config */
    private function basis(array $config): string
    {
        $processed = (new Processor())->processConfiguration(new Configuration(), [$config]);
        self::assertIsString($processed['amount_basis']);

        return $processed['amount_basis'];
    }
}
